<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mandrill;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Contracts\HttpResponse;
use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Entities\SendResult;
use ExcelleInsights\Email\Support\AttachmentResolver;
use ExcelleInsights\Email\Support\HttpRequestLogger;

class MandrillProvider implements EmailProviderInterface
{
    public const KEY = 'mandrill';

    /**
     * @param \Closure(?int): MandrillConfig $configResolver
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MandrillResponseMapper $mapper,
        private readonly MandrillWebhookParser $webhookParser,
        private readonly AttachmentResolver $attachmentResolver,
        private readonly \Closure $configResolver,
        private readonly ?HttpRequestLogger $auditLogger = null,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDisplayName(): string
    {
        return 'Mandrill';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            bulk: true,
            attachments: true,
            cc: true,
            bcc: true,
            tracking: true,
            webhookEvents: true,
            nativeTemplates: true,
        );
    }

    public function send(SendRequest $request): SendResult
    {
        $config = $this->resolveConfig($request->tenantId);
        $useNativeTemplate = $request->templateKey !== null
            && $request->templateKey !== ''
            && (bool) ($request->options['native_template'] ?? $config->nativeTemplates);

        $payload = [
            'key' => $config->apiKey,
            'message' => $this->message($request, $config),
        ];

        $url = $config->sendUrl();
        if ($useNativeTemplate) {
            $url = $config->sendTemplateUrl();
            $payload['template_name'] = $request->templateKey;
            $payload['template_content'] = $this->templateContent($request);
            $payload['message']['global_merge_vars'] = $this->mergeVars($request);
        }

        $encoded = json_encode($payload);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode Mandrill request');
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $options = ['headers' => $headers, 'body' => $encoded];

        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->post($url, $options);
        } catch (\Throwable $e) {
            $this->audit($request, $url, $headers, $this->auditBody($payload), null, (int) ((microtime(true) - $startedAt) * 1000));

            throw $e;
        }

        $this->audit($request, $url, $headers, $this->auditBody($payload), $response, (int) ((microtime(true) - $startedAt) * 1000));

        $data = $this->mapper->decode($response->body);
        if (! $response->isSuccessful() || $this->mapper->isApiError($data)) {
            throw new \RuntimeException(
                $this->redactSecrets($this->mapper->error($data, $response->statusCode), $config->secrets()),
            );
        }

        $result = $this->mapper->firstResult($data);
        if ($result === []) {
            throw new \RuntimeException('Mandrill response missing recipient data');
        }

        $status = $this->mapper->status($result);
        if ($status !== MessageStatus::SENT) {
            return new SendResult(
                status: MessageStatus::FAILED,
                error: $this->redactSecrets($this->mapper->errorReason($result), $config->secrets()),
            );
        }

        return new SendResult(
            status: MessageStatus::SENT,
            providerMessageId: $this->mapper->messageId($data),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function parseWebhook(array $headers, string $payload): ?EmailEvent
    {
        return $this->webhookParser->parse($headers, $payload);
    }

    private function resolveConfig(?int $tenantId): MandrillConfig
    {
        $config = ($this->configResolver)($tenantId);
        if (! $config instanceof MandrillConfig) {
            throw new \RuntimeException('Mandrill config resolver did not return a valid config');
        }

        $config->validate();

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function message(SendRequest $request, MandrillConfig $config): array
    {
        $fromEmail = $request->fromEmail ?? $config->fromEmail;
        if ($fromEmail === null || $fromEmail === '') {
            throw new \RuntimeException('Mandrill From address is not configured');
        }

        $message = [
            'subject' => $request->subject,
            'from_email' => $fromEmail,
            'from_name' => $request->fromName ?? $config->fromName ?? '',
            'to' => [[
                'email' => $request->to,
                'name' => $request->recipientName ?? '',
                'type' => 'to',
            ]],
            'track_opens' => (bool) ($request->options['track_opens'] ?? $config->trackOpens),
            'track_clicks' => (bool) ($request->options['track_clicks'] ?? $config->trackClicks),
        ];

        if ($request->html !== '') {
            $message['html'] = $request->html;
        }

        if ($request->text !== null && $request->text !== '') {
            $message['text'] = $request->text;
        } elseif ($config->autoText) {
            $message['auto_text'] = true;
        }

        $replyTo = $request->replyTo ?? $config->replyTo;
        if ($replyTo !== null && $replyTo !== '') {
            $message['headers'] = ['Reply-To' => $replyTo];
        }

        if ($request->cc !== []) {
            $message['cc'] = $request->cc;
        }

        if ($request->bcc !== []) {
            $message['bcc'] = $request->bcc;
        }

        $metadata = $this->metadata($request);
        if ($metadata !== []) {
            $message['metadata'] = $metadata;
        }

        $attachments = $this->attachments($request);
        if ($attachments !== []) {
            $message['attachments'] = $attachments;
        }

        return $message;
    }

    /**
     * @return array<string, string>
     */
    private function metadata(SendRequest $request): array
    {
        $metadata = [];
        foreach ($request->metadata as $key => $value) {
            $metadata[$key] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return $metadata;
    }

    /**
     * @return array<int, array{type: string, name: string, content: string}>
     */
    private function attachments(SendRequest $request): array
    {
        $attachments = [];
        foreach ($this->attachmentResolver->resolve($request->attachments) as $attachment) {
            $content = @file_get_contents($attachment['path']);
            if ($content === false) {
                throw new \RuntimeException('Unable to read email attachment');
            }

            $attachments[] = [
                'type' => $attachment['type'],
                'name' => $attachment['name'],
                'content' => base64_encode($content),
            ];
        }

        return $attachments;
    }

    /**
     * @return array<int, array{name: string, content: string}>
     */
    private function templateContent(SendRequest $request): array
    {
        $content = $request->options['template_content'] ?? [];
        if (! is_array($content)) {
            return [];
        }

        $result = [];
        foreach ($content as $name => $value) {
            if (is_array($value) && isset($value['name'], $value['content']) && is_string($value['name'])) {
                $result[] = [
                    'name' => $value['name'],
                    'content' => is_scalar($value['content']) ? (string) $value['content'] : (string) json_encode($value['content']),
                ];
            } elseif (is_string($name)) {
                $result[] = [
                    'name' => $name,
                    'content' => is_scalar($value) ? (string) $value : (string) json_encode($value),
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array{name: string, content: mixed}>
     */
    private function mergeVars(SendRequest $request): array
    {
        $vars = [];
        foreach ($request->templateVars as $name => $value) {
            $vars[] = ['name' => $name, 'content' => $value];
        }

        return $vars;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function auditBody(array $payload): string
    {
        if (isset($payload['message']['html'])) {
            $payload['message']['html'] = '[BODY OMITTED]';
        }
        if (isset($payload['message']['text'])) {
            $payload['message']['text'] = '[BODY OMITTED]';
        }
        if (isset($payload['message']['attachments']) && is_array($payload['message']['attachments'])) {
            foreach ($payload['message']['attachments'] as &$attachment) {
                if (is_array($attachment)) {
                    $attachment['content'] = '[ATTACHMENT OMITTED]';
                }
            }
            unset($attachment);
        }

        $encoded = json_encode($payload);

        return $encoded === false ? '[ENCODING FAILED]' : $encoded;
    }

    /**
     * @param array<string, string> $headers
     */
    private function audit(
        SendRequest $request,
        string $url,
        array $headers,
        string $body,
        ?HttpResponse $response,
        int $durationMs,
    ): void {
        if ($this->auditLogger === null) {
            return;
        }

        $this->auditLogger->log(
            $request->tenantId,
            self::KEY,
            'POST',
            $url,
            $headers,
            $body,
            $response?->statusCode,
            $response?->body,
            $response?->headers ?? [],
            $durationMs,
        );
    }

    /**
     * @param array<int, string> $secrets
     */
    private function redactSecrets(string $value, array $secrets): string
    {
        return $secrets === [] ? $value : str_ireplace($secrets, '[REDACTED]', $value);
    }
}

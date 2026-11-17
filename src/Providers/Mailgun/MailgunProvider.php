<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mailgun;

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

class MailgunProvider implements EmailProviderInterface
{
    public const KEY = 'mailgun';

    /**
     * @param \Closure(?int): MailgunConfig $configResolver
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MailgunResponseMapper $mapper,
        private readonly MailgunWebhookParser $webhookParser,
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
        return 'Mailgun';
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
        );
    }

    public function send(SendRequest $request): SendResult
    {
        $config = $this->resolveConfig($request->tenantId);
        $url = $config->messagesUrl();
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => $config->basicAuth(),
        ];

        $fields = $this->fields($request, $config);
        $attachments = $this->attachmentResolver->resolve($request->attachments);
        $safeFields = $fields;
        $safeFields['html'] = '[BODY OMITTED]';
        if (isset($safeFields['text'])) {
            $safeFields['text'] = '[BODY OMITTED]';
        }

        if ($attachments === []) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $options = [
                'headers' => $headers,
                'body' => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            ];
            $auditBody = http_build_query($safeFields, '', '&', PHP_QUERY_RFC3986);
        } else {
            $multipartFields = [];
            foreach ($fields as $name => $value) {
                $multipartFields[$name] = (string) $value;
            }

            $files = [];
            foreach ($attachments as $index => $attachment) {
                $files[] = [
                    'name' => 'attachment[' . $index . ']',
                    'path' => $attachment['path'],
                    'filename' => $attachment['name'],
                    'type' => $attachment['type'],
                ];
            }

            $options = [
                'headers' => $headers,
                'multipart' => [
                    'fields' => $multipartFields,
                    'files' => $files,
                ],
            ];
            $auditBody = http_build_query($safeFields, '', '&', PHP_QUERY_RFC3986);
        }

        $startedAt = microtime(true);
        try {
            $response = $this->httpClient->post($url, $options);
        } catch (\Throwable $e) {
            $this->audit(
                $request,
                $url,
                $headers,
                $auditBody,
                null,
                (int) ((microtime(true) - $startedAt) * 1000),
            );

            throw $e;
        }

        $this->audit(
            $request,
            $url,
            $headers,
            $auditBody,
            $response,
            (int) ((microtime(true) - $startedAt) * 1000),
        );

        if (! $response->isSuccessful()) {
            throw new \RuntimeException(
                $this->redactSecrets($this->mapper->error($response->statusCode, $response->body), $config->secrets()),
            );
        }

        return new SendResult(
            status: MessageStatus::SENT,
            providerMessageId: $this->mapper->messageId($response->body),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function parseWebhook(array $headers, string $payload): ?EmailEvent
    {
        return $this->webhookParser->parse($headers, $payload);
    }

    private function resolveConfig(?int $tenantId): MailgunConfig
    {
        $config = ($this->configResolver)($tenantId);
        if (! $config instanceof MailgunConfig) {
            throw new \RuntimeException('Mailgun config resolver did not return a valid config');
        }

        $config->validate();

        return $config;
    }

    /**
     * @return array<string, string>
     */
    private function fields(SendRequest $request, MailgunConfig $config): array
    {
        $fromEmail = $request->fromEmail ?? $config->fromEmail;
        if ($fromEmail === null || $fromEmail === '') {
            throw new \RuntimeException('Mailgun From address is not configured');
        }

        $fields = [
            'from' => $this->formatAddress($fromEmail, $request->fromName ?? $config->fromName),
            'to' => $this->formatAddress($request->to, $request->recipientName),
            'subject' => $request->subject,
            'html' => $request->html,
            'o:tracking' => $this->flag($request, $config->tracking, 'tracking'),
            'o:tracking-clicks' => $this->flag($request, $config->clickTracking, 'click_tracking'),
            'o:tracking-opens' => $this->flag($request, $config->openTracking, 'open_tracking'),
        ];

        if ($request->text !== null && $request->text !== '') {
            $fields['text'] = $request->text;
        }

        if ($request->cc !== []) {
            $fields['cc'] = implode(',', $request->cc);
        }

        if ($request->bcc !== []) {
            $fields['bcc'] = implode(',', $request->bcc);
        }

        $replyTo = $request->replyTo ?? $config->replyTo;
        if ($replyTo !== null && $replyTo !== '') {
            $fields['h:Reply-To'] = $replyTo;
        }

        foreach ($request->metadata as $key => $value) {
            $name = preg_replace('/[^A-Za-z0-9_-]/', '_', $key);
            if ($name === null || $name === '') {
                continue;
            }

            $fields['v:' . $name] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return $fields;
    }

    private function flag(SendRequest $request, bool $default, string $option): string
    {
        $enabled = array_key_exists($option, $request->options)
            ? (bool) $request->options[$option]
            : $default;

        return $enabled ? 'yes' : 'no';
    }

    private function formatAddress(string $email, ?string $name): string
    {
        $email = str_replace(["\r", "\n"], '', $email);
        if ($name === null || $name === '') {
            return $email;
        }

        $name = str_replace(['"', "\r", "\n"], '', $name);

        return '"' . $name . '" <' . $email . '>';
    }

    /**
     * @param array<string, string> $headers
     */
    private function audit(
        SendRequest $request,
        string $url,
        array $headers,
        ?string $body,
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

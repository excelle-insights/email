<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use ExcelleInsights\Email\Config\EmailConfig;
use ExcelleInsights\Email\Entities\WebhookResult;
use ExcelleInsights\Email\Exceptions\WebhookVerificationException;
use ExcelleInsights\Email\Providers\ProviderRegistry;
use ExcelleInsights\Email\Repositories\WebhookLogRepository;
use Psr\Log\LoggerInterface;

final class WebhookService
{
    private const MAILGUN = 'mailgun';
    private const MANDRILL = 'mandrill';

    /**
     * @var array<int, string>
     */
    private const REDACTED_HEADERS = [
        'authorization',
        'cookie',
        'proxy-authorization',
        'x-api-key',
        'x-mandrill-signature',
    ];

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly EmailConfig $config,
        private readonly WebhookLogRepository $webhookLogRepository,
        private readonly EventService $eventService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $requestData
     */
    public function verify(string $provider, array $requestData, string $payload): bool
    {
        try {
            $this->assertVerified($provider, $requestData, $payload);

            return true;
        } catch (WebhookVerificationException $e) {
            $this->logger->warning('Email webhook verification failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $requestData
     */
    public function process(string $provider, array $requestData, string $payload): WebhookResult
    {
        $provider = strtolower(trim($provider));
        $this->assertSupported($provider);

        $headers = $this->normaliseHeaders($requestData['headers'] ?? []);
        $this->logWebhook($provider, $headers, $payload, $requestData);
        $this->assertVerified($provider, $requestData, $payload, $headers);

        $event = $this->providerRegistry->get($provider)->parseWebhook($headers, $payload);
        if ($event === null) {
            $this->logger->warning('Email webhook payload could not be parsed', ['provider' => $provider]);

            return new WebhookResult(WebhookResult::IGNORED);
        }

        return $this->eventService->process($event, $provider, $payload);
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, string>|null $headers
     */
    private function assertVerified(
        string $provider,
        array $requestData,
        string $payload,
        ?array $headers = null,
    ): void {
        $provider = strtolower(trim($provider));
        $this->assertSupported($provider);
        $headers ??= $this->normaliseHeaders($requestData['headers'] ?? []);

        match ($provider) {
            self::MAILGUN => $this->verifyMailgun($payload),
            self::MANDRILL => $this->verifyMandrill($requestData, $headers, $payload),
            default => throw new WebhookVerificationException("Unsupported webhook provider {$provider}"),
        };
    }

    private function assertSupported(string $provider): void
    {
        if (! in_array($provider, [self::MAILGUN, self::MANDRILL], true)) {
            throw new WebhookVerificationException("Webhooks are not supported for provider {$provider}");
        }

        if (! $this->providerRegistry->has($provider)) {
            throw new WebhookVerificationException("Provider {$provider} is not registered");
        }

        if (! $this->providerRegistry->get($provider)->capabilities()->webhookEvents) {
            throw new WebhookVerificationException("Provider {$provider} does not emit webhook events");
        }
    }

    private function verifyMailgun(string $payload): void
    {
        $decoded = json_decode($payload, true);
        $body = is_array($decoded) ? $decoded : [];
        $signature = $body['signature'] ?? [];
        if (! is_array($signature)) {
            throw new WebhookVerificationException('Malformed Mailgun webhook signature');
        }

        $timestamp = $this->stringValue($signature['timestamp'] ?? null);
        $token = $this->stringValue($signature['token'] ?? null);
        $received = $this->stringValue($signature['signature'] ?? null);

        if ($timestamp === null || $token === null || $received === null) {
            throw new WebhookVerificationException('Missing Mailgun webhook signature fields');
        }

        $this->assertFresh((int) $timestamp);

        $keys = array_filter([
            $this->config->webhookSigningKey(self::MAILGUN),
            $this->config->mailgunApiKey(),
        ]);
        if ($keys === []) {
            throw new WebhookVerificationException('Mailgun webhook key is not configured');
        }

        foreach ($keys as $key) {
            $expected = hash_hmac('sha256', $timestamp . $token, (string) $key);
            if (hash_equals($expected, $received)) {
                return;
            }
        }

        throw new WebhookVerificationException('Invalid Mailgun webhook signature');
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, string> $headers
     */
    private function verifyMandrill(array $requestData, array $headers, string $payload): void
    {
        $signature = $headers['x-mandrill-signature'] ?? $this->stringValue($requestData['signature'] ?? null);
        $key = $this->config->webhookSigningKey(self::MANDRILL);

        if ($signature === null || $signature === '') {
            throw new WebhookVerificationException('Missing Mandrill webhook signature');
        }
        if ($key === null) {
            throw new WebhookVerificationException('Mandrill webhook key is not configured');
        }

        $url = $this->stringValue($requestData['url'] ?? null)
            ?? $this->stringValue($requestData['webhook_url'] ?? null)
            ?? ($headers['x-mandrill-url'] ?? null);
        if ($url === null || $url === '') {
            throw new WebhookVerificationException('Mandrill webhook URL is required for verification');
        }

        $params = [];
        if (isset($requestData['post']) && is_array($requestData['post'])) {
            $params = $requestData['post'];
        } else {
            parse_str($payload, $params);
        }
        ksort($params, SORT_STRING);

        $signedData = $url;
        foreach ($params as $name => $value) {
            $signedData .= (string) $name . $this->stringify($value);
        }

        $expected = base64_encode(hash_hmac('sha1', $signedData, $key, true));
        if (! hash_equals($expected, $signature)) {
            throw new WebhookVerificationException('Invalid Mandrill webhook signature');
        }

        $event = $this->providerRegistry->get(self::MANDRILL)->parseWebhook($headers, $payload);
        $timestamp = $event?->eventTimestamp?->getTimestamp() ?? 0;
        $this->assertFresh($timestamp);
    }

    private function assertFresh(int $timestamp): void
    {
        if ($timestamp <= 0) {
            throw new WebhookVerificationException('Webhook timestamp is missing');
        }

        if (abs(time() - $timestamp) > $this->config->webhookFreshnessSeconds()) {
            throw new WebhookVerificationException('Webhook timestamp is outside the freshness window');
        }
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, string> $headers
     */
    private function logWebhook(string $provider, array $headers, string $payload, array $requestData): void
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $redacted[$name] = in_array(strtolower($name), self::REDACTED_HEADERS, true)
                ? '[REDACTED]'
                : $value;
        }

        $this->webhookLogRepository->create(
            $provider,
            'inbound',
            $redacted,
            $payload,
            $this->tenantId($requestData, $headers),
        );
    }

    /**
     * @param array<string, mixed> $requestData
     * @param array<string, string> $headers
     */
    private function tenantId(array $requestData, array $headers): ?int
    {
        $value = $requestData['tenant_id'] ?? null;
        if (! is_int($value) && ! is_string($value)) {
            $query = $requestData['query'] ?? [];
            $value = is_array($query) ? ($query['tenant_id'] ?? null) : null;
        }
        if (! is_int($value) && ! is_string($value)) {
            $value = $headers['x-tenant-id'] ?? null;
        }

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, string>
     */
    private function normaliseHeaders(array $headers): array
    {
        $normalised = [];
        foreach ($headers as $name => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $normalised[strtolower((string) $name)] = (string) $value;
            }
        }

        return $normalised;
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return $encoded === false ? '' : $encoded;
    }
}

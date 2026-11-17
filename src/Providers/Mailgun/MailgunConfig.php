<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mailgun;

class MailgunConfig
{
    public function __construct(
        public readonly string $apiKey = '',
        public readonly string $domain = '',
        public readonly string $baseUrl = 'https://api.mailgun.net/v3',
        public readonly bool $tracking = true,
        public readonly bool $clickTracking = true,
        public readonly bool $openTracking = true,
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
    ) {
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public static function fromArray(array $credentials): self
    {
        $region = strtolower((string) ($credentials['region'] ?? 'us'));
        $defaultBaseUrl = $region === 'eu'
            ? 'https://api.eu.mailgun.net/v3'
            : 'https://api.mailgun.net/v3';

        return new self(
            apiKey: (string) ($credentials['api_key'] ?? $credentials['key'] ?? ''),
            domain: (string) ($credentials['domain'] ?? ''),
            baseUrl: self::optionalString($credentials['base_url'] ?? null) ?? $defaultBaseUrl,
            tracking: array_key_exists('tracking', $credentials) ? (bool) $credentials['tracking'] : true,
            clickTracking: array_key_exists('click_tracking', $credentials) ? (bool) $credentials['click_tracking'] : true,
            openTracking: array_key_exists('open_tracking', $credentials) ? (bool) $credentials['open_tracking'] : true,
            fromEmail: self::optionalString($credentials['from_email'] ?? null),
            fromName: self::optionalString($credentials['from_name'] ?? null),
            replyTo: self::optionalString($credentials['reply_to'] ?? null),
        );
    }

    public function messagesUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/' . rawurlencode($this->domain) . '/messages';
    }

    public function validate(): void
    {
        if ($this->apiKey === '' || $this->domain === '') {
            throw new \RuntimeException('Mailgun credentials are not configured');
        }
    }

    public function basicAuth(): string
    {
        return 'Basic ' . base64_encode('api:' . $this->apiKey);
    }

    /**
     * @return array<int, string>
     */
    public function secrets(): array
    {
        return $this->apiKey === '' ? [] : [$this->apiKey];
    }

    private static function optionalString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}

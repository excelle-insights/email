<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mandrill;

class MandrillConfig
{
    public function __construct(
        public readonly string $apiKey = '',
        public readonly string $baseUrl = 'https://mandrillapp.com/api/1.0',
        public readonly bool $nativeTemplates = false,
        public readonly bool $trackOpens = true,
        public readonly bool $trackClicks = true,
        public readonly bool $autoText = true,
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
        return new self(
            apiKey: (string) ($credentials['api_key'] ?? $credentials['key'] ?? ''),
            baseUrl: self::optionalString($credentials['base_url'] ?? null) ?? 'https://mandrillapp.com/api/1.0',
            nativeTemplates: (bool) ($credentials['native_templates'] ?? false),
            trackOpens: array_key_exists('track_opens', $credentials) ? (bool) $credentials['track_opens'] : true,
            trackClicks: array_key_exists('track_clicks', $credentials) ? (bool) $credentials['track_clicks'] : true,
            autoText: array_key_exists('auto_text', $credentials) ? (bool) $credentials['auto_text'] : true,
            fromEmail: self::optionalString($credentials['from_email'] ?? null),
            fromName: self::optionalString($credentials['from_name'] ?? null),
            replyTo: self::optionalString($credentials['reply_to'] ?? null),
        );
    }

    public function sendUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/messages/send.json';
    }

    public function sendTemplateUrl(): string
    {
        return rtrim($this->baseUrl, '/') . '/messages/send-template.json';
    }

    public function validate(): void
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Mandrill credentials are not configured');
        }
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

<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Phpmailer;

class PhpmailerConfig
{
    public function __construct(
        public readonly string $host = '',
        public readonly int $port = 587,
        public readonly string $encryption = 'tls',
        public readonly bool $auth = true,
        public readonly string $username = '',
        public readonly string $password = '',
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $replyTo = null,
        public readonly bool $debug = false,
    ) {
    }

    /**
     * @param array<string, mixed> $credentials
     */
    public static function fromArray(array $credentials): self
    {
        $encryption = strtolower((string) ($credentials['encryption'] ?? $credentials['smtp_secure'] ?? 'tls'));
        if (in_array($encryption, ['none', 'off', 'false', '0', ''], true)) {
            $encryption = '';
        }

        $port = (int) ($credentials['port'] ?? ($encryption === 'ssl' ? 465 : 587));

        return new self(
            host: (string) ($credentials['host'] ?? ''),
            port: $port > 0 ? $port : ($encryption === 'ssl' ? 465 : 587),
            encryption: $encryption,
            auth: array_key_exists('auth', $credentials) ? (bool) $credentials['auth'] : true,
            username: (string) ($credentials['username'] ?? $credentials['user'] ?? ''),
            password: (string) ($credentials['password'] ?? ''),
            fromEmail: self::optionalString($credentials['from_email'] ?? null),
            fromName: self::optionalString($credentials['from_name'] ?? null),
            replyTo: self::optionalString($credentials['reply_to'] ?? null),
            debug: (bool) ($credentials['debug'] ?? false),
        );
    }

    public function validate(): void
    {
        if ($this->host === '') {
            throw new \RuntimeException('PHPMailer SMTP host is not configured');
        }

        if ($this->auth && ($this->username === '' || $this->password === '')) {
            throw new \RuntimeException('PHPMailer SMTP credentials are not configured');
        }
    }

    /**
     * @return array<int, string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->password, $this->username], static fn (string $value): bool => $value !== ''));
    }

    private static function optionalString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}

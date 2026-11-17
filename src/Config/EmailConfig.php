<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Config;

class EmailConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function tablePrefix(): string
    {
        return (string) ($this->config['table_prefix'] ?? 'email_');
    }

    public function encryptionKey(): string
    {
        $key = $this->config['encryption_key'] ?? '';

        if ($key === '') {
            throw new \RuntimeException('Email encryption key is not configured');
        }

        return (string) $key;
    }

    public function defaultSender(): ?string
    {
        $sender = $this->config['default_sender'] ?? null;

        return $sender === null ? null : (string) $sender;
    }

    public function defaultProvider(): ?string
    {
        $provider = $this->config['default_provider'] ?? null;

        return $provider === null ? null : (string) $provider;
    }

    public function escapeVariables(): bool
    {
        return (bool) ($this->config['escape_variables'] ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public function globals(): array
    {
        $globals = $this->config['globals'] ?? [];

        return is_array($globals) ? $globals : [];
    }

    public function webhookFreshnessSeconds(): int
    {
        return (int) ($this->config['webhook_freshness_seconds'] ?? 1800);
    }

    public function webhookSigningKey(string $provider): ?string
    {
        $keys = $this->config['webhook_signing_keys'] ?? [];
        if (! is_array($keys)) {
            return null;
        }

        $key = $keys[$provider] ?? null;

        return $key === null || $key === '' ? null : (string) $key;
    }

    public function mailgunApiKey(): ?string
    {
        $key = $this->config['mailgun_api_key'] ?? null;

        return $key === null || $key === '' ? null : (string) $key;
    }

    public function retryAttempts(): int
    {
        return (int) ($this->config['retry_attempts'] ?? 5);
    }

    /**
     * @return array<int, int>
     */
    public function retryDelays(): array
    {
        $delays = $this->config['retry_delays'] ?? [1, 2, 4, 8, 16];

        return array_values(array_map('intval', is_array($delays) ? $delays : [1, 2, 4, 8, 16]));
    }

    public function idempotencyKeyTtlHours(): int
    {
        return (int) ($this->config['idempotency_key_ttl_hours'] ?? 24);
    }

    public function workerLockFile(): string
    {
        return (string) ($this->config['worker_lock_file'] ?? sys_get_temp_dir() . '/email-worker.lock');
    }

    public function workerRunSeconds(): int
    {
        return (int) ($this->config['worker_run_seconds'] ?? 55);
    }

    public function workerBatchSize(): int
    {
        return (int) ($this->config['worker_batch_size'] ?? 100);
    }

    public function workerSleepSeconds(): int
    {
        return (int) ($this->config['worker_sleep_seconds'] ?? 5);
    }

    public function templatesPath(): string
    {
        return (string) ($this->config['templates_path'] ?? dirname(__DIR__, 2) . '/resources/templates');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->config;
    }
}

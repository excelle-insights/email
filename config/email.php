<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$envPath = is_file(getcwd() . '/.env') ? getcwd() : __DIR__ . '/..';
Dotenv::createImmutable($envPath)->safeLoad();

return [
    'table_prefix' => $_ENV['EMAIL_TABLE_PREFIX'] ?? 'email_',
    'encryption_key' => $_ENV['EMAIL_ENCRYPTION_KEY'] ?? '',
    'default_sender' => $_ENV['EMAIL_DEFAULT_SENDER'] ?? null,
    'default_provider' => $_ENV['EMAIL_PROVIDER'] ?? null,
    'smtp_host' => $_ENV['EMAIL_HOST'] ?? '',
    'smtp_port' => (int) ($_ENV['EMAIL_PORT'] ?? (! empty($_ENV['EMAIL_SECURE']) ? 465 : 587)),
    'smtp_encryption' => $_ENV['EMAIL_ENCRYPTION'] ?? (! empty($_ENV['EMAIL_SECURE']) ? 'ssl' : 'tls'),
    'smtp_user' => $_ENV['EMAIL'] ?? '',
    'smtp_password' => $_ENV['EMAIL_PASSWORD'] ?? '',
    'mailgun_api_key' => $_ENV['MAILGUN_API_KEY'] ?? '',
    'mailgun_domain' => $_ENV['MAILGUN_DOMAIN'] ?? '',
    'mandrill_api_key' => $_ENV['MANDRILL_API_KEY'] ?? '',
    'escape_variables' => true,
    'globals' => [
        'site_title' => $_ENV['SITETITLE'] ?? 'Excelle',
        'site_url' => $_ENV['SITE_URL'] ?? '',
        'support_email' => $_ENV['SUPPORT_EMAIL'] ?? $_ENV['EMAIL'] ?? '',
        'support_phone' => $_ENV['SUPPORT_PHONE'] ?? '',
        'year' => date('Y'),
    ],
    'webhook_freshness_seconds' => 1800,
    'webhook_signing_keys' => [
        'mailgun' => $_ENV['MAILGUN_WEBHOOK_SIGNING_KEY'] ?? null,
        'mandrill' => $_ENV['MANDRILL_WEBHOOK_KEY'] ?? null,
    ],
    'retry_attempts' => 5,
    'retry_delays' => [1, 2, 4, 8, 16],
    'idempotency_key_ttl_hours' => 24,
    'db_dsn' => $_ENV['DB_DSN'] ?? sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306',
        $_ENV['DB_NAME'] ?? 'excelle',
    ),
    'db_user' => $_ENV['DB_USER'] ?? 'root',
    'db_pass' => $_ENV['DB_PASSWORD'] ?? '',
    'worker_lock_file' => __DIR__ . '/../storage/email-worker.lock',
    'worker_run_seconds' => 55,
    'worker_batch_size' => 100,
    'worker_sleep_seconds' => 5,
];

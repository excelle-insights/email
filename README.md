# Excelle Insights Email

Multi-provider email package for Excelle applications.

**Status:** v1.0.0 release preparation complete. Remote publication to GitHub and Packagist is pending. See [docs/milestones.md](docs/milestones.md) for progress and [docs/prd.md](docs/prd.md) for the full specification.

## Features

- One provider-agnostic API for transactional, bulk and campaign email.
- Database-backed default provider and From identity, per tenant or global.
- Encrypted provider credentials at rest.
- Local-first persistence with a retry queue, idempotency and a status audit trail.
- Versioned HTML templates with bundled defaults and a Mustache-style renderer.
- Per-tenant webhook events, suppression list and campaign analytics.
- Framework-agnostic; bring your own PDO, HTTP client and logger.

## Providers (v1)

| Provider | Key | Send | CC | Attachments | Events |
| --- | --- | --- | --- | --- | --- |
| PHPMailer SMTP | `phpmailer` | Yes | Yes | Yes | No |
| Mailgun | `mailgun` | Yes | Yes | Yes | Yes |
| Mandrill (Mailchimp transactional) | `mandrill` | Yes | Yes | Yes | Yes |
| Log | `log` | No-op | — | — | — |

## Requirements

- PHP >= 8.1
- PDO MySQL
- OpenSSL extension

## Installation

```bash
composer require excelle-insights/email
```

Run the migrations:

```bash
php vendor/excelle-insights/email/migrate.php
```

See [docs/usage.md](docs/usage.md) for bootstrap, senders, templates, campaigns,
webhooks and analytics.

## API summary

```php
use ExcelleInsights\Email\Facade\EmailManager;

$email = new EmailManager($config, $pdo, $httpClient, $logger);
$email->registerBuiltInProviders();

$email->setSenderCredentials('mailgun', null, [
    'api_key' => 'key-example',
    'domain' => 'mg.example.com',
], 'noreply@example.com', 'Example');

$email->send('user@example.com', 'Your OTP', '<p>4821</p>', ['tenant_id' => 7]);
$email->sendNow('user@example.com', 'Reset password', $html, ['template' => 'password-reset']);
```

`send()` persists and queues; `sendNow()` dispatches synchronously for OTP and password-reset mail.
Call `registerBuiltInProviders()` before `sendNow()`, starting the worker or processing webhooks, or register individual adapters with `registerProvider()`.

Credentials are stored per sender provider in `email_senders`. Use `api_key`/`domain` for Mailgun, `api_key` for Mandrill, and `host`/`port`/`encryption`/`username`/`password` for PHPMailer. Legacy `.env` SMTP and Mailgun values are used only as bootstrap fallbacks.

## Campaigns and webhooks

```php
$campaign = $email->campaigns()->create(new Campaign(
    name: 'November announcement',
    provider: 'mailgun',
    subject: 'What is new',
));

$email->campaigns()->queue($campaign->id, $recipients, [
    'template' => 'welcome',
    'scheduled_at' => '2026-11-20 09:00:00',
]);

$analytics = $email->campaigns()->analytics($campaign->id);

$verified = $email->webhookController()->verify('mailgun', $requestData, $rawBody);
$result = $email->webhookController()->process('mailgun', $requestData, $rawBody);
```

Recipients come from the host app; the package does not parse CSV files or own
contact groups. Mailgun and Mandrill webhooks are signature verified, checked
against a freshness window, deduplicated and stored in `email_events` and
`email_webhook_logs`. See [docs/usage.md](docs/usage.md) for complete examples and
[docs/adoption.md](docs/adoption.md) for configuration and legacy migration
guidance.

## Documentation

- [docs/usage.md](docs/usage.md) - application usage guide
- [docs/adoption.md](docs/adoption.md) - adopting existing `email_*` tables
- [docs/prd.md](docs/prd.md) - behavioural specification
- [docs/milestones.md](docs/milestones.md) - delivery checklist

## Running checks

```bash
composer install
composer test
composer analyse
composer style
```

## License

MIT

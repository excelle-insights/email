# PRD: Excelle Insights Email – Multi-provider email package

*Draft v1.0 · Package: `excelle-insights/email` · Status: agreed for implementation*

Progress is tracked in [docs/milestones.md](milestones.md).

## 1. Summary

A PHP package that gives Excelle applications one consistent API for transactional, bulk and campaign email. Developers write `$email->send(...)` once; the package routes the message through the default sender stored in the database (globally or per tenant), or an explicitly chosen one. v1 covers single sends, synchronous sends for OTP-style mail, bulk sends, scheduling, versioned templates, delivery/engagement events and suppression handling.

It follows the conventions of `excelle-insights/sms`: facade manager, `.env` bootstrap configuration, Phinx migrations, prefixed tables, shared `http_request_logs`, injectable HTTP client/PDO/logger, and a local-first queue. Provider payload handling and templating behaviour are ported from `agentpro-api`'s `EmailService`, `EmailTemplateRenderer`, `MailgunProvider` and `PHPMailerProvider`.

## 2. Problem and goals

**Problem.** Each email provider has its own auth, payload shape, attachment handling, tracking model and webhook format. Applications that integrate directly get locked in, duplicate code across projects, and cannot switch provider without a rewrite. Existing `email_*` tables in Excelle apps have different shapes, so a new package must adopt them rather than replace them.

**Goals**

1. One provider-agnostic API for send, synchronous send, bulk, schedule, status and events.
2. Providers are plug-in adapters behind a single contract; adding one never touches core code.
3. Default provider and From identity are runtime, database-backed settings, changeable without redeploying and overridable per tenant.
4. Normalised lifecycle and delivery/engagement state regardless of provider.
5. Reliable by default: local persistence, retry queue, idempotency, full request logging.
6. Additive, non-destructive migration for existing `email_*` tables.
7. Consistent with the existing Excelle package structure and tooling.

**Non-goals (v1)**

- Mailchimp Marketing/audience APIs (Mandrill is the supported Mailchimp product).
- Automatic failover, cost-based routing and country-based routing.
- Contact groups, CSV ingestion and UI/admin screens (host apps own recipients).
- Sending-domain, DKIM/SPF/DMARC management and provider balance checks.

## 3. Users and use cases

| Persona | Need |
| --- | --- |
| Application developer | Send OTPs, receipts and notifications with one call, without knowing the active provider. |
| Platform admin | Switch the default provider or From identity per tenant from a settings screen or script. |
| Operations | Run announcement campaigns, schedule reminders, inspect delivery and engagement outcomes. |
| Package maintainer | Add a provider by implementing one interface and passing a shared contract test suite. |

Typical flows: transactional (OTP, password reset, invoice), bulk (announcements, statements), scheduled (due-date reminders) and webhook-driven engagement tracking (opens, clicks, bounces).

## 4. Supported providers (v1)

| Provider | Key | Send | CC/BCC | Attachments | Native templates | Events | Credentials |
| --- | --- | --- | --- | --- | --- | --- | --- |
| PHPMailer SMTP | `phpmailer` | Yes | Yes | Yes | No | No | host, port, encryption, user, password |
| Mailgun | `mailgun` | Yes | Yes | Yes | No | opens, clicks, bounces, complaints, deliveries | API key, domain, webhook signing key |
| Mandrill | `mandrill` | Yes | Yes | Yes | Yes (opt-in) | sends, opens, clicks, bounces, complaints, unsubscribes | API key, webhook key |
| Log | `log` | No-op | — | — | — | — | none |

All sending uses package-rendered HTML by default so changing provider never changes the output. Mandrill native templates are an opt-in capability, not the default path.

## 5. Functional requirements

### 5.1 Provider abstraction

- `EmailProviderInterface` with `getKey()`, `getDisplayName()`, `capabilities()`, `send(SendRequest): SendResult`, `parseWebhook(headers, payload): ?EmailEvent`, and optional capability interfaces for attachments, CC and native templates.
- Capability flags let core services decide whether to delegate or emulate; core never assumes a provider supports a feature.
- `ProviderRegistry` registers providers by key (`phpmailer`, `mailgun`, `mandrill`, `log`). Third parties can register custom providers.
- Every adapter passes a shared contract suite using recorded HTTP fixtures.
- `log` provider writes to the PSR-3 logger and never sends.

### 5.2 Sender resolution and credentials

- An `email_senders` row is a provider configuration **and** a From identity: tenant, provider key, encrypted credentials, `from_email`, `from_name`, `reply_to`, `enabled`, `is_default`.
- Resolution order for every send: explicit provider/sender → tenant default → global default (`tenant_id = NULL`) → `.env` bootstrap (`EMAIL_DEFAULT_SENDER`, `EMAIL_PROVIDER`, legacy SMTP/Mailgun variables) → `NoDefaultSenderException`.
- Credentials use the same tenant/global fallback and are encrypted at rest with OpenSSL using `EMAIL_ENCRYPTION_KEY`.
- Exactly one default per tenant, enforced by a generated column plus unique index, as in the SMS package.
- Secrets are never logged or returned in plaintext; `testConnection($sender)` validates credentials where the provider supports it.
- Changing the default affects new sends only. Queued rows freeze the provider and From identity at creation.

### 5.3 Sending

- `send(to, subject, html, options)` persists a queue row and returns `SendResult(status, local_id)`; a worker dispatches it.
- `sendNow(...)` persists the same row and dispatches synchronously for OTP, password reset and other latency-sensitive mail.
- `sendBulk(recipients, ...)` creates a campaign/batch with one queue row per recipient.
- Options include provider/sender, from override, recipient name, CC, reply-to, plain-text alternative, attachments (paths or streams), template and variables, campaign id, metadata, idempotency key, scheduled time, timezone, `pending` hold, priority and origin.
- Address validation, suppression check, idempotency check (24h TTL) and rendering happen at creation. Body, subject and recipient are immutable once created.
- Invalid recipients in bulk sends are skipped and reported in the result; they never fail the whole batch.

### 5.4 Status model

Every queue row carries two independent fields:

- **Lifecycle `status`:** `pending`, `scheduled`, `queued`, `processing`, `sent`, `failed`, `rejected`, `cancelled`.
- **Delivery `delivery_status`:** `none`, `deferred`, `delivered`, `bounced`, `complained`, `suppressed`, `unknown`.

Opens and clicks are engagement events stored in `email_events`; they are not delivery statuses. Webhooks never change lifecycle status; delivery status only moves forward. Every lifecycle change writes a row to `email_status_changes` with actor, reason and source.

### 5.5 Approval workflow

Messages are queued by default (or scheduled when `scheduled_at` is set). Only calls with `pending: true` create inert rows awaiting approval. `EmailManager::status()` exposes guarded transitions for a single row, a list of ids or a whole campaign, with `StatusContext` (actor, reason, `scheduled_at`, `expected_from`, `atomic`, `dry_run`). Application code cannot set `processing` or `sent`; those belong to the worker. Transition validation is centralised in `StatusTransitionPolicy`, row locking prevents worker/approval races, and idempotent transitions return `unchanged`.

### 5.6 Worker and retry

- A CLI worker handles due scheduled rows and transient retry backoff: 1, 2, 4, 8, 16 minutes, up to 5 attempts.
- Permanent errors (invalid address, suppression, authentication) fail immediately without retry.
- Worker lock file, run window and batch size mirror the SMS worker. At-least-once dispatch is protected by idempotency keys.

### 5.7 Templates

- `email_templates` holds identity, subject, category (`marketing`/`transactional`), status (`draft`/`active`/`archived`), `variables_schema`, `design_json` and `current_version`.
- `email_template_versions` stores immutable subject, HTML, variable definitions, author and timestamp. Editing a template creates a new version.
- Bundled HTML files ship with the package: `base`, `welcome`, `password-reset` and `components/button`.
- `TemplateRenderer` ports the agentpro renderer: `{{var}}`, `{{#var}}…{{/var}}`, `{{^var}}…{{/var}}`, global variables (`site_title`, `site_url`, `support_email`, `support_phone`, `year`) and multi-pass replacement so nested placeholders resolve. HTML escaping is on by default with an explicit raw-value syntax. Subject lines are rendered with the same variables.
- Tenant templates win over global templates; bundled files are a fallback. Only active templates can be sent.
- Rendered subject, HTML and variables are stored on the queue row at creation, so later template edits never change queued or sent mail.
- `templates()->preview($key, $vars)` returns the rendered subject, HTML and detected variables without sending.

### 5.8 Campaigns

- Host apps create a campaign and call `campaigns()->queue($campaignId, $recipients, $options)` or pass `campaign_id` on a bulk send. The package does not resolve contact groups or parse CSV.
- Campaigns hold status, send date, provider/sender, counters (total, queued, sent, delivered, failed, opened, clicked, bounced) and metadata. Counters increment atomically as events arrive.
- `cancel()` stops all pending rows in a campaign and marks the campaign cancelled. Scheduling and approval behave as for single sends.

### 5.9 Webhooks, events and compliance

- `WebhookController::verify()/process()` per provider. Mailgun verifies HMAC-SHA256 over `timestamp + token` with the webhook signing key (API key fallback) and enforces a 30-minute freshness window. Mandrill verifies its signed-POST scheme. PHPMailer and log providers have no webhooks.
- Events are deduplicated on `(message_id, event_type, recipient)` and normalised to `delivered`, `deferred`, `bounced`, `complained`, `opened`, `clicked`, `unsubscribed` and `unknown`. Raw payloads and headers are stored in `email_webhook_logs`.
- Bounces, complaints and unsubscribes add rows to `email_suppressions`. Marketing sends check suppression; transactional sends may bypass with `bypass_suppression: true`.
- Campaign counters are updated atomically on event receipt.

### 5.10 Logging and observability

- Every provider HTTP call is logged to the shared `http_request_logs` table with API keys, passwords and Authorization headers redacted.
- PSR-3 logger injected into the manager; provider exceptions never include secret values.

## 6. Architecture

```
src/
├── Contracts/      EmailProviderInterface, HttpClientInterface, MessageStatusServiceInterface,
│                   ApprovalAuthorizerInterface
├── Entities/       Message, Campaign, EmailTemplate, EmailEvent, SendRequest, SendResult,
│                   RenderedMessage, StatusContext, StatusResult, ProviderCapabilities, enums
├── Providers/      Phpmailer/, Mailgun/, Mandrill/, Log/, ProviderRegistry
├── Facade/         EmailManager
├── Services/       SendService, CampaignService, ProviderResolver, TemplateService,
│                   TemplateRenderer, WorkerService, WebhookService, EventService,
│                   SuppressionService, MessageStatusService, StatusTransitionPolicy
├── Repositories/   SenderRepository, QueueRepository, CampaignRepository, TemplateRepository,
│                   TemplateVersionRepository, EventRepository, SuppressionRepository,
│                   WebhookLogRepository, StatusChangeRepository
├── Validation/     EmailAddressValidator, TemplateValidator, MessageValidator
├── Config/         EmailConfig
├── Controller/     WebhookController
├── Support/        DefaultHttpClient, DatabaseLogger, OpenSSL crypto
└── Exceptions/
database/migrations/ · config/email.php · examples/ · docs/ · tests/ · .github/workflows/
```

Key design decisions: adapter pattern plus registry; `ProviderResolver` is the single source of default selection; capabilities over assumptions; local-first persistence for every message; dependency injection of PDO, HTTP client and logger via the manager constructor.

## 7. Data model (prefix `EMAIL_TABLE_PREFIX`, default `email_`)

The four named tables are the core; supporting tables follow the SMS pattern.

| Table | Purpose |
| --- | --- |
| `email_senders` | Provider config plus From identity: encrypted credentials, reply-to, enabled, default (one default per tenant) |
| `email_queue` | Local-first message rows, extended from the legacy table: tenant, provider, From, recipient, subject, HTML, text, template ids and variables, CC, reply-to, attachments, lifecycle and delivery status, attempts, idempotency key, campaign id, provider message id, metadata |
| `email_campaigns` | Campaign header, status, send date, counters and metadata |
| `email_templates` | Template identity per tenant or global: key, name, subject, category, status, variables schema, design JSON, current version |
| `email_template_versions` | Immutable subject, HTML, variable definitions, author and timestamp per version |
| `email_events` | Normalised webhook events with dedupe key and provider payload data |
| `email_suppressions` | Bounces, complaints and manual unsubscribes per tenant |
| `email_webhook_logs` | Raw webhook headers and payloads for audit and replay |
| `email_status_changes` | Audit trail of every lifecycle status change: actor, reason, source, timestamp |
| `http_request_logs` | Shared, non-prefixed HTTP call log |

All package tables carry nullable `tenant_id`. Adopt migrations copy legacy `workspace_id` into it and retain the old column for host-app joins. Migrations are additive and non-destructive; down migrations do not drop adopted tables or data.

## 8. Developer experience

```php
use ExcelleInsights\Email\Facade\EmailManager;

$email = new EmailManager($config, $pdo, $httpClient, $logger);

// Admin: choose the default sender (global, or per tenant)
$email->setDefaultSender('mailgun', tenantId: 7);

// Queue a transactional message; worker dispatches it
$r = $email->send('user@example.com', 'Your OTP is 4821', '<p>4821</p>', ['tenant_id' => 7]);

// Send immediately when the request must wait for the result
$email->sendNow('user@example.com', 'Reset password', $html, ['template' => 'password-reset', 'vars' => $vars]);

// One-off with a specific provider
$email->provider('phpmailer')->send($request);

// Campaign from host-supplied recipients
$email->campaigns()->queue(42, $recipients, [
    'template' => 'welcome',
    'scheduled_at' => '2026-11-20 09:00:00',
    'timezone' => 'Africa/Nairobi',
]);

$email->templates()->preview('welcome', ['first_name' => 'Wanjiru']);
$email->status()->approveBatch(42, new StatusContext(actor: $user->id));
$email->worker()->dispatchQueuedMessages(100);
```

`.env` holds only bootstrap values: `DB_*`, `EMAIL_TABLE_PREFIX`, `EMAIL_ENCRYPTION_KEY`, `EMAIL_DEFAULT_SENDER`, `EMAIL_PROVIDER`, webhook signing keys and legacy SMTP/Mailgun variables. Provider credentials live in the database.

## 9. Non-functional requirements

- PHP >= 8.1, PSR-4 autoloading, MySQL via PDO, Composer-installable from Packagist with tagged releases.
- Security: encrypted credentials, redacted logs, verified webhooks, no secrets in exceptions, HTML-escaped template variables by default.
- Performance: a 10,000-recipient campaign is accepted and persisted without blocking the request.
- Reliability: at-least-once dispatch with idempotency protecting against duplicates.
- Quality bar: MIT licence, PHPUnit with provider contract tests, PHPStan level 8, php-cs-fixer, GitHub Actions CI, `CHANGELOG.md`, semantic versioning.

## 10. Success metrics

- A new provider adapter can be added in under one day by following the contract and passing shared tests.
- Switching a tenant's default provider requires no code change or deploy.
- Zero duplicate sends from retried requests in testing.
- Existing Excelle databases can adopt the package without dropping or rewriting legacy email data.

## 11. Decisions confirmed

- Mailchimp means **Mandrill transactional**, not the Marketing/audience API.
- `email_senders` is a provider configuration plus From identity (SMS `provider_configs` role).
- `send()` queues; `sendNow()` dispatches synchronously.
- Migrations adopt existing tables in place, preserving legacy data.
- v1 includes events, suppression and raw webhook audit alongside the four core tables.
- Tenancy uses nullable `tenant_id`, mapped from legacy `workspace_id`.
- Lifecycle status and delivery status are separate fields with email-specific delivery statuses.
- Templates are versioned in the database and bundled as HTML files.
- Providers use the SMS-style rich contract with capabilities and shared contract tests.
- Recipients come from host apps; the package does not own contact groups or CSV parsing.
- Message fields have full agentpro parity: CC, reply-to, plain-text alternative, attachments, metadata, idempotency, scheduling, priority and origin.
- `phpmailer/phpmailer` is a hard `require` dependency.
- Template variables are HTML-escaped by default with an explicit raw-value syntax.
- Campaign counters increment incrementally rather than being recomputed on read.

## 12. Milestones

See [docs/milestones.md](milestones.md) for M0–M5 and their checklists.

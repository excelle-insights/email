# Milestone tracker: `excelle-insights/email`

Status: M5 local release work complete; stopped for review before remote publication · Last updated: 2026-11-17

Package: `excelle-insights/email` · Specification: [docs/prd.md](prd.md)
Architecture: mirrors `excelle-insights/sms`; provider and templating behaviour ported from `agentpro-api`.

Mark an item `- [x]` and add the completion date when it is done. Use `- [~]` with a note for work in progress. Update the progress table after each milestone.

## Progress

| Milestone | Scope | Status | Completed |
| --- | --- | --- | --- |
| M0 | PRD, scaffold, CI, licence | Complete | 2026-11-17 |
| M1 | Contracts, senders, migrations | Complete | 2026-11-17 |
| M2 | Send pipeline, templates, worker | Complete | 2026-11-17 |
| M3 | Provider adapters | Complete | 2026-11-17 |
| M4 | Campaigns, webhooks, compliance | Complete | 2026-11-17 |
| M5 | Release | Local complete; remote publication blocked | 2026-11-17 (local) |

## M0 — PRD, repo scaffold, CI, licence

- [x] Confirm the five PRD assumptions (2026-11-17; adopted as decisions in `docs/prd.md` §11)
- [x] Finalise and review `docs/prd.md` (2026-11-17)
- [ ] Create the `excelle-insights/email` GitHub repo (local scaffold ready; remote not created)
- [x] Add `composer.json` (PHP >= 8.1, PSR-4 `ExcelleInsights\Email\`, Phinx, PDO, PSR-3, phpdotenv, PHPMailer) (2026-11-17)
- [x] Add MIT `LICENSE`, `README.md`, `.gitignore`, `.env.example` (2026-11-17)
- [x] Add PHPUnit, PHPStan and php-cs-fixer configuration (2026-11-17)
- [x] Add `.github/workflows/ci.yml` (tests, static analysis, style) (2026-11-17)
- [x] Add `config/email.php` bootstrap config (2026-11-17)
- [x] Verify `composer validate --strict`, `composer analyse`, `composer style` and `composer test` pass (2026-11-17)

## M1 — Contracts, senders, migrations

- [x] `EmailProviderInterface` with key, name, capabilities, send, webhook parsing (2026-11-17)
- [x] `HttpClientInterface`, logger wiring and shared `http_request_logs` redaction (2026-11-17)
- [x] Entities: `SendRequest`, `SendResult`, `ProviderCapabilities`, `Message`, enums, `StatusContext`, `StatusResult` (2026-11-17)
- [x] `EmailConfig`, `ProviderRegistry`, `ProviderResolver` (explicit → tenant → global → env) (2026-11-17)
- [x] `SenderRepository` with encrypted credentials, From/reply-to identity, one default per tenant (2026-11-17)
- [x] `EmailManager` facade skeleton with sender/provider admin methods (2026-11-17)
- [x] Phinx migrations for `email_senders` plus additive adoption of legacy tables (2026-11-17)
- [x] `email_status_changes` and `email_webhook_logs` migration (2026-11-17)
- [x] Unit tests for resolver, config and crypto (2026-11-17)

## M2 — Send pipeline, templates, worker

- [x] `email_queue` migration (extended legacy columns, nullable `tenant_id`, campaign/message ids) (2026-11-17)
- [x] `send()`, `sendNow()`, `sendBulk()` with validation, suppression check and idempotency (2026-11-17)
- [x] Frozen rendered subject/HTML/variables on each queue row (2026-11-17)
- [x] Lifecycle status and separate delivery status with `StatusTransitionPolicy` (2026-11-17)
- [x] `MessageStatusService` with guarded single/list/campaign transitions and audit (2026-11-17)
- [x] `email_templates` + `email_template_versions` migrations (2026-11-17)
- [x] Bundled HTML templates (`base`, `welcome`, `password-reset`, `components/button`) (2026-11-17)
- [x] `TemplateRenderer` port: `{{var}}`, conditionals, globals, escaping, subject rendering, preview (2026-11-17)
- [x] Worker CLI with due scheduling, claim/processing, retries/backoff, lock and batch size (2026-11-17)
- [x] Examples: `send.php`, `sendNow.php`, `worker.php` (2026-11-17)
- [x] Unit and integration tests for pipeline, templates and worker (2026-11-17)

## M3 — Provider adapters

- [x] Log provider (no-op for local development and tests) (2026-11-17)
- [x] PHPMailer provider: SMTP auth, TLS/port config, CC, attachments, reply-to (2026-11-17)
- [x] Mailgun provider: HTTP v3, Basic auth, tracking flags, attachments, message id parsing (2026-11-17)
- [x] Mandrill provider: `/messages/send.json`, `/messages/send-template.json`, message id parsing (2026-11-17)
- [x] Per-provider webhook parsers and capability flags (2026-11-17)
- [x] Shared provider contract test suite with recorded HTTP fixtures (2026-11-17)
- [x] Adapters never log or expose secrets (2026-11-17)

## M4 — Campaigns, webhooks, compliance

- [x] `email_campaigns` migration, campaign service and counters (2026-11-17; additive migration landed in M2)
- [x] `campaigns()->queue($recipients)` and `cancel()`; host app owns contact groups/CSV (2026-11-17)
- [x] `WebhookController::verify()/process()` for Mailgun and Mandrill (2026-11-17)
- [x] HMAC verification, freshness window, dedupe and raw payload logging (2026-11-17)
- [x] `email_events` migration and event normalisation (opened, clicked, delivered, deferred, bounced, complained, unsubscribed) (2026-11-17)
- [x] `email_suppressions` migration, suppression service, marketing/transactional rules (2026-11-17; migration landed in M2)
- [x] Atomic campaign counter updates (2026-11-17)
- [x] Analytics helpers and campaign detail queries (2026-11-17)
- [x] Adoption guide: mapping legacy `workspace_id`, status aliases, existing columns (2026-11-17)

## M5 — Release

- [x] `docs/usage.md` and `docs/adoption.md` (2026-11-17)
- [x] Run `composer validate --strict`, static analysis, style and tests (2026-11-17)
- [x] Verify clean MySQL migrations and the legacy in-place adoption path (2026-11-17)
- [x] Verify a distribution-style archive installs into an empty Composer project (2026-11-17)
- [x] Fix MySQL detection of a pre-existing shared `http_request_logs` table (2026-11-17)
- [ ] Verify install from the Packagist tarball (blocked: package not submitted or tagged)
- [ ] Tag `v1.0.0` and submit to Packagist (blocked: remote repository and credentials)
- [ ] Announce to host apps and plan migration of `agentpro-api` (blocked: publication)

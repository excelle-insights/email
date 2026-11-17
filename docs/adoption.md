# Adopting `excelle-insights/email` in an existing Excelle app

The package is designed for **in-place adoption**. It adds columns and indexes to
existing `email_*` tables, keeps legacy columns, and never drops adopted tables
in a down migration. Take a database backup before running migrations.

## 1. Run the migrations

```bash
php vendor/excelle-insights/email/migrate.php
```

Set `DB_DSN`, `DB_USER`, `DB_PASSWORD` and optionally `EMAIL_TABLE_PREFIX` in the
host app's `.env`. The default prefix is `email_`.

The M4 event migration adds `email_events`. It creates the table when missing or
adds `tenant_id`, `provider`, `message_id`, `queue_id`, `campaign_id`,
`recipient_email`, `event_type`, `dedupe_key`, `event_data`, `ip_address`,
`user_agent` and `event_timestamp` to an existing event table.

## 2. Legacy `workspace_id` mapping

All package tables use nullable `tenant_id`. Where an existing table still has
`workspace_id`, migrations copy it into `tenant_id`:

```sql
UPDATE email_queue
SET tenant_id = workspace_id
WHERE tenant_id IS NULL AND workspace_id IS NOT NULL;
```

The legacy `workspace_id` column is retained for existing host-app joins. New
package code reads and writes `tenant_id` only. Global rows keep
`tenant_id = NULL`, which also represents tenant fallback for suppression and
sender credentials.

## 3. Status aliases

Lifecycle `status` and delivery `delivery_status` are separate fields. Existing
rows are read as follows:

| Legacy value | Package meaning |
| --- | --- |
| `draft`, `pending`, `awaiting_approval` | lifecycle `pending` |
| `scheduled` | lifecycle `scheduled` |
| `queued`, `new` | lifecycle `queued` |
| `processing`, `sending` | lifecycle `processing` |
| `sent`, `success` | lifecycle `sent` |
| `failed`, `error` | lifecycle `failed` |
| `rejected`, `spam` | lifecycle `rejected` |
| `cancelled`, `canceled` | lifecycle `cancelled` |

Delivery values are normalised from provider webhooks:

| Provider value | `delivery_status` |
| --- | --- |
| `accepted`, `send`, `sent`, `delivered` | `delivered` |
| `deferred`, `defer` | `deferred` |
| `bounce`, `bounced`, `hard_bounce`, `soft_bounce`, `reject` | `bounced` |
| `complaint`, `complained`, `spam` | `complained` |
| `unsubscribe`, `unsubscribed` | `suppressed` |
| anything else | `unknown` |

Opens and clicks never change lifecycle or delivery status; they are stored in
`email_events` and counted on campaigns.

## 4. Existing columns that are reused

| Legacy column | Package use |
| --- | --- |
| `email_queue.message_id` | Provider message id fallback; new rows use `provider_message_id` and mirror it here |
| `email_queue.content_sections` | Rendered HTML body |
| `email_queue.variables` | Legacy variable JSON fallback; new rows use `template_variables` |
| `email_queue.send_date` | Scheduled send time |
| `email_queue.status` | Lifecycle status |
| `email_campaigns.status` | `draft`, `queued`, `sending`, `sent` or `cancelled` |
| `email_suppressions.type` | `bounce`, `complaint`, `unsubscribe` or `manual` |

Additive columns are nullable or given safe defaults so existing rows stay
valid. The `down()` migrations for adopted tables are intentionally empty.

## 5. Providers and From identity

An `email_senders` row combines provider credentials with the From identity:

```php
$email->setSenderCredentials('mailgun', 7, [
    'api_key' => 'key-...',
    'domain' => 'mg.example.com',
], 'noreply@example.com', 'Example', 'support@example.com');

$email->setDefaultSender('mailgun', 7);
```

Resolution order is explicit provider/sender, tenant default, global default,
`.env` bootstrap values, then `NoDefaultSenderException`. Queued rows freeze the
provider and From identity at creation.

## 6. Campaigns

The package does not own contact groups or CSV parsing. Create a campaign, then
queue host-supplied recipients:

```php
$campaign = $email->campaigns()->create(new Campaign(
    name: 'November announcement',
    provider: 'mailgun',
    subject: 'What is new',
));

$email->campaigns()->queue($campaign->id, $recipients, [
    'html' => '<p>Hello {{first_name}}</p>',
    'template' => 'welcome',
    'scheduled_at' => '2026-11-20 09:00:00',
]);

$email->campaigns()->cancel($campaign->id);
$analytics = $email->campaigns()->analytics($campaign->id);
$details = $email->campaigns()->details($campaign->id);
```

Counters (`total`, `queued`, `sent`, `delivered`, `failed`, `opened`, `clicked`,
`bounced`) are incremented with atomic SQL updates. Event handlers deduplicate
on message id, normalised event type and recipient before applying counters.

## 7. Webhooks

Register the bundled providers and configure webhook keys before exposing an
endpoint. Always pass the raw request body and the original headers.

```php
$email->registerBuiltInProviders();

if ($email->webhookController()->verify('mailgun', $requestData, $rawBody)) {
    $result = $email->webhookController()->process('mailgun', $requestData, $rawBody);
}
```

Mailgun uses HMAC-SHA256 over `timestamp + token` with
`MAILGUN_WEBHOOK_SIGNING_KEY`, falling back to `MAILGUN_API_KEY`. Mandrill uses
its signed-POST scheme with `MANDRILL_WEBHOOK_KEY`; pass the exact webhook URL
as `url` (or `X-Mandrill-URL`) and the decoded POST parameters as `post` in
`$requestData`.

Both providers enforce `webhook_freshness_seconds` (default 1800). Raw headers
and payloads are written to `email_webhook_logs`; sensitive headers are masked.
Bounced, complained and unsubscribed recipients are added to
`email_suppressions`. Marketing sends check that list; transactional sends skip
it, and any send can opt in explicitly with `bypass_suppression: true`.

## 8. Upgrade checklist

- [ ] Back up the database.
- [ ] Set `EMAIL_ENCRYPTION_KEY` and keep it stable across deploys.
- [ ] Set webhook signing keys and register the endpoint with each provider.
- [ ] Run the migrations and confirm `email_events` exists.
- [ ] Spot-check a legacy row: `tenant_id` matches `workspace_id`, HTML is in
      `content_sections`, and `delivery_status` has a valid value.
- [ ] Queue a small campaign and process a test webhook before a full send.

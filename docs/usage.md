# Using `excelle-insights/email`

This guide covers normal application use of the package. For existing Excelle
databases, read [docs/adoption.md](adoption.md) before running migrations. The
full behavioural specification is in [docs/prd.md](prd.md).

## 1. Bootstrap

Install the package and run its migrations from the host application:

```bash
composer require excelle-insights/email
php vendor/excelle-insights/email/migrate.php
```

Set `DB_DSN`, `DB_USER`, `DB_PASSWORD`, `EMAIL_TABLE_PREFIX` and, importantly,
`EMAIL_ENCRYPTION_KEY` in the host app's `.env`. Keep the encryption key stable
across deploys because sender credentials are encrypted at rest.

```php
require __DIR__ . '/vendor/autoload.php';

use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Support\DatabaseLogger;
use ExcelleInsights\Email\Support\DefaultHttpClient;

$config = require __DIR__ . '/vendor/excelle-insights/email/config/email.php';

$pdo = new PDO(
    $config['db_dsn'],
    $config['db_user'],
    $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$email = new EmailManager(
    $config,
    $pdo,
    new DefaultHttpClient(),
    new DatabaseLogger($pdo, $config['table_prefix']),
);

$email->registerBuiltInProviders();
```

`registerBuiltInProviders()` registers PHPMailer SMTP, Mailgun, Mandrill and the
log adapter. It is required before `sendNow()`, worker dispatch and webhook
processing. Call it once during application bootstrap. A custom adapter can be
registered with `$email->registerProvider($provider)` instead.

The constructor also accepts an optional `ApprovalAuthorizerInterface`. The
package always writes status changes; the authorizer can deny application-level
approvals and rejections.

## 2. Configure a sender

An `email_senders` row holds both provider credentials and the From identity.
Rows are keyed by provider and nullable `tenant_id`. A `NULL` tenant is a global
fallback.

```php
$email->setSenderCredentials(
    'mailgun',
    null,
    [
        'api_key' => 'key-example',
        'domain' => 'mg.example.com',
        'region' => 'us',
    ],
    'noreply@example.com',
    'Example',
    'support@example.com',
    'Primary Mailgun',
);

$email->setDefaultSender('mailgun', null);
```

Credential keys by provider:

| Provider | Required credentials | Optional credentials |
| --- | --- | --- |
| `phpmailer` | `host`, `port`, `encryption`, `username`, `password` | `auth`, `debug` |
| `mailgun` | `api_key`, `domain` | `region`, `base_url`, `tracking`, `open_tracking`, `click_tracking` |
| `mandrill` | `api_key` | `base_url`, `native_templates`, `track_opens`, `track_clicks`, `auto_text` |

Provider resolution order is explicit `provider` option, tenant default, global
default, `.env` bootstrap values, then `NoDefaultSenderException`. Use
`$email->resolveProvider($provider, $tenantId)` to inspect the result.

The legacy `.env` SMTP and Mailgun values are bootstrap fallbacks only. They are
not a replacement for database-backed sender rows in production.

## 3. Send messages

`send()` validates, renders, persists and queues a message. It never contacts a
provider, so it is safe in request handlers.

```php
$result = $email->send(
    'user@example.com',
    'Welcome to Excelle',
    '<p>Hello {{first_name}}, welcome aboard.</p>',
    [
        'vars' => ['first_name' => 'Wanjiru'],
        'category' => 'transactional',
        'idempotency_key' => 'welcome-1001',
    ],
);

$result->status->value;      // queued, scheduled or pending
$result->localId;            // email_queue.id
$result->providerMessageId;  // null until the provider responds
```

`sendNow()` uses the same persistence path and then dispatches immediately. Use
it for OTP, password reset and other latency-sensitive transactional mail.

```php
$result = $email->sendNow(
    'user@example.com',
    'Reset your password',
    '<p>Use this code: {{reset_code}}</p>',
    [
        'template' => 'password-reset',
        'vars' => ['reset_code' => '482913'],
        'category' => 'transactional',
    ],
);
```

`sendBulk()` queues many recipients and creates or reuses a campaign. It returns
per-recipient failures rather than aborting the whole batch.

```php
$result = $email->sendBulk(
    [
        ['to' => 'one@example.com', 'vars' => ['first_name' => 'One']],
        ['to' => 'two@example.com', 'vars' => ['first_name' => 'Two']],
    ],
    'Hello {{first_name}}',
    '<p>Hello {{first_name}}</p>',
    ['campaign_name' => 'November announcement'],
);

$result->campaignId;
$result->queued;
$result->failed;
```

Common send options:

| Option | Meaning |
| --- | --- |
| `tenant_id` | Tenant that owns the message and sender lookup |
| `provider` | Explicit provider key, overriding tenant and global defaults |
| `template` | Database or bundled template key |
| `vars` or `template_vars` | Template variables, HTML escaped by default |
| `text` | Plain-text alternative; it is rendered with the same variables |
| `recipient_name`, `from_email`, `from_name`, `reply_to` | Override sender identity for one message |
| `cc`, `bcc`, `attachments` | Arrays of strings passed to the provider |
| `idempotency_key` | Reuse an existing queued message within the configured TTL |
| `scheduled_at`, `timezone` | Schedule delivery; worker releases due messages |
| `category` | `marketing` by default; `transactional` skips suppression |
| `bypass_suppression` | Explicitly ignore the suppression list |
| `metadata` | Arbitrary application metadata stored with the message |
| `origin`, `priority` | Audit origin and queue priority |
| `pending` | Create the message in `pending` for approval instead of `queued` |

Marketing messages check `email_suppressions`. Transactional messages and any
send with `bypass_suppression => true` skip that check.

Mandrill native templates are disabled by default. Enable them for a sender with
`native_templates => true`, or set `options['native_template'] => true` on a
direct `SendRequest` when constructing a provider call yourself. Database and
bundled templates remain the default templating path.

## 4. Templates

Database-backed templates are versioned. Updating a template writes a new
`email_template_versions` row and bumps `current_version`.

```php
$template = $email->templates()->create([
    'key' => 'welcome-v2',
    'name' => 'Welcome v2',
    'subject' => 'Welcome, {{first_name}}',
    'html' => '<h1>Hello {{first_name}}</h1>{{#show_offer}}<p>{{offer}}</p>{{/show_offer}}',
    'category' => 'marketing',
    'tenant_id' => null,
    'variables' => ['first_name' => '', 'show_offer' => false],
    'author' => 'admin@example.com',
]);

$email->templates()->update($template->id, [
    'html' => '<h1>Hello {{first_name}}</h1>',
    'author' => 'admin@example.com',
]);

$preview = $email->templates()->preview('welcome-v2', ['first_name' => 'Wanjiru']);
```

Bundled templates live in `resources/templates` and are used when no database
template matches the key. Bundled keys include `base`, `welcome`,
`password-reset` and `components/button`. Set `templates_path` in config to use
your own directory.

The renderer supports Mustache-style placeholders:

| Syntax | Meaning |
| --- | --- |
| `{{name}}` | HTML-escaped value |
| `{{name\|raw}}` | Unescaped value |
| `{{{name}}}` | Unescaped value |
| `{{#flag}}...{{/flag}}` | Render block when `flag` is truthy |
| `{{^flag}}...{{/flag}}` | Render block when `flag` is falsy |

Global values from config (`site_title`, `site_url`, `support_email`,
`support_phone`, `year`) are available to every template. Rendered subject,
HTML, text and variables are frozen on the queue row at creation time.

## 5. Campaigns

The package does not own contact groups or CSV parsing. The host application
supplies recipients to `campaigns()->queue()`.

```php
use ExcelleInsights\Email\Entities\Campaign;

$campaign = $email->campaigns()->create(new Campaign(
    name: 'November announcement',
    provider: 'mailgun',
    subject: 'What is new',
));

$batch = $email->campaigns()->queue($campaign->id, $recipients, [
    'html' => '<p>Hello {{first_name}}</p>',
    'template' => 'welcome-v2',
    'scheduled_at' => '2026-11-20 09:00:00',
    'metadata' => ['source' => 'host-app'],
]);

$email->campaigns()->cancel($campaign->id);
```

`queue()` also accepts an array of recipients as its first argument to create a
campaign in one call. Counters (`total`, `queued`, `sent`, `delivered`, `failed`,
`opened`, `clicked`, `bounced`) are incremented atomically as messages and
events change.

```php
$analytics = $email->campaigns()->analytics($campaign->id);

$analytics->deliveryRate; // percentage of all recipients
$analytics->openRate;     // percentage of delivered recipients, falling back to total
$analytics->clickRate;    // percentage of delivered recipients, falling back to total
$analytics->eventsByType; // counts keyed by normalised event type
$analytics->messagesByStatus;

$details = $email->campaigns()->details($campaign->id, 50);
// ['campaign' => ..., 'analytics' => ..., 'messages' => ..., 'events' => ...]
```

## 6. Status, worker and retries

Lifecycle `status` and provider `delivery_status` are separate fields. Lifecycle
values include `pending`, `scheduled`, `queued`, `processing`, `sent`, `failed`,
`rejected` and `cancelled`. Opens and clicks never change those statuses; they
are recorded as events.

The bundled worker example releases due scheduled messages, retries eligible
failures with backoff, then dispatches queued messages:

```bash
php vendor/excelle-insights/email/examples/worker.php
```

Inside a long-running host worker, call the service directly:

```php
$dispatched = $email->worker()->processBatch(100);
```

Application-level transitions are guarded by `StatusTransitionPolicy` and
audited in `email_status_changes`:

```php
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\StatusContext;

$email->status()->approve($messageId, new StatusContext(actor: 'admin@example.com'));
$email->status()->cancel($messageId, new StatusContext(actor: 'admin@example.com'));
$allowed = $email->status()->allowedTransitions($messageId);
```

## 7. Webhooks

Register the endpoint with Mailgun or Mandrill and pass the raw request body and
original request data to the controller. Never re-encode the body before
verification.

```php
$email->registerBuiltInProviders();

if (! $email->webhookController()->verify('mailgun', $requestData, $rawBody)) {
    http_response_code(400);
    return;
}

$result = $email->webhookController()->process('mailgun', $requestData, $rawBody);

$result->status;    // received, duplicate or ignored
$result->eventType; // normalised event type
$result->eventId;
```

`$requestData` may contain `headers`, `query`, `tenant_id` and, for Mandrill,
`url` and decoded `post` parameters. Header names are case-insensitive. Raw
headers and payloads are written to `email_webhook_logs` with sensitive headers
masked. Each webhook is deduplicated on provider event identity, normalised type
and recipient.

Mailgun uses HMAC-SHA256 over `timestamp + token` with
`MAILGUN_WEBHOOK_SIGNING_KEY`, falling back to `MAILGUN_API_KEY`. Mandrill uses
signed POST with `MANDRILL_WEBHOOK_KEY`. Both enforce
`webhook_freshness_seconds`, which defaults to 1800 seconds.

Normalised events are `opened`, `clicked`, `delivered`, `deferred`, `bounced`,
`complained` and `unsubscribed`. Bounces, complaints and unsubscribes add the
recipient to `email_suppressions`; opens and clicks only update campaign event
counts.

## 8. Suppression and event queries

```php
$email->suppression()->add('user@example.com', null, 'manual', 'admin');

if ($email->suppression()->isSuppressed('user@example.com', null)) {
    // Skip marketing sends.
}

$events = $email->events()->findByCampaign($campaignId, 50);
$counts = $email->events()->countByCampaign($campaignId);
```

Use `campaigns()->analytics()` for derived rates and `campaigns()->details()`
for a campaign-level report. The audit repositories are available through
`$email->statusChanges()`, `$email->webhookLogs()` and
`$email->httpRequestLogger()`.

## 9. Running checks

```bash
composer validate --strict
composer analyse
composer style
composer test
```

See [docs/adoption.md](adoption.md) for legacy table and status mappings.

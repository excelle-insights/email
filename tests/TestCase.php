<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests;

use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_senders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    provider_key TEXT NOT NULL,
    name TEXT,
    credentials TEXT,
    from_email TEXT,
    from_name TEXT,
    reply_to TEXT,
    enabled INTEGER DEFAULT 1,
    is_default INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(tenant_id, provider_key)
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    campaign_id INTEGER,
    provider TEXT,
    provider_message_id TEXT,
    recipient_name TEXT,
    recipient_email TEXT,
    from_email TEXT,
    from_name TEXT,
    reply_to TEXT,
    cc TEXT,
    bcc TEXT,
    subject TEXT,
    content_sections TEXT,
    text_content TEXT,
    template TEXT,
    template_id INTEGER,
    template_version INTEGER,
    template_variables TEXT,
    variables TEXT,
    attachments TEXT,
    status TEXT DEFAULT 'queued',
    delivery_status TEXT DEFAULT 'none',
    send_date TEXT,
    attempts INTEGER DEFAULT 0,
    idempotency_key TEXT,
    error_message TEXT,
    message_id TEXT,
    origin TEXT DEFAULT 'campaign',
    priority INTEGER DEFAULT 5,
    metadata TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    name TEXT NOT NULL,
    provider TEXT,
    template_id INTEGER,
    subject TEXT,
    total_recipients INTEGER DEFAULT 0,
    queued_count INTEGER DEFAULT 0,
    sent_count INTEGER DEFAULT 0,
    delivered_count INTEGER DEFAULT 0,
    failed_count INTEGER DEFAULT 0,
    opened_count INTEGER DEFAULT 0,
    clicked_count INTEGER DEFAULT 0,
    bounced_count INTEGER DEFAULT 0,
    status TEXT DEFAULT 'draft',
    send_date TEXT,
    metadata TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    key TEXT,
    name TEXT NOT NULL,
    subject TEXT,
    category TEXT DEFAULT 'marketing',
    status TEXT DEFAULT 'draft',
    current_version INTEGER DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_template_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    template_id INTEGER NOT NULL,
    version INTEGER NOT NULL,
    subject TEXT,
    html TEXT NOT NULL,
    variables TEXT,
    author TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(template_id, version)
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_suppressions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    email TEXT NOT NULL,
    type TEXT NOT NULL,
    source TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_status_changes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER,
    campaign_id INTEGER,
    from_status TEXT,
    to_status TEXT NOT NULL,
    actor TEXT,
    reason TEXT,
    source TEXT DEFAULT 'application',
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_webhook_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    provider TEXT NOT NULL,
    direction TEXT NOT NULL,
    headers TEXT,
    payload TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    provider TEXT,
    message_id TEXT,
    queue_id INTEGER,
    email_queue_id INTEGER,
    campaign_id INTEGER,
    recipient_email TEXT,
    event_type TEXT NOT NULL DEFAULT 'unknown',
    dedupe_key TEXT,
    event_data TEXT,
    ip_address TEXT,
    user_agent TEXT,
    event_timestamp TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE email_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    level TEXT NOT NULL,
    message TEXT NOT NULL,
    context TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)
SQL);

        $this->pdo->exec(<<<'SQL'
CREATE TABLE http_request_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    provider TEXT,
    level TEXT DEFAULT 'INFO',
    message TEXT,
    method TEXT NOT NULL,
    url TEXT NOT NULL,
    request_headers TEXT,
    request_body TEXT,
    response_status INTEGER,
    response_headers TEXT,
    response_body TEXT,
    error_message TEXT,
    duration_ms INTEGER,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT
)
SQL);
    }
}

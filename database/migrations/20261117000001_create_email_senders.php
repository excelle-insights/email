<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateEmailSenders extends AbstractMigration
{
    private string $prefix;

    public function up(): void
    {
        $this->prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? 'email_');

        $table = $this->table('senders', ['id' => true, 'signed' => false]);
        $table
            ->addColumn('tenant_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('provider_key', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('credentials', 'text', ['null' => true])
            ->addColumn('from_email', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('from_name', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('reply_to', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
            ->addColumn('is_default', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id', 'provider_key'], ['unique' => true])
            ->addIndex(['tenant_id'])
            ->addIndex(['provider_key'])
            ->create();

        // Enforce exactly one default sender per tenant, as in the SMS package.
        $this->execute(
            "ALTER TABLE {$this->prefix}senders "
            . 'ADD COLUMN default_tenant_id INT AS (CASE WHEN is_default = TRUE THEN tenant_id ELSE NULL END) STORED, '
            . 'ADD UNIQUE INDEX idx_default_email_sender (default_tenant_id)',
        );
    }

    public function down(): void
    {
        $this->table('senders')->drop()->save();
    }
}

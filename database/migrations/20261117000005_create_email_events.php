<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Creates the normalised webhook event table and adopts legacy Excelle event rows.
 */
final class CreateEmailEvents extends AbstractMigration
{
    private string $prefix;

    public function up(): void
    {
        $this->prefix = (string) ($this->getAdapter()->getOption('table_prefix') ?? 'email_');

        $columns = [
            'tenant_id' => ['integer', ['null' => true, 'signed' => false]],
            'provider' => ['string', ['limit' => 64, 'null' => true]],
            'message_id' => ['string', ['limit' => 255, 'null' => true]],
            'queue_id' => ['integer', ['null' => true, 'signed' => false]],
            'email_queue_id' => ['integer', ['null' => true, 'signed' => false]],
            'campaign_id' => ['integer', ['null' => true, 'signed' => false]],
            'recipient_email' => ['string', ['limit' => 255, 'null' => true]],
            'event_type' => ['string', ['limit' => 64, 'null' => false, 'default' => 'unknown']],
            'dedupe_key' => ['string', ['limit' => 64, 'null' => true]],
            'event_data' => ['json', ['null' => true]],
            'ip_address' => ['string', ['limit' => 64, 'null' => true]],
            'user_agent' => ['text', ['null' => true]],
            'event_timestamp' => ['datetime', ['null' => true]],
            'created_at' => ['datetime', ['null' => false, 'default' => 'CURRENT_TIMESTAMP']],
        ];

        $indexes = [
            [['message_id', 'event_type', 'recipient_email'], false],
            [['campaign_id', 'event_type'], false],
            [['tenant_id', 'event_timestamp'], false],
        ];

        if (! $this->hasTable('events')) {
            $table = $this->table('events', ['id' => true, 'signed' => false]);
            foreach ($columns as $column => [$type, $options]) {
                $table->addColumn($column, $type, $options);
            }
            foreach ($indexes as [$columnsForIndex, $unique]) {
                $table->addIndex($columnsForIndex, ['unique' => $unique]);
            }
            $table->addIndex(['dedupe_key'], ['unique' => true])
                ->create();

            return;
        }

        $table = $this->table('events');
        $changed = false;

        foreach ($columns as $column => [$type, $options]) {
            if ($table->hasColumn($column)) {
                continue;
            }

            $table->addColumn($column, $type, $options);
            $changed = true;
        }

        foreach ($indexes as [$columnsForIndex, $unique]) {
            if ($table->hasIndex($columnsForIndex)) {
                continue;
            }

            $table->addIndex($columnsForIndex, ['unique' => $unique]);
            $changed = true;
        }

        if (! $table->hasIndex(['dedupe_key'])) {
            $table->addIndex(['dedupe_key'], ['unique' => true]);
            $changed = true;
        }

        if ($changed) {
            $table->update();
        }

        $this->copyTenantIds();
    }

    /**
     * Adopted tables and their legacy rows must survive a rollback.
     */
    public function down(): void
    {
    }

    private function copyTenantIds(): void
    {
        $table = $this->table('events');
        if (! $table->hasColumn('workspace_id') || ! $table->hasColumn('tenant_id')) {
            return;
        }

        $this->execute(
            "UPDATE {$this->prefix}events SET tenant_id = workspace_id "
            . 'WHERE tenant_id IS NULL AND workspace_id IS NOT NULL',
        );
    }
}

<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Config;

use ExcelleInsights\Email\Config\EmailConfig;
use PHPUnit\Framework\TestCase;

final class EmailConfigTest extends TestCase
{
    public function testUsesPackageDefaults(): void
    {
        $config = new EmailConfig(['encryption_key' => 'key']);

        self::assertSame('email_', $config->tablePrefix());
        self::assertTrue($config->escapeVariables());
        self::assertSame([1, 2, 4, 8, 16], $config->retryDelays());
        self::assertSame(1800, $config->webhookFreshnessSeconds());
    }

    public function testReadsOverrides(): void
    {
        $config = new EmailConfig([
            'table_prefix' => 'mail_',
            'default_sender' => 'mailgun',
            'webhook_signing_keys' => ['mailgun' => 'signing-key'],
            'retry_delays' => ['1', '5'],
        ]);

        self::assertSame('mail_', $config->tablePrefix());
        self::assertSame('mailgun', $config->defaultSender());
        self::assertSame('signing-key', $config->webhookSigningKey('mailgun'));
        self::assertSame([1, 5], $config->retryDelays());
    }
}

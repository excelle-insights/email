<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConfigFileTest extends TestCase
{
    public function testConfigExposesBootstrapKeys(): void
    {
        $config = require dirname(__DIR__, 2) . '/config/email.php';

        self::assertArrayHasKey('table_prefix', $config);
    }
}

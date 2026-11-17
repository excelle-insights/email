<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Providers\Log;

use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Providers\Log\LogEmailProvider;
use ExcelleInsights\Email\Tests\Support\EmailProviderContractTest;
use ExcelleInsights\Email\Tests\Support\RecordingLogger;

final class LogEmailProviderTest extends EmailProviderContractTest
{
    private RecordingLogger $logger;

    protected function makeProvider(): LogEmailProvider
    {
        $this->logger = new RecordingLogger();

        return new LogEmailProvider($this->logger);
    }

    protected function expectedCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(bulk: true);
    }

    protected function queueSuccessfulSend(): void
    {
    }

    protected function logger(): RecordingLogger
    {
        return $this->logger;
    }

    public function testItDoesNotLogMessageBodies(): void
    {
        $this->provider->send($this->request());

        self::assertStringNotContainsString('<p>Hello</p>', $this->logger->contents());
    }
}

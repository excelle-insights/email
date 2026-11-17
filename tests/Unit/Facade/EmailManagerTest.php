<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Facade;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class EmailManagerTest extends TestCase
{
    public function testWiresSenderAndProviderAdministration(): void
    {
        $manager = new EmailManager(
            ['encryption_key' => 'secret-key'],
            $this->pdo,
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
        );

        $manager->setSenderCredentials('mailgun', null, ['api_key' => 'secret']);
        $manager->setDefaultSender('mailgun');
        $provider = $this->createMock(EmailProviderInterface::class);
        $provider->method('getKey')->willReturn('custom');
        $manager->registerProvider($provider);

        self::assertSame(['custom'], $manager->listProviders());
        self::assertSame('mailgun', $manager->resolveProvider());
        self::assertSame(['api_key' => 'secret'], $manager->getSenderCredentials('mailgun'));
    }
}

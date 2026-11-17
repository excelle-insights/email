<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Facade;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class EmailManagerM3Test extends TestCase
{
    public function testItRegistersAllBuiltInProviders(): void
    {
        $manager = $this->manager();

        $manager->registerBuiltInProviders();

        self::assertSame(['log', 'phpmailer', 'mailgun', 'mandrill'], $manager->listProviders());
        foreach ($manager->listProviders() as $key) {
            self::assertInstanceOf(EmailProviderInterface::class, $manager->provider($key));
            self::assertSame($key, $manager->provider($key)->getKey());
        }
    }

    public function testBuiltInLogProviderCanDispatchQueuedMessages(): void
    {
        $manager = $this->manager();
        $manager->registerBuiltInProviders();
        $manager->setSenderCredentials('log', null, []);
        $manager->setDefaultSender('log');

        $result = $manager->sendNow('user@example.com', 'Subject', '<p>Body</p>', [
            'category' => 'transactional',
        ]);

        self::assertSame(MessageStatus::SENT, $result->status);
        self::assertNotNull($result->providerMessageId);
        self::assertStringStartsWith('log-', $result->providerMessageId);
    }

    private function manager(): EmailManager
    {
        return new EmailManager(
            ['encryption_key' => 'secret-key'],
            $this->pdo,
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }
}

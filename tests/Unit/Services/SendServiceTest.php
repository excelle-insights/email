<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Services;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\Support\RecordingProvider;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class SendServiceTest extends TestCase
{
    public function testSendQueuesFrozenRenderedMessage(): void
    {
        $manager = $this->manager();
        $result = $manager->send(
            'user@example.com',
            'Welcome',
            '<p>Placeholder</p>',
            [
                'template' => 'welcome',
                'vars' => ['subject' => 'Welcome aboard', 'first_name' => 'Wanjiru'],
            ],
        );
        $message = $manager->queue()->findById($result->localId ?? 0);

        self::assertSame('Welcome aboard', $message?->subject);
        self::assertStringContainsString('Welcome, Wanjiru', (string) $message->html);
    }

    public function testSendRejectsSuppressedMarketingRecipient(): void
    {
        $manager = $this->manager();
        $manager->suppression()->add('blocked@example.com', null, 'bounce');

        $this->expectException(\RuntimeException::class);
        $manager->send('blocked@example.com', 'Subject', '<p>Body</p>');
    }

    public function testTransactionalSendBypassesSuppression(): void
    {
        $manager = $this->manager();
        $manager->suppression()->add('blocked@example.com', null, 'bounce');

        $result = $manager->send('blocked@example.com', 'Subject', '<p>Body</p>', [
            'category' => 'transactional',
        ]);

        self::assertSame(MessageStatus::QUEUED, $result->status);
    }

    public function testIdempotencyReturnsTheExistingMessage(): void
    {
        $manager = $this->manager();
        $first = $manager->send('user@example.com', 'Subject', '<p>Body</p>', [
            'idempotency_key' => 'otp-123',
        ]);
        $second = $manager->send('user@example.com', 'Subject', '<p>Body</p>', [
            'idempotency_key' => 'otp-123',
        ]);

        self::assertSame($first->localId, $second->localId);
    }

    public function testBulkSendCreatesCampaignAndSkipsInvalidRecipients(): void
    {
        $manager = $this->manager();
        $result = $manager->sendBulk([
            ['to' => 'valid@example.com'],
            ['to' => 'not-an-email'],
        ], 'Subject', '<p>Body</p>');
        $campaign = $manager->campaigns()->find($result->campaignId ?? 0);

        self::assertSame(1, $campaign->queuedCount);
        self::assertSame(1, $campaign->failedCount);
    }

    public function testSendNowDispatchesSynchronously(): void
    {
        $manager = $this->manager();
        $provider = new RecordingProvider('record');
        $manager->registerProvider($provider);

        $result = $manager->sendNow('user@example.com', 'Subject', '<p>Body</p>', [
            'category' => 'transactional',
        ]);

        self::assertSame(MessageStatus::SENT, $result->status);
        self::assertCount(1, $provider->requests);
    }

    public function testScheduledSendDoesNotDispatchImmediately(): void
    {
        $manager = $this->manager();
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', [
            'scheduled_at' => '2099-01-01 09:00:00',
        ]);

        self::assertSame(MessageStatus::SCHEDULED, $result->status);
    }

    private function manager(): EmailManager
    {
        $manager = new EmailManager(
            ['encryption_key' => 'secret-key', 'default_provider' => 'record'],
            $this->pdo,
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
        );
        $manager->setDefaultSender('record');

        return $manager;
    }
}

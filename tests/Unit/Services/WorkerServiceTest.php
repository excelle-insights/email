<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Services;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\Support\RecordingProvider;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class WorkerServiceTest extends TestCase
{
    public function testDispatchQueuedMessagesSendsQueuedRows(): void
    {
        $manager = $this->manager();
        $provider = new RecordingProvider('record');
        $manager->registerProvider($provider);
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>');
        $messageId = $result->localId ?? 0;

        $dispatched = $manager->worker()->dispatchQueuedMessages(10);

        self::assertSame(1, $dispatched);
        self::assertSame(MessageStatus::SENT, $manager->queue()->findById($messageId)?->status);
    }

    public function testReleaseDueScheduledMessagesQueuesDueRows(): void
    {
        $manager = $this->manager();
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', [
            'scheduled_at' => '2020-01-01 09:00:00',
        ]);
        $messageId = $result->localId ?? 0;

        $released = $manager->worker()->releaseDueScheduledMessages(10);

        self::assertSame(1, $released);
        self::assertSame(MessageStatus::QUEUED, $manager->queue()->findById($messageId)?->status);
    }

    public function testRetryFailedMessagesHonoursBackoff(): void
    {
        $manager = $this->manager();
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>');
        $messageId = $result->localId ?? 0;
        $manager->queue()->markFailed($messageId, 'temporary failure', 0);
        $this->pdo->exec("UPDATE email_queue SET updated_at = '2020-01-01 00:00:00' WHERE id = {$messageId}");

        $retried = $manager->worker()->retryFailedMessages(10);

        self::assertSame(1, $retried);
        self::assertSame(MessageStatus::QUEUED, $manager->queue()->findById($messageId)?->status);
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

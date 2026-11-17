<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Services;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Entities\Campaign;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\StatusContext;
use ExcelleInsights\Email\Entities\StatusResult;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class MessageStatusServiceTest extends TestCase
{
    public function testApproveChangesPendingMessageToQueuedAndAuditsIt(): void
    {
        $manager = $this->manager();
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', ['pending' => true]);
        $messageId = $result->localId ?? 0;

        $change = $manager->status()->approve($messageId, new StatusContext(actor: 'tester'));

        self::assertSame(StatusResult::CHANGED, $change->outcome);
        self::assertSame(
            MessageStatus::QUEUED,
            $manager->queue()->findById($messageId)?->status,
        );
    }

    public function testInvalidTransitionIsRejected(): void
    {
        $manager = $this->manager();
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', ['pending' => true]);

        $change = $manager->status()->change(
            $result->localId ?? 0,
            MessageStatus::SENT,
            new StatusContext(actor: 'tester'),
        );

        self::assertSame(StatusResult::INVALID_TRANSITION, $change->outcome);
    }

    public function testChangeManyReportsChangedCount(): void
    {
        $manager = $this->manager();
        $first = $manager->send('first@example.com', 'Subject', '<p>Body</p>', ['pending' => true]);
        $second = $manager->send('second@example.com', 'Subject', '<p>Body</p>', ['pending' => true]);

        $result = $manager->status()->changeMany(
            [$first->localId ?? 0, $second->localId ?? 0],
            MessageStatus::CANCELLED,
            new StatusContext(actor: 'tester'),
        );

        self::assertSame(2, $result->changed);
    }

    public function testChangeCampaignCancelsAllCampaignMessages(): void
    {
        $manager = $this->manager();
        $bulk = $manager->sendBulk([
            ['to' => 'first@example.com'],
            ['to' => 'second@example.com'],
        ], 'Subject', '<p>Body</p>');

        $result = $manager->status()->changeCampaign(
            $bulk->campaignId ?? 0,
            MessageStatus::CANCELLED,
            new StatusContext(actor: 'tester'),
        );

        self::assertSame(2, $result->changed);
        self::assertSame(
            Campaign::STATUS_CANCELLED,
            $manager->campaigns()->find($bulk->campaignId ?? 0)->status,
        );
    }

    public function testExpectedFromDetectsStaleState(): void
    {
        $manager = $this->manager();
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', ['pending' => true]);

        $change = $manager->status()->change(
            $result->localId ?? 0,
            MessageStatus::QUEUED,
            new StatusContext(actor: 'tester', expectedFrom: MessageStatus::SCHEDULED),
        );

        self::assertSame(StatusResult::STALE_STATE, $change->outcome);
    }

    public function testAllowedTransitionsUsesCurrentStatus(): void
    {
        $manager = $this->manager();
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', ['pending' => true]);

        self::assertContains(
            MessageStatus::QUEUED,
            $manager->status()->allowedTransitions($result->localId ?? 0),
        );
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

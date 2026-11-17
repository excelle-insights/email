<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Services;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Entities\Campaign;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class CampaignServiceTest extends TestCase
{
    public function testQueueAddsHostSuppliedRecipientsToAnExistingCampaign(): void
    {
        $manager = $this->manager();
        $campaign = $manager->campaigns()->create(new Campaign(
            name: 'Launch',
            provider: 'record',
            status: Campaign::STATUS_DRAFT,
        ));
        $campaignId = $campaign->id ?? 0;

        $result = $manager->campaigns()->queue($campaignId, [
            ['to' => 'one@example.com'],
            ['to' => 'two@example.com'],
        ], [
            'subject' => 'Launch day',
            'html' => '<p>Hello</p>',
        ]);
        $stored = $manager->campaigns()->find($campaignId);

        self::assertSame($campaignId, $result->campaignId);
        self::assertSame(2, $result->queued);
        self::assertSame(2, $stored->totalRecipients);
        self::assertSame(2, $stored->queuedCount);
        self::assertSame(Campaign::STATUS_QUEUED, $stored->status);
    }

    public function testCancelStopsPendingCampaignMessages(): void
    {
        $manager = $this->manager();
        $campaign = $manager->campaigns()->create(new Campaign(
            name: 'Launch',
            provider: 'record',
            status: Campaign::STATUS_DRAFT,
        ));
        $campaignId = $campaign->id ?? 0;
        $manager->campaigns()->queue($campaignId, [
            ['to' => 'one@example.com'],
            ['to' => 'two@example.com'],
        ], [
            'subject' => 'Launch day',
            'html' => '<p>Hello</p>',
        ]);

        $result = $manager->campaigns()->cancel($campaignId);
        $campaign = $manager->campaigns()->find($campaignId);
        $ids = $manager->campaignRepository()->findMessageIds($campaignId);

        self::assertSame(2, $result->changed);
        self::assertSame(Campaign::STATUS_CANCELLED, $campaign->status);
        self::assertSame(0, $campaign->queuedCount);
        self::assertSame(MessageStatus::CANCELLED, $manager->queue()->findById($ids[0])?->status);
    }

    public function testDetailsExposeCampaignMessagesAndAnalytics(): void
    {
        $manager = $this->manager();
        $campaign = $manager->campaigns()->create(new Campaign(
            name: 'Launch',
            provider: 'record',
            status: Campaign::STATUS_DRAFT,
        ));
        $campaignId = $campaign->id ?? 0;
        $manager->campaigns()->queue($campaignId, [
            ['to' => 'one@example.com'],
        ], [
            'subject' => 'Launch day',
            'html' => '<p>Hello</p>',
        ]);

        $details = $manager->campaigns()->details($campaignId);
        $analytics = $manager->campaigns()->analytics($campaignId);

        self::assertSame($campaignId, $analytics->campaignId);
        self::assertSame(1, $analytics->messagesByStatus['queued']);
        self::assertCount(1, $details['messages']);
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

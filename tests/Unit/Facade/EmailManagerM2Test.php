<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Facade;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Services\MessageStatusService;
use ExcelleInsights\Email\Services\TemplateService;
use ExcelleInsights\Email\Services\WorkerService;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class EmailManagerM2Test extends TestCase
{
    public function testExposesM2Services(): void
    {
        $manager = $this->manager();

        self::assertInstanceOf(TemplateService::class, $manager->templates());
    }

    public function testExposesStatusWorkerSuppressionAndQueueServices(): void
    {
        $manager = $this->manager();

        self::assertInstanceOf(WorkerService::class, $manager->worker());
        self::assertInstanceOf(MessageStatusService::class, $manager->status());
    }

    public function testFacadeSendQueuesMessage(): void
    {
        $manager = $this->manager();

        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', [
            'category' => 'transactional',
        ]);

        self::assertSame(MessageStatus::QUEUED, $result->status);
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

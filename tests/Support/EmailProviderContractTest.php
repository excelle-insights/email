<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Support;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
abstract class EmailProviderContractTest extends TestCase
{
    protected EmailProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = $this->makeProvider();
    }

    abstract protected function makeProvider(): EmailProviderInterface;

    abstract protected function expectedCapabilities(): ProviderCapabilities;

    abstract protected function queueSuccessfulSend(): void;

    abstract protected function logger(): RecordingLogger;

    /**
     * @return array<int, string>
     */
    protected function expectedSecrets(): array
    {
        return [];
    }

    protected function webhookPayload(): string
    {
        return '';
    }

    protected function expectedWebhookEventType(): string
    {
        return 'unknown';
    }

    final public function testItExposesStableIdentityAndCapabilityFlags(): void
    {
        $expected = $this->expectedCapabilities();
        $actual = $this->provider->capabilities();

        self::assertNotSame('', $this->provider->getKey());
        self::assertNotSame('', $this->provider->getDisplayName());
        self::assertSame($expected->bulk, $actual->bulk, 'bulk capability mismatch');
        self::assertSame($expected->attachments, $actual->attachments, 'attachments capability mismatch');
        self::assertSame($expected->cc, $actual->cc, 'cc capability mismatch');
        self::assertSame($expected->bcc, $actual->bcc, 'bcc capability mismatch');
        self::assertSame($expected->tracking, $actual->tracking, 'tracking capability mismatch');
        self::assertSame($expected->webhookEvents, $actual->webhookEvents, 'webhook capability mismatch');
        self::assertSame($expected->nativeTemplates, $actual->nativeTemplates, 'template capability mismatch');
    }

    final public function testItSendsAMessageAndDoesNotLogSecrets(): void
    {
        $this->queueSuccessfulSend();

        $result = $this->provider->send($this->request());

        self::assertTrue($result->isSuccess());
        self::assertSame(MessageStatus::SENT, $result->status);
        self::assertNotSame('', $result->providerMessageId);
        self::assertNotNull($result->providerMessageId);

        foreach ($this->expectedSecrets() as $secret) {
            self::assertStringNotContainsString($secret, $this->logger()->contents());
        }

        $this->assertProviderSpecificSecurity();
    }

    final public function testItParsesWebhooksWhenSupported(): void
    {
        if (! $this->provider->capabilities()->webhookEvents) {
            self::markTestSkipped('Provider has no webhook events');
        }

        $event = $this->provider->parseWebhook([], $this->webhookPayload());

        self::assertNotNull($event);
        self::assertSame($this->expectedWebhookEventType(), $event->eventType);
        self::assertNotSame('', $event->recipientEmail);
        self::assertNotNull($event->eventTimestamp);
    }

    protected function assertProviderSpecificSecurity(): void
    {
    }

    protected function request(): SendRequest
    {
        return new SendRequest(
            to: 'user@example.com',
            subject: 'Contract test',
            html: '<p>Hello</p>',
            text: 'Hello',
            recipientName: 'Test User',
            fromEmail: 'noreply@example.com',
            fromName: 'Example',
            attachments: [],
            metadata: ['source' => 'contract-test'],
        );
    }
}

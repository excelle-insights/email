<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Controller;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Entities\Campaign;
use ExcelleInsights\Email\Entities\DeliveryStatus;
use ExcelleInsights\Email\Entities\WebhookResult;
use ExcelleInsights\Email\Exceptions\WebhookVerificationException;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class WebhookControllerTest extends TestCase
{
    public function testMailgunDeliveryEventIsVerifiedRecordedAndApplied(): void
    {
        $manager = $this->manager();
        $campaignId = $this->campaignId($manager);
        $messageId = $this->queueMessage($manager, 'mailgun', 'mg-message-1', $campaignId);
        $timestamp = time() - 10;
        $payload = $this->mailgunPayload('delivered', 'mg-message-1', $timestamp);
        $requestData = ['headers' => ['Content-Type' => 'application/json']];

        self::assertTrue($manager->webhookController()->verify('mailgun', $requestData, $payload));

        $result = $manager->webhookController()->process('mailgun', $requestData, $payload);
        $message = $manager->queue()->findById($messageId);
        $campaign = $manager->campaigns()->find($campaignId);
        $logStatement = $this->pdo->query('SELECT payload FROM email_webhook_logs');
        if ($logStatement === false) {
            self::fail('Unable to query webhook logs');
        }
        $logs = $logStatement->fetchAll();

        self::assertSame(WebhookResult::RECEIVED, $result->status);
        self::assertSame('delivered', $result->eventType);
        self::assertSame(DeliveryStatus::DELIVERED, $message?->deliveryStatus);
        self::assertSame(1, $campaign->deliveredCount);
        self::assertCount(1, $logs);
        self::assertSame($payload, $logs[0]['payload']);
    }

    public function testDuplicateMailgunEventIsSkipped(): void
    {
        $manager = $this->manager();
        $campaignId = $this->campaignId($manager);
        $this->queueMessage($manager, 'mailgun', 'mg-message-2', $campaignId);
        $timestamp = time() - 10;
        $payload = $this->mailgunPayload('opened', 'mg-message-2', $timestamp);
        $requestData = ['headers' => []];

        $first = $manager->webhookController()->process('mailgun', $requestData, $payload);
        $second = $manager->webhookController()->process('mailgun', $requestData, $payload);
        $countStatement = $this->pdo->query('SELECT COUNT(*) FROM email_events');
        if ($countStatement === false) {
            self::fail('Unable to count email events');
        }
        $total = $countStatement->fetchColumn();

        self::assertSame(WebhookResult::RECEIVED, $first->status);
        self::assertSame(WebhookResult::DUPLICATE, $second->status);
        self::assertSame(1, (int) $total);
    }

    public function testMailgunEventWithInvalidSignatureIsRejected(): void
    {
        $manager = $this->manager();
        $timestamp = time() - 10;
        $payload = $this->mailgunPayload('delivered', 'mg-message-3', $timestamp, 'invalid-signature');

        self::assertFalse($manager->webhookController()->verify('mailgun', [], $payload));

        $this->expectException(WebhookVerificationException::class);
        $manager->webhookController()->process('mailgun', [], $payload);
    }

    public function testStaleMailgunEventIsRejected(): void
    {
        $manager = $this->manager();
        $payload = $this->mailgunPayload('delivered', 'mg-message-4', time() - 4000);

        $this->expectException(WebhookVerificationException::class);
        $manager->webhookController()->process('mailgun', [], $payload);
    }

    public function testBounceEventSuppressesMarketingRecipientsButNotTransactionalOnes(): void
    {
        $manager = $this->manager();
        $campaignId = $this->campaignId($manager);
        $this->queueMessage($manager, 'mailgun', 'mg-message-5', $campaignId, 'marketing');
        $payload = $this->mailgunPayload('bounced', 'mg-message-5', time() - 10);

        $manager->webhookController()->process('mailgun', [], $payload);
        $suppressed = $manager->suppression()->isSuppressed('user@example.com', null);
        $campaign = $manager->campaigns()->find($campaignId);

        self::assertTrue($suppressed);
        self::assertSame(1, $campaign->bouncedCount);

        $this->expectException(\RuntimeException::class);
        $manager->send('user@example.com', 'News', '<p>Hello</p>', ['provider' => 'mailgun']);
    }

    public function testMandrillEventUsesSignedPostVerification(): void
    {
        $manager = $this->manager();
        $campaignId = $this->campaignId($manager);
        $messageId = $this->queueMessage($manager, 'mandrill', 'mandrill-msg-1', $campaignId);
        [$payload, $requestData] = $this->mandrillPayload('open', 'mandrill-msg-1', time() - 10);

        $result = $manager->webhookController()->process('mandrill', $requestData, $payload);
        $campaign = $manager->campaigns()->find($campaignId);
        $headerStatement = $this->pdo->query('SELECT headers FROM email_webhook_logs');
        if ($headerStatement === false) {
            self::fail('Unable to query webhook headers');
        }
        $headers = $headerStatement->fetchColumn();

        self::assertSame(WebhookResult::RECEIVED, $result->status);
        self::assertSame('opened', $result->eventType);
        self::assertSame(1, $campaign->openedCount);
        self::assertSame($messageId, $result->queueId);
        self::assertStringNotContainsString('mandrill-key', (string) $headers);
    }

    public function testUnsupportedProviderWebhookIsRejected(): void
    {
        $manager = $this->manager();

        self::assertFalse($manager->webhookController()->verify('phpmailer', [], '{}'));

        $this->expectException(WebhookVerificationException::class);
        $manager->webhookController()->process('phpmailer', [], '{}');
    }

    private function manager(): EmailManager
    {
        $manager = new EmailManager(
            [
                'encryption_key' => 'secret-key',
                'default_provider' => 'record',
                'mailgun_api_key' => 'api-fallback-key',
                'webhook_signing_keys' => [
                    'mailgun' => 'signing-key',
                    'mandrill' => 'mandrill-key',
                ],
            ],
            $this->pdo,
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
        );
        $manager->registerBuiltInProviders();
        $manager->setDefaultSender('record');

        return $manager;
    }

    private function campaignId(EmailManager $manager): int
    {
        $campaign = $manager->campaigns()->create(new Campaign(
            name: 'Webhook campaign',
            provider: 'record',
            totalRecipients: 1,
            status: Campaign::STATUS_QUEUED,
        ));

        return $campaign->id ?? 0;
    }

    private function queueMessage(
        EmailManager $manager,
        string $provider,
        string $providerMessageId,
        int $campaignId,
        string $category = 'marketing',
    ): int {
        $result = $manager->send('user@example.com', 'Subject', '<p>Body</p>', [
            'provider' => $provider,
            'campaign_id' => $campaignId,
            'category' => $category,
        ]);
        $messageId = $result->localId ?? 0;
        $manager->queue()->attachProviderMessageIdIfMissing($messageId, $providerMessageId);

        return $messageId;
    }

    private function mailgunPayload(
        string $eventType,
        string $messageId,
        int $timestamp,
        ?string $signature = null,
    ): string {
        $timestampText = (string) $timestamp;
        $token = 'token-' . $messageId;

        return (string) json_encode([
            'signature' => [
                'timestamp' => $timestampText,
                'token' => $token,
                'signature' => $signature ?? hash_hmac('sha256', $timestampText . $token, 'signing-key'),
            ],
            'event-data' => [
                'id' => 'event-' . $messageId,
                'event' => $eventType,
                'timestamp' => $timestamp,
                'recipient' => 'user@example.com',
                'message' => ['headers' => ['message-id' => '<' . $messageId . '>']],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function mandrillPayload(string $eventType, string $messageId, int $timestamp): array
    {
        $eventsJson = (string) json_encode([
            [
                'event' => $eventType,
                'ts' => $timestamp,
                'msg' => [
                    'message_id' => $messageId,
                    'email' => 'user@example.com',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $url = 'https://example.com/hooks/mandrill';
        $params = ['mandrill_events' => $eventsJson];

        ksort($params, SORT_STRING);
        $signedData = $url;
        foreach ($params as $name => $value) {
            $signedData .= (string) $name . (string) $value;
        }

        $requestData = [
            'url' => $url,
            'post' => $params,
            'headers' => [
                'X-Mandrill-Signature' => base64_encode(hash_hmac('sha1', $signedData, 'mandrill-key', true)),
            ],
        ];

        return [http_build_query($params, '', '&', PHP_QUERY_RFC3986), $requestData];
    }
}

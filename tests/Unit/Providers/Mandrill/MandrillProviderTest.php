<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Providers\Mandrill;

use ExcelleInsights\Email\Contracts\HttpResponse;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Providers\Mandrill\MandrillConfig;
use ExcelleInsights\Email\Providers\Mandrill\MandrillProvider;
use ExcelleInsights\Email\Providers\Mandrill\MandrillResponseMapper;
use ExcelleInsights\Email\Providers\Mandrill\MandrillWebhookParser;
use ExcelleInsights\Email\Support\AttachmentResolver;
use ExcelleInsights\Email\Support\EventTypeNormalizer;
use ExcelleInsights\Email\Support\HttpRequestLogger;
use ExcelleInsights\Email\Tests\Support\EmailProviderContractTest;
use ExcelleInsights\Email\Tests\Support\HttpFixture;
use ExcelleInsights\Email\Tests\Support\MockHttpClient;
use ExcelleInsights\Email\Tests\Support\RecordingLogger;
use PDO;

final class MandrillProviderTest extends EmailProviderContractTest
{
    private const API_KEY = 'secret-mandrill-key';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function makeProvider(): MandrillProvider
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();

        return new MandrillProvider(
            $this->http,
            new MandrillResponseMapper(),
            new MandrillWebhookParser(new EventTypeNormalizer()),
            new AttachmentResolver(),
            fn (): MandrillConfig => new MandrillConfig(apiKey: self::API_KEY),
            new HttpRequestLogger($this->pdo),
        );
    }

    protected function expectedCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            bulk: true,
            attachments: true,
            cc: true,
            bcc: true,
            tracking: true,
            webhookEvents: true,
            nativeTemplates: true,
        );
    }

    protected function queueSuccessfulSend(): void
    {
        $this->http->queueResponse(HttpFixture::response('mandrill_send_success.json'));
    }

    protected function logger(): RecordingLogger
    {
        return $this->logger;
    }

    /**
     * @return array<int, string>
     */
    protected function expectedSecrets(): array
    {
        return [self::API_KEY];
    }

    protected function webhookPayload(): string
    {
        return HttpFixture::raw('mandrill_webhook_open.json');
    }

    protected function expectedWebhookEventType(): string
    {
        return 'opened';
    }

    protected function assertProviderSpecificSecurity(): void
    {
        $stmt = $this->pdo->query(
            'SELECT request_body, response_body FROM http_request_logs ORDER BY id DESC LIMIT 1',
        );
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertStringNotContainsString(self::API_KEY, (string) $row['request_body']);
        self::assertStringNotContainsString(self::API_KEY, (string) $row['response_body']);
        self::assertStringContainsString('[REDACTED]', (string) $row['request_body']);
        self::assertStringNotContainsString('<p>Hello</p>', (string) $row['request_body']);
    }

    public function testItPostsTheExpectedMessagePayload(): void
    {
        $this->queueSuccessfulSend();

        $result = $this->provider->send($this->request());

        self::assertSame('mandrill-msg-1', $result->providerMessageId);
        $request = $this->http->requests[0];
        self::assertSame('https://mandrillapp.com/api/1.0/messages/send.json', $request['url']);

        $payload = json_decode((string) $request['options']['body'], true);
        self::assertIsArray($payload);
        self::assertSame(self::API_KEY, $payload['key']);
        self::assertSame('Contract test', $payload['message']['subject']);
        self::assertSame('<p>Hello</p>', $payload['message']['html']);
        self::assertSame('reply@example.com', $payload['message']['headers']['Reply-To']);
        self::assertSame(['cc@example.com'], $payload['message']['cc']);
        self::assertSame(['bcc@example.com'], $payload['message']['bcc']);
        self::assertTrue($payload['message']['track_opens']);
        self::assertTrue($payload['message']['track_clicks']);
    }

    public function testItUsesTheNativeTemplateEndpointWhenOptedIn(): void
    {
        $this->http->queueResponse(HttpFixture::response('mandrill_send_template_success.json'));

        $request = new SendRequest(
            to: 'user@example.com',
            subject: 'Template test',
            html: '<p>Hello</p>',
            fromEmail: 'noreply@example.com',
            templateKey: 'welcome',
            templateVars: ['first_name' => 'Ada'],
            options: [
                'native_template' => true,
                'template_content' => ['header' => 'Hi Ada'],
            ],
        );

        $result = $this->provider->send($request);

        self::assertSame('mandrill-template-msg-1', $result->providerMessageId);
        self::assertSame(
            'https://mandrillapp.com/api/1.0/messages/send-template.json',
            $this->http->requests[0]['url'],
        );

        $payload = json_decode((string) $this->http->requests[0]['options']['body'], true);
        self::assertIsArray($payload);
        self::assertSame('welcome', $payload['template_name']);
        self::assertSame([['name' => 'header', 'content' => 'Hi Ada']], $payload['template_content']);
        self::assertSame(
            [['name' => 'first_name', 'content' => 'Ada']],
            $payload['message']['global_merge_vars'],
        );
    }

    public function testItReturnsFailedWhenMandrillRejectsTheRecipient(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode([
            ['email' => 'user@example.com', 'status' => 'rejected', 'reject_reason' => 'invalid'],
        ]) ?: '[]'));

        $result = $this->provider->send($this->request());

        self::assertSame(MessageStatus::FAILED, $result->status);
        self::assertSame('invalid', $result->error);
    }

    public function testItDoesNotLeakTheApiKeyInErrors(): void
    {
        $this->http->queueResponse(new HttpResponse(200, json_encode([
            'status' => 'error',
            'message' => 'Invalid API key ' . self::API_KEY,
        ]) ?: '{}'));

        try {
            $this->provider->send($this->request());
            self::fail('Expected the provider to throw');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
        }
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
            replyTo: 'reply@example.com',
            cc: ['cc@example.com'],
            bcc: ['bcc@example.com'],
            metadata: ['source' => 'contract-test'],
        );
    }
}

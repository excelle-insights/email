<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Providers\Mailgun;

use ExcelleInsights\Email\Contracts\HttpResponse;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Providers\Mailgun\MailgunConfig;
use ExcelleInsights\Email\Providers\Mailgun\MailgunProvider;
use ExcelleInsights\Email\Providers\Mailgun\MailgunResponseMapper;
use ExcelleInsights\Email\Providers\Mailgun\MailgunWebhookParser;
use ExcelleInsights\Email\Support\AttachmentResolver;
use ExcelleInsights\Email\Support\EventTypeNormalizer;
use ExcelleInsights\Email\Support\HttpRequestLogger;
use ExcelleInsights\Email\Tests\Support\EmailProviderContractTest;
use ExcelleInsights\Email\Tests\Support\HttpFixture;
use ExcelleInsights\Email\Tests\Support\MockHttpClient;
use ExcelleInsights\Email\Tests\Support\RecordingLogger;
use PDO;

final class MailgunProviderTest extends EmailProviderContractTest
{
    private const API_KEY = 'secret-mailgun-key';

    private MockHttpClient $http;
    private RecordingLogger $logger;

    protected function makeProvider(): MailgunProvider
    {
        $this->http = new MockHttpClient();
        $this->logger = new RecordingLogger();

        return new MailgunProvider(
            $this->http,
            new MailgunResponseMapper(),
            new MailgunWebhookParser(new EventTypeNormalizer()),
            new AttachmentResolver(),
            fn (): MailgunConfig => new MailgunConfig(
                apiKey: self::API_KEY,
                domain: 'mg.example.com',
            ),
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
        );
    }

    protected function queueSuccessfulSend(): void
    {
        $this->http->queueResponse(HttpFixture::response('mailgun_send_success.json'));
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
        return HttpFixture::raw('mailgun_webhook_delivered.json');
    }

    protected function expectedWebhookEventType(): string
    {
        return 'delivered';
    }

    protected function assertProviderSpecificSecurity(): void
    {
        $stmt = $this->pdo->query(
            'SELECT request_headers, request_body, response_body FROM http_request_logs ORDER BY id DESC LIMIT 1',
        );
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        foreach (['request_headers', 'request_body', 'response_body'] as $column) {
            self::assertStringNotContainsString(self::API_KEY, (string) $row[$column]);
        }
        self::assertStringNotContainsString('<p>Hello</p>', (string) $row['request_body']);
    }

    public function testItUsesTheV3MessagesEndpointWithBasicAuthAndTracking(): void
    {
        $this->queueSuccessfulSend();

        $result = $this->provider->send($this->request());

        self::assertSame('20261117120000.1.ABCDEF@mg.example.com', $result->providerMessageId);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.mailgun.net/v3/mg.example.com/messages', $request['url']);
        self::assertSame(
            'Basic ' . base64_encode('api:' . self::API_KEY),
            $request['options']['headers']['Authorization'],
        );

        parse_str((string) $request['options']['body'], $body);
        self::assertSame('yes', $body['o:tracking']);
        self::assertSame('yes', $body['o:tracking-clicks']);
        self::assertSame('yes', $body['o:tracking-opens']);
        self::assertSame('cc@example.com', $body['cc']);
        self::assertSame('bcc@example.com', $body['bcc']);
        self::assertSame('reply@example.com', $body['h:Reply-To']);
        self::assertSame('contract-test', $body['v:source']);
    }

    public function testItSendsAttachmentsAsMultipart(): void
    {
        $attachment = tempnam(sys_get_temp_dir(), 'email-attachment-');
        self::assertIsString($attachment);
        file_put_contents($attachment, 'attachment contents');

        $this->queueSuccessfulSend();
        $request = new SendRequest(
            to: 'user@example.com',
            subject: 'Attachments',
            html: '<p>Hello</p>',
            fromEmail: 'noreply@example.com',
            attachments: [$attachment],
        );

        $this->provider->send($request);

        $multipart = $this->http->requests[0]['options']['multipart'];
        self::assertIsArray($multipart);
        self::assertSame('attachment[0]', $multipart['files'][0]['name']);
        self::assertSame($attachment, $multipart['files'][0]['path']);
        self::assertArrayNotHasKey('body', $this->http->requests[0]['options']);

        unlink($attachment);
    }

    public function testItDoesNotLeakTheApiKeyInErrors(): void
    {
        $this->http->queueResponse(new HttpResponse(401, json_encode([
            'message' => 'Invalid api_key ' . self::API_KEY,
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

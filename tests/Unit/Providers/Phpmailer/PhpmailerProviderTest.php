<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Providers\Phpmailer;

use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Providers\Phpmailer\PhpmailerConfig;
use ExcelleInsights\Email\Providers\Phpmailer\PhpmailerProvider;
use ExcelleInsights\Email\Tests\Support\EmailProviderContractTest;
use ExcelleInsights\Email\Tests\Support\RecordingLogger;
use ExcelleInsights\Email\Tests\Support\StaticPhpmailerFactory;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\MockObject\MockObject;

final class PhpmailerProviderTest extends EmailProviderContractTest
{
    private const PASSWORD = 'secret-smtp-password';

    private RecordingLogger $logger;

    /**
     * @var PHPMailer&MockObject
     */
    private PHPMailer $mail;

    protected function makeProvider(): PhpmailerProvider
    {
        $this->logger = new RecordingLogger();
        $this->mail = $this->getMockBuilder(PHPMailer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['send', 'getLastMessageID', 'addReplyTo', 'addCC', 'addBCC', 'addAttachment'])
            ->getMock();

        return new PhpmailerProvider(
            new StaticPhpmailerFactory($this->mail),
            fn (): PhpmailerConfig => new PhpmailerConfig(
                host: 'smtp.example.com',
                port: 587,
                encryption: 'tls',
                username: 'smtp-user',
                password: self::PASSWORD,
                debug: false,
            ),
            $this->logger,
        );
    }

    protected function expectedCapabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(bulk: true, attachments: true, cc: true, bcc: true);
    }

    protected function queueSuccessfulSend(): void
    {
        $this->mail->expects(self::once())->method('send')->willReturn(true);
        $this->mail->method('getLastMessageID')->willReturn('<phpmailer-msg-1>');
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
        return [self::PASSWORD];
    }

    public function testItConfiguresSmtpDetailsRecipientsAndAttachments(): void
    {
        $attachment = tempnam(sys_get_temp_dir(), 'email-attachment-');
        self::assertIsString($attachment);
        file_put_contents($attachment, 'attachment contents');

        $this->mail->expects(self::once())->method('addReplyTo')->with('reply@example.com');
        $this->mail->expects(self::once())->method('addCC')->with('cc@example.com');
        $this->mail->expects(self::once())->method('addBCC')->with('bcc@example.com');
        $this->mail->expects(self::once())->method('addAttachment')->with($attachment);
        $this->mail->expects(self::once())->method('send')->willReturn(true);
        $this->mail->method('getLastMessageID')->willReturn('<phpmailer-msg-2>');

        $request = new SendRequest(
            to: 'user@example.com',
            subject: 'SMTP test',
            html: '<p>Hello</p>',
            text: 'Hello',
            fromEmail: 'noreply@example.com',
            replyTo: 'reply@example.com',
            cc: ['cc@example.com'],
            bcc: ['bcc@example.com'],
            attachments: [$attachment],
        );

        $result = $this->provider->send($request);

        self::assertSame(MessageStatus::SENT, $result->status);
        self::assertSame('smtp.example.com', $this->mail->Host);
        self::assertSame(587, $this->mail->Port);
        self::assertSame('tls', $this->mail->SMTPSecure);
        self::assertTrue($this->mail->SMTPAuth);
        self::assertSame('smtp-user', $this->mail->Username);
        self::assertSame(self::PASSWORD, $this->mail->Password);
        self::assertSame('Hello', $this->mail->AltBody);
        self::assertSame('SMTP test', $this->mail->Subject);
        self::assertSame('<p>Hello</p>', $this->mail->Body);

        unlink($attachment);
    }

    public function testItDoesNotLeakSecretsWhenSmtpFails(): void
    {
        $this->mail->method('send')->willThrowException(
            new \RuntimeException('SMTP authentication failed for ' . self::PASSWORD),
        );

        try {
            $this->provider->send($this->request());
            self::fail('Expected the provider to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('PHPMailer SMTP send failed', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $this->logger->contents());
        }
    }
}

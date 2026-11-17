<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Phpmailer;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Entities\SendResult;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

class PhpmailerProvider implements EmailProviderInterface
{
    public const KEY = 'phpmailer';

    /**
     * @param \Closure(?int): PhpmailerConfig $configResolver
     */
    public function __construct(
        private readonly PhpmailerFactoryInterface $factory,
        private readonly \Closure $configResolver,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDisplayName(): string
    {
        return 'PHPMailer SMTP';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            bulk: true,
            attachments: true,
            cc: true,
            bcc: true,
        );
    }

    public function send(SendRequest $request): SendResult
    {
        $config = $this->resolveConfig($request->tenantId);
        $fromEmail = $request->fromEmail ?? $config->fromEmail;
        if ($fromEmail === null || $fromEmail === '') {
            throw new \RuntimeException('PHPMailer From address is not configured');
        }

        $mail = $this->factory->create();

        try {
            $mail->SMTPDebug = $config->debug ? SMTP::DEBUG_SERVER : SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host = $config->host;
            $mail->Port = $config->port;
            $mail->SMTPSecure = $config->encryption;
            $mail->SMTPAuth = $config->auth;

            if ($config->auth) {
                $mail->Username = $config->username;
                $mail->Password = $config->password;
            }

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->isHTML(true);
            $mail->setFrom($fromEmail, $request->fromName ?? $config->fromName ?? '');
            $mail->addAddress($request->to, $request->recipientName ?? '');

            $replyTo = $request->replyTo ?? $config->replyTo;
            if ($replyTo !== null && $replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            foreach ($request->cc as $cc) {
                $mail->addCC($cc);
            }

            foreach ($request->bcc as $bcc) {
                $mail->addBCC($bcc);
            }

            foreach ($request->attachments as $attachment) {
                $mail->addAttachment($attachment);
            }

            if ($request->text !== null && $request->text !== '') {
                $mail->AltBody = $request->text;
            }

            $mail->Subject = $request->subject;
            $mail->Body = $request->html;
            $mail->send();

            $providerMessageId = trim($mail->getLastMessageID());

            return new SendResult(
                status: MessageStatus::SENT,
                providerMessageId: $providerMessageId === '' ? null : $providerMessageId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('PHPMailer SMTP send failed', [
                'error' => $this->redactSecrets($e->getMessage(), $config->secrets()),
                'error_info' => $this->redactSecrets((string) $mail->ErrorInfo, $config->secrets()),
            ]);

            throw new \RuntimeException('PHPMailer SMTP send failed');
        }
    }

    /**
     * @param array<string, string> $headers
     */
    public function parseWebhook(array $headers, string $payload): ?EmailEvent
    {
        return null;
    }

    private function resolveConfig(?int $tenantId): PhpmailerConfig
    {
        $config = ($this->configResolver)($tenantId);
        if (! $config instanceof PhpmailerConfig) {
            throw new \RuntimeException('PHPMailer config resolver did not return a valid config');
        }

        $config->validate();

        return $config;
    }

    /**
     * @param array<int, string> $secrets
     */
    private function redactSecrets(string $value, array $secrets): string
    {
        return $secrets === [] ? $value : str_ireplace($secrets, '[REDACTED]', $value);
    }
}

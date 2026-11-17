<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Log;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Entities\MessageStatus;
use ExcelleInsights\Email\Entities\ProviderCapabilities;
use ExcelleInsights\Email\Entities\SendRequest;
use ExcelleInsights\Email\Entities\SendResult;
use Psr\Log\LoggerInterface;

class LogEmailProvider implements EmailProviderInterface
{
    public const KEY = 'log';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getDisplayName(): string
    {
        return 'Log / Null Provider';
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(bulk: true);
    }

    public function send(SendRequest $request): SendResult
    {
        $this->logger->info('[EMAIL] Would send message', [
            'to' => $request->to,
            'subject' => $request->subject,
            'template' => $request->templateKey,
            'provider' => self::KEY,
        ]);

        return new SendResult(
            status: MessageStatus::SENT,
            providerMessageId: 'log-' . bin2hex(random_bytes(8)),
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public function parseWebhook(array $headers, string $payload): ?EmailEvent
    {
        return null;
    }
}

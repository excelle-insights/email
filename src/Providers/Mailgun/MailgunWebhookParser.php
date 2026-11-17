<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mailgun;

use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Support\EventTypeNormalizer;

class MailgunWebhookParser
{
    public function __construct(private readonly EventTypeNormalizer $eventTypes)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function parse(array $headers, string $payload): ?EmailEvent
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return null;
        }

        $event = $decoded['event-data'] ?? $decoded;
        if (! is_array($event)) {
            return null;
        }

        $eventType = $event['event'] ?? null;
        if (! is_string($eventType) || $eventType === '') {
            return null;
        }

        $messageId = $event['message']['headers']['message-id'] ?? $event['id'] ?? null;
        $messageId = is_string($messageId) ? trim($messageId, '<>') : '';
        $recipient = $event['recipient'] ?? null;

        return new EmailEvent(
            messageId: $messageId === '' ? null : $messageId,
            recipientEmail: is_string($recipient) ? $recipient : '',
            eventType: $this->eventTypes->normalize($eventType),
            eventData: $event,
            ipAddress: $this->stringValue($event['client-info']['ip'] ?? null),
            userAgent: $this->stringValue($event['client-info']['user-agent'] ?? null),
            eventTimestamp: $this->timestamp($event['timestamp'] ?? null),
        );
    }

    private function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > 0 ? new \DateTimeImmutable('@' . $timestamp) : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}

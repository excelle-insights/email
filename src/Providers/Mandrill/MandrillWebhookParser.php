<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mandrill;

use ExcelleInsights\Email\Entities\EmailEvent;
use ExcelleInsights\Email\Support\EventTypeNormalizer;

class MandrillWebhookParser
{
    public function __construct(private readonly EventTypeNormalizer $eventTypes)
    {
    }

    /**
     * @param array<string, string> $headers
     */
    public function parse(array $headers, string $payload): ?EmailEvent
    {
        $json = $payload;
        $form = [];
        parse_str($payload, $form);
        if (isset($form['mandrill_events']) && is_string($form['mandrill_events'])) {
            $json = $form['mandrill_events'];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $event = $decoded[0] ?? null;
        if (! is_array($event) || ! isset($event['event']) || ! is_string($event['event'])) {
            return null;
        }

        $message = $event['msg'] ?? [];
        if (! is_array($message)) {
            $message = [];
        }

        $messageId = $message['message_id'] ?? null;
        $messageId = is_string($messageId) ? trim($messageId, '<>') : '';
        $recipient = $message['email'] ?? null;
        $timestamp = $event['ts'] ?? $message['ts'] ?? null;

        return new EmailEvent(
            messageId: $messageId === '' ? null : $messageId,
            recipientEmail: is_string($recipient) ? $recipient : '',
            eventType: $this->eventTypes->normalize($event['event']),
            eventData: $event,
            ipAddress: $this->stringValue($event['ip'] ?? null),
            userAgent: $this->stringValue($event['user_agent'] ?? null),
            eventTimestamp: $this->timestamp($timestamp),
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

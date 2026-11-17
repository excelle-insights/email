<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Support;

final class EventTypeNormalizer
{
    public function normalize(string $event): string
    {
        return match (strtolower(trim($event))) {
            'accepted', 'send', 'sent' => 'sent',
            'delivered', 'delivery' => 'delivered',
            'deferred', 'defer' => 'deferred',
            'bounce', 'bounced', 'hard_bounce', 'soft_bounce' => 'bounced',
            'complaint', 'complained', 'spam' => 'complained',
            'open', 'opened' => 'opened',
            'click', 'clicked' => 'clicked',
            'unsub', 'unsubscribe', 'unsubscribed' => 'unsubscribed',
            'reject', 'rejected' => 'bounced',
            default => 'unknown',
        };
    }
}

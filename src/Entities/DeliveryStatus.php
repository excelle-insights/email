<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

enum DeliveryStatus: string
{
    case NONE = 'none';
    case DEFERRED = 'deferred';
    case DELIVERED = 'delivered';
    case BOUNCED = 'bounced';
    case COMPLAINED = 'complained';
    case SUPPRESSED = 'suppressed';
    case UNKNOWN = 'unknown';
}

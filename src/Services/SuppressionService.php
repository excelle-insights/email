<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use ExcelleInsights\Email\Entities\EmailTemplate;
use ExcelleInsights\Email\Repositories\SuppressionRepository;

class SuppressionService
{
    public const TYPE_BOUNCE = 'bounce';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_UNSUBSCRIBE = 'unsubscribe';
    public const TYPE_MANUAL = 'manual';

    public function __construct(private readonly SuppressionRepository $repository)
    {
    }

    public function isSuppressed(string $email, ?int $tenantId): bool
    {
        return $this->repository->exists($email, $tenantId);
    }

    public function add(
        string $email,
        ?int $tenantId,
        string $type = self::TYPE_MANUAL,
        ?string $source = null,
    ): void {
        $this->repository->add($email, $tenantId, $type, $source);
    }

    public function suppressionTypeForEvent(string $eventType): ?string
    {
        return match ($eventType) {
            'bounced' => self::TYPE_BOUNCE,
            'complained' => self::TYPE_COMPLAINT,
            'unsubscribed' => self::TYPE_UNSUBSCRIBE,
            default => null,
        };
    }

    public function applyEvent(
        string $email,
        ?int $tenantId,
        string $eventType,
        ?string $source = null,
    ): bool {
        $suppressionType = $this->suppressionTypeForEvent($eventType);
        if ($suppressionType === null) {
            return false;
        }

        $this->add($email, $tenantId, $suppressionType, $source);

        return true;
    }

    public function isBlocked(
        string $email,
        ?int $tenantId,
        string $category,
        bool $bypass = false,
    ): bool {
        if ($bypass || $category === EmailTemplate::CATEGORY_TRANSACTIONAL) {
            return false;
        }

        return $this->isSuppressed($email, $tenantId);
    }
}

<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

class ProviderCapabilities
{
    public function __construct(
        public readonly bool $bulk = false,
        public readonly bool $attachments = false,
        public readonly bool $cc = false,
        public readonly bool $bcc = false,
        public readonly bool $tracking = false,
        public readonly bool $webhookEvents = false,
        public readonly bool $nativeTemplates = false,
    ) {
    }
}

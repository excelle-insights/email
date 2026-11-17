<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Entities;

class RenderedMessage
{
    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly ?string $text = null,
        public readonly array $variables = [],
    ) {
    }
}

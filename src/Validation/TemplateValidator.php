<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Validation;

class TemplateValidator
{
    /**
     * @return array<int, string>
     */
    public function detectVariables(string $template): array
    {
        preg_match_all('/\{\{\{?\s*[#^\/]?\s*([a-zA-Z0-9_]+)(?:\|raw)?\s*\}?\}\}/', $template, $matches);

        return array_values(array_unique(array_filter($matches[1], static fn (string $name): bool => $name !== '')));
    }

    public function validate(string $subject, string $html): void
    {
        if (trim($subject) === '') {
            throw new \InvalidArgumentException('Email template subject cannot be empty');
        }

        if (trim($html) === '') {
            throw new \InvalidArgumentException('Email template HTML cannot be empty');
        }
    }
}

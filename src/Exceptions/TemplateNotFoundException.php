<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Exceptions;

class TemplateNotFoundException extends EmailException
{
    public function __construct(string $key)
    {
        parent::__construct("Email template '{$key}' not found or archived");
    }
}

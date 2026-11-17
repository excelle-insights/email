<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Exceptions;

class ProviderNotFoundException extends EmailException
{
    public function __construct(string $provider)
    {
        parent::__construct("Email provider not registered: {$provider}");
    }
}

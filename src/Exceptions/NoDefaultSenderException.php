<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Exceptions;

class NoDefaultSenderException extends EmailException
{
    public function __construct(string $message = 'No default email sender is configured')
    {
        parent::__construct($message);
    }
}

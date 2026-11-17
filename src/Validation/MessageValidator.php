<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Validation;

use ExcelleInsights\Email\Entities\RenderedMessage;

class MessageValidator
{
    public function validate(RenderedMessage $message): void
    {
        if (trim($message->subject) === '') {
            throw new \InvalidArgumentException('Email subject cannot be empty');
        }

        if (trim($message->html) === '' && trim((string) $message->text) === '') {
            throw new \InvalidArgumentException('Email body cannot be empty');
        }
    }
}

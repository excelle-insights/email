<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Validation;

class EmailAddressValidator
{
    public function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    public function isValid(string $email): bool
    {
        return filter_var($this->normalise($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function validate(string $email): string
    {
        $normalised = $this->normalise($email);

        if (! $this->isValid($normalised)) {
            throw new \InvalidArgumentException("Invalid email address: {$email}");
        }

        return $normalised;
    }
}

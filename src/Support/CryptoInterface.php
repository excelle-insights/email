<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Support;

use ExcelleInsights\Email\Exceptions\EncryptionException;

interface CryptoInterface
{
    /**
     * @throws EncryptionException
     */
    public function encrypt(string $plaintext): string;

    /**
     * @throws EncryptionException
     */
    public function decrypt(string $ciphertext): string;
}

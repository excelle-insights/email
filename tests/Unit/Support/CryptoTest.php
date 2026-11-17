<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Support;

use ExcelleInsights\Email\Exceptions\EncryptionException;
use ExcelleInsights\Email\Support\OpenSslCrypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testEncryptsAndDecrypts(): void
    {
        $key = base64_encode(random_bytes(32));
        $crypto = new OpenSslCrypto($key);
        $plaintext = 'api-key:secret-value';

        $cipher = $crypto->encrypt($plaintext);

        self::assertNotSame($plaintext, $cipher);
        self::assertSame($plaintext, $crypto->decrypt($cipher));
    }

    public function testRejectsUnsupportedCiphertext(): void
    {
        $crypto = new OpenSslCrypto('a-secret-key');

        $this->expectException(EncryptionException::class);
        $crypto->decrypt('not-encrypted');
    }
}

<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Repositories;

use ExcelleInsights\Email\Repositories\SenderRepository;
use ExcelleInsights\Email\Support\OpenSslCrypto;
use ExcelleInsights\Email\Tests\TestCase;
use PDO;

final class SenderRepositoryTest extends TestCase
{
    private SenderRepository $senders;
    private OpenSslCrypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crypto = new OpenSslCrypto('secret-key');
        $this->senders = new SenderRepository($this->pdo, $this->crypto, 'email_');
    }

    public function testCredentialsAreEncryptedAtRestAndDecryptedOnRead(): void
    {
        $credentials = ['api_key' => 'secret-api-key', 'domain' => 'example.com'];

        $this->senders->saveCredentials(
            'mailgun',
            7,
            $credentials,
            'no-reply@example.com',
            'Example',
            'support@example.com',
        );

        $stmt = $this->pdo->prepare('SELECT credentials, from_email, reply_to FROM email_senders WHERE tenant_id = 7');
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertNotSame('secret-api-key', $row['credentials']);
        self::assertSame($credentials, $this->senders->getCredentials('mailgun', 7));
        self::assertSame('support@example.com', $row['reply_to']);
    }

    public function testTenantSenderWinsOverGlobalSender(): void
    {
        $this->senders->saveCredentials('mailgun', null, ['api_key' => 'global']);
        $this->senders->saveCredentials('mailgun', 7, ['api_key' => 'tenant']);

        self::assertSame(['api_key' => 'tenant'], $this->senders->getCredentials('mailgun', 7));
        self::assertSame(['api_key' => 'global'], $this->senders->getCredentials('mailgun', 8));
    }

    public function testOnlyOneDefaultSenderPerTenant(): void
    {
        $this->senders->setDefaultSender('mailgun', 7);
        $this->senders->setDefaultSender('mandrill', 7);

        self::assertSame('mandrill', $this->senders->getDefaultSender(7));
        self::assertNull($this->senders->getDefaultSender(null));
    }

    public function testSaveCredentialsUpdatesExistingSender(): void
    {
        $this->senders->saveCredentials('phpmailer', null, ['password' => 'first']);
        $this->senders->saveCredentials('phpmailer', null, ['password' => 'second']);

        self::assertSame(['password' => 'second'], $this->senders->getCredentials('phpmailer', null));
        self::assertCount(1, $this->senders->all(null));
    }
}

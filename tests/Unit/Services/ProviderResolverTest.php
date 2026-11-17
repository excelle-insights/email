<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Services;

use ExcelleInsights\Email\Config\EmailConfig;
use ExcelleInsights\Email\Exceptions\NoDefaultSenderException;
use ExcelleInsights\Email\Repositories\SenderRepository;
use ExcelleInsights\Email\Services\ProviderResolver;
use ExcelleInsights\Email\Support\OpenSslCrypto;
use ExcelleInsights\Email\Tests\TestCase;

final class ProviderResolverTest extends TestCase
{
    private SenderRepository $senders;
    private ProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->senders = new SenderRepository($this->pdo, new OpenSslCrypto('secret-key'), 'email_');
        $this->resolver = new ProviderResolver($this->senders, new EmailConfig([]));
    }

    public function testExplicitProviderWins(): void
    {
        $this->senders->setDefaultSender('mailgun', 7);

        self::assertSame('phpmailer', $this->resolver->resolve('phpmailer', 7));
    }

    public function testTenantDefaultWinsOverGlobalDefault(): void
    {
        $this->senders->setDefaultSender('mailgun', null);
        $this->senders->setDefaultSender('mandrill', 7);

        self::assertSame('mandrill', $this->resolver->resolve(null, 7));
        self::assertSame('mailgun', $this->resolver->resolve(null, 8));
    }

    public function testFallsBackToGlobalDefaultWhenTenantHasNone(): void
    {
        $this->senders->setDefaultSender('mailgun', null);

        self::assertSame('mailgun', $this->resolver->resolve(null, 7));
    }

    public function testFallsBackToEnvironmentBootstrap(): void
    {
        $resolver = new ProviderResolver(
            $this->senders,
            new EmailConfig(['default_sender' => 'log', 'default_provider' => 'mailgun']),
        );

        self::assertSame('log', $resolver->resolve(null, null));
    }

    public function testThrowsWhenNothingIsConfigured(): void
    {
        $this->expectException(NoDefaultSenderException::class);
        $this->resolver->resolve(null, null);
    }
}

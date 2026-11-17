<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers;

use ExcelleInsights\Email\Contracts\EmailProviderInterface;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Providers\Log\LogEmailProvider;
use ExcelleInsights\Email\Providers\Mailgun\MailgunConfig;
use ExcelleInsights\Email\Providers\Mailgun\MailgunProvider;
use ExcelleInsights\Email\Providers\Mailgun\MailgunResponseMapper;
use ExcelleInsights\Email\Providers\Mailgun\MailgunWebhookParser;
use ExcelleInsights\Email\Providers\Mandrill\MandrillConfig;
use ExcelleInsights\Email\Providers\Mandrill\MandrillProvider;
use ExcelleInsights\Email\Providers\Mandrill\MandrillResponseMapper;
use ExcelleInsights\Email\Providers\Mandrill\MandrillWebhookParser;
use ExcelleInsights\Email\Providers\Phpmailer\DefaultPhpmailerFactory;
use ExcelleInsights\Email\Providers\Phpmailer\PhpmailerConfig;
use ExcelleInsights\Email\Providers\Phpmailer\PhpmailerProvider;
use ExcelleInsights\Email\Support\AttachmentResolver;
use ExcelleInsights\Email\Support\EventTypeNormalizer;

final class BuiltInProviders
{
    /**
     * @return array<int, EmailProviderInterface>
     */
    public static function all(EmailManager $manager): array
    {
        return [
            self::log($manager),
            self::phpmailer($manager),
            self::mailgun($manager),
            self::mandrill($manager),
        ];
    }

    public static function log(EmailManager $manager): LogEmailProvider
    {
        return new LogEmailProvider($manager->getLogger());
    }

    public static function phpmailer(EmailManager $manager): PhpmailerProvider
    {
        $bootstrap = [
            'host' => $manager->getConfig()->toArray()['smtp_host'] ?? '',
            'port' => $manager->getConfig()->toArray()['smtp_port'] ?? 587,
            'encryption' => $manager->getConfig()->toArray()['smtp_encryption'] ?? 'tls',
            'username' => $manager->getConfig()->toArray()['smtp_user'] ?? '',
            'password' => $manager->getConfig()->toArray()['smtp_password'] ?? '',
        ];

        return new PhpmailerProvider(
            new DefaultPhpmailerFactory(),
            static fn (?int $tenantId): PhpmailerConfig => PhpmailerConfig::fromArray(
                self::senderConfig($manager, PhpmailerProvider::KEY, $tenantId, $bootstrap),
            ),
            $manager->getLogger(),
        );
    }

    public static function mailgun(EmailManager $manager): MailgunProvider
    {
        $bootstrap = [
            'api_key' => $manager->getConfig()->toArray()['mailgun_api_key'] ?? '',
            'domain' => $manager->getConfig()->toArray()['mailgun_domain'] ?? '',
        ];

        return new MailgunProvider(
            $manager->getHttpClient(),
            new MailgunResponseMapper(),
            new MailgunWebhookParser(new EventTypeNormalizer()),
            new AttachmentResolver(),
            static fn (?int $tenantId): MailgunConfig => MailgunConfig::fromArray(
                self::senderConfig($manager, MailgunProvider::KEY, $tenantId, $bootstrap),
            ),
            $manager->httpRequestLogger(),
        );
    }

    public static function mandrill(EmailManager $manager): MandrillProvider
    {
        $bootstrap = [
            'api_key' => $manager->getConfig()->toArray()['mandrill_api_key'] ?? '',
        ];

        return new MandrillProvider(
            $manager->getHttpClient(),
            new MandrillResponseMapper(),
            new MandrillWebhookParser(new EventTypeNormalizer()),
            new AttachmentResolver(),
            static fn (?int $tenantId): MandrillConfig => MandrillConfig::fromArray(
                self::senderConfig($manager, MandrillProvider::KEY, $tenantId, $bootstrap),
            ),
            $manager->httpRequestLogger(),
        );
    }

    /**
     * @param array<string, mixed> $bootstrap
     *
     * @return array<string, mixed>
     */
    private static function senderConfig(EmailManager $manager, string $provider, ?int $tenantId, array $bootstrap): array
    {
        $sender = $manager->senders()->getSender($provider, $tenantId) ?? [];
        $credentials = $sender['credentials'] ?? [];
        if (! is_array($credentials)) {
            $credentials = [];
        }

        $identity = array_filter([
            'from_email' => $sender['from_email'] ?? null,
            'from_name' => $sender['from_name'] ?? null,
            'reply_to' => $sender['reply_to'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return array_merge($bootstrap, $credentials, $identity);
    }
}

<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Services;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Exceptions\TemplateNotFoundException;
use ExcelleInsights\Email\Facade\EmailManager;
use ExcelleInsights\Email\Tests\TestCase;
use Psr\Log\LoggerInterface;

final class TemplateServiceTest extends TestCase
{
    public function testBundledTemplateFallbackRendersSubjectAndHtml(): void
    {
        $preview = $this->manager()->templates()->preview('welcome', [
            'subject' => 'Welcome',
            'first_name' => 'Wanjiru',
        ]);

        self::assertSame('Welcome', $preview->subject);
        self::assertStringContainsString('Welcome, Wanjiru', $preview->html);
    }

    public function testDatabaseTemplateWinsOverBundledTemplate(): void
    {
        $manager = $this->manager();
        $manager->templates()->create([
            'key' => 'welcome',
            'name' => 'Database welcome',
            'subject' => 'DB {{name}}',
            'html' => '<p>DB {{name}}</p>',
        ]);

        $preview = $manager->templates()->preview('welcome', ['name' => 'Wanjiru']);

        self::assertSame('DB Wanjiru', $preview->subject);
    }

    public function testCreatingTemplateStoresVersionOne(): void
    {
        $template = $this->manager()->templates()->create([
            'key' => 'receipt',
            'name' => 'Receipt',
            'subject' => 'Your receipt',
            'html' => '<p>Thank you</p>',
            'variables' => ['order_id'],
        ]);

        self::assertSame(1, $template->version);
    }

    public function testUpdatingTemplateCreatesANewVersion(): void
    {
        $manager = $this->manager();
        $template = $manager->templates()->create([
            'key' => 'receipt',
            'name' => 'Receipt',
            'subject' => 'Old subject',
            'html' => '<p>Old body</p>',
        ]);

        $updated = $manager->templates()->update($template->id ?? 0, [
            'subject' => 'New subject',
            'html' => '<p>New body</p>',
            'author' => 'tester',
        ]);

        self::assertSame(2, $updated->version);
        self::assertSame('New subject', $updated->subject);
    }

    public function testArchivedTemplateCannotBeSent(): void
    {
        $manager = $this->manager();
        $template = $manager->templates()->create([
            'key' => 'archived',
            'name' => 'Archived',
            'subject' => 'Archived',
            'html' => '<p>Archived</p>',
        ]);
        $manager->templates()->setStatus($template->id ?? 0, 'archived');

        $this->expectException(TemplateNotFoundException::class);
        $manager->templates()->preview('archived', []);
    }

    public function testTenantTemplateWinsOverGlobalTemplate(): void
    {
        $manager = $this->manager();
        $manager->templates()->create([
            'key' => 'custom',
            'name' => 'Global',
            'subject' => 'Global',
            'html' => '<p>Global</p>',
        ]);
        $manager->templates()->create([
            'tenant_id' => 7,
            'key' => 'custom',
            'name' => 'Tenant',
            'subject' => 'Tenant',
            'html' => '<p>Tenant</p>',
        ]);

        self::assertSame('Tenant', $manager->templates()->preview('custom', [], 7)->subject);
    }

    private function manager(): EmailManager
    {
        return new EmailManager(
            ['encryption_key' => 'secret-key', 'default_provider' => 'record'],
            $this->pdo,
            $this->createMock(HttpClientInterface::class),
            $this->createMock(LoggerInterface::class),
        );
    }
}

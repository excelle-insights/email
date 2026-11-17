<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Services;

use ExcelleInsights\Email\Services\TemplateRenderer;
use PHPUnit\Framework\TestCase;

final class TemplateRendererTest extends TestCase
{
    public function testEscapesVariablesByDefault(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame(
            'Hello &lt;b&gt;Wanjiru&lt;/b&gt;',
            $renderer->render('Hello {{name}}', ['name' => '<b>Wanjiru</b>']),
        );
    }

    public function testTripleBracesRenderRawValues(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame('<p>Trusted</p>', $renderer->render('{{{html}}}', ['html' => '<p>Trusted</p>']));
    }

    public function testRawFilterRendersRawValues(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame('<p>Trusted</p>', $renderer->render('{{ html|raw }}', ['html' => '<p>Trusted</p>']));
    }

    public function testPositiveConditionalsRenderWhenTruthy(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame('Welcome, Wanjiru', $renderer->render('Welcome{{#name}}, {{name}}{{/name}}', ['name' => 'Wanjiru']));
    }

    public function testPositiveConditionalsAreRemovedWhenFalsy(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame('Welcome', $renderer->render('Welcome{{#name}}, {{name}}{{/name}}', ['name' => '']));
    }

    public function testInverseConditionalsRenderWhenFalsy(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame('No name', $renderer->render('{{^name}}No name{{/name}}', ['name' => '']));
    }

    public function testGlobalsAreAvailableAndCanBeOverridden(): void
    {
        $renderer = new TemplateRenderer(true, ['site_title' => 'Excelle', 'year' => '2026']);

        self::assertSame(
            'Excelle 2027',
            $renderer->render('{{site_title}} {{year}}', ['year' => '2027']),
        );
    }

    public function testDetectsUniqueVariables(): void
    {
        $renderer = new TemplateRenderer();

        self::assertSame(
            ['name', 'action_url'],
            $renderer->detectVariables('Hi {{name}} {{name}} {{#action_url}}Go{{/action_url}}'),
        );
    }
}

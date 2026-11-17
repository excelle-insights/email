<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

class TemplateRenderer
{
    private const MAX_PASSES = 5;

    /**
     * @param array<string, mixed> $globals
     */
    public function __construct(
        private readonly bool $escape = true,
        private readonly array $globals = [],
    ) {
    }

    /**
     * Render Mustache-style placeholders, conditionals and globals.
     *
     * @param array<string, mixed> $variables
     */
    public function render(string $template, array $variables): string
    {
        return $this->replace($template, array_merge($this->globals, $variables));
    }

    /**
     * @return array<int, string>
     */
    public function detectVariables(string $template): array
    {
        preg_match_all('/\{\{\{?\s*[#^\/]?\s*([a-zA-Z0-9_]+)(?:\|raw)?\s*\}?\}\}/', $template, $matches);

        return array_values(array_unique(array_filter($matches[1], static fn (string $name): bool => $name !== '')));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function replace(string $html, array $vars): string
    {
        $html = $this->replaceConditionals($html, $vars, true);
        $html = $this->replaceConditionals($html, $vars, false);

        return $this->replaceVariables($html, $vars);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function replaceConditionals(string $html, array $vars, bool $positive): string
    {
        $pattern = $positive
            ? '/\{\{#([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/\1\}\}/s'
            : '/\{\{\^([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/\1\}\}/s';

        return preg_replace_callback(
            $pattern,
            function (array $matches) use ($vars, $positive): string {
                $truthy = ! empty($vars[$matches[1]] ?? null);

                return $positive === $truthy ? $this->replace($matches[2], $vars) : '';
            },
            $html,
        ) ?? $html;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function replaceVariables(string $html, array $vars): string
    {
        for ($pass = 0; $pass < self::MAX_PASSES; ++$pass) {
            $before = $html;

            $html = preg_replace_callback(
                '/\{\{\{\s*([a-zA-Z0-9_]+)\s*\}\}\}/',
                fn (array $matches): string => $this->value($matches[1], $matches[0], $vars, true),
                $html,
            ) ?? $html;

            $html = preg_replace_callback(
                '/\{\{\s*([a-zA-Z0-9_]+)(\|raw)?\s*\}\}/',
                fn (array $matches): string => $this->value($matches[1], $matches[0], $vars, ($matches[2] ?? '') !== ''),
                $html,
            ) ?? $html;

            if ($html === $before) {
                break;
            }
        }

        // Remove unresolved tags, matching the agentpro renderer's cleanup step.
        return preg_replace('/\{\{\{?\s*[#^\/]?\s*[a-zA-Z0-9_]+(?:\|raw)?\s*\}?\}\}/', '', $html) ?? $html;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function value(string $key, string $tag, array $vars, bool $raw): string
    {
        if (! array_key_exists($key, $vars)) {
            return $tag;
        }

        $value = $vars[$key];
        if ($value === null) {
            return '';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            $text = (string) $value;

            return $this->escape && ! $raw
                ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $text;
        }

        $encoded = json_encode($value);

        return $encoded === false ? '' : $encoded;
    }
}

<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Services;

use ExcelleInsights\Email\Config\EmailConfig;
use ExcelleInsights\Email\Entities\EmailTemplate;
use ExcelleInsights\Email\Entities\RenderedMessage;
use ExcelleInsights\Email\Exceptions\TemplateNotFoundException;
use ExcelleInsights\Email\Repositories\TemplateRepository;
use ExcelleInsights\Email\Repositories\TemplateVersionRepository;
use ExcelleInsights\Email\Validation\TemplateValidator;

class TemplateService
{
    public function __construct(
        private readonly TemplateRepository $templateRepository,
        private readonly TemplateVersionRepository $templateVersionRepository,
        private readonly TemplateRenderer $templateRenderer,
        private readonly TemplateValidator $templateValidator,
        private readonly EmailConfig $config,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): EmailTemplate
    {
        $subject = (string) ($data['subject'] ?? '');
        $html = (string) ($data['html'] ?? '');
        $this->templateValidator->validate($subject, $html);

        $template = $this->templateRepository->create(new EmailTemplate(
            tenantId: $this->optionalInt($data['tenant_id'] ?? null),
            key: (string) ($data['key'] ?? ''),
            name: (string) ($data['name'] ?? $data['key'] ?? ''),
            subject: $subject,
            category: (string) ($data['category'] ?? EmailTemplate::CATEGORY_MARKETING),
            status: (string) ($data['status'] ?? EmailTemplate::STATUS_ACTIVE),
        ));

        $id = $template->id;
        if ($id === null) {
            throw new \RuntimeException('Failed to create email template');
        }

        $variables = $this->variables($data['variables'] ?? null);
        $this->templateVersionRepository->create(
            $id,
            $template->version,
            $subject,
            $html,
            $variables,
            $this->optionalString($data['author'] ?? null),
        );

        return $this->hydrate($template, $template->version);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): EmailTemplate
    {
        $template = $this->hydrate($this->templateRepository->findById($id));
        $version = $this->templateRepository->bumpVersion($id);

        $subject = array_key_exists('subject', $data) ? (string) $data['subject'] : $template->subject;
        $html = array_key_exists('html', $data) ? (string) $data['html'] : $template->html;
        $this->templateValidator->validate($subject, $html);

        $variables = array_key_exists('variables', $data)
            ? $this->variables($data['variables'])
            : $template->variables;

        $this->templateVersionRepository->create(
            $id,
            $version,
            $subject,
            $html,
            $variables,
            $this->optionalString($data['author'] ?? null),
        );

        $changes = [];
        foreach (['key', 'name', 'subject', 'category', 'status'] as $column) {
            if (array_key_exists($column, $data)) {
                $changes[$column] = $data[$column];
            }
        }
        $this->templateRepository->update($id, $changes);

        return $this->hydrate($this->templateRepository->findById($id), $version);
    }

    public function get(string $key, ?int $tenantId = null): EmailTemplate
    {
        $template = $this->templateRepository->findByKey($key, $tenantId);

        if ($template !== null) {
            if ($template->status !== EmailTemplate::STATUS_ACTIVE) {
                throw new TemplateNotFoundException($key);
            }

            return $this->hydrate($template);
        }

        return $this->bundled($key);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function preview(string $key, array $variables, ?int $tenantId = null): RenderedMessage
    {
        $template = $this->get($key, $tenantId);

        return new RenderedMessage(
            subject: $this->templateRenderer->render($template->subject, $variables),
            html: $this->templateRenderer->render($template->html, $variables),
            variables: $variables,
        );
    }

    /**
     * @return array<int, EmailTemplate>
     */
    public function list(?int $tenantId = null, ?string $status = null): array
    {
        return array_map(
            fn (EmailTemplate $template): EmailTemplate => $this->hydrate($template),
            $this->templateRepository->list($tenantId, $status),
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->templateRepository->setStatus($id, $status);
    }

    /**
     * @return array<int, string>
     */
    public function detectVariables(EmailTemplate $template): array
    {
        return array_values(array_unique(array_merge(
            $this->templateValidator->detectVariables($template->subject),
            $this->templateValidator->detectVariables($template->html),
        )));
    }

    private function bundled(string $key): EmailTemplate
    {
        if (preg_match('/^[a-z0-9][a-z0-9\/_-]*$/', $key) !== 1) {
            throw new TemplateNotFoundException($key);
        }

        $path = rtrim($this->config->templatesPath(), '/') . '/' . $key . '.html';
        if (! is_file($path)) {
            throw new TemplateNotFoundException($key);
        }

        $html = file_get_contents($path);
        if ($html === false) {
            throw new TemplateNotFoundException($key);
        }

        return new EmailTemplate(
            key: $key,
            name: $key,
            subject: $this->subjectFromHtml($key, $html),
            html: $html,
            category: EmailTemplate::CATEGORY_TRANSACTIONAL,
            status: EmailTemplate::STATUS_ACTIVE,
            bundled: true,
        );
    }

    private function subjectFromHtml(string $key, string $html): string
    {
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $matches) === 1) {
            return trim($matches[1]);
        }

        return ucwords(str_replace(['-', '/', '_'], ' ', $key));
    }

    private function hydrate(EmailTemplate $template, ?int $version = null): EmailTemplate
    {
        $id = $template->id;
        if ($id === null) {
            return $template;
        }

        $version ??= $template->version;
        $row = $this->templateVersionRepository->get($id, $version);

        if ($row === null) {
            return $template;
        }

        $variables = $this->stringKeyedArray($row['variables'] ?? null);

        return new EmailTemplate(
            id: $id,
            tenantId: $template->tenantId,
            key: $template->key,
            name: $template->name,
            subject: (string) ($row['subject'] ?? ''),
            html: (string) ($row['html'] ?? ''),
            category: $template->category,
            status: $template->status,
            version: $version,
            variables: $variables,
        );
    }

    /**
     * @param mixed $variables
     *
     * @return array<string, mixed>
     */
    private function variables(mixed $variables): array
    {
        return $this->stringKeyedArray($variables);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    private function optionalInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function optionalString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}

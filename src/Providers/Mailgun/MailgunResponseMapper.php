<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mailgun;

class MailgunResponseMapper
{
    /**
     * @return array<string, mixed>
     */
    public function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function messageId(string $body): ?string
    {
        $id = $this->decode($body)['id'] ?? null;
        if (! is_string($id)) {
            return null;
        }

        $id = trim($id, '<>');

        return $id === '' ? null : $id;
    }

    public function error(int $statusCode, string $body): string
    {
        $message = $this->decode($body)['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return 'Mailgun send failed: ' . $message;
        }

        return 'Mailgun send failed with HTTP ' . $statusCode;
    }
}

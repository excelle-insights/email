<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Providers\Mandrill;

use ExcelleInsights\Email\Entities\MessageStatus;

class MandrillResponseMapper
{
    /**
     * @return array<mixed>
     */
    public function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $response
     */
    public function isApiError(array $response): bool
    {
        return isset($response['status']) && $response['status'] === 'error';
    }

    /**
     * @param array<mixed> $response
     */
    public function error(array $response, int $statusCode): string
    {
        $message = $response['message'] ?? null;

        return 'Mandrill send failed: '
            . (is_string($message) && $message !== '' ? $message : 'HTTP ' . $statusCode);
    }

    /**
     * @param array<mixed> $response
     *
     * @return array<string, mixed>
     */
    public function firstResult(array $response): array
    {
        $first = array_is_list($response) ? ($response[0] ?? null) : null;

        return is_array($first) ? $first : [];
    }

    /**
     * @param array<string, mixed> $result
     */
    public function status(array $result): MessageStatus
    {
        return match (strtolower((string) ($result['status'] ?? ''))) {
            'sent', 'queued' => MessageStatus::SENT,
            default => MessageStatus::FAILED,
        };
    }

    /**
     * @param array<string, mixed> $result
     */
    public function errorReason(array $result): string
    {
        $reason = $result['reject_reason'] ?? $result['message'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : 'Mandrill rejected the message';
    }

    /**
     * @param array<mixed> $response
     */
    public function messageId(array $response): ?string
    {
        $results = array_is_list($response) ? $response : [$response];
        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $id = $result['id'] ?? $result['_id'] ?? null;
            if (is_string($id)) {
                $id = trim($id, '<>');
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return null;
    }
}

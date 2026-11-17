<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Support;

final class AttachmentResolver
{
    /**
     * @param array<int, string> $attachments
     *
     * @return array<int, array{path: string, name: string, type: string}>
     */
    public function resolve(array $attachments): array
    {
        $resolved = [];
        foreach ($attachments as $path) {
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                throw new \RuntimeException('Email attachment is not readable');
            }

            $resolved[] = [
                'path' => $path,
                'name' => basename($path),
                'type' => function_exists('mime_content_type')
                    ? (string) (mime_content_type($path) ?: 'application/octet-stream')
                    : 'application/octet-stream',
            ];
        }

        return $resolved;
    }
}

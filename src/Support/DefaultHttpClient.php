<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Support;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Contracts\HttpResponse;

class DefaultHttpClient implements HttpClientInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): HttpResponse
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(string $url, array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $method, string $url, array $options): HttpResponse
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('Failed to initialise cURL');
        }

        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? null;
        $multipart = $options['multipart'] ?? null;

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        if (is_array($headers) && $headers !== []) {
            $flatHeaders = [];
            foreach ($headers as $key => $value) {
                if ($multipart !== null && strtolower((string) $key) === 'content-type') {
                    continue;
                }
                $flatHeaders[] = $key . ': ' . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $flatHeaders);
        }

        if (is_array($multipart) && $multipart !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->multipartFields($multipart));
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        curl_setopt($ch, CURLOPT_HEADER, true);

        $response = curl_exec($ch);
        if ($response === false || ! is_string($response)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return new HttpResponse(
            $statusCode,
            substr($response, $headerSize),
            $this->parseHeaders(substr($response, 0, $headerSize)),
        );
    }

    /**
     * @param array<string, mixed> $multipart
     *
     * @return array<string, mixed>
     */
    private function multipartFields(array $multipart): array
    {
        $fields = $multipart['fields'] ?? [];
        if (! is_array($fields)) {
            $fields = [];
        }

        $files = $multipart['files'] ?? [];
        if (! is_array($files)) {
            $files = [];
        }

        foreach ($files as $file) {
            if (! is_array($file) || ! isset($file['name'], $file['path']) || ! is_string($file['name']) || ! is_string($file['path'])) {
                continue;
            }

            $fields[$file['name']] = new \CURLFile(
                $file['path'],
                is_string($file['type'] ?? null) ? $file['type'] : 'application/octet-stream',
                is_string($file['filename'] ?? null) ? $file['filename'] : basename($file['path']),
            );
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }
}

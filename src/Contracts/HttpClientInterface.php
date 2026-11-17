<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Contracts;

interface HttpClientInterface
{
    /**
     * Options may contain `headers`, `body` and `multipart` file uploads.
     *
     * @param array<string, mixed> $options
     */
    public function get(string $url, array $options = []): HttpResponse;

    /**
     * Options may contain `headers`, `body` and `multipart` file uploads.
     *
     * @param array<string, mixed> $options
     */
    public function post(string $url, array $options = []): HttpResponse;
}

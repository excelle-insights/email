<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Support;

use ExcelleInsights\Email\Contracts\HttpClientInterface;
use ExcelleInsights\Email\Contracts\HttpResponse;

final class MockHttpClient implements HttpClientInterface
{
    /**
     * @var array<int, array{method: string, url: string, options: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @var array<int, HttpResponse>
     */
    private array $responses = [];

    public function queueResponse(HttpResponse $response): void
    {
        $this->responses[] = $response;
    }

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
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $response = array_shift($this->responses);
        if (! $response instanceof HttpResponse) {
            throw new \RuntimeException('No queued HTTP response');
        }

        return $response;
    }
}

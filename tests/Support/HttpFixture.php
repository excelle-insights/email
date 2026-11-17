<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Support;

use ExcelleInsights\Email\Contracts\HttpResponse;

final class HttpFixture
{
    public static function response(string $name): HttpResponse
    {
        $data = self::decode($name);
        $body = $data['body'] ?? '';
        $body = is_string($body) ? $body : (string) json_encode($body);
        $headers = $data['headers'] ?? [];

        return new HttpResponse(
            (int) ($data['status'] ?? 200),
            $body,
            is_array($headers) ? $headers : [],
        );
    }

    public static function raw(string $name): string
    {
        return (string) file_get_contents(self::path($name));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $name): array
    {
        $data = json_decode(self::raw($name), true);

        return is_array($data) ? $data : [];
    }

    private static function path(string $name): string
    {
        return dirname(__DIR__) . '/Fixtures/http/' . $name;
    }
}

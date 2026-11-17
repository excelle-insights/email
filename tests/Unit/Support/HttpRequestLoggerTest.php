<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Tests\Unit\Support;

use ExcelleInsights\Email\Support\HttpRequestLogger;
use ExcelleInsights\Email\Tests\TestCase;
use PDO;

final class HttpRequestLoggerTest extends TestCase
{
    public function testItRedactsNestedJsonSecrets(): void
    {
        $redacted = HttpRequestLogger::redactBody(json_encode([
            'key' => 'secret-1',
            'nested' => ['api_key' => 'secret-2', 'safe' => 'value'],
        ]) ?: '{}');

        $decoded = json_decode((string) $redacted, true);
        self::assertIsArray($decoded);
        self::assertSame('[REDACTED]', $decoded['key']);
        self::assertSame('[REDACTED]', $decoded['nested']['api_key']);
        self::assertSame('value', $decoded['nested']['safe']);
    }

    public function testItRedactsUrlEncodedSecrets(): void
    {
        $redacted = HttpRequestLogger::redactBody('key=secret-1&api_key=secret-2&safe=value');

        parse_str((string) $redacted, $decoded);
        self::assertSame('[REDACTED]', $decoded['key']);
        self::assertSame('[REDACTED]', $decoded['api_key']);
        self::assertSame('value', $decoded['safe']);
    }

    public function testItRedactsLoggedHeadersAndResponseBodies(): void
    {
        (new HttpRequestLogger($this->pdo))->log(
            null,
            'mailgun',
            'POST',
            'https://api.mailgun.net/v3/mg.example.com/messages',
            ['Authorization' => 'Basic secret-1', 'X-Safe' => 'value'],
            'key=secret-2',
            200,
            '{"key":"secret-3"}',
            ['Set-Cookie' => 'token=secret-4'],
            12,
        );

        $stmt = $this->pdo->query('SELECT * FROM http_request_logs ORDER BY id DESC LIMIT 1');
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        $headers = json_decode((string) $row['request_headers'], true);
        self::assertIsArray($headers);
        self::assertSame('[REDACTED]', $headers['Authorization']);
        self::assertSame('value', $headers['X-Safe']);
        self::assertStringNotContainsString('secret-2', (string) $row['request_body']);
        self::assertStringNotContainsString('secret-3', (string) $row['response_body']);
        self::assertStringNotContainsString('secret-4', (string) $row['response_headers']);
    }
}

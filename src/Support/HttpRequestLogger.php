<?php

declare(strict_types=1);

namespace ExcelleInsights\Email\Support;

use PDO;

class HttpRequestLogger
{
    private const REDACTED = '[REDACTED]';
    private const SENSITIVE_KEY = '/\b(authorization|api[_-]?key|password|secret|token|credential|key)\b/i';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $table = 'http_request_logs',
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $responseHeaders
     */
    public function log(
        ?int $tenantId,
        string $provider,
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?int $statusCode,
        ?string $responseBody,
        array $responseHeaders,
        ?int $durationMs,
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table}
             (tenant_id, provider, method, url, request_headers, request_body, response_status,
              response_body, response_headers, duration_ms, created_at)
             VALUES (:tenant_id, :provider, :method, :url, :request_headers, :request_body,
                     :response_status, :response_body, :response_headers, :duration_ms, CURRENT_TIMESTAMP)",
        );

        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':provider' => $provider,
            ':method' => $method,
            ':url' => $url,
            ':request_headers' => json_encode(self::redactHeaders($headers)),
            ':request_body' => self::redactBody($body),
            ':response_status' => $statusCode,
            ':response_body' => self::redactBody($responseBody),
            ':response_headers' => json_encode(self::redactHeaders($responseHeaders)),
            ':duration_ms' => $durationMs,
        ]);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $key => $value) {
            $redacted[$key] = preg_match(self::SENSITIVE_KEY, $key) === 1
                ? self::REDACTED
                : self::redactText($value);
        }

        return $redacted;
    }

    public static function redactBody(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $encoded = json_encode(self::redactArray($decoded));

            return $encoded === false ? self::REDACTED : $encoded;
        }

        $form = [];
        parse_str($body, $form);
        if ($form !== []) {
            return http_build_query(self::redactArray($form), '', '&', PHP_QUERY_RFC3986);
        }

        return self::redactText($body);
    }

    private static function redactText(string $value): string
    {
        $redacted = preg_replace(
            '/\b(authorization|api[_-]?key|password|secret|token|credential)\b\s*[=:]\s*([^\s;,]+)/i',
            '$1=[REDACTED]',
            $value,
        );
        if ($redacted !== null) {
            $value = $redacted;
        }

        $redacted = preg_replace('/\b(bearer|basic)\s+[^\s]+/i', '$1 [REDACTED]', $value);

        return $redacted ?? $value;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function redactArray(array $values): array
    {
        $redacted = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $redacted[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $redacted[$key] = self::redactArray($value);
            } else {
                $redacted[$key] = $value;
            }
        }

        return $redacted;
    }
}

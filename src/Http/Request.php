<?php

declare(strict_types=1);

namespace Machma\Forms\Http;

final class Request
{
    /**
     * @param array<string, string> $headers lower-case header names
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, string> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $ip = '0.0.0.0',
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly bool $https = false,
        public readonly float $startedAt = 0.0,
        /** true when the body was larger than the read limit */
        public readonly bool $bodyTooLarge = false,
    ) {
    }

    public static function fromGlobals(int $maxBody): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $path = '/' . ltrim(rawurldecode($path), '/');

        $body = '';
        $tooLarge = false;
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $declared = (int) ($headers['content-length'] ?? 0);
            if ($declared > $maxBody) {
                $tooLarge = true;
            } else {
                $stream = fopen('php://input', 'rb');
                $body = $stream ? (string) stream_get_contents($stream, $maxBody + 1) : '';
                if ($stream) {
                    fclose($stream);
                }
                if (strlen($body) > $maxBody) {
                    $tooLarge = true;
                    $body = '';
                }
            }
        }

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            headers: $headers,
            body: $body,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            post: $_POST,
            cookies: array_map('strval', $_COOKIE),
            https: (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['SERVER_PORT'] ?? '') === '443'),
            startedAt: (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            bodyTooLarge: $tooLarge,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function origin(): ?string
    {
        $origin = $this->header('origin');
        return $origin === null || $origin === '' || $origin === 'null' ? null : $origin;
    }

    public function isJson(): bool
    {
        $type = strtolower(trim(explode(';', (string) $this->header('content-type'))[0]));
        return $type === 'application/json';
    }

    public function postString(string $key): string
    {
        $value = $this->post[$key] ?? '';
        return is_string($value) ? $value : '';
    }

    public function queryString(string $key): string
    {
        $value = $this->query[$key] ?? '';
        return is_string($value) ? $value : '';
    }
}

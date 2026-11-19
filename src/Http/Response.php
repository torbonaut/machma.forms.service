<?php

declare(strict_types=1);

namespace Machma\Forms\Http;

final class Response
{
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<string> raw Set-Cookie values */
    public array $cookies = [];

    public function __construct(
        public int $status = 200,
        public string $body = '',
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[$name] = $value;
        }
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withCookie(string $name, string $value, int $maxAge, bool $secure, string $path = '/'): self
    {
        $parts = [
            rawurlencode($name) . '=' . rawurlencode($value),
            'Path=' . $path,
            'Max-Age=' . $maxAge,
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }
        $this->cookies[] = implode('; ', $parts);
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        header_remove('X-Powered-By');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        echo $this->body;
    }
}

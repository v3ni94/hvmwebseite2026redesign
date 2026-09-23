<?php

declare(strict_types=1);

namespace Hvm\Http;

/**
 * HTTP-Antwort. Einzige Stelle, die Ausgaben an den Client schreibt (send()).
 */
final class Response
{
    /** @var array<string, array{0: string, 1: string}> Schlüssel in Kleinbuchstaben => [Originalname, Wert] */
    private array $headers = [];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'application/xml; charset=utf-8']);
    }

    /**
     * @param array<mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (preg_match('/[\r\n]/', $location)) {
            throw new \InvalidArgumentException('Ungültiges Weiterleitungsziel.');
        }

        return new self('', $status, ['Location' => $location]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function withStatus(int $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function withBody(string $body): self
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $result = [];
        foreach ($this->headers as [$name, $value]) {
            $result[$name] = $value;
        }

        return $result;
    }

    public function setHeader(string $name, string $value): void
    {
        if (preg_match('/[\r\n]/', $name . $value)) {
            throw new \InvalidArgumentException('Header darf keine Zeilenumbrüche enthalten.');
        }
        $this->headers[strtolower($name)] = [$name, $value];
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->setHeader($name, $value);

        return $clone;
    }

    public function withoutHeader(string $name): self
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);

        return $clone;
    }

    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }
        }
        if ($withBody) {
            echo $this->body;
        }
    }
}

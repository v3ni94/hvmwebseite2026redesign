<?php

declare(strict_types=1);

namespace Hvm\Http;

final class RouteMatch
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';

    /**
     * @param array{0: class-string, 1: string}|null $handler
     * @param array<string, mixed>                   $params
     * @param list<string>                           $allowedMethods
     */
    private function __construct(
        public readonly string $status,
        public readonly ?array $handler = null,
        public readonly array $params = [],
        public readonly array $allowedMethods = [],
    ) {
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, mixed>              $params
     */
    public static function found(array $handler, array $params): self
    {
        return new self(self::FOUND, $handler, $params);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /**
     * @param list<string> $allowed
     */
    public static function methodNotAllowed(array $allowed): self
    {
        return new self(self::METHOD_NOT_ALLOWED, null, [], $allowed);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}

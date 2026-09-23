<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit;

use Hvm\Http\Kernel;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Support\Env;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const BASE_URL = 'https://www.muellerhv.de';

    protected function tearDown(): void
    {
        Env::reset();
        parent::tearDown();
    }

    protected static function basePath(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param array<string, string|null> $env
     */
    protected function kernel(string $appEnv = 'development', array $env = []): Kernel
    {
        return Kernel::create(self::basePath(), array_merge([
            'APP_ENV' => $appEnv,
            'APP_URL' => self::BASE_URL,
            // Produktion verlangt einen gültigen APP_KEY (kein Ersatzschlüssel), nur für Tests
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('t', 32)),
            'SESSION_DRIVER' => 'array',
        ], $env));
    }

    protected function get(string $uri, string $appEnv = 'development'): Response
    {
        return $this->kernel($appEnv)->handle(Request::create('GET', $uri));
    }
}

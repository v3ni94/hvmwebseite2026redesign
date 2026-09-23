<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Fixtures;

use Hvm\Http\Request;
use Hvm\Http\Response;

final class ThrowingController
{
    public function show(Request $request, array $params = []): Response
    {
        throw new \RuntimeException('Interner Fehler mit Details');
    }
}

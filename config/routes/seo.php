<?php

declare(strict_types=1);

use Hvm\Controller\FaktenController;
use Hvm\Controller\HealthController;
use Hvm\Controller\SeoController;

/*
 * SEO und GEO (docs/seo-geo.md): Faktenseite und llms.txt. sitemap.xml und robots.txt stehen in config/routes.php.
 * Dazu security.txt (RFC 9116) und der Betriebs-Endpunkt /health (Docker-Healthcheck, docs/betrieb.md).
 */
return [
    ['GET', '/fakten/', [FaktenController::class, 'show']],
    ['GET', '/llms.txt', [SeoController::class, 'llms']],
    ['GET', '/.well-known/security.txt', [SeoController::class, 'securityTxt']],
    ['GET', '/health', [HealthController::class, 'show']],
];

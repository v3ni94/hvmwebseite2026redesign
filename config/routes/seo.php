<?php

declare(strict_types=1);

use Hvm\Controller\FaktenController;
use Hvm\Controller\SeoController;

/*
 * SEO und GEO (docs/seo-geo.md): Faktenseite und llms.txt. sitemap.xml und robots.txt stehen in config/routes.php.
 */
return [
    ['GET', '/fakten/', [FaktenController::class, 'show']],
    ['GET', '/llms.txt', [SeoController::class, 'llms']],
];

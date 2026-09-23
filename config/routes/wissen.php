<?php

declare(strict_types=1);

use Hvm\Controller\WissenController;

/*
 * Wissens- und FAQ-Bereich. Ersetzt die Platzhalterroute /wissen/ aus config/routes.php
 * und ergänzt /wissen/{slug}/ für die Artikel (config/routes.php Abschnitt "Feature-Routen").
 */
return [
    ['GET', '/wissen/', [WissenController::class, 'index']],
    ['GET', '/wissen/{slug}/', [WissenController::class, 'show']],
];

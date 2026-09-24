<?php

declare(strict_types=1);

use Hvm\Controller\StadtController;

/*
 * Betreuungsgebiete (config/staedte.php): Übersicht ersetzt die statische Route /betreuungsgebiete/ aus
 * config/routes.php, dazu je Stadt /hausverwaltung-{slug}/ (Text aus content/staedte/{slug}.md, sonst 404).
 */
return [
    ['GET', '/betreuungsgebiete/', [StadtController::class, 'index']],
    ['GET', '/hausverwaltung-{slug}/', [StadtController::class, 'show']],
];

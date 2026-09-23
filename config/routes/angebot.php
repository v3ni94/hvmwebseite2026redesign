<?php

declare(strict_types=1);

use Hvm\Controller\AngebotController;

/*
 * Leadstrecke: Angebotsformular (GET, POST) und Danke-Seite. Ersetzt die Stub-Routen aus config/routes.php.
 */
return [
    ['GET', '/angebot/', [AngebotController::class, 'show']],
    ['POST', '/angebot/', [AngebotController::class, 'submit']],
    ['GET', '/angebot/danke/', [AngebotController::class, 'danke']],
];

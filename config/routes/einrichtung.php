<?php

declare(strict_types=1);

use Hvm\Controller\EinrichtungController;

/*
 * Web-Einrichtung ohne Kommandozeile (docs/deploy-sftp.md). Der Controller antwortet mit 404, solange kein
 * SETUP_TOKEN (mindestens 32 Zeichen) gesetzt ist oder storage/setup.lock existiert. Nie in Sitemap,
 * robots.txt oder llms.txt aufnehmen.
 */
return [
    ['GET', EinrichtungController::PFAD, [EinrichtungController::class, 'show']],
    ['POST', EinrichtungController::PFAD, [EinrichtungController::class, 'action']],
];

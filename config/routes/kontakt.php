<?php

declare(strict_types=1);

use Hvm\Controller\BewerbungController;
use Hvm\Controller\KontaktController;

/*
 * Allgemeines Kontaktformular und Bewerbungsformular. Ersetzt die Stub-Routen aus config/routes.php.
 */
return [
    ['GET', '/kontakt/', [KontaktController::class, 'show']],
    ['POST', '/kontakt/', [KontaktController::class, 'submit']],
    ['GET', '/kontakt/danke/', [KontaktController::class, 'danke']],

    ['GET', '/karriere/bewerbung/', [BewerbungController::class, 'show']],
    ['POST', '/karriere/bewerbung/', [BewerbungController::class, 'submit']],
    ['GET', '/karriere/bewerbung/danke/', [BewerbungController::class, 'danke']],
];

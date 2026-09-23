<?php

declare(strict_types=1);

use Hvm\Controller\Admin\AuthController;
use Hvm\Controller\Admin\DashboardController;
use Hvm\Controller\Admin\LeadController;

/*
 * Admin-Bereich (MP 6.5). Ersetzt die Stub-Route /admin/ aus config/routes.php.
 * Alle Seiten: Anmeldung mit TOTP, X-Robots-Tag noindex, Cache-Control no-store, optionale IP-Beschränkung.
 */
return [
    ['GET', '/admin/', [DashboardController::class, 'index']],
    ['GET', '/admin/login/', [AuthController::class, 'showLogin']],
    ['POST', '/admin/login/', [AuthController::class, 'login']],
    ['GET', '/admin/login/code/', [AuthController::class, 'showCode']],
    ['POST', '/admin/login/code/', [AuthController::class, 'code']],
    ['POST', '/admin/logout/', [AuthController::class, 'logout']],
    ['GET', '/admin/leads/', [LeadController::class, 'index']],
    ['GET', '/admin/leads/export.csv', [LeadController::class, 'export']],
    ['GET', '/admin/leads/{uuid}/', [LeadController::class, 'show']],
    ['POST', '/admin/leads/{uuid}/status/', [LeadController::class, 'status']],
    ['POST', '/admin/leads/{uuid}/notiz/', [LeadController::class, 'notiz']],
    ['POST', '/admin/leads/{uuid}/zuweisung/', [LeadController::class, 'zuweisung']],
];

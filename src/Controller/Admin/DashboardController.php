<?php

declare(strict_types=1);

namespace Hvm\Controller\Admin;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Service\Dashboard;
use Hvm\Support\Container;

/**
 * Dashboard: Leads je Woche, je Quelle, je Verwaltungsart und je Status (MP 6.5).
 */
final class DashboardController
{
    public function __construct(
        private readonly AdminGate $gate,
        private readonly Container $container,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $user = $this->gate->requireUser($request);
        if ($user instanceof Response) {
            return $user;
        }
        $zeitraum = $request->queryValue('zeitraum') === Dashboard::ZEITRAUM_GESAMT ? Dashboard::ZEITRAUM_GESAMT : Dashboard::ZEITRAUM_12W;
        /** @var Dashboard $dashboard */
        $dashboard = $this->container->get(Dashboard::class);

        return $this->gate->render('admin/dashboard.html.twig', [
            'titel' => 'Dashboard',
            'daten' => $dashboard->data($zeitraum),
            'admin' => ['bereich' => 'dashboard'],
        ], $user);
    }
}

<?php

declare(strict_types=1);

namespace Hvm\Controller\Admin;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Session;
use Hvm\Security\AdminAuth;
use Hvm\Support\Config;
use Hvm\Support\Container;
use Hvm\View\View;

/**
 * Gemeinsame Zugangsprüfung und Antwortaufbereitung aller Admin-Controller.
 *
 * - IP-Beschränkung (ADMIN_IP_ALLOWLIST) vor jeder weiteren Verarbeitung.
 * - Anmeldung erforderlich, sonst Weiterleitung auf /admin/login/.
 * - Jede Antwort erhält X-Robots-Tag noindex und Cache-Control no-store.
 *
 * AdminAuth wird erst bei Bedarf aus dem Container geholt (Datenbankverbindung).
 */
final class AdminGate
{
    public const LOGIN_PATH = '/admin/login/';
    private const FLASH = '_admin_flash';

    private ?AdminAuth $auth = null;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
        private readonly Session $session,
        private readonly View $view,
    ) {
    }

    public function auth(): AdminAuth
    {
        return $this->auth ??= $this->container->get(AdminAuth::class);
    }

    public function clientIp(Request $request): string
    {
        return $request->clientIp(array_values((array) $this->config->get('app.trusted_proxies', [])));
    }

    /**
     * Antwort bei gesperrter IP, sonst null.
     */
    public function denyByIp(Request $request): ?Response
    {
        if ($this->auth()->ipAllowed($this->clientIp($request))) {
            return null;
        }

        return self::secure(Response::text('Zugriff nicht erlaubt.', 403));
    }

    /**
     * Angemeldeter Benutzer oder eine Antwort (IP gesperrt, Weiterleitung zur Anmeldung).
     *
     * @return array{id: int, email: string}|Response
     */
    public function requireUser(Request $request): array|Response
    {
        $denied = $this->denyByIp($request);
        if ($denied !== null) {
            return $denied;
        }
        $user = $this->auth()->user();
        if ($user === null) {
            return self::secure(Response::redirect(self::LOGIN_PATH, 303));
        }

        return $user;
    }

    public static function secure(Response $response): Response
    {
        return $response
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * @param array<string, mixed>              $vars
     * @param array{id: int, email: string}|null $user
     */
    public function render(string $template, array $vars, ?array $user, int $status = 200): Response
    {
        $vars['admin'] = [
            'user' => $user,
            'flash' => $this->pullFlash(),
        ] + (is_array($vars['admin'] ?? null) ? $vars['admin'] : []);

        return self::secure($this->view->response($template, $vars, $status));
    }

    public function flash(string $typ, string $text): void
    {
        $this->session->set(self::FLASH, ['typ' => $typ, 'text' => $text]);
    }

    /**
     * @return array{typ: string, text: string}|null
     */
    private function pullFlash(): ?array
    {
        $flash = $this->session->get(self::FLASH);
        if ($flash !== null) {
            $this->session->remove(self::FLASH);
        }

        return is_array($flash) ? ['typ' => (string) ($flash['typ'] ?? 'info'), 'text' => (string) ($flash['text'] ?? '')] : null;
    }
}

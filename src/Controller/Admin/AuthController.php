<?php

declare(strict_types=1);

namespace Hvm\Controller\Admin;

use Hvm\Http\Request;
use Hvm\Http\Response;

/**
 * Anmeldung (E-Mail und Passwort, danach TOTP) und Abmeldung per POST mit CSRF.
 */
final class AuthController
{
    private const MELDUNG_UNGUELTIG = 'E-Mail-Adresse oder Passwort ist nicht korrekt.';

    public function __construct(private readonly AdminGate $gate)
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function showLogin(Request $request, array $params = []): Response
    {
        $denied = $this->gate->denyByIp($request);
        if ($denied !== null) {
            return $denied;
        }
        $auth = $this->gate->auth();
        if ($auth->user() !== null) {
            return AdminGate::secure(Response::redirect('/admin/', 303));
        }
        $hinweis = $auth->pullNotice() === 'abgelaufen'
            ? 'Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.'
            : null;

        return $this->loginPage(null, $hinweis, '');
    }

    /**
     * @param array<string, mixed> $params
     */
    public function login(Request $request, array $params = []): Response
    {
        $denied = $this->gate->denyByIp($request);
        if ($denied !== null) {
            return $denied;
        }
        $email = mb_substr((string) $request->postValue('email', ''), 0, 254);
        $password = (string) $request->postValue('passwort', '');
        $result = $this->gate->auth()->attemptPassword($email, $password, $this->gate->clientIp($request));

        if ($result['status'] === 'ok') {
            return AdminGate::secure(Response::redirect('/admin/login/code/', 303));
        }
        if ($result['status'] === 'locked') {
            return $this->loginPage(self::lockMessage($result['retry_after']), null, $email, 429)
                ->withHeader('Retry-After', (string) $result['retry_after']);
        }

        return $this->loginPage(self::MELDUNG_UNGUELTIG, null, $email, 422);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function showCode(Request $request, array $params = []): Response
    {
        $denied = $this->gate->denyByIp($request);
        if ($denied !== null) {
            return $denied;
        }
        if (!$this->gate->auth()->hasPending()) {
            return AdminGate::secure(Response::redirect(AdminGate::LOGIN_PATH, 303));
        }

        return $this->codePage(null);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function code(Request $request, array $params = []): Response
    {
        $denied = $this->gate->denyByIp($request);
        if ($denied !== null) {
            return $denied;
        }
        $code = mb_substr((string) $request->postValue('code', ''), 0, 20);
        $result = $this->gate->auth()->attemptTotp($code, $this->gate->clientIp($request));
        if ($result['status'] === 'ok' && isset($result['verbleibend'])) {
            $this->gate->flash('info', self::recoveryNotice($result['verbleibend']));
        }

        return match ($result['status']) {
            'ok' => AdminGate::secure(Response::redirect('/admin/', 303)),
            'invalid' => $this->codePage('Der Code ist nicht korrekt oder wurde bereits verwendet.', 422),
            'locked' => $this->loginPage(self::lockMessage($result['retry_after']), null, '', 429)
                ->withHeader('Retry-After', (string) $result['retry_after']),
            default => $this->loginPage(null, 'Die Anmeldung ist abgelaufen. Bitte melden Sie sich erneut an.', '', 200),
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    public function logout(Request $request, array $params = []): Response
    {
        $this->gate->auth()->logout();
        $this->gate->flash('info', 'Sie wurden abgemeldet.');

        return AdminGate::secure(Response::redirect(AdminGate::LOGIN_PATH, 303));
    }

    public static function recoveryNotice(int $remaining): string
    {
        $text = sprintf(
            'Sie haben sich mit einem Wiederherstellungscode angemeldet. Verbleibende Codes: %d.',
            $remaining
        );
        if ($remaining <= 3) {
            $text .= ' Bitte neue Codes erzeugen lassen (bin/admin-user.php recovery-codes).';
        }

        return $text;
    }

    public static function lockMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return sprintf(
            'Zu viele Anmeldeversuche. Bitte versuchen Sie es in %d %s erneut.',
            $minutes,
            $minutes === 1 ? 'Minute' : 'Minuten'
        );
    }

    private function loginPage(?string $fehler, ?string $hinweis, string $email, int $status = 200): Response
    {
        return $this->gate->render('admin/login.html.twig', [
            'titel' => 'Anmeldung',
            'fehler' => $fehler,
            'hinweis' => $hinweis,
            'email' => $email,
        ], null, $status);
    }

    private function codePage(?string $fehler, int $status = 200): Response
    {
        return $this->gate->render('admin/login-code.html.twig', [
            'titel' => 'Bestätigungscode',
            'fehler' => $fehler,
        ], null, $status);
    }
}

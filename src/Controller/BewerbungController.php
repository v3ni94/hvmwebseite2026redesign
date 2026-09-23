<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Session;
use Hvm\Security\RateLimiter;
use Hvm\Security\SpamGuard;
use Hvm\Service\ApplicationService;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Container;
use Hvm\Support\Log;
use Hvm\Validation\BewerbungValidator;
use Hvm\Validation\UploadValidator;
use Hvm\View\PageMeta;
use Hvm\View\View;
use Throwable;

/**
 * Bewerbungsformular (MP 6.3, Karriere): Anzeige, Verarbeitung mit PRG, Danke-Seite.
 *
 * Die Datei wird nie in old-Werten zurückgegeben (Sicherheit, Praxis bei Datei-Uploads):
 * bei einem Fehler muss die PDF-Datei erneut ausgewählt werden, die übrigen Angaben bleiben erhalten.
 *
 * Datenbankdienste (Rate Limiter, ApplicationService) werden erst bei Bedarf aus dem Container geholt,
 * damit die Formularseite auch ohne Datenbankverbindung ausgeliefert wird.
 */
final class BewerbungController
{
    public const FORM = 'bewerbung';

    /** Alle POST-Versuche je IP (auch mit Eingabefehlern) */
    public const LIMIT_ATTEMPTS = 10;
    /** Gespeicherte Bewerbungen je IP */
    public const LIMIT_REQUESTS = 5;
    public const LIMIT_WINDOW = 600;

    private const SESSION_DONE = 'bewerbung_abgeschlossen';
    private const SESSION_USED = 'bewerbung_tokens';

    public function __construct(
        private readonly View $view,
        private readonly Config $config,
        private readonly PageMeta $meta,
        private readonly Session $session,
        private readonly Log $log,
        private readonly Container $container,
        private readonly SpamGuard $spam,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $werte = [];
        $stellen = $this->stellen();
        $stelle = $request->queryValue('stelle');
        if ($stelle !== null && ($stellen === [] || in_array($stelle, array_column($stellen, 'slug'), true))) {
            $werte['stelle'] = $stelle;
        }

        return $this->render($request, $werte, [], $this->spam->issueToken(self::FORM));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function submit(Request $request, array $params = []): Response
    {
        $post = $request->post();
        $ip = $request->clientIp(array_values((array) $this->config->get('app.trusted_proxies', [])));
        $tokenPosted = is_string($post[SpamGuard::TOKEN_FIELD] ?? null) ? (string) $post[SpamGuard::TOKEN_FIELD] : '';

        $limiter = $this->limiter();
        if ($limiter !== null) {
            $attempt = $this->safeHit($limiter, 'bewerbung_versuch', $ip, self::LIMIT_ATTEMPTS);
            if ($attempt !== null && !$attempt['allowed']) {
                return $this->tooMany($request, $post, $attempt['retry_after']);
            }
        }

        $spam = $this->spam->check($post, self::FORM, ['nachricht'], ['name']);
        $stellen = $this->stellen();
        $result = (new BewerbungValidator())->validate($post, $stellen);
        // Die Datei wird immer geprüft (auch ohne Datenbankverbindung): UploadValidator braucht keine
        // Datenbank. Ob die Datei am Ende gespeichert werden kann, entscheidet erst store().
        $upload = UploadValidator::validate($this->uploadedFile($request), ApplicationService::ALLOWED_MIME, 'pdf', ApplicationService::MAX_FILE_BYTES);

        if ($spam['status'] === 'spam') {
            if ($result['valid'] && $upload['ok']) {
                $this->store($result['data'], $upload, $spam);
            } else {
                $this->log->info('Bewerbungsformular: Spamverdacht mit ungültigen Daten verworfen', ['grund' => $spam['grund']]);
            }

            return Response::redirect('/karriere/bewerbung/danke/', 303);
        }

        if ($spam['status'] === 'abgelaufen') {
            return $this->render($request, $result['form'], $result['errors'], $this->spam->issueToken(self::FORM), [
                'titel' => 'Das Formular war zu lange geöffnet',
                'text' => 'Aus Sicherheitsgründen konnte die Bewerbung nicht gesendet werden. Ihre Angaben sind erhalten geblieben, die Datei muss erneut ausgewählt werden.',
            ], 422);
        }

        $errors = $result['errors'];
        if (!$upload['ok']) {
            $errors = BewerbungValidator::ordered($errors + ['datei' => (string) $upload['fehler']]);
        }
        if ($errors !== []) {
            return $this->render($request, $result['form'], $errors, $tokenPosted, null, 422);
        }

        $tokenHash = hash('sha256', $tokenPosted);
        $used = (array) $this->session->get(self::SESSION_USED, []);
        if (in_array($tokenHash, $used, true)) {
            return Response::redirect('/karriere/bewerbung/danke/', 303);
        }

        if ($limiter !== null) {
            $requests = $this->safeHit($limiter, 'bewerbung_anfrage', $ip, self::LIMIT_REQUESTS);
            if ($requests !== null && !$requests['allowed']) {
                return $this->tooMany($request, $result['form'], $requests['retry_after']);
            }
        }

        /** @var array{ok: bool, fehler: ?string, tmp_name: string, groesse: int, mime: string} $upload */
        $stored = $this->store($result['data'], $upload, null);
        if ($stored === null) {
            return $this->render($request, $result['form'], [], $tokenPosted, [
                'titel' => 'Ihre Bewerbung konnte nicht gespeichert werden',
                'text' => 'Aus technischen Gründen ist die Übermittlung derzeit nicht möglich. Bitte versuchen Sie es in einigen Minuten erneut oder schreiben Sie uns an ' . (string) $this->config->get('unternehmen.email') . '.',
            ], 503);
        }

        $used[] = $tokenHash;
        $this->session->set(self::SESSION_USED, array_slice($used, -10));
        $this->session->set(self::SESSION_DONE, true);

        return Response::redirect('/karriere/bewerbung/danke/', 303);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function danke(Request $request, array $params = []): Response
    {
        $abschluss = $this->session->get(self::SESSION_DONE) === true;
        if ($abschluss) {
            $this->session->remove(self::SESSION_DONE);
        }

        return $this->view->response('pages/bewerbung-danke.html.twig', [
            'page' => $this->meta->build('bewerbung-danke', $request->path()),
            'abschluss' => $abschluss,
        ]);
    }

    /**
     * @param array<string, mixed>       $werte
     * @param array<string, string>      $fehler
     * @param array{titel: string, text: string}|null $hinweis
     */
    private function render(Request $request, array $werte, array $fehler, string $token, ?array $hinweis = null, int $status = 200): Response
    {
        $fehlerliste = [];
        foreach ($fehler as $feld => $text) {
            $fehlerliste[] = ['id' => BewerbungValidator::fieldId($feld), 'text' => $text];
        }

        return $this->view->response('pages/karriere-bewerbung.html.twig', [
            'page' => $this->meta->build('karriere-bewerbung', '/karriere/bewerbung/'),
            'form' => [
                'aktion' => '/karriere/bewerbung/',
                'werte' => $werte,
                'fehler' => $fehler,
                'fehlerliste' => $fehlerliste,
                'token' => $token,
                'token_feld' => SpamGuard::TOKEN_FIELD,
                'honeypot_feld' => SpamGuard::HONEYPOT_FIELD,
                'stellen' => $this->stellen(),
                'max' => BewerbungValidator::MAX,
                'max_datei_mb' => (int) (ApplicationService::MAX_FILE_BYTES / 1024 / 1024),
                'hinweis' => $hinweis,
                'consent_version' => ApplicationService::CONSENT_TEXT_VERSION,
            ],
        ], $status)->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * @param array<string, mixed> $werte
     */
    private function tooMany(Request $request, array $werte, int $retryAfter): Response
    {
        $this->log->warning('Bewerbungsformular: Rate Limit erreicht');
        $werte = array_map(static fn (mixed $v): mixed => is_string($v) ? mb_substr($v, 0, 3000) : (is_scalar($v) ? $v : null), $werte);
        unset($werte[SpamGuard::TOKEN_FIELD], $werte[SpamGuard::HONEYPOT_FIELD], $werte['_csrf']);

        return $this->render($request, $werte, [], $this->spam->issueToken(self::FORM), [
            'titel' => 'Zu viele Anfragen in kurzer Zeit',
            'text' => 'Bitte warten Sie einige Minuten und senden Sie das Formular dann erneut. Sie erreichen uns auch per E-Mail an ' . (string) $this->config->get('unternehmen.email') . '.',
        ], 429)->withHeader('Retry-After', (string) $retryAfter);
    }

    /**
     * @param array<string, mixed>                                       $data
     * @param array{ok: bool, fehler: ?string, tmp_name: ?string, groesse: int, mime: ?string} $upload
     * @param array{status: string, grund: ?string}|null                 $spam
     * @return array{id: int, uuid: string, status: string}|null
     */
    private function store(array $data, array $upload, ?array $spam): ?array
    {
        try {
            $service = $this->application();
            if ($service === null) {
                return null;
            }

            /** @var array{tmp_name: string, groesse: int, mime: string} $datei */
            $datei = $upload;

            return $service->submit($data, $datei, $spam, Clock::now());
        } catch (Throwable $e) {
            $this->log->error('Bewerbungsformular: Bewerbung konnte nicht gespeichert werden', ['fehler' => get_class($e), 'code' => (string) $e->getCode()]);

            return null;
        }
    }

    private function application(): ?ApplicationService
    {
        try {
            return $this->container->get(ApplicationService::class);
        } catch (Throwable $e) {
            $this->log->error('Bewerbungsformular: Dienst nicht verfügbar', ['fehler' => get_class($e)]);

            return null;
        }
    }

    private function limiter(): ?RateLimiter
    {
        try {
            return $this->container->get(RateLimiter::class);
        } catch (Throwable $e) {
            $this->log->error('Bewerbungsformular: Rate Limiter nicht verfügbar', ['fehler' => get_class($e)]);

            return null;
        }
    }

    /**
     * @return array{allowed: bool, hits: int, limit: int, retry_after: int}|null
     */
    private function safeHit(RateLimiter $limiter, string $bucket, string $ip, int $limit): ?array
    {
        try {
            return $limiter->hit($bucket, $ip, $limit, self::LIMIT_WINDOW);
        } catch (Throwable $e) {
            $this->log->error('Bewerbungsformular: Rate Limit nicht prüfbar', ['fehler' => get_class($e)]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function uploadedFile(Request $request): ?array
    {
        $file = $request->files()['datei'] ?? null;

        return is_array($file) ? $file : null;
    }

    /**
     * @return list<array{slug: string, titel: string}>
     */
    private function stellen(): array
    {
        $stellen = [];
        foreach ((array) $this->config->get('stellen', []) as $stelle) {
            if (is_array($stelle) && isset($stelle['slug'], $stelle['titel'])) {
                $stellen[] = ['slug' => (string) $stelle['slug'], 'titel' => (string) $stelle['titel']];
            }
        }

        return $stellen;
    }
}

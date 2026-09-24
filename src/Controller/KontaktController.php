<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Http\Session;
use Hvm\Security\RateLimiter;
use Hvm\Security\SpamGuard;
use Hvm\Service\Attribution;
use Hvm\Service\ContactService;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Container;
use Hvm\Support\Log;
use Hvm\Validation\KontaktValidator;
use Hvm\View\PageMeta;
use Hvm\View\View;
use Throwable;

/**
 * Allgemeines Kontaktformular (MP 6.3): Anzeige, Verarbeitung mit PRG, Danke-Seite.
 *
 * Die Seite pages/kontakt.html.twig (Hauptsitz, Anfahrt, sonstiger Inhalt) gestaltet ein anderer
 * Agent; die Variable "form" (siehe render()) steht ihr aus dieser Klasse zur Verfügung und wird von
 * templates/partials/forms/kontakt-form.html.twig ausgewertet, das per include eigenständig funktioniert
 * (kein Aufrufer muss zusätzliche Variablen übergeben).
 *
 * Datenbankdienste (Rate Limiter, ContactService) werden erst bei Bedarf aus dem Container geholt,
 * damit die Formularseite auch ohne Datenbankverbindung ausgeliefert wird.
 */
final class KontaktController
{
    public const FORM = 'kontakt';

    /** Alle POST-Versuche je IP (auch mit Eingabefehlern) */
    public const LIMIT_ATTEMPTS = 10;
    /** Gespeicherte Anfragen je IP */
    public const LIMIT_REQUESTS = 5;
    public const LIMIT_WINDOW = 600;

    private const SESSION_DONE = 'kontakt_abgeschlossen';
    private const SESSION_USED = 'kontakt_tokens';
    private const HIDDEN = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'landing_page', 'referrer', 'region'];

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
        $query = $request->query();
        $werte = [];
        $anliegen = $request->queryValue('anliegen');
        if ($anliegen !== null && isset(KontaktValidator::ANLIEGEN[$anliegen])) {
            $werte['anliegen'] = $anliegen;
        }

        $hidden = Attribution::utm($query);
        $hidden['landing_page'] = Attribution::internalPath($query[Attribution::PARAM_LANDING] ?? null) ?? $request->path();
        $hidden['referrer'] = Attribution::referrerForStorage($request->header('Referer'), $this->ownHosts($request));
        $hidden['region'] = $this->region($request->queryValue('region'));

        return $this->render($request, $werte, [], $hidden, $this->spam->issueToken(self::FORM));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function submit(Request $request, array $params = []): Response
    {
        $post = $request->post();
        $ip = $request->clientIp(array_values((array) $this->config->get('app.trusted_proxies', [])));
        $hidden = $this->hiddenFromPost($post, $request);
        $tokenPosted = is_string($post[SpamGuard::TOKEN_FIELD] ?? null) ? (string) $post[SpamGuard::TOKEN_FIELD] : '';

        $limiter = $this->limiter();
        if ($limiter !== null) {
            $attempt = $this->safeHit($limiter, 'kontakt_versuch', $ip, self::LIMIT_ATTEMPTS);
            if ($attempt !== null && !$attempt['allowed']) {
                return $this->tooMany($request, $post, $hidden, $attempt['retry_after']);
            }
        }

        $spam = $this->spam->check($post, self::FORM, ['nachricht'], ['name']);
        $result = (new KontaktValidator())->validate($post);

        if ($spam['status'] === 'spam') {
            if ($result['valid']) {
                $this->store($result['data'], $hidden, $spam);
            } else {
                $this->log->info('Kontaktformular: Spamverdacht mit ungültigen Daten verworfen', ['grund' => $spam['grund']]);
            }

            return Response::redirect('/kontakt/danke/', 303);
        }

        if ($spam['status'] === 'abgelaufen') {
            return $this->render($request, $result['form'], $result['errors'], $hidden, $this->spam->issueToken(self::FORM), [
                'titel' => 'Das Formular war zu lange geöffnet',
                'text' => 'Aus Sicherheitsgründen konnte die Nachricht nicht gesendet werden. Ihre Angaben sind erhalten geblieben. Bitte prüfen Sie sie und senden Sie das Formular erneut.',
            ], 422);
        }

        if (!$result['valid']) {
            return $this->render($request, $result['form'], $result['errors'], $hidden, $tokenPosted, null, 422);
        }

        $tokenHash = hash('sha256', $tokenPosted);
        $used = (array) $this->session->get(self::SESSION_USED, []);
        if (in_array($tokenHash, $used, true)) {
            return Response::redirect('/kontakt/danke/', 303);
        }

        if ($limiter !== null) {
            $requests = $this->safeHit($limiter, 'kontakt_anfrage', $ip, self::LIMIT_REQUESTS);
            if ($requests !== null && !$requests['allowed']) {
                return $this->tooMany($request, $result['form'], $hidden, $requests['retry_after']);
            }
        }

        $stored = $this->store($result['data'], $hidden, null);
        if ($stored === null) {
            return $this->render($request, $result['form'], [], $hidden, $tokenPosted, [
                'titel' => 'Ihre Nachricht konnte nicht gespeichert werden',
                'text' => 'Aus technischen Gründen ist die Übermittlung derzeit nicht möglich. Bitte versuchen Sie es in einigen Minuten erneut oder schreiben Sie uns an ' . (string) $this->config->get('unternehmen.email') . '.',
            ], 503);
        }

        $used[] = $tokenHash;
        $this->session->set(self::SESSION_USED, array_slice($used, -10));
        $this->session->set(self::SESSION_DONE, ['anliegen' => (string) $result['data']['anliegen']]);

        return Response::redirect('/kontakt/danke/', 303);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function danke(Request $request, array $params = []): Response
    {
        $abschluss = $this->session->get(self::SESSION_DONE);
        if ($abschluss !== null) {
            $this->session->remove(self::SESSION_DONE);
        }
        $abschluss = is_array($abschluss) ? $abschluss : null;

        return $this->view->response('pages/kontakt-danke.html.twig', [
            'page' => $this->meta->build('kontakt-danke', $request->path()),
            'abschluss' => $abschluss === null ? null : [
                'anliegen_label' => KontaktValidator::ANLIEGEN[(string) ($abschluss['anliegen'] ?? '')] ?? null,
            ],
        ]);
    }

    /**
     * @param array<string, mixed>       $werte
     * @param array<string, string>      $fehler
     * @param array<string, ?string>     $hidden
     * @param array{titel: string, text: string}|null $hinweis
     */
    private function render(Request $request, array $werte, array $fehler, array $hidden, string $token, ?array $hinweis = null, int $status = 200): Response
    {
        $fehlerliste = [];
        foreach ($fehler as $feld => $text) {
            $fehlerliste[] = ['id' => KontaktValidator::fieldId($feld), 'text' => $text];
        }

        return $this->view->response('pages/kontakt.html.twig', [
            'page' => $this->meta->build('kontakt', '/kontakt/'),
            'form' => [
                'aktion' => '/kontakt/',
                'werte' => $werte,
                'fehler' => $fehler,
                'fehlerliste' => $fehlerliste,
                'token' => $token,
                'token_feld' => SpamGuard::TOKEN_FIELD,
                'honeypot_feld' => SpamGuard::HONEYPOT_FIELD,
                'hidden' => array_filter($hidden, static fn (?string $v): bool => $v !== null && $v !== ''),
                'anliegen' => self::options(KontaktValidator::ANLIEGEN),
                'max' => KontaktValidator::MAX,
                'hinweis' => $hinweis,
                'consent_version' => ContactService::CONSENT_TEXT_VERSION,
            ],
        ], $status)->withHeader('Cache-Control', 'no-store, private');
    }

    /**
     * @param array<string, mixed>   $werte
     * @param array<string, ?string> $hidden
     */
    private function tooMany(Request $request, array $werte, array $hidden, int $retryAfter): Response
    {
        $this->log->warning('Kontaktformular: Rate Limit erreicht');
        $werte = array_map(static fn (mixed $v): mixed => is_string($v) ? mb_substr($v, 0, 3000) : (is_scalar($v) ? $v : null), $werte);
        unset($werte[SpamGuard::TOKEN_FIELD], $werte[SpamGuard::HONEYPOT_FIELD], $werte['_csrf']);

        return $this->render($request, $werte, [], $hidden, $this->spam->issueToken(self::FORM), [
            'titel' => 'Zu viele Anfragen in kurzer Zeit',
            'text' => 'Bitte warten Sie einige Minuten und senden Sie das Formular dann erneut. Sie erreichen uns auch per E-Mail an ' . (string) $this->config->get('unternehmen.email') . '.',
        ], 429)->withHeader('Retry-After', (string) $retryAfter);
    }

    /**
     * @param array<string, mixed>   $data
     * @param array<string, ?string> $hidden
     * @param array{status: string, grund: ?string}|null $spam
     * @return array{id: int, uuid: string, status: string}|null
     */
    private function store(array $data, array $hidden, ?array $spam): ?array
    {
        $utm = array_filter(array_intersect_key($hidden, array_flip(Attribution::UTM_KEYS)), static fn (?string $v): bool => $v !== null);
        $attribution = $utm + [
            'source' => Attribution::deriveSource($utm, null, $hidden['referrer'] ?? null),
            'landing_page' => $hidden['landing_page'] ?? null,
            'referrer' => $hidden['referrer'] ?? null,
            'region' => $hidden['region'] ?? null,
        ];
        try {
            /** @var ContactService $service */
            $service = $this->container->get(ContactService::class);

            return $service->submit($data, $attribution, $spam, Clock::now());
        } catch (Throwable $e) {
            $this->log->error('Kontaktformular: Anfrage konnte nicht gespeichert werden', ['fehler' => get_class($e), 'code' => (string) $e->getCode()]);

            return null;
        }
    }

    private function limiter(): ?RateLimiter
    {
        try {
            return $this->container->get(RateLimiter::class);
        } catch (Throwable $e) {
            $this->log->error('Kontaktformular: Rate Limiter nicht verfügbar', ['fehler' => get_class($e)]);

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
            $this->log->error('Kontaktformular: Rate Limit nicht prüfbar', ['fehler' => get_class($e)]);

            return null;
        }
    }

    /**
     * Versteckte Attributionsfelder aus dem POST, erneut bereinigt (vom Client veränderbar).
     *
     * @param array<string, mixed> $post
     * @return array<string, ?string>
     */
    private function hiddenFromPost(array $post, Request $request): array
    {
        $hidden = Attribution::utm($post);
        $hidden['landing_page'] = Attribution::internalPath($post['landing_page'] ?? null);
        $referrer = $post['referrer'] ?? null;
        $hidden['referrer'] = is_string($referrer)
            ? Attribution::referrerForStorage('https://' . ltrim($referrer, '/'), $this->ownHosts($request))
            : null;
        $hidden['region'] = $this->region(is_string($post['region'] ?? null) ? (string) $post['region'] : null);

        return array_intersect_key($hidden, array_flip(self::HIDDEN));
    }

    /**
     * Betreuungsgebiet aus der Vorbelegung: Stadt aus config/staedte.php (Slug oder Name, etwa von den
     * Stadtseiten /angebot/?region=Köln) oder schlichter Ortsname.
     */
    private function region(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 60) {
            return null;
        }
        foreach ((array) $this->config->get('staedte', []) as $stadt) {
            if (!is_array($stadt)) {
                continue;
            }
            if (($stadt['slug'] ?? null) === $value || mb_strtolower((string) ($stadt['name'] ?? '')) === mb_strtolower($value)) {
                return (string) $stadt['name'];
            }
        }

        return preg_match('~^[\p{L}][\p{L} ./\-]{1,59}$~u', $value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function ownHosts(Request $request): array
    {
        $hosts = [];
        $appHost = parse_url((string) $this->config->get('app.url', ''), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = $appHost;
        }
        $host = $request->header('Host');
        if ($host !== null && $host !== '') {
            $hosts[] = $host;
        }

        return $hosts;
    }

    /**
     * @param array<string, string> $map
     * @return list<array{wert: string, label: string}>
     */
    private static function options(array $map): array
    {
        $result = [];
        foreach ($map as $wert => $label) {
            $result[] = ['wert' => $wert, 'label' => $label];
        }

        return $result;
    }
}

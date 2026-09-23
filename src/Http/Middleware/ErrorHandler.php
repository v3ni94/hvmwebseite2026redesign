<?php

declare(strict_types=1);

namespace Hvm\Http\Middleware;

use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Support\Log;
use Throwable;

/**
 * Äußerste Middleware: fängt alle Ausnahmen, protokolliert ohne personenbezogene Daten
 * und liefert eine generische 500-Seite. In Produktion niemals Details.
 */
final class ErrorHandler implements Middleware
{
    /** @var (callable(Throwable, Request): ?string)|null */
    private $renderer;

    /** @var (callable(Response, Request): Response)|null */
    private $decorator;

    /**
     * @param (callable(Throwable, Request): ?string)|null  $renderer  rendert die 500-Seite (z. B. per Twig), null bei Fehlschlag
     * @param (callable(Response, Request): Response)|null  $decorator ergänzt z. B. Sicherheitsheader
     */
    public function __construct(
        private readonly Log $log,
        private readonly bool $production,
        ?callable $renderer = null,
        ?callable $decorator = null,
    ) {
        $this->renderer = $renderer;
        $this->decorator = $decorator;
        if ($production) {
            self::enforceProductionIni();
        }
    }

    public static function enforceProductionIni(): void
    {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        ini_set('html_errors', '0');
        ini_set('expose_php', '0');
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (Throwable $e) {
            return $this->handle($e, $request);
        }
    }

    public function handle(Throwable $e, Request $request): Response
    {
        $this->log->error('Unbehandelte Ausnahme', [
            'klasse' => $e::class,
            'meldung' => $e->getMessage(),
            'ort' => basename($e->getFile()) . ':' . $e->getLine(),
            'methode' => $request->method(),
            'pfad' => $request->path(),
        ]);

        $body = null;
        if ($this->production) {
            if ($this->renderer !== null) {
                try {
                    $body = ($this->renderer)($e, $request);
                } catch (Throwable) {
                    $body = null;
                }
            }
            $body ??= self::genericPage();
        } else {
            $body = self::debugPage($e);
        }

        $response = Response::html($body, 500)->withHeader('Cache-Control', 'no-store');
        if ($this->decorator !== null) {
            $response = ($this->decorator)($response, $request);
        }

        return $response;
    }

    public static function genericPage(): string
    {
        return '<!doctype html><html lang="de"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>Vorübergehende Störung | Hausverwaltung Müller GmbH</title></head><body>'
            . '<main><h1>Vorübergehende Störung</h1>'
            . '<p>Die Seite kann derzeit nicht angezeigt werden. Bitte versuchen Sie es in einigen Minuten erneut.</p>'
            . '<p><a href="/">Zur Startseite</a></p></main></body></html>';
    }

    private static function debugPage(Throwable $e): string
    {
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Fehler (Entwicklung)</title></head><body>'
            . '<h1>' . $h($e::class) . '</h1>'
            . '<p>' . $h($e->getMessage()) . '</p>'
            . '<p>' . $h($e->getFile() . ':' . $e->getLine()) . '</p>'
            . '<pre>' . $h($e->getTraceAsString()) . '</pre>'
            . '<p>Diese Ansicht erscheint nur außerhalb der Produktion.</p></body></html>';
    }
}

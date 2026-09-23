<?php

declare(strict_types=1);

namespace Hvm\Http;

use Hvm\Controller\ErrorController;
use Hvm\Http\Middleware\Csrf;
use Hvm\Http\Middleware\ErrorHandler;
use Hvm\Http\Middleware\LegacyRedirects;
use Hvm\Http\Middleware\Middleware;
use Hvm\Http\Middleware\SecurityHeaders;
use Hvm\Http\Middleware\Session as SessionMiddleware;
use Hvm\Http\Middleware\TrailingSlash;
use Hvm\Security\SpamGuard;
use Hvm\Support\Config;
use Hvm\Support\Container;
use Hvm\Support\Db;
use Hvm\Support\Env;
use Hvm\Support\Log;
use Hvm\View\View;
use PDO;
use Throwable;

/**
 * Anwendungskern: lädt Konfiguration, verdrahtet Dienste und führt die Middleware-Kette aus.
 *
 * Reihenfolge laut docs/architektur.md Abschnitt 3:
 * ErrorHandler, SecurityHeaders, LegacyRedirects, TrailingSlash, Session, Csrf, Router.
 */
final class Kernel
{
    private ?Request $globalRequest = null;

    private function __construct(
        private readonly string $basePath,
        private readonly Config $config,
        private readonly Container $container,
    ) {
    }

    public static function fromGlobals(?string $basePath = null): self
    {
        $basePath ??= dirname(__DIR__, 2);
        Env::load($basePath . '/.env');
        $kernel = self::boot($basePath);

        if ($kernel->isProduction()) {
            ErrorHandler::enforceProductionIni();
            $kernel->assertProductionSecrets();
        }
        $kernel->globalRequest = Request::fromGlobals();

        return $kernel;
    }

    /**
     * Erzeugt einen Kernel ohne .env-Datei, mit festen Umgebungswerten (Tests, Werkzeuge).
     *
     * @param array<string, string|null> $env
     */
    public static function create(string $basePath, array $env = []): self
    {
        Env::reset();
        foreach ($env as $key => $value) {
            Env::set($key, $value);
        }

        return self::boot($basePath);
    }

    private static function boot(string $basePath): self
    {
        date_default_timezone_set('Europe/Berlin');
        mb_internal_encoding('UTF-8');

        $config = Config::fromDirectory($basePath . '/config');
        $config->set('app.base_path', $basePath);
        $container = new Container();
        $kernel = new self($basePath, $config, $container);
        $kernel->registerServices();

        return $kernel;
    }

    private function registerServices(): void
    {
        $c = $this->container;
        $config = $this->config;
        $base = $this->basePath;

        $c->instance(Config::class, $config);
        $c->instance(Container::class, $c);
        $c->instance(self::class, $this);
        $c->set(RequestContext::class, static fn (): RequestContext => new RequestContext());
        $c->set(Log::class, static fn (): Log => new Log(
            $base . '/storage/logs',
            'app.log',
            $config->get('app.env') === 'production' ? 'info' : 'debug'
        ));
        $c->set(Session::class, static fn (): Session => new Session([
            'name' => (string) $config->get('app.session.name', 'hvm_sid'),
            'secure' => (bool) $config->get('app.session.secure', true),
            'idle_timeout' => (int) $config->get('app.session.idle_timeout', 1800),
            'driver' => (string) $config->get('app.session.driver', 'native'),
        ]));
        $c->set(Csrf::class, static fn (Container $c): Csrf => new Csrf(
            $c->get(Session::class),
            array_values((array) $config->get('app.csrf_exempt', []))
        ));
        $c->set(Router::class, static fn (): Router => Router::fromArray(
            $config->array('routes'),
            (string) $config->get('app.env', 'production')
        ));
        $c->set(View::class, static fn (Container $c): View => new View(
            $config,
            $c->get(RequestContext::class),
            $c->get(Csrf::class),
            $base . '/templates',
            $base . '/storage/cache/twig',
            $base . '/public/assets/build/manifest.json'
        ));
        $c->set(PDO::class, static fn (): PDO => Db::fromConfig($config));
    }

    /**
     * Produktion startet nicht ohne gültigen APP_KEY (Web und bin/-Skripte über fromGlobals()).
     * Ohne Schlüssel würden Uploads, TOTP-Geheimnisse und Signaturen mit einem öffentlich
     * ableitbaren Ersatzschlüssel geschützt.
     */
    public function assertProductionSecrets(): void
    {
        if ($this->isProduction() && !SpamGuard::hasAppKey($this->config)) {
            throw new \RuntimeException('APP_KEY fehlt oder ist ungültig (mindestens 32 Byte, Format base64:...). Start in Produktion abgebrochen.');
        }
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function isProduction(): bool
    {
        return $this->config->get('app.env') === 'production';
    }

    public function handle(?Request $request = null): Response
    {
        $request ??= $this->globalRequest ?? Request::fromGlobals();

        /** @var RequestContext $context */
        $context = $this->container->get(RequestContext::class);
        $context->begin($request);

        $response = $this->pipeline($this->middleware(), fn (Request $r): Response => $this->dispatch($r))($request);

        if ($request->isMethod('HEAD')) {
            $response = $response->withBody('');
        }

        return $response;
    }

    /**
     * @return list<Middleware>
     */
    private function middleware(): array
    {
        $c = $this->container;
        $env = (string) $this->config->get('app.env', 'production');
        $security = new SecurityHeaders($c->get(RequestContext::class), $env);

        return [
            new ErrorHandler(
                $c->get(Log::class),
                // Fehlerdetails nur lokal (development). Staging ist öffentlich erreichbar und zeigt die generische Seite.
                $env !== 'development',
                function (Throwable $e, Request $request): ?string {
                    return $this->container->get(ErrorController::class)->renderServerError($request);
                },
                static fn (Response $response, Request $request): Response => $security->apply($response, $request),
            ),
            $security,
            new LegacyRedirects(array_values($this->config->array('redirects'))),
            new TrailingSlash(),
            new SessionMiddleware(
                $c->get(Session::class),
                array_values((array) $this->config->get('app.session.paths', []))
            ),
            $c->get(Csrf::class),
        ];
    }

    /**
     * @param list<Middleware>            $middleware
     * @param callable(Request): Response $core
     * @return callable(Request): Response
     */
    private function pipeline(array $middleware, callable $core): callable
    {
        $next = $core;
        foreach (array_reverse($middleware) as $layer) {
            $next = static fn (Request $request): Response => $layer->process($request, $next);
        }

        return $next;
    }

    private function dispatch(Request $request): Response
    {
        /** @var RequestContext $context */
        $context = $this->container->get(RequestContext::class);
        $context->setRequest($request);

        /** @var Router $router */
        $router = $this->container->get(Router::class);
        $match = $router->match($request->method(), $request->path());

        if ($match->status === RouteMatch::METHOD_NOT_ALLOWED) {
            return Response::text('Methode nicht erlaubt.', 405)->withHeader('Allow', implode(', ', $match->allowedMethods));
        }
        if (!$match->isFound() || $match->handler === null) {
            return $this->container->get(ErrorController::class)->notFound($request);
        }

        [$class, $method] = $match->handler;
        $controller = $this->container->get($class);
        $request = $request->withAttribute('route', $match->params);
        $context->setRequest($request);

        $response = $controller->{$method}($request, $match->params);
        if (!$response instanceof Response) {
            throw new \LogicException(sprintf('%s::%s muss eine Response liefern.', $class, $method));
        }

        return $response;
    }
}

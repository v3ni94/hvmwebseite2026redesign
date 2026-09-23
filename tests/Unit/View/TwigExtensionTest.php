<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\View;

use Hvm\Http\Middleware\Csrf;
use Hvm\Http\RequestContext;
use Hvm\Http\Session;
use Hvm\View\TwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TwigExtensionTest extends TestCase
{
    private function render(string $template, array $vars = [], ?string $manifest = null): string
    {
        $context = new RequestContext();
        $csrf = new Csrf(new Session(['driver' => 'array']));
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addExtension(new TwigExtension($context, $csrf, $manifest ?? '/pfad/fehlt/manifest.json', false));

        return $twig->render('t', $vars);
    }

    public function testPlaceholderIsEscaped(): void
    {
        self::assertSame(
            '<span class="placeholder">[Telefonnummer bestätigen]</span>',
            $this->render("{{ placeholder('Telefonnummer bestätigen') }}")
        );
        self::assertSame(
            '<span class="placeholder">[&lt;script&gt;alert(1)&lt;/script&gt; &quot;x&quot;]</span>',
            $this->render('{{ placeholder(text) }}', ['text' => '<script>alert(1)</script> "x"'])
        );
    }

    public function testDatum(): void
    {
        self::assertSame('01.07.2026', TwigExtension::datum('2026-07-01'));
        self::assertSame('04.03.2020', TwigExtension::datum(new \DateTimeImmutable('2020-03-04 12:00:00', new \DateTimeZone('Europe/Berlin'))));
        // UTC-Zeitstempel kurz vor Mitternacht ist in Berlin bereits der Folgetag
        self::assertSame('02.07.2026', TwigExtension::datum('2026-07-01 23:30:00'));
        self::assertSame('', TwigExtension::datum(null));
        self::assertSame('', TwigExtension::datum('kein datum'));
        self::assertSame('01.07.2026', $this->render("{{ '2026-07-01'|datum }}"));
        self::assertSame('01.07.2026', $this->render("{{ datum('2026-07-01') }}"));
    }

    public function testBetrag(): void
    {
        self::assertSame('1.234,56 EUR', TwigExtension::betrag(1234.56));
        self::assertSame('49.000,00 EUR', TwigExtension::betrag('49000'));
        self::assertSame('0,50 EUR', TwigExtension::betrag(0.5));
        self::assertSame('-12,00 EUR', TwigExtension::betrag(-12));
        self::assertSame('', TwigExtension::betrag(null));
        self::assertSame('', TwigExtension::betrag('abc'));
        self::assertSame('1.234,56 EUR', $this->render('{{ 1234.56|betrag }}'));
    }

    public function testTelHref(): void
    {
        self::assertSame('tel:+4930123456789', TwigExtension::telHref('030 123456789'));
        self::assertSame('tel:+4930123456789', TwigExtension::telHref('+49 (0) 30 / 123 456 789'));
        self::assertSame('tel:+4930123456789', TwigExtension::telHref('0049 30 123456789'));
        self::assertSame('', TwigExtension::telHref(null));
        self::assertSame('', TwigExtension::telHref('  '));
    }

    public function testJsonLdCannotBreakOutOfScript(): void
    {
        $json = TwigExtension::jsonLd(['name' => '</script><script>alert(1)</script>', 'x' => "a&b'c\"d", 'u' => 'Müller']);

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString('&', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertStringContainsString(chr(92) . 'u003C/script' . chr(92) . 'u003E', $json);
        self::assertStringContainsString('Müller', $json);
        self::assertSame(['name' => '</script><script>alert(1)</script>', 'x' => "a&b'c\"d", 'u' => 'Müller'], json_decode($json, true));

        $html = $this->render('<script type="application/ld+json">{{ json_ld(data) }}</script>', ['data' => ['a' => '</script>']]);
        self::assertSame(1, substr_count($html, '</script>'));
    }

    public function testJsonLdEscapesLineSeparators(): void
    {
        self::assertStringContainsString(chr(92) . 'u2028', TwigExtension::jsonLd(['a' . "\u{2028}" . 'b']));
    }

    public function testAssetUsesManifest(): void
    {
        $manifest = tempnam(sys_get_temp_dir(), 'manifest');
        self::assertIsString($manifest);
        file_put_contents($manifest, json_encode(['app.css' => 'app.abc123.css', 'app.js' => 'js/abc/app.js']));
        try {
            self::assertSame('/assets/build/app.abc123.css', $this->render("{{ asset('app.css') }}", [], $manifest));
            self::assertSame('/assets/build/js/abc/app.js', $this->render("{{ asset('app.js') }}", [], $manifest));
            self::assertSame('/assets/build/fehlt.css', $this->render("{{ asset('fehlt.css') }}", [], $manifest));
        } finally {
            unlink($manifest);
        }
    }

    public function testCsrfFieldAndNonce(): void
    {
        $html = $this->render('{{ csrf_field() }}|{{ csrf_token() }}|{{ csp_nonce() }}');
        [$field, $token, $nonce] = explode('|', $html);
        self::assertSame('<input type="hidden" name="_csrf" value="' . $token . '">', $field);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertNotSame('', $nonce);
    }
}

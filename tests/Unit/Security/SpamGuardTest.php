<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Security;

use Hvm\Security\SpamGuard;
use Hvm\Support\Config;
use PHPUnit\Framework\TestCase;

final class SpamGuardTest extends TestCase
{
    private function guard(string $key = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='): SpamGuard
    {
        return new SpamGuard(new Config(['app' => ['key' => $key, 'url' => 'https://www.example.org']]));
    }

    /**
     * @return array<string, string>
     */
    private function post(string $token): array
    {
        return [SpamGuard::TOKEN_FIELD => $token, SpamGuard::HONEYPOT_FIELD => '', 'nachricht' => 'Hallo'];
    }

    public function testValidSubmissionPasses(): void
    {
        $guard = $this->guard();
        $token = $guard->issueToken('angebot', 1_790_000_000);
        self::assertSame(['status' => 'ok', 'grund' => null], $guard->check($this->post($token), 'angebot', ['nachricht'], [], 1_790_000_010));
    }

    public function testTooFastIsSpam(): void
    {
        $guard = $this->guard();
        $token = $guard->issueToken('angebot', 1_790_000_000);
        $result = $guard->check($this->post($token), 'angebot', [], [], 1_790_000_000 + SpamGuard::MIN_SECONDS - 1);
        self::assertSame('spam', $result['status']);
        self::assertSame('zeitfalle_schnell', $result['grund']);
    }

    public function testMinimumTimeIsAccepted(): void
    {
        $guard = $this->guard();
        $token = $guard->issueToken('angebot', 1_790_000_000);
        self::assertSame('ok', $guard->check($this->post($token), 'angebot', [], [], 1_790_000_000 + SpamGuard::MIN_SECONDS)['status']);
    }

    public function testOlderThanMaxAgeIsExpired(): void
    {
        $guard = $this->guard();
        $token = $guard->issueToken('angebot', 1_790_000_000);
        $result = $guard->check($this->post($token), 'angebot', [], [], 1_790_000_000 + SpamGuard::MAX_AGE + 1);
        self::assertSame('abgelaufen', $result['status']);
    }

    public function testTamperedTokenIsSpam(): void
    {
        $guard = $this->guard();
        $token = $guard->issueToken('angebot', 1_790_000_000);
        $forged = '1789999000.' . explode('.', $token)[1];
        self::assertSame('zeitfalle_signatur', $guard->check($this->post($forged), 'angebot', [], [], 1_790_000_010)['grund']);
        self::assertSame('zeitfalle_signatur', $guard->check($this->post(''), 'angebot', [], [], 1_790_000_010)['grund']);
        self::assertSame('zeitfalle_signatur', $guard->check([SpamGuard::HONEYPOT_FIELD => ''], 'angebot', [], [], 1_790_000_010)['grund']);
    }

    public function testTokenIsBoundToFormAndKey(): void
    {
        $token = $this->guard()->issueToken('kontakt', 1_790_000_000);
        self::assertNull($this->guard()->tokenTime($token, 'angebot'));
        self::assertNull($this->guard('base64:' . base64_encode(str_repeat('x', 32)))->tokenTime($token, 'kontakt'));
        self::assertSame(1_790_000_000, $this->guard()->tokenTime($token, 'kontakt'));
    }

    public function testHoneypotIsSpam(): void
    {
        $guard = $this->guard();
        $post = $this->post($guard->issueToken('angebot', 1_790_000_000));
        $post[SpamGuard::HONEYPOT_FIELD] = 'https://spam.example.org';
        self::assertSame(['status' => 'spam', 'grund' => 'honeypot'], $guard->check($post, 'angebot', [], [], 1_790_000_010));

        $post[SpamGuard::HONEYPOT_FIELD] = ['array'];
        self::assertSame('honeypot', $guard->check($post, 'angebot', [], [], 1_790_000_010)['grund']);
    }

    public function testLinkLimits(): void
    {
        $guard = $this->guard();
        $post = $this->post($guard->issueToken('angebot', 1_790_000_000));
        $post['nachricht'] = 'Siehe https://a.example.org und www.b.example.org';
        self::assertSame('ok', $guard->check($post, 'angebot', ['nachricht'], ['nachname'], 1_790_000_010)['status']);

        $post['nachricht'] = 'https://a.example.org https://b.example.org https://c.example.org';
        self::assertSame('links_im_freitext', $guard->check($post, 'angebot', ['nachricht'], [], 1_790_000_010)['grund']);

        $post['nachricht'] = 'ok';
        $post['nachname'] = 'Kaufen www.spam.example.org';
        self::assertSame('link_in_feld', $guard->check($post, 'angebot', ['nachricht'], ['nachname'], 1_790_000_010)['grund']);
    }

    public function testWorksWithoutAppKeyButReportsIt(): void
    {
        $guard = new SpamGuard(new Config(['app' => ['key' => null, 'url' => 'https://www.example.org']]));
        self::assertFalse($guard->isConfigured());
        $token = $guard->issueToken('angebot', 1_790_000_000);
        self::assertSame(1_790_000_000, $guard->tokenTime($token, 'angebot'));
        self::assertTrue($this->guard()->isConfigured());
    }
}

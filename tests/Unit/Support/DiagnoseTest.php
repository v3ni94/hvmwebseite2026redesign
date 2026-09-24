<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Support;

use Hvm\Support\Diagnose;
use Hvm\Tests\Unit\TestCase;

final class DiagnoseTest extends TestCase
{
    public function testReportsWithoutSecrets(): void
    {
        $werte = [
            'APP_ENV' => 'staging',
            'APP_URL' => 'https://neu.example.org',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('g', 32)),
            'SETUP_TOKEN' => 'fiktiver-token-der-nie-ausgegeben-wird-0123',
            'STAGING_BASIC_AUTH' => 'pruefer:' . password_hash('geheim-geheim', PASSWORD_BCRYPT, ['cost' => 4]),
            'DB_PASSWORD' => 'fiktives-db-passwort',
            'OUTBOX_MODE' => 'inline',
            'STORAGE_MODE' => 'datenbank',
        ];
        $diagnose = (new Diagnose(self::basePath(), static fn (string $k): ?string => $werte[$k] ?? null))->run();
        $text = json_encode($diagnose->ergebnisse(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::assertIsString($text);
        foreach (['APP_KEY', 'SETUP_TOKEN', 'DB_PASSWORD'] as $geheim) {
            self::assertStringNotContainsString($werte[$geheim], $text, $geheim);
        }
        self::assertStringNotContainsString(explode(':', $werte['STAGING_BASIC_AUTH'], 2)[1], $text);
        self::assertStringContainsString('APP_KEY gültig', $text);
        self::assertStringContainsString('OUTBOX_MODE (inline)', $text);
        self::assertStringContainsString('STAGING_BASIC_AUTH gesetzt', $text);
        // ohne DB_HOST: Fehler mit Lösungshinweis
        self::assertStringContainsString('DB_HOST gesetzt', $text);
        self::assertGreaterThan(0, $diagnose->fehlerAnzahl());
    }

    public function testInvalidAppKeyIsError(): void
    {
        $diagnose = (new Diagnose(self::basePath(), static fn (string $k): ?string => $k === 'APP_KEY' ? 'base64:kurz' : null))->run();
        $zeile = array_values(array_filter($diagnose->ergebnisse(), static fn (array $e): bool => str_starts_with($e['text'], 'APP_KEY')))[0];
        self::assertSame(Diagnose::FEHLER, $zeile['status']);
    }

    public function testFileModeChecksWebhookMailAndOutboxInsteadOfDatabase(): void
    {
        $werte = [
            'APP_ENV' => 'staging',
            'APP_KEY' => 'base64:' . base64_encode(str_repeat('g', 32)),
            'N8N_WEBHOOK_URL' => 'http://n8n.example.org/webhook/anfragen',
            'N8N_WEBHOOK_SECRET' => str_repeat('s', 48),
            'MAIL_HOST' => 'smtp.example.org',
        ];
        $diagnose = (new Diagnose(self::basePath(), static fn (string $k): ?string => $werte[$k] ?? null))->run();
        $zeilen = [];
        foreach ($diagnose->ergebnisse() as $e) {
            $zeilen[$e['text']] = $e['status'];
        }
        $text = implode("\n", array_keys($zeilen));
        self::assertStringNotContainsString('DB_HOST', $text);
        self::assertStringNotContainsString(str_repeat('s', 48), $text);
        self::assertSame(Diagnose::OK, $zeilen['storage/outbox beschreibbar']);
        self::assertSame(Diagnose::FEHLER, $zeilen['N8N_WEBHOOK_URL ohne https oder ungültig']);
        self::assertSame(Diagnose::OK, $zeilen['N8N_WEBHOOK_SECRET gesetzt (mindestens 32 Zeichen)']);
        self::assertSame(Diagnose::FEHLER, $zeilen['SMTP konfiguriert (fehlt: MAIL_FROM)']);
        self::assertSame(Diagnose::FEHLER, $zeilen['LEAD_NOTIFY_TO gesetzt']);
    }
}

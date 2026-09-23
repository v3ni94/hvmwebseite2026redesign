<?php

declare(strict_types=1);

namespace Hvm\Support;

/**
 * Schlichter Datei-Logger (storage/logs/app.log).
 *
 * Nachrichten und Kontext werden vor dem Schreiben um E-Mail-Adressen und Telefonnummern bereinigt.
 * Namen und Anschriften sind technisch nicht zuverlässig erkennbar: Aufrufer übergeben nie
 * personenbezogene Daten, Leads werden nur über ihre UUID referenziert.
 */
final class Log
{
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    public function __construct(
        private readonly string $directory,
        private readonly string $file = 'app.log',
        private readonly string $minLevel = 'info',
    ) {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('critical', $message, $context);
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $level = in_array($level, self::LEVELS, true) ? $level : 'info';
        if (array_search($level, self::LEVELS, true) < array_search($this->minLevel, self::LEVELS, true)) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            Clock::now()->format('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            self::redact($message),
            $context === [] ? '' : ' ' . self::redact((string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR))
        );

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
        @file_put_contents(rtrim($this->directory, '/') . '/' . $this->file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Entfernt E-Mail-Adressen und Telefonnummern (mindestens sieben Ziffern, übliche Trennzeichen).
     * ISO-Datumsangaben und UUIDs bleiben erhalten.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email entfernt]', $text);

        return (string) preg_replace_callback(
            '/(?<![\w\-.:])\+?\(?\d[\d\s\/().\-]{5,}\d(?![\w\-])/u',
            static function (array $m): string {
                $candidate = $m[0];
                if (preg_match('/^\d{4}-\d{2}-\d{2}/', $candidate)) {
                    return $candidate;
                }
                if (preg_match_all('/\d/', $candidate) < 7) {
                    return $candidate;
                }

                return '[telefon entfernt]';
            },
            $text
        );
    }
}

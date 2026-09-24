<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

/**
 * bin/deploy-post.sh: Migrationen gegen die Testdatenbank, Twig-Cache geleert, Optionen geprüft.
 */
final class DeployPostTest extends IntegrationTestCase
{
    /**
     * @return array{0: int, 1: string}
     */
    private static function skript(array $args): array
    {
        $process = proc_open(
            array_merge(['sh', self::basePath() . '/bin/deploy-post.sh'], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::basePath(),
            self::processEnv()
        );
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $output];
    }

    public function testRunsMigrationsAndClearsTwigCache(): void
    {
        $cache = self::basePath() . '/storage/cache/twig/deploy-test';
        @mkdir($cache, 0775, true);
        file_put_contents($cache . '/vorlage.php', '<?php // Testeintrag');

        [$code, $output] = self::skript(['--ohne-build']);
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('1/4 Migrationen', $output);
        self::assertStringContainsString('Build übersprungen', $output);
        self::assertDirectoryDoesNotExist($cache);
    }

    public function testRejectsUnknownOption(): void
    {
        [$code, $output] = self::skript(['--unbekannt']);
        self::assertSame(2, $code, $output);
    }
}

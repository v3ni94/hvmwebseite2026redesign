<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * docker-compose.yml: env_file reicht die gesamte .env in jeden Dienst. Geheimnisse, die nur der
 * Datenbankdienst braucht, dürfen in Anwendung, Worker und Backup nicht ankommen.
 */
final class DockerComposeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function compose(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 3) . '/docker-compose.yml');
    }

    public function testRootPasswordIsClearedOutsideDatabaseService(): void
    {
        $services = self::compose()['services'];
        foreach (['php', 'worker', 'backup'] as $name) {
            self::assertContains('.env', (array) ($services[$name]['env_file'] ?? []), $name . ' liest .env');
            self::assertArrayHasKey('DB_ROOT_PASSWORD', $services[$name]['environment'], $name);
            self::assertSame('', $services[$name]['environment']['DB_ROOT_PASSWORD'], $name);
        }
        self::assertStringContainsString('DB_ROOT_PASSWORD', (string) $services['db']['environment']['MARIADB_ROOT_PASSWORD']);
    }
}

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

    public function testBackendNetworkHasFixedSubnet(): void
    {
        $backend = self::compose()['networks']['backend'];
        $subnet = (string) ($backend['ipam']['config'][0]['subnet'] ?? '');
        self::assertStringContainsString('BACKEND_SUBNET', $subnet, 'Subnetz je Compose-Projekt konfigurierbar');
        self::assertMatchesRegularExpression('#:-172\.30\.\d+\.0/24\}$#', $subnet, 'fester Standardwert');
    }

    public function testEnvExampleRestrictsTrustedProxies(): void
    {
        $env = \Hvm\Support\Env::parse((string) file_get_contents(dirname(__DIR__, 3) . '/.env.example'));
        $proxies = array_filter(array_map('trim', explode(',', $env['TRUSTED_PROXIES'] ?? '')));
        self::assertNotSame([], $proxies);
        foreach ($proxies as $cidr) {
            self::assertNotSame('172.16.0.0/12', $cidr, 'nicht alle Docker-Netze vertrauen');
            [$ip, $bits] = explode('/', $cidr) + [1 => '32'];
            self::assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP), $cidr);
            self::assertGreaterThanOrEqual(24, (int) $bits, $cidr . ' ist zu weit gefasst');
        }
        self::assertSame('false', $env['N8N_WEBHOOK_ALLOW_HTTP_INTERNAL'] ?? null);
    }

    /**
     * Das Image muss denselben Build ausführen wie "composer build". public/og und der Suchindex sind nicht
     * im Repository (und public/og steht in .dockerignore), fehlen sie im Build, liefert Produktion 404.
     */
    public function testImageRunsCompleteAssetBuild(): void
    {
        $root = dirname(__DIR__, 3);
        $dockerfile = (string) file_get_contents($root . '/docker/php/Dockerfile');
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        foreach ((array) $composer['scripts']['build'] as $step) {
            self::assertMatchesRegularExpression('#' . preg_quote((string) $step, '#') . '\b#', $dockerfile, $step . ' fehlt im Dockerfile');
        }
    }

    public function testNginxServesPrecompressedAssetsAndDynamicWellKnown(): void
    {
        $conf = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/default.conf');
        self::assertMatchesRegularExpression('/^\s*gzip_static on;/m', $conf);
        self::assertMatchesRegularExpression('/gzip_types[^;]*text\/markdown/', $conf);
        self::assertMatchesRegularExpression('/gzip_types[^;]*application\/json/', $conf);
        // security.txt kommt aus PHP: .well-known darf nicht mit =404 enden
        self::assertMatchesRegularExpression('#location \^~ /\.well-known/ \{\s*try_files \$uri @php;#', $conf);
        self::assertMatchesRegularExpression('#location = /llms\.txt \{\s*types \{ \}\s*default_type "text/markdown#', $conf);
        self::assertMatchesRegularExpression('#location \^~ /og/ \{[^}]*Cache-Control#', $conf);
        self::assertMatchesRegularExpression('#location \^~ /assets/img/ \{[^}]*Cache-Control#', $conf);
    }

    public function testNginxHealthcheckUsesHealthEndpoint(): void
    {
        $test = implode(' ', (array) self::compose()['services']['nginx']['healthcheck']['test']);
        self::assertStringContainsString('http://127.0.0.1/health', $test);
    }
}

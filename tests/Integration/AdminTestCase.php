<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

use Hvm\Http\Kernel;
use Hvm\Repository\LeadRepository;
use Hvm\Security\AdminAuth;
use Hvm\Security\Password;
use Hvm\Security\Totp;
use Hvm\Support\Clock;
use Hvm\Support\Config;
use Hvm\Support\Uuid;

/**
 * Gemeinsame Hilfen für Tests des Admin-Bereichs, des Löschlaufs und des Imports. Nur fiktive Daten (example.org).
 */
abstract class AdminTestCase extends IntegrationTestCase
{
    protected const PASSWORD = 'Fiktive-Passphrase-2026';

    protected function cleanTables(): void
    {
        parent::cleanTables();
        foreach (['admin_login_attempts', 'admin_users', 'contact_requests', 'job_applications'] as $table) {
            $this->db()->exec('DELETE FROM ' . $table);
        }
    }

    /**
     * @return array{id: int, secret: string}
     */
    protected function createAdmin(Kernel $kernel, string $email = 'admin@example.org', string $password = self::PASSWORD): array
    {
        $secret = Totp::generateSecret();
        $config = $kernel->container()->get(Config::class);
        $this->db()->prepare('INSERT INTO admin_users (email, password_hash, totp_secret, totp_enabled, created_at) VALUES (?, ?, ?, 1, ?)')
            ->execute([$email, Password::hash($password), AdminAuth::encryptSecret($config, $secret), Clock::now()->format('Y-m-d H:i:s')]);

        return ['id' => (int) $this->db()->lastInsertId(), 'secret' => $secret];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function insertLead(array $overrides = []): int
    {
        return (new LeadRepository($this->db()))->insert($overrides + [
            'uuid' => Uuid::v4(),
            'management_form' => 'weg',
            'contact_salutation' => 'frau',
            'contact_first_name' => 'Erika',
            'contact_last_name' => 'Beispiel',
            'contact_email' => 'erika.beispiel@example.org',
            'contact_phone' => '0000 000000',
            'object_street' => 'Musterweg 1',
            'object_zip' => '41061',
            'object_city' => 'Musterstadt',
            'units_residential' => 10,
            'message' => 'Fiktive Nachricht.',
            'source' => 'organisch',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Hvm\Tests\Integration;

/**
 * Basis für die Kontakt- und Bewerbungsstrecke: räumt zusätzlich contact_requests und
 * job_applications auf, die IntegrationTestCase::cleanTables() (fremde Datei) nicht kennt.
 */
abstract class FormularIntegrationTestCase extends IntegrationTestCase
{
    protected function cleanTables(): void
    {
        parent::cleanTables();
        foreach (['contact_requests', 'job_applications'] as $table) {
            self::$pdo?->exec('DELETE FROM ' . $table);
        }
    }
}

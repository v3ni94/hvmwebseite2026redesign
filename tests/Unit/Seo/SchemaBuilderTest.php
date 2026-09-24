<?php

declare(strict_types=1);

namespace Hvm\Tests\Unit\Seo;

use Hvm\Support\Config;
use Hvm\View\SchemaBuilder;
use PHPUnit\Framework\TestCase;

final class SchemaBuilderTest extends TestCase
{
    private function config(array $overrides = []): Config
    {
        $config = new Config([
            'app' => ['url' => 'https://www.muellerhv.de'],
            'unternehmen' => [
                'name' => 'Hausverwaltung Müller GmbH',
                'email' => 'info@muellerhv.de',
                'gruendung' => '2020-03-04',
                'anschrift' => [
                    'strasse' => 'Rheinpromenade 13',
                    'plz' => '40789',
                    'ort' => 'Monheim am Rhein',
                    'land' => 'DE',
                ],
                'telefon' => '02431 9550300',
                'notfall_telefon' => null,
                'ust_id' => null,
                'portal_url' => null,
                'mitgliedschaften' => [
                    ['kurz' => 'VZIV', 'name' => 'Verein Zertifizierter ImmobilienVerwalter e. V.'],
                ],
            ],
            'staedte' => [
                ['slug' => 'monheim-am-rhein', 'name' => 'Monheim am Rhein', 'hauptsitz' => true],
            ],
        ]);
        foreach ($overrides as $key => $value) {
            $config->set($key, $value);
        }

        return $config;
    }

    private function seite(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'weg-verwaltung',
            'title' => 'WEG-Verwaltung | Hausverwaltung Müller GmbH',
            'description' => 'WEG-Verwaltung Beschreibung.',
            'canonical' => 'https://www.muellerhv.de/weg-verwaltung/',
            'breadcrumbs' => [
                ['label' => 'WEG-Verwaltung', 'url' => '/weg-verwaltung/'],
                ['label' => 'Start', 'url' => '/'],
            ],
        ], $overrides);
    }

    public function testOrganizationEnthaeltNurBelegteAngaben(): void
    {
        $builder = new SchemaBuilder($this->config());
        $organisation = $builder->organization();

        self::assertSame(['Organization', 'RealEstateAgent'], $organisation['@type']);
        self::assertSame('2020-03-04', $organisation['foundingDate']);
        self::assertSame([['@type' => 'City', 'name' => 'Monheim am Rhein']], $organisation['areaServed'], 'nur der Hauptsitz, solange keine weiteren Betreuungsgebiete hinterlegt sind');
        self::assertSame('Verein Zertifizierter ImmobilienVerwalter e. V.', $organisation['memberOf'][0]['name']);
        self::assertSame('VZIV', $organisation['memberOf'][0]['alternateName']);
        self::assertArrayNotHasKey('sameAs', $organisation, 'keine bekannten Profile, sameAs muss fehlen');
        self::assertSame('https://www.muellerhv.de/#organisation', $organisation['@id']);
        self::assertSame('info@muellerhv.de', $organisation['email']);
        self::assertSame('+49 2431 9550300', $organisation['telephone']);
        self::assertArrayNotHasKey('vatID', $organisation, 'ust_id ist null und darf nicht erfunden werden');
        self::assertSame('Rheinpromenade 13', $organisation['address']['streetAddress']);
    }

    public function testOrganizationOhneTelefonLaesstFeldWeg(): void
    {
        $config = $this->config();
        $firma = $config->array('unternehmen');
        $firma['telefon'] = null;
        $config->set('unternehmen', $firma);

        $organisation = (new SchemaBuilder($config))->organization();

        self::assertArrayNotHasKey('telephone', $organisation);
    }

    public function testServiceVerweistPerIdAufOrganisationUndNurBestaetigteOrte(): void
    {
        $builder = new SchemaBuilder($this->config());
        $schemas = $builder->forPage($this->seite(), 'Service');

        $service = self::finde($schemas, 'Service');
        self::assertNotNull($service);
        self::assertSame(['@id' => 'https://www.muellerhv.de/#organisation'], $service['provider']);
        self::assertSame([['@type' => 'City', 'name' => 'Monheim am Rhein']], $service['areaServed'], 'nur der bestätigte Hauptsitz');
        self::assertNotNull(self::finde($schemas, ['Organization', 'RealEstateAgent']), 'Organisation auf jeder indexierbaren Seite');
    }

    public function testNichtIndexierbareSeiteOhneOrganisation(): void
    {
        $schemas = (new SchemaBuilder($this->config()))->forPage($this->seite(), 'none');

        self::assertNull(self::finde($schemas, ['Organization', 'RealEstateAgent']));
        self::assertNotNull(self::finde($schemas, 'BreadcrumbList'));
    }

    public function testWebsiteMitSearchActionAufWissenssuche(): void
    {
        $website = (new SchemaBuilder($this->config()))->website();

        self::assertSame('SearchAction', $website['potentialAction']['@type']);
        self::assertSame('https://www.muellerhv.de/wissen/?q={search_term_string}', $website['potentialAction']['target']['urlTemplate']);
        self::assertSame('required name=search_term_string', $website['potentialAction']['query-input']);
    }

    public function testMitBetreuungsgebietenIstDeutschlandEinsatzgebiet(): void
    {
        $config = $this->config();
        $config->set('staedte', [
            ['slug' => 'monheim-am-rhein', 'name' => 'Monheim am Rhein', 'hauptsitz' => true],
            ['slug' => 'berlin', 'name' => 'Berlin', 'hauptsitz' => false],
        ]);

        $service = self::finde((new SchemaBuilder($config))->forPage($this->seite(), 'Service'), 'Service');

        self::assertNotNull($service);
        self::assertSame([['@type' => 'City', 'name' => 'Monheim am Rhein'], ['@type' => 'Country', 'name' => 'Deutschland']], $service['areaServed']);
    }

    public function testStartseiteEnthaeltOrganisationUndWebsite(): void
    {
        $builder = new SchemaBuilder($this->config());
        $schemas = $builder->forPage($this->seite(['slug' => 'start', 'breadcrumbs' => []]), 'WebPage');

        self::assertNotNull(self::finde($schemas, ['Organization', 'RealEstateAgent']));
        self::assertNotNull(self::finde($schemas, 'WebSite'));
    }

    public function testBreadcrumbListNurAbZweiEintraegen(): void
    {
        $builder = new SchemaBuilder($this->config());

        $ohne = $builder->forPage($this->seite(['breadcrumbs' => [['label' => 'Start', 'url' => '/']]]), 'WebPage');
        self::assertNull(self::finde($ohne, 'BreadcrumbList'));

        $mit = $builder->forPage($this->seite(), 'WebPage');
        $breadcrumbs = self::finde($mit, 'BreadcrumbList');
        self::assertNotNull($breadcrumbs);
        self::assertSame('https://www.muellerhv.de/weg-verwaltung/', $breadcrumbs['itemListElement'][0]['item']);
    }

    public function testFaqPageAusFragenUndAntworten(): void
    {
        $builder = new SchemaBuilder($this->config());
        $faq = $builder->faqPage(
            [['frage' => 'Was kostet die Verwaltung?', 'antwort' => 'Abhängig vom Objekt.']],
            'https://www.muellerhv.de/wissen/kosten/'
        );

        self::assertSame('FAQPage', $faq['@type']);
        self::assertSame('Was kostet die Verwaltung?', $faq['mainEntity'][0]['name']);
        self::assertSame('Abhängig vom Objekt.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
    }

    public function testArticleOhneStandLaesstDatumsfelderWeg(): void
    {
        $builder = new SchemaBuilder($this->config());
        $artikel = $builder->article(['titel' => 'Wirtschaftsplan verstehen', 'url' => 'https://www.muellerhv.de/wissen/wirtschaftsplan/']);

        self::assertSame('Article', $artikel['@type']);
        self::assertArrayNotHasKey('dateModified', $artikel);
        self::assertSame('https://www.muellerhv.de/#organisation', $artikel['author']['@id']);
        self::assertSame('Hausverwaltung Müller GmbH', $artikel['author']['name']);
        self::assertSame(['@id' => 'https://www.muellerhv.de/#organisation'], $artikel['publisher']);
    }

    public function testArticleMitStandSetztIsoDatum(): void
    {
        $artikel = (new SchemaBuilder($this->config()))->article([
            'titel' => 'Wirtschaftsplan verstehen',
            'url' => 'https://www.muellerhv.de/wissen/wirtschaftsplan/',
            'stand' => '2026-05-01',
        ]);

        self::assertSame('2026-05-01', $artikel['dateModified']);
        self::assertSame('2026-05-01', $artikel['datePublished']);
    }

    /**
     * @param list<array<string, mixed>> $schemas
     * @param string|list<string>        $type
     */
    private static function finde(array $schemas, string|array $type): ?array
    {
        foreach ($schemas as $schema) {
            if ($schema['@type'] === $type) {
                return $schema;
            }
        }

        return null;
    }
}

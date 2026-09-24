<?php

declare(strict_types=1);

namespace Hvm\Controller;

use Hvm\Content\Stadt;
use Hvm\Content\StadtRepository;
use Hvm\Content\StadtSeite;
use Hvm\Http\Request;
use Hvm\Http\Response;
use Hvm\Support\Config;
use Hvm\View\PageMeta;
use Hvm\View\SchemaBuilder;
use Hvm\View\View;

/**
 * Betreuungsgebiete: Übersicht /betreuungsgebiete/ (alle 42 Städte nach Bundesland, Karte) und
 * Stadtseiten /hausverwaltung-{slug}/ mit Text aus content/staedte/{slug}.md.
 *
 * Strukturierte Daten je Stadtseite: Organisation (Hauptsitz), WebSite, Service mit areaServed (City und
 * PLZ-Bereich) und BreadcrumbList. Bewusst kein LocalBusiness je Stadt, keine Anschrift je Stadt.
 */
final class StadtController
{
    public function __construct(
        private readonly View $view,
        private readonly PageMeta $meta,
        private readonly SchemaBuilder $schemaBuilder,
        private readonly Config $config,
        private readonly ErrorController $errors,
        private readonly StadtRepository $staedte,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public function index(Request $request, array $params = []): Response
    {
        $gruppen = [];
        foreach ($this->staedte->nachBundesland() as $bundesland => $staedte) {
            $gruppen[] = [
                'bundesland' => $bundesland,
                'staedte' => array_map(fn (Stadt $s): array => $this->stadtDaten($s), $staedte),
            ];
        }

        return $this->view->response('pages/betreuungsgebiete.html.twig', [
            'page' => $this->meta->build('betreuungsgebiete', $request->path()),
            'gruppen' => $gruppen,
            'orte' => array_map(fn (Stadt $s): array => $this->stadtDaten($s), $this->staedte->staedte()),
            'anzahl' => count($this->staedte->staedte()),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
    {
        $slug = (string) ($params['slug'] ?? '');
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            return $this->errors->notFound($request);
        }
        $seite = $this->staedte->seite($slug);
        if ($seite === null) {
            return $this->errors->notFound($request);
        }
        $stadt = $seite->stadt;

        $page = $this->meta->build('stadt', $stadt->pfad(), [
            'heading' => $stadt->name,
            'title' => PageMeta::seitentitel($seite->titel, (string) $this->config->get('unternehmen.name', '')),
            'description' => $seite->beschreibung,
            // Stadtseiten ohne eigenes Bild: Open-Graph-Bild der Übersicht Betreuungsgebiete
            'og_image' => rtrim((string) $this->config->get('app.url', ''), '/') . '/og/betreuungsgebiete.png',
            // indexierbar nur mit Freigabe und lokalem Bezug (config/staedte.php), Canonical bleibt auf sich selbst
            'robots' => $seite->indexierbar() ? PageMeta::ROBOTS_INDEX : 'noindex, follow',
        ]);
        $page['heading'] = $seite->titel;
        $page['freigabe'] = ['status' => $seite->freigegeben ? 'freigegeben' : 'entwurf', 'datum' => $seite->stand, 'durch' => null];
        $page['schema'] = $this->schema($seite, $page);

        return $this->view->response('pages/stadt.html.twig', [
            'page' => $page,
            'seite' => $seite,
            'stadt' => $this->stadtDaten($stadt),
            'nachbarn' => array_map(fn (Stadt $s): array => $this->stadtDaten($s), $this->staedte->nachbarn($stadt, 3)),
            'hauptsitz' => ($h = $this->staedte->hauptsitz()) !== null ? $this->stadtDaten($h) : null,
        ]);
    }

    /**
     * Daten einer Stadt für Twig. url nur, wenn die Stadtseite in dieser Umgebung erreichbar ist.
     *
     * @return array<string, mixed>
     */
    private function stadtDaten(Stadt $stadt): array
    {
        return [
            'slug' => $stadt->slug,
            'name' => $stadt->name,
            'bundesland' => $stadt->bundesland,
            'plz_von' => $stadt->plzVon,
            'plz_bis' => $stadt->plzBis,
            'bestand_orte' => $stadt->bestandOrte,
            'hauptsitz' => $stadt->hauptsitz,
            'karte' => $stadt->karte(),
            'url' => $this->staedte->hatSichtbareSeite($stadt->slug) ? $stadt->pfad() : null,
            'indexierbar' => $stadt->indexierbar,
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return list<array<string, mixed>>
     */
    private function schema(StadtSeite $seite, array $page): array
    {
        $stadt = $seite->stadt;
        $canonical = (string) $page['canonical'];
        $leistungen = array_values(array_map(
            static fn (array $l): string => (string) $l['name'],
            array_filter((array) $this->config->get('unternehmen.leistungen', []), static fn ($l): bool => is_array($l) && in_array($l['schluessel'] ?? '', ['weg', 'miet', 'se'], true))
        ));

        $schemas = [
            $this->schemaBuilder->organization(),
            $this->schemaBuilder->website(),
            [
                '@context' => 'https://schema.org',
                '@type' => 'Service',
                '@id' => $canonical . '#leistung',
                'name' => 'Hausverwaltung ' . $stadt->name,
                'serviceType' => $leistungen !== [] ? $leistungen : 'Hausverwaltung',
                'description' => $seite->beschreibung,
                'url' => $canonical,
                'inLanguage' => 'de-DE',
                'provider' => ['@id' => $this->schemaBuilder->organizationId()],
                'areaServed' => [
                    [
                        '@type' => 'City',
                        'name' => $stadt->name,
                        'containedInPlace' => ['@type' => 'State', 'name' => $stadt->bundesland],
                    ],
                    [
                        '@type' => 'DefinedRegion',
                        'name' => 'Postleitzahlen ' . $stadt->plzVon . ' bis ' . $stadt->plzBis,
                        'addressCountry' => 'DE',
                        'postalCodeRange' => [
                            '@type' => 'PostalCodeRangeSpecification',
                            'postalCodeBegin' => $stadt->plzVon,
                            'postalCodeEnd' => $stadt->plzBis,
                        ],
                    ],
                ],
                'mainEntityOfPage' => $canonical,
            ],
        ];
        $schemas[] = $this->schemaBuilder->breadcrumbList($page['breadcrumbs'], $canonical);

        // FAQPage nur für freigegebene Seiten (Fragen und Antworten sind Teil der Freigabe)
        if ($seite->faq !== [] && $seite->freigegeben) {
            $schemas[] = $this->schemaBuilder->faqPage($seite->faq, $canonical);
        }

        return $schemas;
    }
}

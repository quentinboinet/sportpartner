<?php

namespace App\Service\Activity;

use App\Service\Route\DurationFormat;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Export CSV des activités (statistiques : période affichée ; liste des activités : filtres affichés).
 *
 * Une ligne par activité, dans l'ordre chronologique : date et heure de départ LOCALES (fuseau de l'activité,
 * comme le calendrier), sport traduit, nom, distance (km, 2 décimales), temps en mouvement et écoulé (h:mm:ss),
 * D+ (m), allure (h:mm:ss par km, tous les sports sauf le vélo) ou vitesse (km/h, 1 décimale, vélo) selon la
 * règle d'affichage des pages, FC moyenne et max (bpm), matériel (marque, modèle, surnom), origine (Strava,
 * Saisie manuelle, Séance santé) et lien Strava. En-têtes dans la langue de l'utilisateur ; séparateur,
 * décimales, BOM et neutralisation des formules : voir CsvFormat. Cellule vide quand la valeur manque.
 *
 * Les allures et durées sont écrites en h:mm:ss (« 0:05:07 ») : un tableur les lit comme des durées, alors
 * qu'il lirait « 5:07 » comme 5 h 07.
 */
final class ActivityCsvExporter
{
    /** Colonnes, dans l'ordre ; en-tête = activity_export.col_<colonne>. */
    public const COLUMNS = [
        'date', 'start_time', 'sport', 'name', 'distance', 'moving_time', 'elapsed_time', 'elevation',
        'pace', 'speed', 'hr_avg', 'hr_max', 'gear', 'source', 'strava_url',
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Écrit le fichier complet (BOM, en-tête, lignes) dans $handle.
     *
     * @param resource                    $handle
     * @param iterable<ActivityExportRow> $rows
     */
    public function write($handle, iterable $rows, string $locale): void
    {
        $format = CsvFormat::forLocale($locale);
        fwrite($handle, CsvFormat::BOM);
        $format->writeRow($handle, $this->header($locale));
        foreach ($rows as $row) {
            $format->writeRow($handle, $this->cells($row, $format, $locale));
        }
    }

    /** @return list<string> */
    public function header(string $locale): array
    {
        return array_map(fn (string $col): string => $this->translator->trans('activity_export.col_'.$col, [], null, $locale), self::COLUMNS);
    }

    /** @return list<string> une cellule par colonne de COLUMNS (non neutralisées : CsvFormat::writeRow() s'en charge) */
    public function cells(ActivityExportRow $row, CsvFormat $format, string $locale): array
    {
        $local = $row->localStart();
        $slug = $row->sportSlug ?? 'other';
        $meters = $row->distanceMeters !== null && $row->distanceMeters > 0 ? $row->distanceMeters : null;
        $moving = $row->movingSeconds !== null && $row->movingSeconds > 0 ? $row->movingSeconds : null;

        // Vitesse pour le vélo, allure pour les autres sports (règle des pages Activités et Calendrier)
        $pace = '';
        $speed = '';
        if ($slug === 'ride') {
            $ms = $row->averageSpeed !== null && $row->averageSpeed > 0
                ? $row->averageSpeed
                : ($meters !== null && $moving !== null ? $meters / $moving : null);
            $speed = $ms !== null ? $format->number($ms * 3.6, 1) : '';
        } elseif ($meters !== null && $moving !== null) {
            $pace = DurationFormat::format((int) round($moving / $meters * 1000), true);
        }

        return [
            $local->format('Y-m-d'),
            $local->format('H:i'),
            $this->translator->trans('sport.'.$slug, [], null, $locale),
            (string) $row->name,
            $meters !== null ? $format->number($meters / 1000, 2) : '',
            $moving !== null ? DurationFormat::format($moving, true) : '',
            $row->elapsedSeconds !== null && $row->elapsedSeconds > 0 ? DurationFormat::format($row->elapsedSeconds, true) : '',
            $row->elevationMeters !== null ? $format->number($row->elevationMeters, 0) : '',
            $pace,
            $speed,
            $row->hrAvg !== null && $row->hrAvg > 0 ? $format->number($row->hrAvg, 0) : '',
            $row->hrMax !== null && $row->hrMax > 0 ? $format->number($row->hrMax, 0) : '',
            $row->gearLabel(),
            $this->translator->trans($row->source()->labelKey(), [], null, $locale),
            (string) $row->stravaUrl(),
        ];
    }

    /**
     * Nom du fichier : « sportpartner-activites[-<sport>]-<début>_<fin>.csv », ou « ...-<fin>.csv » sans date de
     * début (liste des activités sans filtre de période). Préfixe traduit (activity_export.filename).
     */
    public function filename(string $locale, ?\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $sportSlug = null): string
    {
        $parts = [$this->translator->trans('activity_export.filename', [], null, $locale)];
        if ($sportSlug !== null && preg_match('/^[a-z_]{1,50}$/', $sportSlug)) {
            $parts[] = str_replace('_', '-', $sportSlug);
        }
        $parts[] = ($from !== null ? $from->format('Y-m-d').'_' : '').$to->format('Y-m-d');

        return implode('-', $parts).'.csv';
    }

    /**
     * Réponse en flux (rien n'est gardé en mémoire), téléchargée en pièce jointe, jamais mise en cache.
     *
     * @param iterable<ActivityExportRow> $rows lu pendant l'envoi
     */
    public function response(iterable $rows, string $locale, string $filename): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($rows, $locale): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            $this->write($out, $rows, $locale);
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $ascii));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Garde exactement « no-store, private » (sinon la session ajoute max-age=0, must-revalidate)
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}

<?php

namespace App\Service\Race;

use App\Entity\RaceIntent;
use App\Entity\RaceType;
use App\Service\Input\TextInput;

/**
 * Formulaire d'une course (ajout ou modification, RaceController), validé en entier avant de toucher à la course.
 * Calcul pur. Erreur = clé de traduction du message flash (la première rencontrée), rien n'est modifié.
 *
 * - name : obligatoire, une ligne, 200 caractères ; location : une ligne, 200 ; notes : 5000 (sauts de ligne gardés).
 *   UTF-8 valide exigé (sinon MySQL refuserait l'octet invalide : erreur 500), texte trop long refusé (jamais tronqué).
 * - website : vide = aucun ; sans schéma, https:// est ajouté ; sinon http(s) seulement, sans espace, 500 caractères.
 * - distance_km : vide = aucune ; nombre de 0 à 1000 km (virgule ou point, 3 décimales au plus).
 * - elevation_gain : vide = aucun ; entier de 0 à 20 000 m.
 * - race_date : vide = aucune ; date Y-m-d existante (le 31/02 est refusé) ; elle fixe l'année.
 * - year (sans date) : entier borné à 1900-2100, sinon l'année par défaut.
 * - intent, race_type : valeur d'un cas de l'énumération, sinon null (le contrôleur garde alors la valeur existante).
 * Un champ envoyé en tableau (`name[]=x`) est invalide ou ignoré, sans erreur 400.
 *
 * @phpstan-type Values array{name: string, location: ?string, website: ?string, notes: ?string, distanceKm: ?float, elevationGain: ?int, intent: ?RaceIntent, type: ?RaceType, raceDate: ?\DateTimeImmutable, year: int}
 */
final class RaceInput
{
    public const NAME_MAX        = 200;
    public const LOCATION_MAX    = 200;
    public const NOTES_MAX       = 5000;
    public const WEBSITE_MAX     = 500;
    public const DISTANCE_MAX_KM = 1000;
    public const ELEVATION_MAX_M = 20000;

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $post
     *
     * @return array{error: string}|array{values: Values}
     */
    public static function parse(array $post, int $defaultYear): array
    {
        $name = TextInput::field($post, 'name', self::NAME_MAX);
        if (null === $name) {
            return ['error' => 'races.flash_name_required'];
        }
        $location = TextInput::field($post, 'location', self::LOCATION_MAX);
        $notes    = TextInput::field($post, 'notes', self::NOTES_MAX, true);
        if (false === $name || false === $location || false === $notes) {
            return ['error' => 'races.flash_text_invalid'];
        }

        $website = TextInput::field($post, 'website', self::WEBSITE_MAX);
        if (false === $website) {
            return ['error' => 'races.flash_website_invalid'];
        }
        if (null !== $website) {
            $website = self::normalizeUrl($website);
            if (null === $website) {
                return ['error' => 'races.flash_website_invalid'];
            }
        }

        $distanceRaw = trim(TextInput::raw($post, 'distance_km'));
        $distanceKm  = null;
        if ('' !== $distanceRaw) {
            if (1 !== preg_match('/^\d{1,4}(?:[.,]\d{1,3})?$/', $distanceRaw)) {
                return ['error' => 'races.flash_distance_invalid'];
            }
            $distanceKm = (float) str_replace(',', '.', $distanceRaw);
            if ($distanceKm > self::DISTANCE_MAX_KM) {
                return ['error' => 'races.flash_distance_invalid'];
            }
        }

        $elevationRaw  = trim(TextInput::raw($post, 'elevation_gain'));
        $elevationGain = null;
        if ('' !== $elevationRaw) {
            if (1 !== preg_match('/^\d{1,5}$/', $elevationRaw) || (int) $elevationRaw > self::ELEVATION_MAX_M) {
                return ['error' => 'races.flash_elevation_invalid'];
            }
            $elevationGain = (int) $elevationRaw;
        }

        $dateRaw  = trim(TextInput::raw($post, 'race_date'));
        $raceDate = null;
        if ('' !== $dateRaw) {
            $raceDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw);
            if (false === $raceDate || $raceDate->format('Y-m-d') !== $dateRaw) {
                return ['error' => 'races.flash_date_invalid'];
            }
        }

        // Une date précise, si fournie, fait foi pour l'année
        $yearRaw = TextInput::raw($post, 'year');
        $year    = null !== $raceDate ? (int) $raceDate->format('Y') : (1 === preg_match('/^\d{1,4}$/', $yearRaw) ? (int) $yearRaw : $defaultYear);

        return ['values' => [
            'name'          => $name,
            'location'      => $location,
            'website'       => $website,
            'notes'         => $notes,
            'distanceKm'    => $distanceKm,
            'elevationGain' => $elevationGain,
            'intent'        => RaceIntent::tryFrom(TextInput::raw($post, 'intent')),
            'type'          => RaceType::tryFrom(TextInput::raw($post, 'race_type')),
            'raceDate'      => $raceDate,
            'year'          => max(1900, min(2100, $year)),
        ]];
    }

    /** N'accepte que des liens http(s) (le site est rendu dans un href) ; sans schéma, https:// est ajouté. */
    public static function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ('' === $url) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            $url = 'https://'.$url;
        }

        return 1 === preg_match('#^https?://[^\s]+$#iD', $url) && mb_strlen($url) <= self::WEBSITE_MAX ? $url : null;
    }

    /** Le site d'une course n'est rendu en lien que s'il est en http(s) (les anciennes valeurs ne sont pas sûres). */
    public static function isLinkable(?string $url): bool
    {
        return null !== $url && 1 === preg_match('#^https?://[^\s]+$#iD', $url);
    }
}

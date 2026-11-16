<?php

namespace App\Service\Gear;

use App\Entity\GearBrand;
use App\Entity\GearType;

/**
 * Retrouve la marque (table gearBrand) correspondant au "brand_name" libre d'un matériel Strava.
 *
 * Comparaison insensible à la casse, aux accents et à la ponctuation ("New-Balance" = "New Balance",
 * "Cervelo" = "Cervélo", "B'Twin" = "Btwin"), sans les mots génériques ("Brooks Running" = "Brooks",
 * "Xero Shoes" = "Xero", "Canyon Bicycles" = "Canyon"), avec quelques alias ("HOKA ONE ONE" = "Hoka",
 * "S-Works" = "Specialized"). Si le nom contient plusieurs marques ("Decathlon Kiprun"), la plus
 * précise l'emporte. Seules les marques valables pour le type sont retenues ; une marque maison de
 * Decathlon (Kiprun, Kalenji, Btwin, Rockrider, Evadict...) non valable pour le type devient
 * "Decathlon". Sinon : "Autre".
 */
final class GearBrandMatcher
{
    /** Mots ignorés quand il reste autre chose ("On Running" -> "On"). */
    private const GENERIC_WORDS = [
        'the', 'running', 'run', 'shoes', 'shoe', 'footwear', 'bikes', 'bike', 'bicycles', 'bicycle',
        'cycles', 'cycle', 'cycling', 'sports', 'sport', 'athletic', 'athletics', 'industries', 'inc',
        'co', 'company', 'ltd', 'gmbh', 'official',
    ];

    /** Alias (clé normalisée) -> clé normalisée de la marque de référence. */
    private const ALIASES = [
        'hokaoneone'      => 'hoka',
        'nb'              => 'newbalance',
        'sworks'          => 'specialized',
        'wiliertriestina' => 'wilier',
        'tnf'             => 'northface',
        'vjsport'         => 'vj',
    ];

    /** Marques maison de Decathlon (clés normalisées), ramenées à "Decathlon" si besoin. */
    private const DECATHLON_BRANDS = [
        'decathlon', 'kiprun', 'kalenji', 'quechua', 'btwin', 'triban', 'rockrider', 'vanrysel',
        'newfeel', 'forclaz', 'simond', 'evadict', 'elops', 'riverside', 'domyos',
    ];

    private const DECATHLON = 'decathlon';
    private const MAX_TOKENS = 6;

    /**
     * @param list<GearBrand> $brands Toutes les marques (dont "Autre").
     *
     * @return GearBrand|null La marque retenue, "Autre" à défaut ; null seulement si "Autre" n'existe pas.
     */
    public static function match(?string $stravaBrand, GearType $type, array $brands): ?GearBrand
    {
        $index = [];
        $other = null;
        foreach ($brands as $brand) {
            if ($brand->isOther()) {
                $other = $brand;
                continue;
            }
            $index[self::key($brand->getName())] ??= $brand;
        }

        $tokens = array_slice(self::tokens((string) $stravaBrand), 0, self::MAX_TOKENS);
        if ($tokens === []) {
            return $other;
        }

        // Nom complet d'abord, puis toute suite de mots ("Decathlon Kiprun KD900" -> "Kiprun")
        $best      = null;
        $bestScore = null;
        $decathlon = false;
        $count     = count($tokens);
        for ($len = $count; $len >= 1; $len--) {
            for ($start = 0; $start + $len <= $count; $start++) {
                $key = implode('', array_slice($tokens, $start, $len));
                $key = self::ALIASES[$key] ?? $key;
                if (in_array($key, self::DECATHLON_BRANDS, true)) {
                    $decathlon = true;
                }
                $brand = $index[$key] ?? null;
                if ($brand === null || !$brand->supports($type)) {
                    continue;
                }
                // Le nom complet gagne ; sinon une marque précise plutôt que "Decathlon", puis la plus longue
                $score = [$len === $count ? 1 : 0, $key === self::DECATHLON ? 0 : 1, $len, -$start];
                if ($bestScore === null || $score > $bestScore) {
                    $best      = $brand;
                    $bestScore = $score;
                }
            }
        }

        if ($best !== null) {
            return $best;
        }
        if ($decathlon && isset($index[self::DECATHLON]) && $index[self::DECATHLON]->supports($type)) {
            return $index[self::DECATHLON];
        }

        return $other;
    }

    /** Clé de comparaison d'un nom de marque : minuscules, sans accents, ponctuation ni mots génériques. */
    public static function key(string $name): string
    {
        return implode('', self::tokens($name));
    }

    /** Clé de comparaison simple (casse, accents, ponctuation), sans retirer de mots : "HOKA Speedgoat 6" = "Hoka speedgoat-6". */
    public static function simplify(string $text): string
    {
        return str_replace(' ', '', self::ascii($text));
    }

    /** @return list<string> */
    private static function tokens(string $name): array
    {
        $words = array_values(array_filter(explode(' ', self::ascii($name)), static fn (string $w): bool => $w !== ''));
        $kept  = array_values(array_filter($words, static fn (string $w): bool => !in_array($w, self::GENERIC_WORDS, true)));

        return $kept !== [] ? $kept : $words;
    }

    /** Minuscules ASCII, accents retirés, tout caractère non alphanumérique remplacé par une espace. */
    private static function ascii(string $text): string
    {
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $text       = is_string($decomposed) ? $decomposed : $text;
        $text       = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;
        $text       = mb_strtolower($text);
        $text       = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        return trim($text);
    }
}

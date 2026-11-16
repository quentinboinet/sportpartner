<?php

namespace App\Service\Health;

/**
 * Texte « replié » pour la recherche de la bibliothèque : minuscules, sans accents, espaces simples.
 * « Étirement   du SOLÉAIRE » -> « etirement du soleaire ».
 *
 * Miroir : fold() de health_library_controller.js (normalize('NFD') sans les diacritiques, œ -> oe, æ -> ae),
 * même résultat pour les lettres latines courantes. Sans l'extension intl (non exigée par le projet).
 */
final class TextFold
{
    private const MAP = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'ç' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ];

    public static function fold(string $text): string
    {
        $lower = mb_strtolower($text, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', strtr($lower, self::MAP)));
    }

    /**
     * Mots de la recherche, repliés (vides ignorés).
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $folded = self::fold($query);

        return $folded === '' ? [] : explode(' ', $folded);
    }
}

<?php

namespace App\Service\Input;

/**
 * Lecture d'un champ texte de formulaire (POST) sans erreur 400 ni 500. Calcul pur.
 *
 * Un champ qui n'est pas une chaîne (tableau `name[]=x`) ou pas de l'UTF-8 valide est invalide : MySQL (utf8mb4)
 * refuserait l'octet invalide par une erreur 500. Normalisation avant de compter : fins de ligne CRLF / CR ramenées
 * à LF (le navigateur compte un saut de ligne pour 1 avec maxlength mais en envoie 2), tabulation = espace,
 * caractères de contrôle retirés (une ligne : remplacés par une espace, puis espaces multiples réduites), espaces de
 * début et de fin supprimés. Texte vide = null. Longueur en caractères, comptée après normalisation : un texte trop
 * long est refusé, jamais tronqué en silence.
 */
final class TextInput
{
    private function __construct()
    {
    }

    /**
     * Texte normalisé, null si vide ou absent, false si la valeur n'est pas une chaîne UTF-8.
     */
    public static function normalize(mixed $value, bool $multiline): string|false|null
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return false;
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = str_replace("\t", ' ', $value);
        $value = (string) preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', $multiline ? '' : ' ', $value);
        if (!$multiline) {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * Champ $key de $post normalisé : null si vide ou absent, false si invalide (pas une chaîne UTF-8) ou plus long
     * que $max caractères.
     *
     * @param array<mixed> $post
     */
    public static function field(array $post, string $key, int $max, bool $multiline = false): string|false|null
    {
        $value = self::normalize($post[$key] ?? null, $multiline);
        if (\is_string($value) && mb_strlen($value) > $max) {
            return false;
        }

        return $value;
    }

    /**
     * Valeur scalaire brute d'un champ (chaîne), '' si absent ou si ce n'est pas une chaîne (tableau).
     *
     * @param array<mixed> $values
     */
    public static function raw(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return \is_string($value) ? $value : '';
    }
}

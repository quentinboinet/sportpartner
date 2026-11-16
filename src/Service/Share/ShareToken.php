<?php

namespace App\Service\Share;

/**
 * Jeton des liens de partage publics (/p/{token}) : 24 octets de random_bytes() (192 bits) encodés en base64url
 * sans remplissage, soit 32 caractères [A-Za-z0-9_-], utilisables tels quels dans une URL.
 */
final class ShareToken
{
    public const BYTES = 24;
    public const LENGTH = 32;
    private const PATTERN = '/^[A-Za-z0-9_-]{32}$/D';

    private function __construct()
    {
    }

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
    }

    /** Nouveau jeton, toujours différent de l'actuel (régénération : l'ancien lien doit cesser de fonctionner). */
    public static function regenerate(string $current): string
    {
        do {
            $token = self::generate();
        } while (hash_equals($current, $token));

        return $token;
    }

    /** Format attendu, vérifié AVANT toute requête : un jeton mal formé donne la même 404 qu'un jeton inconnu. */
    public static function isValid(string $token): bool
    {
        return 1 === preg_match(self::PATTERN, $token);
    }
}

<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RateLimiter\AbstractRequestRateLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Limiteur des essais de connexion (security.yaml, `login_throttling.limiter`) : UN SEUL compteur, par adresse
 * e-mail normalisée + IP du client (limiteur « login » de rate_limiter.yaml : 5 essais par 15 minutes, fenêtre
 * glissante). Un essai réussi remet le compteur à zéro.
 *
 * Pourquoi pas le limiteur par défaut de Symfony : il ajoute un compteur global par IP. En production (Alwaysdata),
 * l'IP vue par PHP n'est pas vérifiée (pas de trusted_proxies) : si c'est celle du proxy, ce compteur global serait
 * partagé par tous les visiteurs et bloquerait toutes les connexions à la fois. Ici, dans ce cas, la limite devient
 * simplement « par adresse e-mail » : un tiers peut bloquer un compte 15 minutes au plus, sans toucher aux autres.
 *
 * La clé est un HMAC (secret de l'application) : ni l'adresse ni l'IP n'apparaissent dans le cache.
 */
final class LoginRateLimiter extends AbstractRequestRateLimiter
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $factory,
        #[\SensitiveParameter] private readonly string $secret,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('A non-empty secret is required.');
        }
    }

    protected function getLimiters(Request $request): array
    {
        $username = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME, '');

        return [$this->factory->create(self::key(\is_string($username) ? $username : '', $request->getClientIp(), $this->secret))];
    }

    /** Adresse saisie sans espaces autour, en minuscules (la colonne email est comparée sans casse par MySQL). */
    public static function normalizeUsername(string $username): string
    {
        $username = trim($username);

        return 1 === preg_match('//u', $username) ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    /**
     * Clé du compteur : HMAC-SHA256 de l'IP et de l'adresse normalisée (IP d'abord : elle ne contient jamais de
     * saut de ligne, la séparation est donc sans ambiguïté), en base64 sans caractère réservé.
     */
    public static function key(string $username, ?string $ip, #[\SensitiveParameter] string $secret): string
    {
        $data = ($ip ?? '')."\n".self::normalizeUsername($username);

        return strtr(rtrim(base64_encode(hash_hmac('sha256', $data, $secret, true)), '='), '/+', '._');
    }
}

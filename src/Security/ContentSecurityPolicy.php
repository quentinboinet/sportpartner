<?php

namespace App\Security;

/**
 * Content-Security-Policy APPLIQUÉE (pas en Report-Only) à toutes les pages HTML par SecurityHeadersSubscriber.
 *
 * - Scripts : `'self'` + le nonce de la requête. Chaque <script> inline porte nonce="{{ csp_nonce() }}" et
 *   importmap() reçoit {nonce: csp_nonce()} ; aucun gestionnaire inline (onclick, onsubmit...) ni `javascript:`.
 *   Pas de `'strict-dynamic'` : tous les modules (importmap, import() de FullCalendar) sont servis par le site, et le
 *   CSS de Bootstrap est lié par <link> au lieu d'être importé depuis app.js (AssetMapper en ferait un module data:).
 *   Les <link rel="modulepreload"> d'importmap() n'ont pas de nonce : `'self'` les autorise.
 * - Styles : `'unsafe-inline'` gardé (attributs style et blocs <style> nombreux). Ne JAMAIS ajouter de nonce à
 *   style-src : un nonce désactive `'unsafe-inline'`.
 * - Origines externes : seulement celles des constantes ci-dessous (voir CLAUDE.md, « En-têtes de sécurité et CSP »).
 */
final class ContentSecurityPolicy
{
    /** Tuiles OpenStreetMap des cartes Leaflet (map, race-route, route-preview : {s}.tile.openstreetmap.org). */
    public const IMG_ORIGINS = ['https://*.tile.openstreetmap.org'];

    /** Appels fetch() du navigateur : météo du tableau de bord (weather_controller.js, Open-Meteo + Nominatim). */
    public const CONNECT_ORIGINS = ['https://api.open-meteo.com', 'https://nominatim.openstreetmap.org'];

    /** Valeur base64 / base64url (grammaire « base64-value » de CSP), sans espace ni guillemet. */
    private const NONCE_PATTERN = '/^[A-Za-z0-9+\/_-]+={0,2}$/D';

    private function __construct()
    {
    }

    /**
     * En-tête complet pour ce nonce.
     *
     * @throws \InvalidArgumentException nonce vide ou hors de la grammaire base64 (protège l'en-tête d'une injection)
     */
    public static function header(string $nonce): string
    {
        if (preg_match(self::NONCE_PATTERN, $nonce) !== 1) {
            throw new \InvalidArgumentException('Invalid CSP nonce.');
        }

        $parts = [];
        foreach (self::directives($nonce) as $name => $sources) {
            $parts[] = $name.' '.implode(' ', $sources);
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function directives(string $nonce): array
    {
        return [
            'default-src'     => ["'self'"],
            'script-src'      => ["'self'", "'nonce-".$nonce."'"],
            'style-src'       => ["'self'", "'unsafe-inline'"],
            'img-src'         => ["'self'", 'data:', ...self::IMG_ORIGINS],
            // data: : police d'icônes (flèches précédent / suivant) intégrée au CSS que FullCalendar injecte
            'font-src'        => ["'self'", 'data:'],
            'connect-src'     => ["'self'", ...self::CONNECT_ORIGINS],
            'object-src'      => ["'none'"],
            'base-uri'        => ["'none'"],
            'form-action'     => ["'self'"],
            'frame-ancestors' => ["'none'"],
            'frame-src'       => ["'none'"],
            'worker-src'      => ["'none'"],
        ];
    }
}

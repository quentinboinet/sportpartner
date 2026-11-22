<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Choix de la langue (FR / EN), aussi sur /login et /register (PUBLIC_ACCESS dans security.yaml). La langue est
 * gardée en session, puis retour sur la page d'où l'on vient : seulement un Referer du même hôte, et seulement son
 * chemin et sa requête (redirection relative, jamais vers un autre site, ni « //autre-site »). Sinon : tableau de
 * bord si connecté, page de connexion sinon.
 */
class LocaleController extends AbstractController
{
    #[Route('/switch-locale/{locale}', name: 'app_switch_locale', requirements: ['locale' => 'fr|en'], methods: ['GET'])]
    public function switch(string $locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $locale);

        $target = self::sameHostTarget((string) $request->headers->get('referer', ''), $request->getHost());

        return $this->redirect($target ?? $this->generateUrl(null !== $this->getUser() ? 'app_dashboard' : 'app_login'));
    }

    /**
     * Chemin (+ requête) du Referer s'il vient du même hôte, null sinon. Refusés : autre hôte, chemin qui ne
     * commence pas par un seul « / » (« //hote » ou « /\hote » seraient lus comme un autre site), caractères de
     * contrôle, et la page de changement de langue elle-même.
     */
    public static function sameHostTarget(string $referer, string $host): ?string
    {
        // parse_url() remplacerait les caractères de contrôle par « _ » : un tel Referer est refusé en entier
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $referer)) {
            return null;
        }
        $parts = parse_url($referer);
        if (!\is_array($parts) || !isset($parts['host']) || 0 !== strcasecmp($parts['host'], $host)) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if (1 !== preg_match('#^/(?![/\\\\])#', $path) || str_contains($path, '\\') || str_starts_with($path, '/switch-locale/')) {
            return null;
        }
        $query = $parts['query'] ?? '';

        return $path.('' !== $query ? '?'.$query : '');
    }
}

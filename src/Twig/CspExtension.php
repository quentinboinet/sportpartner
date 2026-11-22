<?php

namespace App\Twig;

use App\EventSubscriber\SecurityHeadersSubscriber;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * csp_nonce() : nonce CSP de la requête principale (SecurityHeadersSubscriber), le même que celui de l'en-tête
 * Content-Security-Policy de la réponse. À poser sur tout <script> inline (nonce="{{ csp_nonce() }}") et à passer à
 * importmap() ({nonce: csp_nonce()}). Chaîne vide hors requête HTTP (rendu en ligne de commande).
 */
final class CspExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csp_nonce', $this->nonce(...)),
        ];
    }

    public function nonce(): string
    {
        $request = $this->requestStack->getMainRequest();

        return $request === null ? '' : SecurityHeadersSubscriber::nonce($request);
    }
}

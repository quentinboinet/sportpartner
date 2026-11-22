<?php

namespace App\EventSubscriber;

use App\Security\ContentSecurityPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * En-têtes de sécurité de la requête principale (voir CLAUDE.md, « En-têtes de sécurité et CSP ») :
 *
 * - requête : nonce CSP aléatoire (16 octets en base64) dans l'attribut NONCE_ATTRIBUTE, lu par csp_nonce() ;
 *   en prod, requête non HTTPS redirigée vers https (301 pour GET / HEAD, 308 sinon : méthode et corps gardés) ;
 * - réponse HTML : Content-Security-Policy (ContentSecurityPolicy::header()), X-Frame-Options, Referrer-Policy,
 *   Permissions-Policy ; X-Content-Type-Options sur toutes les réponses ; aucun en-tête déjà posé par un controller
 *   n'est remplacé (RacePublicController pose les siens) ;
 * - page HTML d'un utilisateur connecté : Cache-Control no-store + private (bouton Retour après une déconnexion sur un
 *   ordinateur partagé), sauf si le controller gère lui-même son cache (en-tête NO_AUTO_CACHE_CONTROL_HEADER) ;
 * - en prod, réponse HTTPS : Strict-Transport-Security (un an, sans includeSubDomains ni preload).
 *
 * Profileur et barre de debug (/_profiler, /_wdt, dev) : aucun en-tête. Pages d'erreur en debug : la CSP est retirée
 * par l'ErrorListener de Symfony (priorité -128, après ce subscriber).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public const NONCE_ATTRIBUTE = '_csp_nonce';

    public const PERMISSIONS_POLICY = 'camera=(), microphone=(), payment=(), usb=(), geolocation=(self)';

    public const REFERRER_POLICY = 'strict-origin-when-cross-origin';

    public const HSTS = 'max-age=31536000';

    /**
     * @param TokenStorageInterface|null $tokenStorage stockage SANS suivi d'usage de la session : le lire ne marque pas
     *                                                 la session comme utilisée
     */
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire(service: 'security.untracked_token_storage')]
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Après ValidateRequestListener (256, hôte de confiance vérifié), avant la session (128), le serveur
            // d'assets de dev (35), le routeur (32) et le pare-feu (8)
            KernelEvents::REQUEST  => ['onKernelRequest', 255],
            // Après ResponseListener (0, Content-Type posé), avant la barre de debug et l'ErrorListener (-128, qui
            // ajoutent leurs nonces ou retirent la CSP) et l'écouteur de session (-1000)
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    /**
     * Nonce CSP de cette requête (créé au besoin). Toujours celui de la requête PRINCIPALE : le contenu d'une
     * sous-requête est inséré dans sa réponse.
     */
    public static function nonce(Request $request): string
    {
        $nonce = $request->attributes->get(self::NONCE_ATTRIBUTE);
        if (!\is_string($nonce) || $nonce === '') {
            $nonce = base64_encode(random_bytes(16));
            $request->attributes->set(self::NONCE_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $request->attributes->set(self::NONCE_ATTRIBUTE, base64_encode(random_bytes(16)));

        if ($this->isProd() && !$request->isSecure()) {
            // getHost() : hôte déjà validé par trusted_hosts (prod), sans le port http
            $status = \in_array($request->getMethod(), ['GET', 'HEAD'], true)
                ? Response::HTTP_MOVED_PERMANENTLY
                : Response::HTTP_PERMANENTLY_REDIRECT;
            $event->setResponse(new RedirectResponse('https://'.$request->getHost().$request->getRequestUri(), $status));
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request  = $event->getRequest();
        $response = $event->getResponse();
        if (!$this->isProd() && self::isProfilerPath($request->getPathInfo())) {
            return;
        }
        $headers = $response->headers;

        if ($this->isProd() && $request->isSecure() && !$headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }

        if (!self::isHtml($response)) {
            return;
        }
        $defaults = [
            'Content-Security-Policy' => ContentSecurityPolicy::header(self::nonce($request)),
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => self::REFERRER_POLICY,
            'Permissions-Policy'      => self::PERMISSIONS_POLICY,
        ];
        foreach ($defaults as $name => $value) {
            if (!$headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        // En-tête testé d'abord : sur une page qui gère son cache (plan partagé public), le jeton n'est même pas lu
        if (!$headers->has(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER) && $this->isAuthenticated()) {
            $response->setPrivate();
            $headers->addCacheControlDirective('no-store');
        }
    }

    private function isProd(): bool
    {
        return $this->environment === 'prod';
    }

    private function isAuthenticated(): bool
    {
        return $this->tokenStorage?->getToken()?->getUser() !== null;
    }

    /** Réponse HTML (ou sans Content-Type : Response::prepare() la servirait en text/html). */
    private static function isHtml(Response $response): bool
    {
        $type = $response->headers->get('Content-Type');

        return $type === null || str_contains(strtolower($type), 'text/html');
    }

    private static function isProfilerPath(string $path): bool
    {
        return str_starts_with($path, '/_profiler') || str_starts_with($path, '/_wdt');
    }
}

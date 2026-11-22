<?php

namespace App\Controller\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RateLimitRetry;
use App\Service\Strava\StravaOAuthState;
use App\Service\Strava\StravaService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Connexion à Strava (OAuth) et synchro manuelle des activités. Utilisateur connecté seulement.
 *
 * - connect() garde un `state` aléatoire en session (StravaOAuthState) ; callback() le retire et le compare avant
 *   tout appel à Strava : absent, différent, déjà utilisé ou expiré = refus avec un message, Strava n'est pas appelé.
 * - Un athlète Strava déjà rattaché à un AUTRE compte n'est pas rattaché une seconde fois (message).
 * - Autorisations décochées sur l'écran de Strava (paramètre `scope`) : le compte est rattaché quand même, avec un
 *   message qui dit ce qui ne marchera pas ; sans lecture des activités, pas de synchro immédiate.
 * - Synchro : POST + CSRF 'strava_sync', une toutes les 5 minutes par utilisateur (limiteur « strava_sync », aussi
 *   consommé par la synchro qui suit la connexion).
 */
#[IsGranted('ROLE_USER')]
class StravaController extends AbstractController
{
    public const SYNC_CSRF_ID = 'strava_sync';

    public function __construct(
        private readonly StravaService $strava,
        private readonly LoggerInterface $logger,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/oauth/strava', name: 'app_oauth_strava', methods: ['GET'])]
    public function connect(Request $request, StravaOAuthState $state): Response
    {
        return $this->redirect($this->strava->getAuthorizationUrl($state->issue($request->getSession())));
    }

    #[Route('/oauth/strava/callback', name: 'app_oauth_strava_callback', methods: ['GET'])]
    public function callback(
        Request $request,
        StravaOAuthState $state,
        UserRepository $users,
        RateLimiterFactoryInterface $stravaSyncLimiter,
        #[CurrentUser] User $user,
    ): Response {
        // all() : un paramètre en tableau (?code[]=x) est refusé ici, sans erreur 400
        $query = $request->query->all();

        if (!$state->consume($request->getSession(), $query['state'] ?? null)) {
            $this->addFlash('error', 'strava.flash_state_invalid');

            return $this->redirectToRoute('app_dashboard');
        }
        if (isset($query['error'])) {
            $this->addFlash('warning', 'strava.flash_cancelled');

            return $this->redirectToRoute('app_dashboard');
        }
        $code = $query['code'] ?? null;
        if (!\is_string($code) || '' === $code || \strlen($code) > 512) {
            $this->addFlash('error', 'strava.flash_code_missing');

            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $tokenData = $this->strava->exchangeCodeForTokens($code);
        } catch (\Throwable $e) {
            $this->logger->warning('Strava token exchange failed: {message}', ['message' => $e->getMessage()]);
            $this->addFlash('error', 'strava.flash_connect_failed');

            return $this->redirectToRoute('app_dashboard');
        }

        $athleteId = StravaService::athleteId($tokenData);
        $other     = null !== $athleteId ? $users->findOneBy(['stravaAthleteId' => $athleteId]) : null;
        if (null !== $other && $other->getId() !== $user->getId()) {
            // Jetons jetés sans révocation : révoquer couperait aussi l'accès du compte déjà rattaché
            $this->logger->notice('Strava athlete already linked to another account', ['user_id' => $user->getId()]);
            $this->addFlash('error', 'strava.flash_athlete_taken');

            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $this->strava->connectUser($user, $tokenData);
        } catch (\Throwable $e) {
            $this->logger->error('Strava account link failed', ['exception' => $e]);
            $this->addFlash('error', 'strava.flash_connect_failed');

            return $this->redirectToRoute('app_dashboard');
        }
        $this->addFlash('success', 'strava.flash_connected');

        // Autorisations accordées : absentes du retour = inconnues, on ne signale rien
        $scope = $query['scope'] ?? null;
        $canSync = true;
        if (\is_string($scope)) {
            $missing = StravaService::missingScopes($scope);
            $canSync = StravaService::grantsActivities($scope);
            if (\in_array('activity:read_all', $missing, true)) {
                $this->addFlash('info', $canSync ? 'strava.scope_missing_private' : 'strava.scope_missing_activities');
            }
            if (\in_array('profile:read_all', $missing, true)) {
                $this->addFlash('info', 'strava.scope_missing_profile');
            }
        }

        if ($canSync) {
            $stravaSyncLimiter->create('user-'.$user->getId())->consume();
            $this->runSync($user);
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/strava/sync', name: 'app_strava_sync', methods: ['POST'])]
    public function sync(
        Request $request,
        RateLimiterFactoryInterface $stravaSyncLimiter,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid(self::SYNC_CSRF_ID, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'strava.flash_csrf');

            return $this->redirectToRoute('app_dashboard');
        }
        if (!$user->getStravaAccessToken()) {
            $this->addFlash('warning', 'strava.flash_not_connected');

            return $this->redirectToRoute('app_dashboard');
        }

        $limit = $stravaSyncLimiter->create('user-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            $this->addFlash('info', $this->translator->trans('strava.flash_sync_cooldown', ['%minutes%' => RateLimitRetry::minutes($limit)]));

            return $this->redirectToRoute('app_dashboard');
        }

        $this->runSync($user);

        return $this->redirectToRoute('app_dashboard');
    }

    private function runSync(User $user): void
    {
        try {
            $count = $this->strava->syncUserActivities($user);
        } catch (\Throwable $e) {
            $this->logger->warning('Strava sync failed: {message}', ['message' => $e->getMessage()]);
            $this->addFlash('error', 'strava.flash_sync_failed');

            return;
        }

        $this->addFlash('success', $this->translator->trans('strava.flash_synced', ['%count%' => $count]));
    }
}

<?php

namespace App\Controller\OAuth;

use App\Service\Strava\StravaService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StravaController extends AbstractController
{
    #[Route('/oauth/strava', name: 'app_oauth_strava')]
    public function connect(StravaService $oauthService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $state = bin2hex(random_bytes(16));

        return $this->redirect($oauthService->getAuthorizationUrl($state));
    }

    #[Route('/oauth/strava/callback', name: 'app_oauth_strava_callback')]
    public function callback(Request $request, StravaService $oauthService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $error = $request->query->get('error');
        if ($error) {
            $this->addFlash('warning', 'Connexion Strava annulée.');
            return $this->redirectToRoute('app_dashboard');
        }

        $code = $request->query->get('code');
        if (!$code) {
            $this->addFlash('danger', 'Code OAuth manquant.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $tokenData = $oauthService->exchangeCodeForTokens($code);
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $oauthService->connectUser($user, $tokenData);
            $this->addFlash('success', 'Strava connecté avec succès !');

            // puis on lance synchro immédiate des activités
            $oauthService->syncUserActivities($user);
            $this->addFlash('info', 'Synchronisation Strava en cours...');

        } catch (\Throwable) {
            $this->addFlash('danger', 'Erreur lors de la connexion Strava.');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/strava/sync', name: 'app_strava_sync')]
    public function sync(Request $request, StravaService $oauthService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if(!$user->getStravaAccessToken()) {
            $this->addFlash('warning', 'Aucun compte Strava connecté.');
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            //on lance synchro immédiate des activités
            $oauthService->syncUserActivities($user);
            $this->addFlash('info', 'Synchronisation Strava en cours...');

        } catch (\Throwable) {
            $this->addFlash('danger', 'Erreur lors de la synchronisation Strava.');
        }

        return $this->redirectToRoute('app_dashboard');
    }
}

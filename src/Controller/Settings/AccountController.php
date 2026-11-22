<?php

namespace App\Controller\Settings;

use App\Entity\User;
use App\Security\RateLimitRetry;
use App\Service\Strava\StravaDeauthorizer;
use App\Service\User\AccountDeleter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Suppression du compte (Paramètres > Compte, zone de danger), droit à l'effacement du RGPD.
 *
 * POST + CSRF 'account_delete', mot de passe actuel et case « Je comprends que c'est définitif ».
 * Aucun identifiant dans l'URL : l'utilisateur connecté ne peut supprimer que son propre compte.
 * Jeton CSRF invalide, case non cochée ou mauvais mot de passe : message d'erreur sur l'onglet Compte,
 * rien n'est supprimé. Sinon : accès Strava retiré au mieux (StravaDeauthorizer, un échec ne bloque pas),
 * suppression en une transaction (AccountDeleter), déconnexion sans second jeton CSRF (session
 * invalidée, cookie remember-me effacé) et retour sur la page de connexion avec un message.
 *
 * Essais limités : 5 par 15 minutes par utilisateur (limiteur « account_delete », compté après le jeton CSRF et la
 * case, avant la vérification du mot de passe) ; au-delà, message sur l'onglet Compte, mot de passe non vérifié.
 */
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public const CSRF_ID = 'account_delete';

    #[Route('/settings/account/delete', name: 'app_account_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        UserPasswordHasherInterface $hasher,
        StravaDeauthorizer $strava,
        AccountDeleter $deleter,
        Security $security,
        LoggerInterface $logger,
        RateLimiterFactoryInterface $accountDeleteLimiter,
        TranslatorInterface $translator,
        #[CurrentUser] User $user,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            return $this->fail('account.error_csrf');
        }
        if ($request->request->get('confirm') !== '1') {
            return $this->fail('account.error_confirm');
        }
        $limit = $accountDeleteLimiter->create('user-'.$user->getId())->consume();
        if (!$limit->isAccepted()) {
            $logger->notice('Account deletion refused: too many attempts', ['user_id' => $user->getId()]);

            return $this->fail($translator->trans('account.error_rate_limited', ['%minutes%' => RateLimitRetry::minutes($limit)]));
        }
        $password = (string) $request->request->get('password', '');
        if ($password === '' || !$hasher->isPasswordValid($user, $password)) {
            $logger->notice('Account deletion refused: wrong password', ['user_id' => $user->getId()]);

            return $this->fail('account.error_password');
        }

        $userId      = $user->getId();
        $stravaState = $strava->deauthorize($user);

        try {
            $deleter->delete($user);
        } catch (\Throwable $e) {
            $logger->error('Account deletion failed', ['user_id' => $userId, 'exception' => $e]);

            return $this->fail('account.error_failed');
        }
        $logger->notice('Account deleted', ['user_id' => $userId, 'strava' => $stravaState]);

        $session  = $request->getSession();
        $locale   = $session->get('_locale');
        $response = $security->logout(false);

        // Session invalidée par la déconnexion : langue et messages passent dans la nouvelle session
        if (is_string($locale)) {
            $session->set('_locale', $locale);
        }
        $this->addFlash('success', 'account.flash_deleted');
        if ($stravaState === StravaDeauthorizer::FAILED) {
            $this->addFlash('warning', 'account.flash_strava_failed');
        }

        return $response ?? $this->redirectToRoute('app_login');
    }

    private function fail(string $message): Response
    {
        $this->addFlash('error', $message);

        // Sans ancre : sans JS, le message reste visible en haut de la page (avec JS, il passe dans la zone de danger)
        return $this->redirectToRoute('app_settings', ['tab' => 'account']);
    }
}

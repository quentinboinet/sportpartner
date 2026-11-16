<?php

namespace App\Controller\Health;

use App\Entity\HealthSession;
use App\Entity\User;
use App\Repository\HealthSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Historique des séances Santé : liste paginée, détail (exercices faits, ressenti, notes, activité liée,
 * « Refaire cette séance ») et suppression. Séance d'un autre utilisateur : 404 en lecture, 403 en écriture.
 * Suppression : l'activité créée par la séance est supprimée aussi si la case est cochée (cochée par défaut),
 * sinon elle reste dans les activités.
 */
#[IsGranted('ROLE_USER')]
class HealthHistoryController extends AbstractController
{
    private const PER_PAGE = 20;

    #[Route('/sante/historique', name: 'app_health_history', methods: ['GET'])]
    public function index(Request $request, HealthSessionRepository $sessions, #[CurrentUser] User $user): Response
    {
        $total = $sessions->countForUser($user);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('page', 1)), $pages);

        return $this->render('health/history.html.twig', [
            'sessions' => $sessions->findPageForUser($user, $page, self::PER_PAGE),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
        ]);
    }

    #[Route('/sante/historique/{id}', name: 'app_health_history_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(HealthSession $session, #[CurrentUser] User $user): Response
    {
        if ($session->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        return $this->render('health/history_show.html.twig', ['session' => $session]);
    }

    #[Route('/sante/historique/{id}/supprimer', name: 'app_health_history_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(HealthSession $session, Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        if ($session->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid(HealthController::CSRF_ID, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'health.flash.csrf');

            return $this->redirectToRoute('app_health_history_show', ['id' => $session->getId()]);
        }

        $activity = $session->getActivity();
        $withActivity = $activity !== null && $request->request->getBoolean('delete_activity');
        $em->wrapInTransaction(static function (EntityManagerInterface $em) use ($session, $activity, $withActivity): void {
            $em->remove($session);
            if ($withActivity && $activity !== null) {
                $em->remove($activity);
            }
        });
        $this->addFlash('success', $withActivity ? 'health.flash.session_deleted_activity' : 'health.flash.session_deleted');

        return $this->redirectToRoute('app_health_history');
    }
}

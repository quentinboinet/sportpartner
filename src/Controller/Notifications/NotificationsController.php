<?php

namespace App\Controller\Notifications;

use App\Entity\User;
use App\Service\Notifications\TodoPresenter;
use App\Service\Notifications\TodoProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Liste « À faire » de la cloche (barre du haut), en JSON, chargée après l'affichage de la page par
 * notifications_controller.js. Données de l'utilisateur connecté seulement, calculées à chaque appel
 * (aucune table, aucune tâche planifiée, aucun appel externe) : voir TodoBuilder pour les règles.
 *
 * Contrat : {items: [{key, severity: urgent|warning|info|success, icon, title, subtitle|null, url}]}, dans
 * l'ordre d'affichage ; textes en texte brut dans la langue de la session.
 */
#[IsGranted('ROLE_USER')]
final class NotificationsController extends AbstractController
{
    #[Route('/notifications', name: 'app_notifications', methods: ['GET'])]
    public function __invoke(Request $request, TodoProvider $provider, TodoPresenter $presenter, #[CurrentUser] User $user): JsonResponse
    {
        $response = $this->json(['items' => $presenter->present($provider->forUser($user), $request->getLocale())]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}

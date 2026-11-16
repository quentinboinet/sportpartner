<?php

declare(strict_types=1);

namespace App\Controller\Race;

use App\Entity\Race;
use App\Entity\User;
use App\Repository\RaceRouteRepository;
use App\Service\Weather\RaceWeatherService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Météo du jour J d'une course, en JSON (carte de la page de la course, chargée après
 * l'affichage par race_weather_controller.js). Contrat : voir RaceWeatherService::forRace().
 * ?retry=1 force un nouvel appel à Open-Meteo si le dernier a échoué.
 */
#[IsGranted('ROLE_USER')]
final class RaceWeatherController extends AbstractController
{
    #[Route('/courses/{id}/weather', name: 'app_race_weather', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(
        Race $race,
        Request $request,
        RaceRouteRepository $routes,
        RaceWeatherService $weather,
        TranslatorInterface $translator,
        #[CurrentUser] User $user,
    ): JsonResponse {
        if ($race->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $data = $weather->forRace($race, $routes->findOneByRace($race), $request->query->getBoolean('retry'));

        // Départ du GPX sans lieu saisi : libellé générique
        if (is_array($data['place'] ?? null) && ($data['place']['name'] ?? null) === null) {
            $data['place']['name'] = $translator->trans('race_weather.gpx_start');
        }

        $response = $this->json($data);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}

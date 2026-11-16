<?php
namespace App\Controller\Race;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceScenario;
use App\Entity\User;
use App\Repository\RaceCheckpointRepository;
use App\Repository\RaceRouteRepository;
use App\Repository\RaceScenarioRepository;
use App\Service\Nutrition\InvalidFuelPayloadException;
use App\Service\Nutrition\RaceNutritionPayloadValidator;
use App\Service\Nutrition\RaceNutritionStore;
use App\Service\Route\ScenarioViewBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Plan nutrition d'un scénario de course (section Nutrition de la page plan, race_nutrition_controller.js).
 *
 *  - save : enregistrement automatique en JSON de TOUT l'état du plan du scénario (réglages, produits, lignes
 *    manuelles ; contrat : RaceNutritionPayloadValidator), remplacé en une transaction (RaceNutritionStore::save()).
 *    Jeton CSRF "race_nutrition" dans l'en-tête X-CSRF-Token. Réponses : 200 {"saved": true} ; 403 si la course est
 *    à un autre utilisateur ; 403 {"error": "invalid_csrf_token"} ; 404 scénario d'une autre course ; 409 conflit
 *    (deux premiers enregistrements simultanés) ; 422 {"error": "...", "message": "..."} si la saisie est refusée
 *    (rien n'est enregistré). Les apports auto ne sont jamais envoyés ni stockés ;
 *  - copy : "Copier le plan nutrition de…" (formulaire, CSRF "race_plan", champ from = scénario source de la même
 *    course) : réglages, produits et lignes manuelles copiés, puis retour sur la section (#nutrition-{sid}).
 */
#[Route('/courses/{id}', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class RaceNutritionController extends AbstractController
{
    /** Jeton CSRF (csrf_token('race_nutrition')), envoyé dans l'en-tête X-CSRF-Token. */
    public const CSRF_ID = 'race_nutrition';
    public const CSRF_HEADER = 'X-CSRF-Token';

    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly RaceScenarioRepository $scenarios,
        private readonly RaceNutritionStore $store,
    ) {
    }

    #[Route('/scenarios/{sid}/nutrition', name: 'app_race_nutrition_save', requirements: ['sid' => '\d+'], methods: ['POST'])]
    public function save(
        Race $race,
        int $sid,
        Request $request,
        RaceNutritionPayloadValidator $validator,
        RaceRouteRepository $routes,
        RaceCheckpointRepository $checkpoints,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->denyUnlessOwner($race, $user);
        $scenario = $this->findScenario($race, $sid);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get(self::CSRF_HEADER, ''))) {
            return $this->error(403, 'invalid_csrf_token', 'Invalid CSRF token.');
        }

        $body = $request->getContent();
        if (\strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error(422, 'invalid_payload', 'Payload too large.');
        }
        try {
            $state = $validator->validate(json_decode($body, true, 16, \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return $this->error(422, 'invalid_json', 'Malformed JSON.');
        } catch (InvalidFuelPayloadException $e) {
            return $this->error(422, 'invalid_payload', $e->getMessage());
        }

        $table = ScenarioViewBuilder::routeCheckpoints($routes->findOneByRace($race), $checkpoints->findByRaceOrdered($race));
        try {
            $this->store->save($scenario, $state, array_map(static fn (RaceCheckpoint $c): int => (int) $c->getId(), $table));
        } catch (UniqueConstraintViolationException) {
            return $this->error(409, 'conflict', 'Concurrent save, retry.');
        }

        return $this->noStore($this->json(['saved' => true]));
    }

    #[Route('/scenarios/{sid}/nutrition/copy', name: 'app_race_nutrition_copy', requirements: ['sid' => '\d+'], methods: ['POST'])]
    public function copy(Race $race, int $sid, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessOwner($race, $user);
        $target = $this->findScenario($race, $sid);
        $back = $this->redirectToRoute('app_race_plan', ['id' => $race->getId(), '_fragment' => 'nutrition-'.$sid]);
        if (!$this->isCsrfTokenValid('race_plan', (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'race_plan.flash_csrf');

            return $back;
        }

        $from = $request->request->all()['from'] ?? null;
        $source = \is_string($from) && ctype_digit($from) ? $this->scenarios->find((int) $from) : null;
        if (null === $source || $source->getRace()->getId() !== $race->getId() || $source->getId() === $target->getId()) {
            $this->addFlash('error', 'race_nutrition.flash_copy_invalid');

            return $back;
        }

        $this->store->copy($source, $target);
        $this->addFlash('success', 'race_nutrition.flash_copied');

        return $back;
    }

    private function findScenario(Race $race, int $sid): RaceScenario
    {
        $scenario = $this->scenarios->find($sid);
        if (null === $scenario || $scenario->getRace()->getId() !== $race->getId()) {
            throw $this->createNotFoundException();
        }

        return $scenario;
    }

    /** Course d'un autre utilisateur : 403, comme les autres actions des courses. */
    private function denyUnlessOwner(Race $race, User $user): void
    {
        if ($race->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return $this->noStore($this->json(['error' => $code, 'message' => $message], $status));
    }

    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}

<?php

namespace App\Controller\Activities;

use App\Entity\Activity;
use App\Entity\User;
use App\Repository\ActivityFuelRepository;
use App\Service\Nutrition\ActivityFuelCalculator;
use App\Service\Nutrition\FuelCatalog;
use App\Service\Nutrition\FuelItem;
use App\Service\Nutrition\FuelPayloadValidator;
use App\Service\Nutrition\InvalidFuelPayloadException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Enregistrement automatique de la carte Ravitaillement (activity_fuel_controller.js) : le JSON décrit TOUT
 * l'état de la carte (contrat : FuelPayloadValidator), les lignes de l'activité sont remplacées en une
 * transaction et le bilan recalculé est renvoyé.
 *
 * Réponses : 200 {"saved": true, "summary": {...}} (FuelSummary::toArray()) ; 403 si l'activité est à un
 * autre utilisateur ; 403 {"error": "invalid_csrf_token"} ; 422 {"error": "...", "message": "..."} si la
 * saisie est refusée (rien n'est enregistré).
 */
#[IsGranted('ROLE_USER')]
final class ActivityFuelController extends AbstractController
{
    /** Jeton CSRF (csrf_token('activity_fuel')), envoyé dans l'en-tête X-CSRF-Token. */
    public const CSRF_ID = 'activity_fuel';
    public const CSRF_HEADER = 'X-CSRF-Token';

    private const MAX_BODY_BYTES = 32768;

    #[Route('/activities/{id}/fuel', name: 'app_activity_fuel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function __invoke(
        Activity $activity,
        Request $request,
        FuelPayloadValidator $validator,
        ActivityFuelRepository $repository,
        ActivityFuelCalculator $calculator,
        #[CurrentUser] User $user,
    ): JsonResponse {
        if ($activity->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get(self::CSRF_HEADER, ''))) {
            return $this->error(403, 'invalid_csrf_token', 'Invalid CSRF token.');
        }
        if (!FuelCatalog::supportsSportSlug($activity->getSport()?->getSlug())) {
            return $this->error(422, 'unsupported_sport', 'Fueling is not tracked for this sport.');
        }

        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error(422, 'invalid_payload', 'Payload too large.');
        }
        try {
            $items = $validator->validate(json_decode($body, true, 16, \JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return $this->error(422, 'invalid_json', 'Malformed JSON.');
        } catch (InvalidFuelPayloadException $e) {
            return $this->error(422, 'invalid_payload', $e->getMessage());
        }

        $repository->replaceForActivity($activity, array_values(array_filter($items, static fn (FuelItem $item): bool => $item->isStorable())));
        $duration = ActivityFuelCalculator::durationSeconds($activity->getMovingTimeSeconds(), $activity->getElapsedTimeSeconds());

        // liquidLiters reste un décimal en JSON (1.0, pas 1)
        return $this->noStore($this->json([
            'saved'   => true,
            'summary' => $calculator->summarize($items, $duration)->toArray(),
        ], context: ['json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRESERVE_ZERO_FRACTION]));
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

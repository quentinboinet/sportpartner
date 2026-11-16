<?php

namespace App\Controller\Health;

use App\Entity\HealthDosageMode;
use App\Entity\HealthSession;
use App\Entity\User;
use App\Repository\HealthExerciseRepository;
use App\Repository\HealthSessionRepository;
use App\Repository\SportRepository;
use App\Service\Health\HealthActivityMapper;
use App\Service\Health\HealthExerciseViewBuilder;
use App\Service\Health\HealthLimits;
use App\Service\Health\HealthSessionFactory;
use App\Service\Health\HealthSessionPayloadValidator;
use App\Service\Health\InvalidHealthPayloadException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Séance guidée de l'espace Santé : page du plateau et du lecteur (health_session_controller.js) et
 * enregistrement de la séance terminée.
 *
 * Le plateau (« séance en cours ») est un brouillon gardé dans le navigateur (localStorage, une clé par
 * utilisateur) : il est instantané, marche hors ligne pendant la séance et ne crée aucune écriture serveur pour
 * un simple brouillon ; il n'est pas partagé entre appareils. La page embarque le catalogue des exercices visibles
 * (textes dans la langue de l'interface) : le plateau ne stocke que des ids et des dosages.
 * ?refaire={id} : préremplit le plateau avec les exercices d'une séance de l'historique (de l'utilisateur).
 */
#[IsGranted('ROLE_USER')]
class HealthSessionController extends AbstractController
{
    /** Jeton CSRF (csrf_token('health_session')), envoyé dans l'en-tête X-CSRF-Token. */
    public const CSRF_ID = 'health_session';
    public const CSRF_HEADER = 'X-CSRF-Token';

    private const MAX_BODY_BYTES = 65536;

    #[Route('/sante/seance', name: 'app_health_session', methods: ['GET'])]
    public function index(Request $request, HealthExerciseViewBuilder $views, HealthSessionRepository $sessions, #[CurrentUser] User $user): Response
    {
        $catalog = [];
        foreach ($views->forUser($user, $request->getLocale()) as $view) {
            $catalog[(string) $view->id] = $view->toPlayer($this->generateUrl('app_health_exercise_show', ['id' => $view->id]));
        }

        $replay = null;
        $replayId = $request->query->getInt('refaire');
        if ($replayId > 0) {
            $session = $sessions->find($replayId);
            if ($session instanceof HealthSession && $session->getUser()->getId() === $user->getId()) {
                $replay = self::replayItems($session, $catalog);
            }
        }

        return $this->render('health/session.html.twig', [
            'catalog' => $catalog,
            'replay'  => $replay,
            'limits'  => [
                'setsMin'   => HealthLimits::SETS_MIN,
                'setsMax'   => HealthLimits::SETS_MAX,
                'restMin'   => HealthLimits::REST_MIN,
                'restMax'   => HealthLimits::REST_MAX,
                'reps'      => [HealthDosageMode::Reps->minValue(), HealthDosageMode::Reps->maxValue()],
                'time'      => [HealthDosageMode::Time->minValue(), HealthDosageMode::Time->maxValue()],
                'switch'    => HealthLimits::SIDE_SWITCH_SECONDS,
                'prep'      => HealthLimits::PREP_SECONDS,
                'exercises'   => HealthLimits::SESSION_EXERCISES_MAX,
                'name'        => HealthLimits::SESSION_NAME_MAX,
                'notes'       => HealthLimits::NOTES_MAX,
                'durationMax' => HealthLimits::DURATION_MAX,
                'elapsedMax'  => HealthLimits::ELAPSED_MAX,
            ],
        ]);
    }

    /**
     * Enregistre la séance terminée (contrat JSON : HealthSessionPayloadValidator) et, si demandé, l'activité
     * correspondante (HealthActivityMapper) dans la même transaction.
     *
     * Réponses : 201 {"saved": true, "id", "url", "activityId"} ; 403 {"error": "invalid_csrf_token"} ;
     * 422 {"error": "invalid_json" | "invalid_payload" | "invalid_start" | "nothing_done" | "unknown_exercise", "message"}.
     */
    #[Route('/sante/seance/enregistrer', name: 'app_health_session_save', methods: ['POST'])]
    public function save(
        Request $request,
        HealthSessionPayloadValidator $validator,
        HealthExerciseRepository $exercises,
        SportRepository $sports,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        ClockInterface $clock,
        #[CurrentUser] User $user,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->headers->get(self::CSRF_HEADER, ''))) {
            return $this->error(403, 'invalid_csrf_token', 'Invalid CSRF token.');
        }
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error(422, 'invalid_payload', 'Payload too large.');
        }

        try {
            $payload = $validator->validate(json_decode($body, true, 16, \JSON_THROW_ON_ERROR), $clock->now());
            $ids = array_map(static fn ($e): int => $e->exerciseId, $payload->exercises);
            $locale = $request->getLocale();
            $session = HealthSessionFactory::create(
                $user,
                $payload,
                $exercises->findVisibleByIds($ids, $user),
                $locale,
                static fn (string $kind): string => $translator->trans('health.session.default_name.'.$kind, [], 'messages', $locale),
            );
        } catch (\JsonException) {
            return $this->error(422, 'invalid_json', 'Malformed JSON.');
        } catch (InvalidHealthPayloadException $e) {
            return $this->error(422, $e->errorCode, $e->getMessage());
        }

        $em->wrapInTransaction(static function (EntityManagerInterface $em) use ($session, $payload, $sports): void {
            if ($payload->addActivity) {
                $slug = HealthActivityMapper::sportSlug(HealthActivityMapper::doneCategories($session), $payload->sport);
                $activity = HealthActivityMapper::toActivity($session, $sports->findBySlug($slug));
                $em->persist($activity);
                $session->setActivity($activity);
            }
            $em->persist($session);
        });

        $this->addFlash('success', $session->getActivity() !== null ? 'health.flash.session_saved_activity' : 'health.flash.session_saved');

        return $this->noStore($this->json([
            'saved'      => true,
            'id'         => $session->getId(),
            'url'        => $this->generateUrl('app_health_history_show', ['id' => $session->getId()]),
            'activityId' => $session->getActivity()?->getId(),
        ], Response::HTTP_CREATED));
    }

    /**
     * Exercices d'une séance passée encore disponibles, au format du plateau.
     *
     * @param array<string, array<string, mixed>> $catalog
     *
     * @return array{items: list<array{id: int, sets: int, value: int, rest: int}>, missing: int, date: string}
     */
    private static function replayItems(HealthSession $session, array $catalog): array
    {
        $items = [];
        $missing = 0;
        foreach ($session->getExercises() as $exercise) {
            $id = $exercise->getExercise()?->getId();
            if ($id === null || !isset($catalog[(string) $id])) {
                ++$missing;
                continue;
            }
            $items[] = ['id' => $id, 'sets' => $exercise->getSets(), 'value' => $exercise->getValue(), 'rest' => $exercise->getRestSeconds()];
        }

        return ['items' => $items, 'missing' => $missing, 'date' => $session->getLocalStart()->format('Y-m-d')];
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

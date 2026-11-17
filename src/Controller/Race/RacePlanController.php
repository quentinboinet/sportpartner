<?php
namespace App\Controller\Race;

use App\Entity\CheckpointType;
use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceRoute;
use App\Entity\RaceShare;
use App\Repository\RaceCheckpointRepository;
use App\Repository\RaceRouteRepository;
use App\Service\Nutrition\RaceNutritionCatalog;
use App\Service\Nutrition\RaceNutritionStore;
use App\Service\Nutrition\RaceNutritionViewBuilder;
use App\Service\Route\GpxParser;
use App\Service\Route\InvalidGpxException;
use App\Service\Route\ParsedRoute;
use App\Service\Route\RemoteGpxException;
use App\Service\Route\RemoteGpxFetcher;
use App\Service\Route\RouteAnalyzer;
use App\Service\Route\ScenarioViewBuilder;
use App\Service\Share\ShareSettingsParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Plan de course : parcours GPX importé + repères (points de passage, ravitaillements).
 * Repère supprimé, ajouté ou déplacé : les lignes manuelles du plan nutrition suivent les règles de
 * App\Service\Nutrition\RaceNutritionRows (report sur le tronçon suivant à la suppression, avec un message).
 */
#[Route('/courses/{id}', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class RacePlanController extends AbstractController
{
    private const CSRF_ID = 'race_plan';
    private const MAX_GPX_BYTES = GpxParser::MAX_BYTES;
    private const MAX_DISTANCE_M = 1_000_000;      // sans parcours : 1000 km max
    private const ROUTE_TOLERANCE_M = 50;           // saisie arrondie à 0,1 km au-delà de l'arrivée
    private const MAX_STOP_MINUTES = 24 * 60;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_NOTES_LENGTH = 5000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RaceRouteRepository $routes,
        private readonly RaceCheckpointRepository $checkpoints,
        private readonly RaceNutritionStore $nutrition,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/plan', name: 'app_race_plan', methods: ['GET'])]
    public function plan(Race $race, RouteAnalyzer $analyzer, ScenarioViewBuilder $scenarioBuilder, RaceNutritionViewBuilder $nutritionBuilder): Response
    {
        $this->denyUnlessOwner($race);

        $route       = $this->routes->findOneByRace($race);
        $checkpoints = $this->checkpoints->findByRaceOrdered($race);
        $points      = $route?->getPoints() ?? [];
        $routeEnd    = $route?->getDistanceM();

        $rows  = [];
        $data  = [];
        $marks = [];
        foreach ($checkpoints as $cp) {
            $beyond = $routeEnd !== null && $cp->getDistanceM() > $routeEnd;
            $ele    = $route !== null && !$beyond ? $analyzer->elevationAt($points, $cp->getDistanceM()) : null;
            $cutoff = $cp->getCutoffSeconds() !== null ? self::formatCutoff($cp->getCutoffSeconds()) : null;

            $rows[] = [
                'checkpoint'  => $cp,
                'distanceKm'  => round($cp->getDistanceM() / 1000, 2),
                'ele'         => $ele,
                'beyondRoute' => $beyond,
                'cutoff'      => $cutoff,
            ];
            $data[] = [
                'id'          => (int) $cp->getId(),
                'name'        => $cp->getName(),
                'type'        => $cp->getType()->value,
                'distanceM'   => $cp->getDistanceM(),
                'ele'         => $ele,
                'stopMinutes' => $cp->getStopSeconds() !== null ? intdiv($cp->getStopSeconds(), 60) : null,
                'cutoff'      => $cutoff,
                'notes'       => $cp->getNotes(),
                'publicMessage' => $cp->getPublicMessage(),
                'assistance'  => $cp->hasAssistance(),
            ];
            // Un repère au-delà de l'arrivée (parcours remplacé par un plus court) ne découpe pas le parcours
            if (!$beyond) {
                $marks[] = ['name' => $cp->getName(), 'distanceM' => $cp->getDistanceM()];
            }
        }

        $scenarioView = $scenarioBuilder->buildWith($race, $route, $checkpoints);
        $date         = $race->getRaceDate();

        return $this->render('race/plan.html.twig', [
            'race'            => $race,
            // En-tête : jours avant la course (même calcul que la page de la course), null sans date
            'daysLeft'        => $date !== null ? (int) (new \DateTimeImmutable('today'))->diff($date)->format('%r%a') : null,
            'route'           => $route,
            'checkpoints'     => $checkpoints,
            'segments'        => $route !== null ? $analyzer->segments($points, $marks, $route->getDistanceM()) : [],
            'checkpointRows'  => $rows,
            'routeData'       => $route !== null ? [
                'points'    => $points,
                'distanceM' => $route->getDistanceM(),
                'gainM'     => $route->getGainM(),
                'lossM'     => $route->getLossM(),
                'minEle'    => $route->getMinEle(),
                'maxEle'    => $route->getMaxEle(),
                'fileName'  => $route->getFileName(),
                'sourceUrl' => $route->getSourceUrl(),
            ] : null,
            'checkpointsData' => $data,
            'checkpointTypes' => CheckpointType::cases(),
            // Phase 2 : scénarios et temps de passage (partial race/_scenarios.html.twig)
            'scenarioView'    => $scenarioView,
            // Plan nutrition de chaque scénario (partial race/_nutrition.html.twig)
            'nutritionView'   => $nutritionBuilder->build($race, $scenarioView),
            'nutritionConfig' => RaceNutritionCatalog::config(),
        ]);
    }

    #[Route('/route', name: 'app_race_route_import', methods: ['POST'])]
    public function importRoute(Race $race, Request $request, GpxParser $parser, RemoteGpxFetcher $fetcher): Response
    {
        $this->denyUnlessOwner($race);

        // POST plus gros que post_max_size : PHP vide $_POST et $_FILES (le jeton CSRF est donc absent)
        if ($request->request->count() === 0 && $request->files->count() === 0 && (int) $request->server->get('CONTENT_LENGTH', 0) > 0) {
            return $this->fail($race, 'race_plan.flash_gpx_too_large');
        }
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race);
        }

        $file = $request->files->get('gpx');
        $url  = trim((string) $request->request->get('gpx_url', ''));

        // Import par lien (champ gpx_url) quand aucun fichier n'est envoyé
        if ((!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) && $url !== '') {
            return $this->importFromUrl($race, $url, $parser, $fetcher);
        }

        if (!$file instanceof UploadedFile || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->fail($race, 'race_plan.flash_gpx_missing');
        }
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->fail($race, 'race_plan.flash_gpx_too_large');
        }
        if (!$file->isValid()) {
            return $this->fail($race, 'race_plan.flash_gpx_invalid');
        }

        $size = $file->getSize();
        if ($size > self::MAX_GPX_BYTES) {
            return $this->fail($race, 'race_plan.flash_gpx_too_large');
        }

        $xml = @file_get_contents($file->getPathname());
        if ($xml === false || $xml === '' || !self::looksLikeGpx($file, $xml)) {
            return $this->fail($race, 'race_plan.flash_gpx_invalid');
        }

        try {
            $parsed = $parser->parse($xml);
        } catch (InvalidGpxException $e) {
            return $this->fail($race, $e->getCode() === InvalidGpxException::TOO_LARGE ? 'race_plan.flash_gpx_too_large' : 'race_plan.flash_gpx_invalid');
        }
        unset($xml);

        return $this->storeRoute($race, $parsed, self::sanitizeFileName($file->getClientOriginalName()), null);
    }

    /** Télécharge le GPX (protection SSRF dans RemoteGpxFetcher), le parse et l'enregistre. */
    private function importFromUrl(Race $race, string $url, GpxParser $parser, RemoteGpxFetcher $fetcher): Response
    {
        try {
            $remote = $fetcher->fetch($url);
        } catch (RemoteGpxException $e) {
            return $this->fail($race, match ($e->getCode()) {
                RemoteGpxException::INVALID_URL => 'race_plan.flash_url_invalid',
                RemoteGpxException::BLOCKED     => 'race_plan.flash_url_blocked',
                RemoteGpxException::HTTP_ERROR  => 'race_plan.flash_url_http_error',
                RemoteGpxException::TOO_LARGE   => 'race_plan.flash_gpx_too_large',
                RemoteGpxException::NOT_GPX     => 'race_plan.flash_url_not_gpx',
                default                         => 'race_plan.flash_url_unreachable',
            });
        }

        try {
            $parsed = $parser->parse($remote->content);
        } catch (InvalidGpxException $e) {
            return $this->fail($race, $e->getCode() === InvalidGpxException::TOO_LARGE ? 'race_plan.flash_gpx_too_large' : 'race_plan.flash_url_not_gpx');
        }

        return $this->storeRoute($race, $parsed, self::sanitizeFileName($remote->fileName), $remote->sourceUrl);
    }

    private function storeRoute(Race $race, ParsedRoute $parsed, string $fileName, ?string $sourceUrl): Response
    {
        if (count($parsed->points) < 2 || $parsed->distanceM <= 0) {
            return $this->fail($race, 'race_plan.flash_gpx_invalid');
        }

        // Remplace le parcours existant (une seule ligne par course) ; les repères sont conservés
        $route = $this->routes->findOneByRace($race) ?? (new RaceRoute())->setRace($race);
        $route
            ->setFileName($fileName)
            ->setSourceUrl($sourceUrl)
            ->setPoints($parsed->points)
            ->setDistanceM($parsed->distanceM)
            ->setGainM($parsed->gainM)
            ->setLossM($parsed->lossM)
            ->setMinEle($parsed->minEle)
            ->setMaxEle($parsed->maxEle)
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($route);

        // Complète la fiche course si ces infos n'étaient pas renseignées
        if ($race->getDistanceKm() === null) {
            $race->setDistanceKm(round($parsed->distanceM / 1000, 1));
        }
        // Sans altitude dans le GPX, gainM vaut 0 : ne pas l'inscrire comme D+ de la course
        if ($race->getElevationGain() === null && $parsed->maxEle !== null) {
            $race->setElevationGain($parsed->gainM);
        }

        $this->em->flush();
        $this->addFlash('success', 'race_plan.flash_route_imported');

        return $this->toPlan($race);
    }

    #[Route('/route/delete', name: 'app_race_route_delete', methods: ['POST'])]
    public function deleteRoute(Race $race, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race);
        }

        $route = $this->routes->findOneByRace($race);
        if ($route !== null) {
            $this->em->remove($route);
            $this->em->flush();
        }
        $this->addFlash('success', 'race_plan.flash_route_deleted');

        return $this->toPlan($race);
    }

    #[Route('/checkpoints', name: 'app_race_checkpoint_add', methods: ['POST'])]
    public function addCheckpoint(Race $race, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race);
        }

        $checkpoint = (new RaceCheckpoint())->setRace($race);
        if (!$this->hydrateCheckpoint($checkpoint, $request, $race)) {
            return $this->fail($race, 'race_plan.flash_checkpoint_invalid');
        }

        $this->em->persist($checkpoint);
        $this->em->flush();
        $this->addFlash('success', 'race_plan.flash_checkpoint_saved');
        $this->flashSplitSegment($race, $checkpoint);

        return $this->toPlan($race);
    }

    #[Route('/checkpoints/{checkpointId}/edit', name: 'app_race_checkpoint_edit', requirements: ['checkpointId' => '\d+'], methods: ['POST'])]
    public function editCheckpoint(Race $race, int $checkpointId, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        $checkpoint = $this->findCheckpoint($race, $checkpointId);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race);
        }

        $previousDistance = $checkpoint->getDistanceM();
        if (!$this->hydrateCheckpoint($checkpoint, $request, $race)) {
            return $this->fail($race, 'race_plan.flash_checkpoint_invalid');
        }

        $this->em->flush();
        $this->addFlash('success', 'race_plan.flash_checkpoint_saved');
        // Repère déplacé : ses apports nutrition manuels (point, tronçon qui y arrive) le suivent
        if ($checkpoint->getDistanceM() !== $previousDistance) {
            $scenarios = $this->nutrition->scenariosWithManualSegment($race, (int) $checkpoint->getId());
            if ([] !== $scenarios) {
                $this->addFlash('info', $this->translator->trans('race_nutrition.flash_checkpoint_moved', [
                    '%name%' => $checkpoint->getName(),
                    '%scenarios%' => implode(', ', $scenarios),
                ]));
            }
            $this->flashSplitSegment($race, $checkpoint);
        }

        return $this->toPlan($race);
    }

    #[Route('/checkpoints/{checkpointId}/delete', name: 'app_race_checkpoint_delete', requirements: ['checkpointId' => '\d+'], methods: ['POST'])]
    public function deleteCheckpoint(Race $race, int $checkpointId, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        $checkpoint = $this->findCheckpoint($race, $checkpointId);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race);
        }

        // Plan nutrition : les apports manuels du repère sont reportés sur le tronçon suivant (RaceNutritionRows)
        $name = $checkpoint->getName();
        $moved = $this->em->wrapInTransaction(function () use ($race, $checkpoint): array {
            $moved = $this->nutrition->moveForDeletedCheckpoint($race, $checkpoint, $this->checkpoints->findByRaceOrdered($race));
            $this->em->remove($checkpoint);

            return $moved;
        });
        $this->addFlash('success', 'race_plan.flash_checkpoint_deleted');
        if ([] !== $moved) {
            $this->addFlash('info', $this->translator->trans('race_nutrition.flash_checkpoint_deleted', [
                '%name%' => $name,
                '%scenarios%' => implode(', ', $moved),
            ]));
        }

        return $this->toPlan($race);
    }

    /**
     * Repère ajouté ou déplacé : si le tronçon qu'il coupe avait des apports nutrition manuels, ils restent sur la
     * partie qui arrive au même point (le message le signale).
     */
    private function flashSplitSegment(Race $race, RaceCheckpoint $checkpoint): void
    {
        $ordered = $this->checkpoints->findByRaceOrdered($race);
        $next = null;
        foreach ($ordered as $i => $cp) {
            if ($cp->getId() === $checkpoint->getId()) {
                $next = $ordered[$i + 1] ?? null;
            }
        }
        $scenarios = $this->nutrition->scenariosWithManualSegment($race, $next?->getId());
        if ([] === $scenarios) {
            return;
        }
        $this->addFlash('info', $this->translator->trans('race_nutrition.flash_checkpoint_split', [
            '%name%' => null !== $next ? $next->getName() : $this->translator->trans('race_plan.finish'),
            '%scenarios%' => implode(', ', $scenarios),
        ]));
    }

    /**
     * Valide tout le formulaire avant de modifier le repère : en cas d'erreur, rien n'est changé.
     */
    private function hydrateCheckpoint(RaceCheckpoint $checkpoint, Request $request, Race $race): bool
    {
        $post = $request->request;

        $name = trim((string) $post->get('name', ''));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return false;
        }

        $typeValue = trim((string) $post->get('type', ''));
        $type      = $typeValue === '' ? CheckpointType::Point : CheckpointType::tryFrom($typeValue);
        if ($type === null) {
            return false;
        }

        $km = self::parseKm((string) $post->get('distance_km', ''));
        if ($km === null) {
            return false;
        }
        $distanceM = (int) round($km * 1000);
        $routeEnd  = $this->routes->findOneByRace($race)?->getDistanceM();
        if ($routeEnd !== null) {
            if ($distanceM > $routeEnd + self::ROUTE_TOLERANCE_M) {
                return false;
            }
            $distanceM = min($distanceM, $routeEnd);
        } elseif ($distanceM > self::MAX_DISTANCE_M) {
            return false;
        }

        $stopSeconds = null;
        if ($type === CheckpointType::AidStation) {
            $stop = trim((string) $post->get('stop_minutes', ''));
            if ($stop !== '') {
                if (!ctype_digit($stop) || (int) $stop > self::MAX_STOP_MINUTES) {
                    return false;
                }
                $stopSeconds = (int) $stop * 60;
            }
        }

        $cutoffRaw = trim((string) $post->get('cutoff', ''));
        $cutoff    = $cutoffRaw === '' ? null : self::parseCutoff($cutoffRaw);
        if ($cutoffRaw !== '' && $cutoff === null) {
            return false;
        }

        $notes = trim((string) $post->get('notes', ''));

        // Message pour les proches (plan partagé), mêmes règles que la page Partager ; champ absent = inchangé
        $hasPublicMessage = $post->has('public_message');
        $publicMessage    = $hasPublicMessage ? ShareSettingsParser::normalize($post->all()['public_message'], true) : null;
        if ($publicMessage === false || ($publicMessage !== null && mb_strlen($publicMessage) > RaceShare::MAX_POINT_MESSAGE_LENGTH)) {
            return false;
        }
        // Assistance / sac d'allègement (plan nutrition) : "1" oui, "0" non (champ caché + case à cocher), absent = inchangé
        $assistance = $post->all()['assistance'] ?? null;
        if (null !== $assistance && '1' !== $assistance && '0' !== $assistance) {
            return false;
        }

        if ($hasPublicMessage) {
            $checkpoint->setPublicMessage($publicMessage);
        }
        if (null !== $assistance) {
            $checkpoint->setAssistance('1' === $assistance);
        }

        $checkpoint
            ->setName($name)
            ->setType($type)
            ->setDistanceM($distanceM)
            ->setStopSeconds($stopSeconds)
            ->setCutoffSeconds($cutoff)
            ->setNotes($notes === '' ? null : mb_substr($notes, 0, self::MAX_NOTES_LENGTH));

        return true;
    }

    /** Distance en km, virgule ou point décimal ; null si invalide ou négative. */
    private static function parseKm(string $value): ?float
    {
        $value = str_replace([',', ' '], ['.', ''], trim($value));
        if (!preg_match('/^(\d{1,7}(\.\d*)?|\.\d+)$/', $value)) {
            return null;
        }

        return (float) $value;
    }

    /** "H:MM" / "HH:MM" (heures > 23 autorisées, ex. "30:15") → secondes depuis le départ. */
    private static function parseCutoff(string $value): ?int
    {
        if (!preg_match('/^(\d{1,3})\s*[:hH]\s*([0-5]\d)$/', trim($value), $m)) {
            return null;
        }

        return (int) $m[1] * 3600 + (int) $m[2] * 60;
    }

    /** Secondes → "H:MM" (heures non bornées à 24). */
    private static function formatCutoff(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private static function looksLikeGpx(UploadedFile $file, string $content): bool
    {
        if (strtolower($file->getClientOriginalExtension()) === 'gpx') {
            return true;
        }
        $head = ltrim(substr($content, 0, 1024), "\xEF\xBB\xBF \t\r\n");

        return str_starts_with($head, '<');
    }

    /** Nom affiché uniquement : basename, sans caractères de contrôle, 255 caractères max. */
    private static function sanitizeFileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
        if ($name === '' || !mb_check_encoding($name, 'UTF-8')) {
            return 'parcours.gpx';
        }

        return mb_substr($name, 0, 255);
    }

    private function findCheckpoint(Race $race, int $checkpointId): RaceCheckpoint
    {
        $checkpoint = $this->checkpoints->find($checkpointId);
        if ($checkpoint === null || $checkpoint->getRace()->getId() !== $race->getId()) {
            throw $this->createNotFoundException();
        }

        return $checkpoint;
    }

    private function csrfOk(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            return true;
        }
        $this->addFlash('error', 'race_plan.flash_csrf');

        return false;
    }

    private function fail(Race $race, string $flash): Response
    {
        $this->addFlash('error', $flash);

        return $this->toPlan($race);
    }

    private function toPlan(Race $race): Response
    {
        return $this->redirectToRoute('app_race_plan', ['id' => $race->getId()]);
    }

    private function denyUnlessOwner(Race $race): void
    {
        if ($race->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}

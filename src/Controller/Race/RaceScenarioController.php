<?php
namespace App\Controller\Race;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceScenario;
use App\Entity\RaceScenarioSplit;
use App\Repository\RaceCheckpointRepository;
use App\Repository\RaceScenarioRepository;
use App\Repository\RaceScenarioSplitRepository;
use App\Service\Route\DurationFormat;
use App\Service\Route\ScenarioViewBuilder;
use App\Service\Route\SplitCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Plan de course, phase 2 : scénarios (objectifs de temps) et temps de passage aux repères.
 * Toutes les actions POST redirigent vers la page plan (ancre #scenario-{sid} ou #scenarios).
 */
#[Route('/courses/{id}', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class RaceScenarioController extends AbstractController
{
    private const CSRF_ID = 'race_plan';
    private const COPY_SUFFIX = ' (copie)';
    private const DEFAULT_NAME_PREFIX = 'Objectif ';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RaceScenarioRepository $scenarios,
        private readonly RaceScenarioSplitRepository $splits,
        private readonly RaceCheckpointRepository $checkpoints,
        private readonly ScenarioViewBuilder $builder,
        private readonly SplitCalculator $calculator,
    ) {
    }

    #[Route('/scenarios', name: 'app_race_scenario_add', methods: ['POST'])]
    public function add(Race $race, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race);
        }

        $existing = $this->scenarios->findByRaceOrdered($race);
        if (\count($existing) >= ScenarioViewBuilder::MAX_SCENARIOS) {
            return $this->fail($race, 'race_plan.flash_scenario_limit');
        }

        $fields = $this->parseFields($request);
        if ($fields === null) {
            return $this->fail($race, 'race_plan.flash_scenario_invalid');
        }

        $scenario = (new RaceScenario())
            ->setRace($race)
            ->setName(($fields['name'] ?? '') !== '' ? (string) $fields['name'] : self::defaultName($existing))
            ->setStartTime($fields['startTime'] ?? null)
            ->setTargetSeconds($fields['target'] ?? null)
            ->setPosition($this->scenarios->nextPosition($race));
        $this->em->persist($scenario);
        $this->em->flush();
        $this->addFlash('success', 'race_plan.flash_scenario_added');

        return $this->toPlan($race, $scenario);
    }

    /**
     * Champs : name, start_time, target, splits[<checkpointId>]. Un champ absent du POST est laissé
     * inchangé ; un temps vide supprime le temps de passage. Tout est validé avant d'écrire.
     */
    #[Route('/scenarios/{sid}/save', name: 'app_race_scenario_save', requirements: ['sid' => '\d+'], methods: ['POST'])]
    public function save(Race $race, int $sid, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        $scenario = $this->findScenario($race, $sid);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race, $scenario);
        }

        $fields = $this->parseFields($request);
        $splitValues = $fields !== null ? $this->parseSplits($request, $race) : null;
        if ($fields === null || $splitValues === null || (\array_key_exists('name', $fields) && $fields['name'] === '')) {
            return $this->fail($race, 'race_plan.flash_scenario_invalid', $scenario);
        }

        $this->em->wrapInTransaction(function () use ($scenario, $fields, $splitValues): void {
            if (\array_key_exists('name', $fields)) {
                $scenario->setName((string) $fields['name']);
            }
            if (\array_key_exists('startTime', $fields)) {
                $scenario->setStartTime($fields['startTime']);
            }
            if (\array_key_exists('target', $fields)) {
                $scenario->setTargetSeconds($fields['target']);
            }
            $this->applySplits($scenario, $splitValues, false);
            $scenario->touch();
        });
        $this->addFlash('success', 'race_plan.flash_scenario_saved');

        return $this->toPlan($race, $scenario);
    }

    /** Remplace tous les temps de passage par une répartition du temps cible (SplitCalculator::distribute). */
    #[Route('/scenarios/{sid}/distribute', name: 'app_race_scenario_distribute', requirements: ['sid' => '\d+'], methods: ['POST'])]
    public function distribute(Race $race, int $sid, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        $scenario = $this->findScenario($race, $sid);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race, $scenario);
        }

        $target = $scenario->getTargetSeconds();
        if ($target === null) {
            return $this->fail($race, 'race_plan.flash_scenario_need_target', $scenario);
        }

        $view = $this->builder->build($race);
        $route = $view['route'];
        try {
            $arrivals = $this->calculator->distribute(
                $route?->getPoints() ?? [],
                $view['routeDistanceM'],
                ScenarioViewBuilder::calculatorCheckpoints($view['checkpoints']),
                $target,
            );
        } catch (\InvalidArgumentException) {
            return $this->fail($race, 'race_plan.flash_scenario_stops_exceed', $scenario);
        }

        $this->em->wrapInTransaction(function () use ($scenario, $arrivals): void {
            $this->applySplits($scenario, $arrivals, true);
            $scenario->touch();
        });
        $this->addFlash('success', 'race_plan.flash_scenario_distributed');

        return $this->toPlan($race, $scenario);
    }

    #[Route('/scenarios/{sid}/duplicate', name: 'app_race_scenario_duplicate', requirements: ['sid' => '\d+'], methods: ['POST'])]
    public function duplicate(Race $race, int $sid, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        $source = $this->findScenario($race, $sid);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race, $source);
        }
        if ($this->scenarios->countByRace($race) >= ScenarioViewBuilder::MAX_SCENARIOS) {
            return $this->fail($race, 'race_plan.flash_scenario_limit', $source);
        }

        $base = mb_substr($source->getName(), 0, RaceScenario::MAX_NAME_LENGTH - mb_strlen(self::COPY_SUFFIX));
        $copy = (new RaceScenario())
            ->setRace($race)
            ->setName(rtrim($base).self::COPY_SUFFIX)
            ->setStartTime($source->getStartTime())
            ->setTargetSeconds($source->getTargetSeconds())
            ->setPosition($this->scenarios->nextPosition($race));

        $this->em->wrapInTransaction(function () use ($copy, $source): void {
            $this->em->persist($copy);
            foreach ($this->splits->findForScenarios([$source])[(int) $source->getId()] ?? [] as $checkpointId => $seconds) {
                $this->em->persist((new RaceScenarioSplit())
                    ->setScenario($copy)
                    ->setCheckpoint($this->em->getReference(RaceCheckpoint::class, $checkpointId))
                    ->setElapsedSeconds($seconds));
            }
        });
        $this->addFlash('success', 'race_plan.flash_scenario_duplicated');

        return $this->toPlan($race, $copy);
    }

    #[Route('/scenarios/{sid}/delete', name: 'app_race_scenario_delete', requirements: ['sid' => '\d+'], methods: ['POST'])]
    public function delete(Race $race, int $sid, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        $scenario = $this->findScenario($race, $sid);
        if (!$this->csrfOk($request)) {
            return $this->toPlan($race, $scenario);
        }

        $this->em->remove($scenario); // temps de passage supprimés par ON DELETE CASCADE
        $this->em->flush();
        $this->addFlash('success', 'race_plan.flash_scenario_deleted');

        return $this->toPlan($race);
    }

    /** Fiche imprimable d'un scénario (?scenario={sid}, par défaut le premier). */
    #[Route('/plan/print', name: 'app_race_plan_print', methods: ['GET'])]
    public function print(Race $race, Request $request): Response
    {
        $this->denyUnlessOwner($race);
        $view = $this->builder->build($race);

        $selected = $view['scenarios'][0] ?? null;
        $requested = trim((string) $request->query->get('scenario', ''));
        if ($requested !== '') {
            $selected = null;
            foreach ($view['scenarios'] as $candidate) {
                if (ctype_digit($requested) && $candidate['id'] === (int) $requested) {
                    $selected = $candidate;
                }
            }
            if ($selected === null) {
                throw $this->createNotFoundException();
            }
        }

        return $this->render('race/plan_print.html.twig', [
            'race' => $race,
            'route' => $view['route'],
            'scenario' => $selected['scenario'] ?? null,
            'scenarioView' => $selected,
            'scenarios' => array_map(static fn (array $s): RaceScenario => $s['scenario'], $view['scenarios']),
            'calc' => $selected['calc'] ?? null,
            'checkpoints' => $view['checkpoints'],
        ]);
    }

    /**
     * Champs communs du scénario. Clés présentes seulement si le champ est dans le POST.
     *
     * @return array{name?: string, startTime?: ?\DateTimeImmutable, target?: ?int}|null null si invalide
     */
    private function parseFields(Request $request): ?array
    {
        $post = $request->request;
        $fields = [];

        if ($post->has('name')) {
            $name = trim((string) preg_replace('/\s+/u', ' ', (string) $post->get('name')));
            if (mb_strlen($name) > RaceScenario::MAX_NAME_LENGTH) {
                return null;
            }
            $fields['name'] = $name;
        }

        if ($post->has('start_time')) {
            $raw = trim((string) $post->get('start_time'));
            if ($raw === '') {
                $fields['startTime'] = null;
            } elseif (preg_match('/^([01]?\d|2[0-3])\s*[:hH]\s*([0-5]\d)$/', $raw, $m)) {
                $fields['startTime'] = \DateTimeImmutable::createFromFormat('!H:i', sprintf('%02d:%02d', $m[1], $m[2])) ?: null;
            } else {
                return null;
            }
        }

        if ($post->has('target')) {
            try {
                $target = DurationFormat::parse((string) $post->get('target'));
            } catch (\InvalidArgumentException) {
                return null;
            }
            if ($target === 0) {
                return null;
            }
            $fields['target'] = $target;
        }

        return $fields;
    }

    /**
     * splits[<checkpointId>] => "H:MM[:SS]" ou "" (suppression). Repères d'une autre course ignorés.
     *
     * @return array<int, ?int>|null checkpointId => secondes (null = supprimer) ; null si une valeur est invalide
     */
    private function parseSplits(Request $request, Race $race): ?array
    {
        try {
            $raw = $request->request->all('splits');
        } catch (BadRequestException) {
            return null;
        }

        $raceCheckpointIds = [];
        foreach ($this->checkpoints->findByRaceOrdered($race) as $cp) {
            $raceCheckpointIds[(int) $cp->getId()] = true;
        }

        $values = [];
        foreach ($raw as $key => $value) {
            if (!\is_string($value) && !\is_int($value)) {
                return null;
            }
            try {
                $seconds = DurationFormat::parse((string) $value);
            } catch (\InvalidArgumentException) {
                return null;
            }
            $checkpointId = (int) $key;
            if (isset($raceCheckpointIds[$checkpointId]) && (string) $checkpointId === (string) $key) {
                $values[$checkpointId] = $seconds;
            }
        }

        return $values;
    }

    /**
     * Upsert des temps de passage (mise à jour en place : Doctrine exécute les INSERT avant les DELETE,
     * supprimer puis recréer la même paire violerait l'index unique).
     *
     * @param array<int, ?int> $values      checkpointId => secondes (null = supprimer)
     * @param bool             $replaceAll  supprime aussi les temps des repères absents de $values
     */
    private function applySplits(RaceScenario $scenario, array $values, bool $replaceAll): void
    {
        $existing = $this->splits->findByScenarioIndexed($scenario);

        foreach ($values as $checkpointId => $seconds) {
            $split = $existing[$checkpointId] ?? null;
            if ($seconds === null) {
                if ($split !== null) {
                    $this->em->remove($split);
                }
                continue;
            }
            if ($split === null) {
                $split = (new RaceScenarioSplit())
                    ->setScenario($scenario)
                    ->setCheckpoint($this->em->getReference(RaceCheckpoint::class, $checkpointId));
                $this->em->persist($split);
            }
            $split->setElapsedSeconds($seconds);
        }

        if ($replaceAll) {
            foreach ($existing as $checkpointId => $split) {
                if (!\array_key_exists($checkpointId, $values)) {
                    $this->em->remove($split);
                }
            }
        }
    }

    /**
     * "Objectif A", "Objectif B"... : première lettre libre parmi les noms existants.
     *
     * @param list<RaceScenario> $existing
     */
    private static function defaultName(array $existing): string
    {
        $used = [];
        foreach ($existing as $scenario) {
            $used[mb_strtolower($scenario->getName())] = true;
        }
        foreach (range('A', 'Z') as $letter) {
            $name = self::DEFAULT_NAME_PREFIX.$letter;
            if (!isset($used[mb_strtolower($name)])) {
                return $name;
            }
        }

        return self::DEFAULT_NAME_PREFIX.(\count($existing) + 1);
    }

    private function findScenario(Race $race, int $sid): RaceScenario
    {
        $scenario = $this->scenarios->find($sid);
        if ($scenario === null || $scenario->getRace()->getId() !== $race->getId()) {
            throw $this->createNotFoundException();
        }

        return $scenario;
    }

    private function csrfOk(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            return true;
        }
        $this->addFlash('error', 'race_plan.flash_csrf');

        return false;
    }

    private function fail(Race $race, string $flash, ?RaceScenario $scenario = null): Response
    {
        $this->addFlash('error', $flash);

        return $this->toPlan($race, $scenario);
    }

    /** Redirige vers la page plan, ancrée sur le scénario (#scenario-{sid}) ou la section (#scenarios). */
    private function toPlan(Race $race, ?RaceScenario $scenario = null): Response
    {
        $fragment = $scenario?->getId() !== null ? 'scenario-'.$scenario->getId() : 'scenarios';

        return $this->redirectToRoute('app_race_plan', ['id' => $race->getId(), '_fragment' => $fragment]);
    }

    private function denyUnlessOwner(Race $race): void
    {
        if ($race->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}

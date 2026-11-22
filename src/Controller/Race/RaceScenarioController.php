<?php
namespace App\Controller\Race;

use App\Entity\PacingIntensity;
use App\Entity\PacingStrategy;
use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceScenario;
use App\Entity\RaceScenarioSplit;
use App\Repository\RaceCheckpointRepository;
use App\Repository\RaceRouteRepository;
use App\Repository\RaceScenarioRepository;
use App\Repository\RaceScenarioSplitRepository;
use App\Service\Nutrition\RaceNutritionStore;
use App\Service\Nutrition\RaceNutritionViewBuilder;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Plan de course, phase 2 : scénarios (objectifs de temps) et temps de passage aux repères.
 * Un temps de passage enregistré (RaceScenarioSplit) est un temps FIXÉ par l'utilisateur ; les repères
 * sans temps enregistré sont "auto" : leur temps est recalculé à chaque affichage par
 * SplitCalculator::fillAuto() (jamais stocké, jamais lu dans le POST), selon la stratégie d'allure du scénario
 * (champs strategy / strategy_intensity, App\Entity\PacingStrategy et PacingIntensity).
 * Toutes les actions POST redirigent vers la page plan (ancre #scenario-{sid} ou #scenarios).
 */
#[Route('/courses/{id}', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class RaceScenarioController extends AbstractController
{
    private const CSRF_ID = 'race_plan';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RaceScenarioRepository $scenarios,
        private readonly RaceScenarioSplitRepository $splits,
        private readonly RaceCheckpointRepository $checkpoints,
        private readonly RaceRouteRepository $routes,
        private readonly ScenarioViewBuilder $builder,
        private readonly SplitCalculator $calculator,
        private readonly TranslatorInterface $translator,
        private readonly RaceNutritionStore $nutrition,
    ) {
    }

    /**
     * Champs : name (vide = "Objectif A"...), start_time, target, strategy et strategy_intensity (absents : stratégie
     * proposée pour le type de course, PacingStrategy::suggestedFor(), intensité moyenne).
     */
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
            ->setName(($fields['name'] ?? '') !== '' ? (string) $fields['name'] : $this->defaultName($existing, $request->getLocale()))
            ->setStartTime($fields['startTime'] ?? null)
            ->setTargetSeconds($fields['target'] ?? null)
            ->setStrategy($fields['strategy'] ?? PacingStrategy::suggestedFor($race->getType()))
            ->setStrategyIntensity($fields['intensity'] ?? PacingIntensity::Medium)
            ->setPosition($this->scenarios->nextPosition($race));
        $this->em->persist($scenario);
        $this->em->flush();
        $this->addFlash('success', 'race_plan.flash_scenario_added');

        return $this->toPlan($race, $scenario);
    }

    /**
     * Champs : name, start_time, target, strategy, strategy_intensity (absent = inchangé) et splits[<checkpointId>], qui REMPLACE l'ensemble
     * des temps fixés des repères du tableau : valeur non vide = temps fixé à cette valeur ; vide ou absente
     * = repère auto (temps enregistré supprimé). Les repères au-delà de la fin du tracé (absents du tableau)
     * gardent leur temps enregistré. Tout est validé avant d'écrire.
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
        $planCheckpointIds = $this->planCheckpointIds($race);
        $pins = $fields !== null ? $this->parsePins($request, $planCheckpointIds) : null;
        if ($fields === null || $pins === null || (\array_key_exists('name', $fields) && $fields['name'] === '')) {
            return $this->fail($race, 'race_plan.flash_scenario_invalid', $scenario);
        }

        // Repères du tableau sans temps posté : repassent en auto (temps enregistré supprimé)
        $splitValues = $pins;
        foreach ($planCheckpointIds as $checkpointId) {
            if (!\array_key_exists($checkpointId, $splitValues)) {
                $splitValues[$checkpointId] = null;
            }
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
            if (\array_key_exists('strategy', $fields)) {
                $scenario->setStrategy($fields['strategy']);
            }
            if (\array_key_exists('intensity', $fields)) {
                $scenario->setStrategyIntensity($fields['intensity']);
            }
            $this->applySplits($scenario, $splitValues, false);
            $scenario->touch();
        });
        $this->addFlash('success', 'race_plan.flash_scenario_saved');

        return $this->toPlan($race, $scenario);
    }

    /**
     * "Répartir" : efface tous les temps fixés du scénario. Tous les repères repassent en auto et sont donc
     * recalculés à partir du temps cible et de la stratégie d'allure enregistrés (SplitCalculator::fillAuto sans
     * temps fixé = distribute()).
     */
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

        $route = $this->routes->findOneByRace($race);
        $checkpoints = ScenarioViewBuilder::routeCheckpoints($route, $this->checkpoints->findByRaceOrdered($race));
        $totalStops = $this->calculator->totalStops(
            ScenarioViewBuilder::routeDistanceM($race, $route),
            ScenarioViewBuilder::calculatorCheckpoints($checkpoints),
        );
        if ($totalStops >= $target) {
            return $this->fail($race, 'race_plan.flash_scenario_stops_exceed', $scenario);
        }

        $this->em->wrapInTransaction(function () use ($scenario): void {
            $this->applySplits($scenario, [], true);
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

        $suffix = $this->translator->trans('race_plan.sc_copy_suffix', [], null, $request->getLocale()); // " (copie)"
        $base = mb_substr($source->getName(), 0, RaceScenario::MAX_NAME_LENGTH - mb_strlen($suffix));
        $copy = (new RaceScenario())
            ->setRace($race)
            ->setName(rtrim($base).$suffix)
            ->setStartTime($source->getStartTime())
            ->setTargetSeconds($source->getTargetSeconds())
            ->setStrategy($source->getStrategy())
            ->setStrategyIntensity($source->getStrategyIntensity())
            ->setPosition($this->scenarios->nextPosition($race));

        $this->em->wrapInTransaction(function () use ($copy, $source): void {
            $this->em->persist($copy);
            // Temps fixés uniquement : les temps auto sont recalculés à l'affichage
            foreach ($this->splits->findForScenarios([$source])[(int) $source->getId()] ?? [] as $checkpointId => $seconds) {
                $this->em->persist((new RaceScenarioSplit())
                    ->setScenario($copy)
                    ->setCheckpoint($this->em->getReference(RaceCheckpoint::class, $checkpointId))
                    ->setElapsedSeconds($seconds));
            }
            // Plan nutrition : réglages, produits et lignes manuelles (les apports auto sont recalculés)
            $this->nutrition->copy($source, $copy);
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

    /**
     * Fiche imprimable d'un scénario (?scenario={sid}, par défaut le premier ; ?format=band = bracelet, sinon fiche A4).
     * Paramètres lus avec all() : une valeur en tableau (?format[]=x, ?scenario[]=x) est ignorée, sans erreur.
     */
    #[Route('/plan/print', name: 'app_race_plan_print', methods: ['GET'])]
    public function print(Race $race, Request $request, RaceNutritionViewBuilder $nutritionBuilder): Response
    {
        $this->denyUnlessOwner($race);
        $view = $this->builder->build($race);

        $query     = $request->query->all();
        $format    = ($query['format'] ?? null) === 'band' ? 'band' : 'sheet';
        $selected  = $view['scenarios'][0] ?? null;
        $requested = \is_string($query['scenario'] ?? null) ? trim($query['scenario']) : '';
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
            'format' => $format,
            'route' => $view['route'],
            'scenario' => $selected['scenario'] ?? null,
            'scenarioView' => $selected,
            'scenarios' => array_map(static fn (array $s): RaceScenario => $s['scenario'], $view['scenarios']),
            'calc' => $selected['calc'] ?? null,
            'checkpoints' => $view['checkpoints'],
            // Plan nutrition du scénario imprimé (colonne « À manger », liste à emporter, bracelet)
            'nutrition' => null !== $selected ? ($nutritionBuilder->build($race, $view)[$selected['id']] ?? null) : null,
        ]);
    }

    /**
     * Champs communs du scénario. Clés présentes seulement si le champ est dans le POST.
     * strategy / strategy_intensity : valeur exacte d'un cas de PacingStrategy / PacingIntensity, sinon invalide
     * (un tableau aussi, sans erreur 400).
     *
     * @return array{name?: string, startTime?: ?\DateTimeImmutable, target?: ?int, strategy?: PacingStrategy, intensity?: PacingIntensity}|null null si invalide
     */
    private function parseFields(Request $request): ?array
    {
        $post = $request->request;
        $fields = [];

        $raw = $post->all();
        if (\array_key_exists('strategy', $raw)) {
            $strategy = PacingStrategy::tryFromInput($raw['strategy']);
            if ($strategy === null) {
                return null;
            }
            $fields['strategy'] = $strategy;
        }
        if (\array_key_exists('strategy_intensity', $raw)) {
            $intensity = PacingIntensity::tryFromInput($raw['strategy_intensity']);
            if ($intensity === null) {
                return null;
            }
            $fields['intensity'] = $intensity;
        }

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
     * Repères du tableau des temps : ceux du parcours, sans ceux au-delà de la fin du tracé.
     *
     * @return list<int>
     */
    private function planCheckpointIds(Race $race): array
    {
        $checkpoints = ScenarioViewBuilder::routeCheckpoints($this->routes->findOneByRace($race), $this->checkpoints->findByRaceOrdered($race));

        return array_map(static fn (RaceCheckpoint $cp): int => (int) $cp->getId(), $checkpoints);
    }

    /**
     * Temps fixés postés : splits[<checkpointId>] => "H:MM[:SS]" (fixé) ou "" (auto, donc ignoré ici).
     * Les repères hors du tableau (autre course, au-delà du tracé) sont ignorés.
     *
     * @param list<int> $planCheckpointIds
     *
     * @return array<int, int>|null checkpointId => secondes ; null si une valeur est invalide
     */
    private function parsePins(Request $request, array $planCheckpointIds): ?array
    {
        try {
            $raw = $request->request->all('splits');
        } catch (BadRequestException) {
            return null;
        }

        $allowed = array_fill_keys($planCheckpointIds, true);
        $pins = [];
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
            if ($seconds !== null && isset($allowed[$checkpointId]) && (string) $checkpointId === (string) $key) {
                $pins[$checkpointId] = $seconds;
            }
        }

        return $pins;
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
     * "Objectif A", "Objectif B"... ("Goal A"... en anglais) : première lettre libre parmi les noms existants.
     *
     * @param list<RaceScenario> $existing
     */
    private function defaultName(array $existing, string $locale): string
    {
        $used = [];
        foreach ($existing as $scenario) {
            $used[mb_strtolower($scenario->getName())] = true;
        }
        foreach (range('A', 'Z') as $letter) {
            $name = $this->translator->trans('race_plan.sc_default_name', ['%letter%' => $letter], null, $locale);
            if (!isset($used[mb_strtolower($name)])) {
                return $name;
            }
        }

        return $this->translator->trans('race_plan.sc_default_name', ['%letter%' => (string) (\count($existing) + 1)], null, $locale);
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

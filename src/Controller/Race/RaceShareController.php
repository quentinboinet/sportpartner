<?php
namespace App\Controller\Race;

use App\Entity\Race;
use App\Entity\RaceCheckpoint;
use App\Entity\RaceScenario;
use App\Entity\RaceShare;
use App\Entity\User;
use App\Repository\RaceShareRepository;
use App\Service\Route\DurationFormat;
use App\Service\Route\ScenarioViewBuilder;
use App\Service\Share\ShareSettingsParser;
use App\Service\Share\ShareToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Réglages du lien de partage public d'un plan de course (page /courses/{id}/partage, propriétaire seulement) :
 * activation, lien (copie, aperçu, régénération), scénario partagé, nom affiché, options (allures, barrières) et
 * messages pour les proches (général, départ, chaque repère, arrivée). La page publique : RacePublicController.
 *
 * Le partage (RaceShare) est créé à la première action (activation ou enregistrement), jamais à l'affichage.
 * Toutes les actions POST vérifient le jeton CSRF "race_share" (champ _token) et redirigent vers la page des réglages,
 * sauf un enregistrement invalide (422 : la page est réaffichée avec la saisie et les erreurs, rien n'est écrit).
 */
#[Route('/courses/{id}/partage', requirements: ['id' => '\d+'])]
#[IsGranted('ROLE_USER')]
final class RaceShareController extends AbstractController
{
    private const CSRF_ID = 'race_share';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RaceShareRepository $shares,
        private readonly ScenarioViewBuilder $scenarioBuilder,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_race_share', methods: ['GET'])]
    public function settings(Race $race, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessOwner($race, $user);
        $share = $this->shares->findOneByRace($race);

        return $this->renderSettings($race, $user, $share, null, []);
    }

    #[Route('', name: 'app_race_share_save', methods: ['POST'])]
    public function save(Race $race, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessOwner($race, $user);
        if (!$this->csrfOk($request)) {
            return $this->toSettings($race);
        }

        $view = $this->scenarioBuilder->build($race);
        $checkpoints = $view['checkpoints'];
        $scenarios = array_map(static fn (array $s): RaceScenario => $s['scenario'], $view['scenarios']);
        $parsed = ShareSettingsParser::parse(
            $request->request->all(),
            array_map(static fn (RaceCheckpoint $c): int => (int) $c->getId(), $checkpoints),
            array_map(static fn (RaceScenario $s): int => (int) $s->getId(), $scenarios),
        );
        $share = $this->shares->findOneByRace($race);
        if ([] !== $parsed['errors']) {
            $this->addFlash('error', 'race_share.flash_invalid');

            return $this->renderSettings($race, $user, $share, $parsed['values'], $parsed['errors'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $values = $parsed['values'];
        $this->em->wrapInTransaction(function () use ($race, $user, $share, $values, $checkpoints, $scenarios): void {
            $share ??= $this->createShare($race, $user);
            $scenario = null;
            foreach ($scenarios as $candidate) {
                if ($candidate->getId() === $values['scenarioId']) {
                    $scenario = $candidate;
                }
            }
            $share
                ->setDisplayName($values['displayName'])
                ->setScenario($scenario)
                ->setShowPaces($values['showPaces'])
                ->setShowCutoffs($values['showCutoffs'])
                ->setMessage($values['message'])
                ->setStartMessage($values['startMessage'])
                ->setFinishMessage($values['finishMessage'])
                ->touch();
            foreach ($checkpoints as $checkpoint) {
                if (\array_key_exists((int) $checkpoint->getId(), $values['checkpointMessages'])) {
                    $checkpoint->setPublicMessage($values['checkpointMessages'][(int) $checkpoint->getId()]);
                }
            }
        });
        $this->addFlash('success', 'race_share.flash_saved');

        return $this->toSettings($race);
    }

    /** Champ enabled : "1" active (crée le partage au besoin), autre valeur désactive (le lien répond 404). */
    #[Route('/toggle', name: 'app_race_share_toggle', methods: ['POST'])]
    public function toggle(Race $race, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessOwner($race, $user);
        if (!$this->csrfOk($request)) {
            return $this->toSettings($race);
        }

        $enable = '1' === $request->request->get('enabled');
        $share = $this->shares->findOneByRace($race);
        if (null === $share && !$enable) {
            return $this->toSettings($race);
        }
        $share ??= $this->createShare($race, $user);
        $share->setEnabled($enable)->touch();
        $this->em->flush();
        $this->addFlash('success', $enable ? 'race_share.flash_enabled' : 'race_share.flash_disabled');

        return $this->toSettings($race);
    }

    /** Nouveau jeton : l'ancien lien répond 404 (même réponse qu'un lien inconnu). */
    #[Route('/regenerate', name: 'app_race_share_regenerate', methods: ['POST'])]
    public function regenerate(Race $race, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessOwner($race, $user);
        if (!$this->csrfOk($request)) {
            return $this->toSettings($race);
        }

        $share = $this->shares->findOneByRace($race);
        if (null === $share) {
            return $this->toSettings($race);
        }
        $share->setToken(ShareToken::regenerate($share->getToken()))->touch();
        $this->em->flush();
        $this->addFlash('success', 'race_share.flash_regenerated');

        return $this->toSettings($race);
    }

    /**
     * @param array{displayName: ?string, scenarioId: ?int, showPaces: bool, showCutoffs: bool, message: ?string, startMessage: ?string, finishMessage: ?string, checkpointMessages: array<int, ?string>}|null $submitted saisie réaffichée après une erreur
     * @param array<string, string> $errors champ => code d'erreur (ShareSettingsParser)
     */
    private function renderSettings(Race $race, User $user, ?RaceShare $share, ?array $submitted, array $errors, int $status = Response::HTTP_OK): Response
    {
        $view = $this->scenarioBuilder->build($race);
        $sharedScenarioId = $share?->getScenario()?->getId();
        $selected = null;
        foreach ($view['scenarios'] as $s) {
            if ($s['id'] === $sharedScenarioId) {
                $selected = $s['id'];
            }
        }
        $selected ??= $view['scenarios'][0]['id'] ?? null; // scénario supprimé ou jamais choisi : le premier

        // Heure prévue de chaque point pour chaque scénario (le libellé suit le choix du scénario dans la page)
        $locale = $this->translator->getLocale();
        $times = ['start' => [], 'finish' => []];
        $scenarioOptions = [];
        foreach ($view['scenarios'] as $s) {
            $times['start'][$s['id']] = $s['startTime'] ?? '0:00';
            $times['finish'][$s['id']] = null !== $s['targetSeconds'] ? $this->timeLabel($s['calc']['finish']['clock'], $s['targetSeconds'], $locale) : null;
            foreach ($s['calc']['rows'] as $row) {
                $times['cp'.$row['checkpointId']][$s['id']] = null !== $s['targetSeconds'] && null !== $row['arrivalSeconds']
                    ? $this->timeLabel($row['clock'], $row['arrivalSeconds'], $locale) : null;
            }
            $scenarioOptions[] = ['id' => $s['id'], 'name' => $s['name'], 'target' => $s['target'], 'startTime' => $s['startTime']];
        }

        $form = $submitted ?? [
            'displayName' => $share?->getDisplayName() ?? (null === $share ? $this->defaultDisplayName($user) : null),
            'scenarioId' => $selected,
            'showPaces' => $share?->isShowPaces() ?? (new RaceShare())->isShowPaces(),
            'showCutoffs' => $share?->isShowCutoffs() ?? (new RaceShare())->isShowCutoffs(),
            'message' => $share?->getMessage(),
            'startMessage' => $share?->getStartMessage(),
            'finishMessage' => $share?->getFinishMessage(),
            'checkpointMessages' => [],
        ];
        if (null === $submitted) {
            foreach ($view['checkpoints'] as $cp) {
                $form['checkpointMessages'][(int) $cp->getId()] = $cp->getPublicMessage();
            }
        } else {
            $form['scenarioId'] ??= $selected;
        }

        return $this->render('race/share.html.twig', [
            'race' => $race,
            'share' => $share,
            'shareUrl' => null !== $share ? $this->generateUrl('app_race_public', ['token' => $share->getToken()], UrlGeneratorInterface::ABSOLUTE_URL) : null,
            'form' => $form,
            'errors' => $errors,
            'checkpoints' => $view['checkpoints'],
            'routeDistanceM' => $view['routeDistanceM'],
            'scenarios' => $scenarioOptions,
            'times' => $times,
            'suggestedName' => $this->defaultDisplayName($user),
            'limits' => [
                'displayName' => RaceShare::MAX_DISPLAY_NAME_LENGTH,
                'message' => RaceShare::MAX_MESSAGE_LENGTH,
                'point' => RaceShare::MAX_POINT_MESSAGE_LENGTH,
            ],
        ], new Response(null, $status));
    }

    /**
     * Heure prévue "09:48" (+ " J+1"), ou temps depuis le départ sans heure de départ.
     *
     * @param array{time: string, dayOffset: int}|null $clock
     */
    private function timeLabel(?array $clock, int $elapsed, string $locale): string
    {
        if (null === $clock) {
            return $this->translator->trans('race_share.time_elapsed', ['%time%' => DurationFormat::format($elapsed)], null, $locale);
        }
        if ($clock['dayOffset'] > 0) {
            return $clock['time'].' '.$this->translator->trans('race_share.day_offset', ['%n%' => $clock['dayOffset']], null, $locale);
        }

        return $clock['time'];
    }

    /** Nom proposé : le prénom du profil (jamais l'e-mail), sinon rien. */
    private function defaultDisplayName(User $user): ?string
    {
        $name = trim((string) $user->getFirstName());

        return '' === $name ? null : mb_substr($name, 0, RaceShare::MAX_DISPLAY_NAME_LENGTH);
    }

    private function createShare(Race $race, User $user): RaceShare
    {
        $share = (new RaceShare())
            ->setRace($race)
            ->setToken(ShareToken::generate())
            ->setDisplayName($this->defaultDisplayName($user));
        $this->em->persist($share);

        return $share;
    }

    private function csrfOk(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            return true;
        }
        $this->addFlash('error', 'race_share.flash_csrf');

        return false;
    }

    private function toSettings(Race $race): Response
    {
        return $this->redirectToRoute('app_race_share', ['id' => $race->getId()]);
    }

    /** Course d'un autre utilisateur : 403, comme les autres actions des courses. */
    private function denyUnlessOwner(Race $race, User $user): void
    {
        if ($race->getUser() !== $user) {
            throw $this->createAccessDeniedException();
        }
    }
}

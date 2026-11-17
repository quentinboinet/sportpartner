<?php

namespace App\Controller\Activities;

use App\Entity\Activity;
use App\Entity\Gear;
use App\Entity\GearType;
use App\Entity\Sport;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\GearRepository;
use App\Repository\SportRepository;
use App\Service\Activity\ActivityDuplicateRule;
use App\Service\Activity\ActivityPlausibilityRule;
use App\Service\Activity\ActivitySourceResolver;
use App\Service\Activity\ManualActivityForm;
use App\Service\Activity\ManualActivityValues;
use App\Service\Health\FieldError;
use App\Service\Strava\StravaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Activités saisies à la main : ajout (/activities/new), modification et suppression. Seules les activités
 * « Saisie manuelle » (ActivitySource : ni stravaId ni séance Santé) se modifient ou se suppriment ici ; une
 * activité Strava reste en lecture seule (sauf matériel et ravitaillement), une activité de séance Santé se gère
 * depuis l'historique Santé.
 *
 * Accès : activité d'un autre utilisateur ou non modifiable : 404 en lecture (GET), 403 en écriture (POST), comme
 * l'espace Santé. Toute écriture est en POST + CSRF (CSRF_ID, champ _token) ; jeton invalide : rien n'est écrit,
 * formulaire réaffiché avec la saisie (422) ou retour à l'activité avec un message (suppression).
 *
 * Saisie invalide : formulaire réaffiché avec la saisie et les erreurs par champ (422). Avertissements, rien n'est
 * écrit sans confirmation : allure ou vitesse invraisemblable (ActivityPlausibilityRule) et doublon probable
 * (ActivityDuplicateRule : même sport, départ à 30 min près), affichés ensemble avec un seul bouton « Enregistrer
 * quand même » (confirm_warnings=1, 200). Succès : message et retour à la page de l'activité.
 */
#[IsGranted('ROLE_USER')]
final class ManualActivityController extends AbstractController
{
    public const CSRF_ID = 'activity_manual';

    public function __construct(
        private readonly SportRepository $sports,
        private readonly GearRepository $gears,
        private readonly ActivityRepository $activities,
        private readonly ActivitySourceResolver $sources,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/activities/new', name: 'app_activities_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->handle($request, $user, null, ManualActivityForm::create(new \DateTimeImmutable(), date_default_timezone_get()));
    }

    #[Route('/activities/{id}/edit', name: 'app_activities_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Activity $activity, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessEditable($activity, $request, $user);

        return $this->handle($request, $user, $activity, ManualActivityForm::fromActivity($activity, $request->getLocale()));
    }

    #[Route('/activities/{id}/delete', name: 'app_activities_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Activity $activity, Request $request, #[CurrentUser] User $user): Response
    {
        $this->denyUnlessEditable($activity, $request, $user);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'activity_form.flash_csrf');

            return $this->redirectToRoute('app_activity_show', ['id' => $activity->getId()]);
        }

        // Ravitaillement noté supprimé par la base (activityFuel ON DELETE CASCADE)
        $this->em->remove($activity);
        $this->em->flush();
        $this->addFlash('success', 'activity_form.flash_deleted');

        return $this->redirectToRoute('app_activities');
    }

    private function handle(Request $request, User $user, ?Activity $activity, ManualActivityForm $form): Response
    {
        $sports = [];
        foreach ($this->sports->findAllOrdered() as $sport) {
            $sports[$sport->getSlug()] = $sport;
        }
        // Matériel proposé : tout le matériel non retiré (filtré par sport dans la page), plus celui déjà rattaché
        $gearOptions = $this->gears->findSelectable($user, GearType::cases(), $activity?->getGear());
        $gearById = [];
        foreach ($gearOptions as $gear) {
            $gearById[(int) $gear->getId()] = $gear;
        }

        $errors = [];
        $duplicates = [];
        $implausible = null;
        $csrfError = false;
        if ($request->isMethod('POST')) {
            $form = ManualActivityForm::fromRequest($request->request->all(), $activity?->getTimeZoneObject()->getName() ?? date_default_timezone_get());
            if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
                $csrfError = true;
            } else {
                $errors = $form->validate(array_keys($sports), new \DateTimeImmutable(), array_map(static fn (Gear $g): GearType => $g->getType(), $gearById));
            }

            if (!$csrfError && $errors === []) {
                $values = $form->values();
                $sport = $sports[$form->sport];
                // Avertissements (allure invraisemblable, doublon probable) : rien n'est écrit sans confirmation
                if (!$request->request->getBoolean('confirm_warnings')) {
                    $implausible = ActivityPlausibilityRule::check($sport->getSlug(), $values->movingSeconds, $values->distanceMeters);
                    [$from, $to] = ActivityDuplicateRule::window($values->startUtc);
                    $duplicates = ActivityDuplicateRule::filter(
                        $this->activities->findStartingBetween($user, $sport->getSlug(), $from, $to),
                        $sport->getSlug(),
                        $values->startUtc,
                        $activity?->getId(),
                    );
                }

                if ($duplicates === [] && $implausible === null) {
                    $isNew = $activity === null;
                    $activity ??= (new Activity())->setUser($user);
                    $form->applyTo($activity, $sport, $this->resolveGear($user, $sport, $values, $gearById), $this->defaultName($sport, $values));
                    if ($isNew) {
                        $this->em->persist($activity);
                    }
                    $this->em->flush();
                    $this->addFlash('success', $isNew ? 'activity_form.flash_created' : 'activity_form.flash_updated');

                    return $this->redirectToRoute('app_activity_show', ['id' => $activity->getId()]);
                }
            }
        }

        return $this->renderForm($form, $errors, $activity, $sports, $gearOptions, $user, $duplicates, $implausible, $csrfError);
    }

    /**
     * Matériel choisi, matériel par défaut du type lié au sport (règle de la synchro Strava), ou aucun.
     *
     * @param array<int, Gear> $gearById matériel proposé (déjà vérifié par ManualActivityForm::validate())
     */
    private function resolveGear(User $user, Sport $sport, ManualActivityValues $values, array $gearById): ?Gear
    {
        if ($values->gearAuto) {
            return StravaService::pickDefaultGear($this->gears->findDefaultsByType($user), $sport->getSlug(), $values->startUtc);
        }

        return $values->gearId !== null ? ($gearById[$values->gearId] ?? null) : null;
    }

    /** « Course du 16/11/2026 » : sport traduit et date locale du départ. */
    private function defaultName(Sport $sport, ManualActivityValues $values): string
    {
        $local = $values->startUtc->setTimezone(new \DateTimeZone($values->timezone));

        return $this->translator->trans('activity_form.default_name', [
            '%sport%' => $this->translator->trans('sport.'.$sport->getSlug()),
            '%date%'  => $local->format($this->translator->trans('activity_form.default_name_date_format')),
        ]);
    }

    private function denyUnlessEditable(Activity $activity, Request $request, User $user): void
    {
        if ($activity->getUser()?->getId() !== $user->getId() || !$this->sources->sourceOf($activity)->isEditable()) {
            // Activité d'un autre utilisateur, importée de Strava ou créée par une séance Santé
            throw $request->isMethod('POST') ? $this->createAccessDeniedException() : $this->createNotFoundException();
        }
    }

    /**
     * @param array<string, FieldError> $errors
     * @param array<string, Sport>      $sports
     * @param list<Gear>                $gearOptions
     * @param list<Activity>            $duplicates
     */
    private function renderForm(ManualActivityForm $form, array $errors, ?Activity $activity, array $sports, array $gearOptions, User $user, array $duplicates, ?FieldError $implausible, bool $csrfError): Response
    {
        // Par sport : types de matériel proposés, et type rattaché automatiquement (option « Par défaut »)
        $compat = [];
        $autoType = [];
        foreach (array_keys($sports) as $slug) {
            $compat[$slug] = array_map(static fn (GearType $t): string => $t->value, GearType::compatibleWithSportSlug($slug));
            $autoType[$slug] = GearType::forSportSlug($slug)?->value;
        }
        $defaults = array_map(static fn (Gear $g): string => $g->getDisplayName(), $this->gears->findDefaultsByType($user));

        return $this->render('activities/form.html.twig', [
            'form'         => $form,
            'errors'       => $errors,
            'csrfError'    => $csrfError,
            'activity'     => $activity,
            'sports'       => $sports,
            'gearOptions'  => $gearOptions,
            'gearTypes'    => GearType::cases(),
            'gearDefaults' => $defaults,
            'gearCompat'   => $compat,
            'gearAutoType' => $autoType,
            'duplicates'   => $duplicates,
            'duplicateSources' => $duplicates !== [] ? $this->sources->sourcesOf($duplicates) : [],
            'implausible'  => $implausible,
            'plausibility' => ActivityPlausibilityRule::forJs(),
            'limits'       => [
                'nameMax'      => ManualActivityForm::NAME_MAX,
                'distanceMax'  => ManualActivityForm::MAX_DISTANCE_KM,
                'elevationMax' => ManualActivityForm::MAX_ELEVATION_M,
                'hrMin'        => ManualActivityForm::HR_MIN,
                'hrMax'        => ManualActivityForm::HR_MAX,
                'hoursMax'     => ManualActivityForm::MAX_HOURS,
                'minDate'      => ManualActivityForm::MIN_DATE,
                'noDistance'   => ManualActivityForm::NO_DISTANCE_SPORTS,
                'noElevation'  => ManualActivityForm::NO_ELEVATION_SPORTS,
            ],
        ], new Response(status: $errors !== [] || $csrfError ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}

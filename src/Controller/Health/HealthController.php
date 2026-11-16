<?php

namespace App\Controller\Health;

use App\Entity\HealthBodyArea;
use App\Entity\HealthCategory;
use App\Entity\HealthDosageMode;
use App\Entity\HealthEquipment;
use App\Entity\HealthExercise;
use App\Entity\HealthExerciseText;
use App\Entity\HealthExerciseUserPref;
use App\Entity\HealthLevel;
use App\Entity\User;
use App\Repository\HealthExerciseRepository;
use App\Repository\HealthExerciseUserPrefRepository;
use App\Service\Health\HealthExerciseForm;
use App\Service\Health\HealthExerciseViewBuilder;
use App\Service\Health\HealthLibraryFilter;
use App\Service\Health\HealthLimits;
use App\Service\Health\VideoUrl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace Santé : bibliothèque d'exercices (fournis + personnels), fiche d'un exercice, exercices personnels
 * (création, modification, suppression), lien vidéo personnel et masquage d'un exercice fourni.
 *
 * Accès : un exercice fourni est visible de tous, un exercice personnel de son seul propriétaire. Lecture d'un
 * exercice non visible : 404 ; écriture : 403. Toute écriture est en POST + CSRF ('health', champ _token).
 */
#[IsGranted('ROLE_USER')]
class HealthController extends AbstractController
{
    public const CSRF_ID = 'health';

    #[Route('/sante', name: 'app_health', methods: ['GET'])]
    public function library(Request $request, HealthExerciseViewBuilder $views, #[CurrentUser] User $user): Response
    {
        $filter = HealthLibraryFilter::fromQuery($request->query->all());
        $all = $views->forUser($user, $request->getLocale());
        $visible = array_values(array_filter($all, $filter->matches(...)));

        return $this->render('health/index.html.twig', [
            'exercises'    => $all,
            'visibleIds'   => array_flip(array_map(static fn ($v): int => $v->id, $visible)),
            'visibleCount' => count($visible),
            'hiddenCount'  => count(array_filter($all, static fn ($v): bool => $v->hidden)),
            'filter'       => $filter,
            'categories'   => HealthCategory::cases(),
            'areas'        => HealthBodyArea::cases(),
            'levels'       => HealthLevel::cases(),
            'equipment'    => HealthEquipment::cases(),
        ]);
    }

    #[Route('/sante/exercices/{id}', name: 'app_health_exercise_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request, HealthExerciseRepository $repository, HealthExerciseViewBuilder $views, #[CurrentUser] User $user): Response
    {
        $exercise = $repository->findVisible($id, $user) ?? throw $this->createNotFoundException();

        return $this->render('health/exercise_show.html.twig', [
            'exercise' => $views->one($exercise, $user, $request->getLocale()),
            'videoMax' => HealthLimits::VIDEO_URL_MAX,
        ]);
    }

    #[Route('/sante/exercices/nouveau', name: 'app_health_exercise_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        $form = new HealthExerciseForm();
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->csrfOk($request)) {
                return $this->redirectToRoute('app_health_exercise_new');
            }
            $form = HealthExerciseForm::fromRequest($request->request->all());
            $errors = $form->validate();
            if ($errors === []) {
                $exercise = (new HealthExercise())->setUser($user);
                $form->applyTo($exercise, $request->getLocale());
                $em->persist($exercise);
                $em->flush();
                $this->addFlash('success', 'health.flash.exercise_created');

                return $this->redirectToRoute('app_health_exercise_show', ['id' => $exercise->getId()]);
            }
        }

        return $this->renderForm($form, $errors, null);
    }

    #[Route('/sante/exercices/{id}/modifier', name: 'app_health_exercise_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(HealthExercise $exercise, Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        if (!$exercise->isOwnedBy($user)) {
            // Exercice fourni (non modifiable) ou d'un autre utilisateur
            throw $request->isMethod('POST') ? $this->createAccessDeniedException() : $this->createNotFoundException();
        }

        $form = HealthExerciseForm::fromExercise($exercise, $request->getLocale());
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->csrfOk($request)) {
                return $this->redirectToRoute('app_health_exercise_edit', ['id' => $exercise->getId()]);
            }
            $form = HealthExerciseForm::fromRequest($request->request->all());
            $errors = $form->validate();
            if ($errors === []) {
                $form->applyTo($exercise, $request->getLocale());
                $exercise->setUpdatedAt(new \DateTimeImmutable());
                $em->flush();
                $this->addFlash('success', 'health.flash.exercise_updated');

                return $this->redirectToRoute('app_health_exercise_show', ['id' => $exercise->getId()]);
            }
        }

        return $this->renderForm($form, $errors, $exercise);
    }

    /** Supprime un exercice personnel ; l'historique garde son nom (healthSessionExercise.exercise_id -> NULL). */
    #[Route('/sante/exercices/{id}/supprimer', name: 'app_health_exercise_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(HealthExercise $exercise, Request $request, EntityManagerInterface $em, #[CurrentUser] User $user): Response
    {
        if (!$exercise->isOwnedBy($user)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->csrfOk($request)) {
            return $this->redirectToRoute('app_health_exercise_show', ['id' => $exercise->getId()]);
        }

        $em->remove($exercise);
        $em->flush();
        $this->addFlash('success', 'health.flash.exercise_deleted');

        return $this->redirectToRoute('app_health');
    }

    /**
     * Lien vidéo : celui de l'exercice personnel, ou le lien personnel de l'utilisateur sur un exercice fourni.
     * Champ video_url vide = suppression du lien.
     */
    #[Route('/sante/exercices/{id}/video', name: 'app_health_exercise_video', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function video(HealthExercise $exercise, Request $request, EntityManagerInterface $em, HealthExerciseUserPrefRepository $prefs, #[CurrentUser] User $user): Response
    {
        if (!$exercise->isVisibleTo($user)) {
            throw $this->createAccessDeniedException();
        }
        $back = $this->redirectToRoute('app_health_exercise_show', ['id' => $exercise->getId(), '_fragment' => 'health-video']);
        if (!$this->csrfOk($request)) {
            return $back;
        }

        try {
            $url = VideoUrl::normalize((string) $request->request->get('video_url', ''));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $back;
        }

        if ($exercise->isCustom()) {
            $exercise->setVideoUrl($url)->setUpdatedAt(new \DateTimeImmutable());
        } else {
            $pref = $prefs->findOneFor($user, $exercise);
            if ($pref === null && $url !== null) {
                $pref = new HealthExerciseUserPref($user, $exercise);
                $em->persist($pref);
            }
            $pref?->setVideoUrl($url);
            if ($pref !== null && $pref->isEmpty()) {
                $em->remove($pref);
            }
        }
        $em->flush();
        $this->addFlash('success', $url !== null ? 'health.flash.video_saved' : 'health.flash.video_removed');

        return $back;
    }

    /** Masque (ou réaffiche) un exercice fourni dans la bibliothèque de l'utilisateur. */
    #[Route('/sante/exercices/{id}/masquer', name: 'app_health_exercise_hide', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function hide(HealthExercise $exercise, Request $request, EntityManagerInterface $em, HealthExerciseUserPrefRepository $prefs, #[CurrentUser] User $user): Response
    {
        if ($exercise->isCustom()) {
            // Un exercice personnel se supprime ; celui d'un autre utilisateur n'est pas accessible
            throw $this->createAccessDeniedException();
        }
        if (!$this->csrfOk($request)) {
            return $this->redirectToRoute('app_health_exercise_show', ['id' => $exercise->getId()]);
        }

        $pref = $prefs->findOneFor($user, $exercise);
        if ($pref === null) {
            $pref = new HealthExerciseUserPref($user, $exercise);
            $em->persist($pref);
        }
        $hidden = !$pref->isHidden();
        $pref->setHidden($hidden);
        if ($pref->isEmpty()) {
            $em->remove($pref);
        }
        $em->flush();
        $this->addFlash('success', $hidden ? 'health.flash.exercise_hidden' : 'health.flash.exercise_shown');

        return $hidden
            ? $this->redirectToRoute('app_health')
            : $this->redirectToRoute('app_health_exercise_show', ['id' => $exercise->getId()]);
    }

    /** @param array<string, \App\Service\Health\FieldError> $errors */
    private function renderForm(HealthExerciseForm $form, array $errors, ?HealthExercise $exercise): Response
    {
        return $this->render('health/exercise_form.html.twig', [
            'form'       => $form,
            'errors'     => $errors,
            'exercise'   => $exercise,
            'categories' => HealthCategory::cases(),
            'areas'      => HealthBodyArea::cases(),
            'levels'     => HealthLevel::cases(),
            'equipment'  => HealthEquipment::cases(),
            'modes'      => HealthDosageMode::cases(),
            'limits'     => [
                'setsMin' => HealthLimits::SETS_MIN, 'setsMax' => HealthLimits::SETS_MAX,
                'restMin' => HealthLimits::REST_MIN, 'restMax' => HealthLimits::REST_MAX,
                'video'   => HealthLimits::VIDEO_URL_MAX,
                'steps'   => HealthExerciseText::STEPS_MAX, 'mistakes' => HealthExerciseText::MISTAKES_MAX,
            ],
        ], new Response(status: $errors !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    private function csrfOk(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            return true;
        }
        $this->addFlash('error', 'health.flash.csrf');

        return false;
    }
}

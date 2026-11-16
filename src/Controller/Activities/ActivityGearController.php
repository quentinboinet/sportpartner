<?php

namespace App\Controller\Activities;

use App\Controller\Settings\GearController;
use App\Entity\Activity;
use App\Entity\GearType;
use App\Entity\User;
use App\Repository\GearRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Choix du matériel (chaussures, vélo) d'une activité, depuis sa page de détail. */
#[IsGranted('ROLE_USER')]
class ActivityGearController extends AbstractController
{
    #[Route('/activities/{id}/gear', name: 'app_activity_gear', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function __invoke(
        Activity $activity,
        Request $request,
        GearRepository $gearRepo,
        EntityManagerInterface $em,
        #[CurrentUser] User $user,
    ): Response {
        if ($activity->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }
        $back = $this->redirectToRoute('app_activity_show', ['id' => $activity->getId()]);

        if (!$this->isCsrfTokenValid(GearController::CSRF_ID, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', 'gear.flash_csrf');

            return $back;
        }

        $gearId = $request->request->getInt('gear_id');
        $gear   = null;
        if ($gearId > 0) {
            $gear = $gearRepo->find($gearId);
            if ($gear === null) {
                throw $this->createNotFoundException();
            }
            if ($gear->getUser()->getId() !== $user->getId()) {
                throw $this->createAccessDeniedException();
            }
            // Même règle que la liste proposée : type compatible et non retiré, sauf le matériel déjà rattaché
            $types      = GearType::compatibleWithSportSlug($activity->getSport()?->getSlug());
            $selectable = $gearRepo->findSelectable($user, $types, $activity->getGear());
            if (!in_array($gear, $selectable, true)) {
                $this->addFlash('error', 'gear.flash_incompatible');

                return $back;
            }
        }

        $activity->setGear($gear);
        $em->flush();

        $this->addFlash('success', $gear === null ? 'gear.flash_activity_cleared' : 'gear.flash_activity_updated');

        return $back;
    }
}

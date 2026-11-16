<?php

namespace App\Controller\Settings;

use App\Entity\AthleteProfile;
use App\Entity\GearType;
use App\Entity\User;
use App\Form\ProfileType;
use App\Repository\GearBrandRepository;
use App\Repository\GearRepository;
use App\Repository\SportRepository;
use App\Service\Gear\GearUsageProvider;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings')]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        TranslatorInterface $translator,
        GearRepository $gearRepo,
        GearBrandRepository $brandRepo,
        GearUsageProvider $gearUsage,
        SportRepository $sportRepo,
        #[CurrentUser] User $user,
    ): Response {
        $tab = $request->query->get('tab', 'profile');
        if ($tab === 'materiel') {
            $tab = 'gear';
        }

        $profile = $user->getProfile();
        if ($profile === null) {
            $profile = new AthleteProfile();
            $profile->setUser($user);
        }

        $form = $this->createForm(ProfileType::class, $profile);

        $profileData = [];
        if ($tab === 'profile') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if ($user->getProfile() === null) {
                    $user->setProfile($profile);
                    $em->persist($profile);
                }
                try {
                    $em->flush();
                    $this->addFlash('success', $translator->trans('settings.saved_flash'));
                } catch (UniqueConstraintViolationException) {
                    // Objectif ajouté pour le même sport depuis un autre onglet entre l'affichage et l'envoi
                    $this->addFlash('error', $translator->trans('settings.goals.conflict'));
                }

                return $this->redirectToRoute('app_settings', ['tab' => 'profile']);
            }
            // Menu "Ajouter un sport" des objectifs par sport
            $profileData = ['sports' => $sportRepo->findAllOrdered()];
        }

        $gearData = [];
        if ($tab === 'gear') {
            $gears    = $gearRepo->findByUser($user);
            $gearData = [
                'gears'     => $gears,
                'gearUsage' => $gearUsage->forUser($user, $gears),
                'brands'    => $brandRepo->findAllOrdered(),
                'gearTypes' => GearType::cases(),
            ];
        }

        return $this->render('settings/index.html.twig', [
            'tab'  => $tab,
            'form' => $form,
        ] + $gearData + $profileData);
    }
}

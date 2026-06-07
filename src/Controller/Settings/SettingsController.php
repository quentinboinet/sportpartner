<?php

namespace App\Controller\Settings;

use App\Entity\AthleteProfile;
use App\Form\ProfileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
    ): Response {
        $tab = $request->query->get('tab', 'profile');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $profile = $user->getProfile();
        if ($profile === null) {
            $profile = new AthleteProfile();
            $profile->setUser($user);
        }

        $form = $this->createForm(ProfileType::class, $profile);

        if ($tab === 'profile') {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                if ($user->getProfile() === null) {
                    $user->setProfile($profile);
                    $em->persist($profile);
                }
                $em->flush();
                $this->addFlash('success', $translator->trans('settings.saved_flash'));

                return $this->redirectToRoute('app_settings', ['tab' => 'profile']);
            }
        }

        return $this->render('settings/index.html.twig', [
            'tab'  => $tab,
            'form' => $form,
        ]);
    }
}

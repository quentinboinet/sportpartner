<?php

namespace App\Controller\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class RegisterController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        ValidatorInterface $validator,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            $user = new User();
            $user->setEmail($request->request->get('email', ''));
            $user->setFirstName($request->request->get('firstName'));
            $user->setLastName($request->request->get('lastName'));
            $user->setPassword($hasher->hashPassword($user, $request->request->get('password', '')));

            $errors = $validator->validate($user);

            if (count($errors) > 0) {
                $errorMessages = [];
                foreach ($errors as $error) {
                    $errorMessages[] = $error->getMessage();
                }

                return $this->render('auth/register.html.twig', [
                    'errors'    => $errorMessages,
                    'email'     => $request->request->get('email'),
                    'firstName' => $request->request->get('firstName'),
                    'lastName'  => $request->request->get('lastName'),
                ]);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Compte créé ! Tu peux maintenant te connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/register.html.twig');
    }
}

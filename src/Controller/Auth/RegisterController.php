<?php

namespace App\Controller\Auth;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Rompetomp\InertiaBundle\Service\InertiaInterface;
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
        InertiaInterface $inertia,
    ): Response {
        if ($request->isMethod('POST')) {
            $data = $request->toArray();

            $user = new User();
            $user->setEmail($data['email'] ?? '');
            $user->setFirstName($data['firstName'] ?? null);
            $user->setLastName($data['lastName'] ?? null);
            $user->setPassword($hasher->hashPassword($user, $data['password'] ?? ''));

            $errors = $validator->validate($user);
            if (count($errors) > 0) {
                return $this->json(['errors' => (string) $errors], 422);
            }

            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('app_login');
        }

        return $inertia->render('Auth/Register');
    }
}

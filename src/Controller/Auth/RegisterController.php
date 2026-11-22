<?php

namespace App\Controller\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RateLimitRetry;
use App\Service\User\RegistrationForm;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Inscription. Ordre des contrôles d'un envoi : jeton CSRF 'register' (refus 422), limiteur par IP
 * « registration » (10 envois par heure, refus 429 + Retry-After), règles de RegistrationForm (422), e-mail déjà
 * utilisé (422, message sur le formulaire, aussi si deux inscriptions se croisent : index unique), puis hachage du
 * mot de passe et création du compte. Le mot de passe n'est jamais réaffiché.
 */
class RegisterController extends AbstractController
{
    public const CSRF_ID = 'register';

    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        RateLimiterFactoryInterface $registrationLimiter,
        TranslatorInterface $translator,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }
        if (!$request->isMethod('POST')) {
            return $this->render('auth/register.html.twig');
        }

        $post   = $request->request->all();
        $values = RegistrationForm::redisplay($post);

        $token = $post['_csrf_token'] ?? null;
        if (!\is_string($token) || !$this->isCsrfTokenValid(self::CSRF_ID, $token)) {
            return $this->form($values, ['auth.register.error_csrf'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $limit = $registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $response = $this->form($values, [
                $translator->trans('auth.register.error_rate_limited', ['%minutes%' => RateLimitRetry::minutes($limit)]),
            ], Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) RateLimitRetry::seconds($limit));

            return $response;
        }

        $form = RegistrationForm::fromPost($post);
        if (!$form->isValid()) {
            return $this->form($values, array_values($form->errors), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (null !== $users->findOneBy(['email' => $form->email])) {
            return $this->form($values, ['auth.register.error_email_taken'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = (new User())
            ->setEmail($form->email)
            ->setFirstName($form->firstName)
            ->setLastName($form->lastName);
        $user->setPassword($hasher->hashPassword($user, $form->password));

        try {
            $em->persist($user);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Même e-mail inscrit entre la vérification et l'enregistrement
            return $this->form($values, ['auth.register.error_email_taken'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('success', 'auth.register.flash_created');

        return $this->redirectToRoute('app_login');
    }

    /**
     * @param array{email: string, firstName: string, lastName: string} $values
     * @param list<string>                                              $errors clés de traduction ou textes traduits
     */
    private function form(array $values, array $errors, int $status): Response
    {
        return $this->render('auth/register.html.twig', ['errors' => $errors] + $values, new Response('', $status));
    }
}

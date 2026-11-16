<?php

namespace App\Controller\Settings;

use App\Entity\User;
use App\Entity\UserAvatar;
use App\Repository\UserAvatarRepository;
use App\Service\User\AvatarException;
use App\Service\User\AvatarProcessor;
use App\Service\User\ProcessedAvatar;
use App\Service\User\StravaAvatarException;
use App\Service\User\StravaAvatarFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Photo de profil (Paramètres > Profil) : envoi d'une image, reprise de la photo Strava et
 * suppression, en POST + CSRF ('avatar') avec retour sur l'onglet Profil ; affichage de la photo
 * de l'utilisateur connecté. L'image est stockée en base (UserAvatar) et User::avatarUpdatedAt
 * sert de version dans son URL (user_avatar_url() côté Twig, sans requête).
 */
#[Route('/settings/avatar', name: 'app_avatar_')]
#[IsGranted('ROLE_USER')]
final class AvatarController extends AbstractController
{
    public const CSRF_ID = 'avatar';

    /** Durée de cache de l'image : son URL change à chaque nouvelle photo. */
    private const IMAGE_MAX_AGE = 31_536_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserAvatarRepository $avatars,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** Version de l'image dans son URL (?v=), null sans photo. */
    public static function imageVersion(User $user): ?string
    {
        $updatedAt = $user->getAvatarUpdatedAt();

        return $updatedAt !== null ? (string) $updatedAt->getTimestamp() : null;
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, AvatarProcessor $processor, #[CurrentUser] User $user): Response
    {
        // POST plus gros que post_max_size : PHP vide $_POST et $_FILES (le jeton CSRF est donc absent)
        if ($request->request->count() === 0 && $request->files->count() === 0 && (int) $request->server->get('CONTENT_LENGTH', 0) > 0) {
            return $this->tooLarge();
        }
        if (!$this->csrfOk($request)) {
            return $this->back();
        }

        $file = $request->files->get('avatar');
        if (!$file instanceof UploadedFile || $file->getError() === \UPLOAD_ERR_NO_FILE) {
            return $this->fail('avatar.error.missing');
        }
        if (in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->tooLarge();
        }
        if (!$file->isValid()) {
            return $this->fail('avatar.error.upload');
        }
        if ($file->getSize() > AvatarProcessor::MAX_BYTES) {
            return $this->tooLarge();
        }

        $bytes = @file_get_contents($file->getPathname());
        if ($bytes === false) {
            return $this->fail('avatar.error.upload');
        }

        try {
            $avatar = $processor->process($bytes);
        } catch (AvatarException $e) {
            return $e->getCode() === AvatarException::TOO_LARGE ? $this->tooLarge() : $this->fail($e->translationKey());
        }

        $this->store($user, $avatar);
        $this->addFlash('success', 'avatar.flash.updated');

        return $this->back();
    }

    /** Reprend la photo du profil Strava (voir StravaAvatarFetcher). Aucune erreur Strava ne donne une page d'erreur. */
    #[Route('/strava', name: 'strava', methods: ['POST'])]
    public function fromStrava(Request $request, StravaAvatarFetcher $fetcher, LoggerInterface $logger, #[CurrentUser] User $user): Response
    {
        if (!$this->csrfOk($request)) {
            return $this->back();
        }

        try {
            $avatar = $fetcher->fetch($user);
        } catch (StravaAvatarException $e) {
            $logger->warning('Strava profile photo import failed (code {code}): {message}', ['code' => $e->getCode(), 'message' => $e->getMessage()]);
            $this->addFlash($e->getCode() === StravaAvatarException::NO_PHOTO ? 'warning' : 'error', $e->translationKey());

            return $this->back();
        } catch (AvatarException $e) {
            $logger->warning('Strava profile photo rejected (code {code}): {message}', ['code' => $e->getCode(), 'message' => $e->getMessage()]);

            return $this->fail('avatar.strava.invalid');
        } catch (\Throwable $e) {
            $logger->error('Strava profile photo import failed', ['exception' => $e]);

            return $this->fail('avatar.strava.unavailable');
        }

        $this->store($user, $avatar);
        $this->addFlash('success', 'avatar.flash.strava');

        return $this->back();
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->csrfOk($request)) {
            return $this->back();
        }

        $avatar = $this->avatars->findOneByUser($user);
        if ($avatar !== null) {
            $this->em->remove($avatar);
        }
        $user->setAvatarUpdatedAt(null);
        $this->em->flush();

        $this->addFlash('success', 'avatar.flash.deleted');

        return $this->back();
    }

    /**
     * Photo de l'utilisateur connecté (jamais celle d'un autre), 404 sans photo. Cache privé d'un
     * an quand l'URL porte la version courante (?v=), revalidation (ETag) sinon.
     */
    #[Route('/image', name: 'image', methods: ['GET'])]
    public function image(Request $request, #[CurrentUser] User $user): Response
    {
        $version = self::imageVersion($user);
        $avatar  = $version !== null ? $this->avatars->findOneByUser($user) : null;
        if ($avatar === null) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $data     = $avatar->getData();
        $response = new Response($data, Response::HTTP_OK, [
            'Content-Type'           => $avatar->getMimeType(),
            'Content-Length'         => (string) strlen($data),
            'X-Content-Type-Options' => 'nosniff',
            // Sans cet en-tête, la session (pare-feu) imposerait "max-age=0, must-revalidate"
            AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER => 'true',
        ]);
        $response->setPrivate();
        $response->setEtag(hash('sha256', $data));
        if ($request->query->get('v') === $version) {
            $response->setMaxAge(self::IMAGE_MAX_AGE);
            $response->setImmutable();
        } else {
            $response->headers->addCacheControlDirective('no-cache');
        }
        $response->isNotModified($request);

        return $response;
    }

    /** Enregistre la photo (création ou remplacement) et change la version de son URL. */
    private function store(User $user, ProcessedAvatar $processed): void
    {
        $now      = new \DateTimeImmutable();
        $previous = $user->getAvatarUpdatedAt();
        // Version strictement croissante : deux photos dans la même seconde ont quand même des URL différentes
        if ($previous !== null && $now->getTimestamp() <= $previous->getTimestamp()) {
            $now = $now->setTimestamp($previous->getTimestamp() + 1);
        }

        $avatar = $this->avatars->findOneByUser($user) ?? new UserAvatar($user);
        $avatar->setData($processed->data)
            ->setMimeType($processed->mimeType)
            ->setWidth($processed->width)
            ->setHeight($processed->height)
            ->setUpdatedAt($now);
        $this->em->persist($avatar);
        $user->setAvatarUpdatedAt($now);
        $this->em->flush();
    }

    private function tooLarge(): Response
    {
        return $this->fail($this->translator->trans('avatar.error.too_large', ['%size%' => AvatarProcessor::maxUploadMegabytes()]));
    }

    private function csrfOk(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token', ''))) {
            return true;
        }
        $this->addFlash('error', 'avatar.error.csrf');

        return false;
    }

    private function fail(string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->back();
    }

    private function back(): Response
    {
        return $this->redirectToRoute('app_settings', ['tab' => 'profile']);
    }
}

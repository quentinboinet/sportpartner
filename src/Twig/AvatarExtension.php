<?php

namespace App\Twig;

use App\Controller\Settings\AvatarController;
use App\Entity\User;
use App\Service\User\AvatarProcessor;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Photo de profil dans les templates : user_avatar_url(user) donne l'URL versionnée de l'image ou
 * null, sans requête (d'après User::avatarUpdatedAt) ; avatar_max_upload_bytes() et
 * avatar_max_upload_mb() donnent la taille maximale d'un envoi sur ce serveur.
 */
final class AvatarExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('user_avatar_url', $this->avatarUrl(...)),
            new TwigFunction('avatar_max_upload_bytes', AvatarProcessor::maxUploadBytes(...)),
            new TwigFunction('avatar_max_upload_mb', AvatarProcessor::maxUploadMegabytes(...)),
        ];
    }

    /**
     * URL de la photo, null sans photo. La route ne sert que la photo de l'utilisateur connecté :
     * null aussi pour tout autre utilisateur.
     */
    public function avatarUrl(?UserInterface $user): ?string
    {
        if (!$user instanceof User) {
            return null;
        }
        $version = AvatarController::imageVersion($user);
        $current = $this->security->getUser();
        if ($version === null || !$current instanceof User || $current->getId() !== $user->getId()) {
            return null;
        }

        return $this->urls->generate('app_avatar_image', ['v' => $version]);
    }
}

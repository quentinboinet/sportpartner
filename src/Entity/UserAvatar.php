<?php

namespace App\Entity;

use App\Repository\UserAvatarRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Photo de profil, stockée en base (aucun fichier sur disque : elle survit aux déploiements).
 * Toujours une image déjà retraitée par AvatarProcessor (carrée, 256 px au plus, sans métadonnées).
 * Pas de relation inverse sur User, pour ne jamais charger l'image avec l'utilisateur :
 * les templates utilisent User::getAvatarUpdatedAt() pour savoir s'il y a une photo.
 */
#[ORM\Entity(repositoryClass: UserAvatarRepository::class)]
#[ORM\Table(name: 'userAvatar')]
class UserAvatar
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $mimeType;

    /**
     * Octets de l'image. Doctrine hydrate un BLOB en flux (resource) : getData() le relit.
     *
     * @var string|resource
     */
    #[ORM\Column(type: Types::BLOB)]
    private mixed $data = '';

    #[ORM\Column]
    private int $width = 0;

    #[ORM\Column]
    private int $height = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user      = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }

    public function getMimeType(): string { return $this->mimeType; }
    public function setMimeType(string $mimeType): static { $this->mimeType = $mimeType; return $this; }

    public function getData(): string
    {
        if (is_resource($this->data)) {
            // Lecture depuis le début sans remplacer la propriété (sinon Doctrine verrait un changement au flush)
            $content = stream_get_contents($this->data, -1, 0);

            return $content === false ? '' : $content;
        }

        return $this->data;
    }

    public function setData(string $data): static { $this->data = $data; return $this; }

    public function getWidth(): int { return $this->width; }
    public function setWidth(int $width): static { $this->width = $width; return $this; }

    public function getHeight(): int { return $this->height; }
    public function setHeight(int $height): static { $this->height = $height; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}

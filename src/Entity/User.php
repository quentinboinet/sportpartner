<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\Email]
    #[Assert\NotBlank]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(nullable: true)]
    private ?string $stravaAccessToken = null;

    #[ORM\Column(nullable: true)]
    private ?string $stravaRefreshToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $stravaTokenExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $stravaAthleteId = null;

    #[ORM\Column(nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 20, options: ['default' => 'free'])]
    private string $subscriptionPlan = 'free';

    #[ORM\Column(default: false)]
    private bool $emailVerified = false;

    #[ORM\Column(nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    public function getUserIdentifier(): string { return (string) $this->email; }
    public function getRoles(): array { return array_unique(array_merge($this->roles, ['ROLE_USER'])); }
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }
    public function eraseCredentials(): void {}
    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(?string $firstName): static { $this->firstName = $firstName; return $this; }
    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(?string $lastName): static { $this->lastName = $lastName; return $this; }
    public function getFullName(): string { return trim($this->firstName.' '.$this->lastName); }
    public function getStravaAccessToken(): ?string { return $this->stravaAccessToken; }
    public function setStravaAccessToken(?string $token): static { $this->stravaAccessToken = $token; return $this; }
    public function getStravaRefreshToken(): ?string { return $this->stravaRefreshToken; }
    public function setStravaRefreshToken(?string $token): static { $this->stravaRefreshToken = $token; return $this; }
    public function getStravaTokenExpiresAt(): ?\DateTimeImmutable { return $this->stravaTokenExpiresAt; }
    public function setStravaTokenExpiresAt(?\DateTimeImmutable $expiresAt): static { $this->stravaTokenExpiresAt = $expiresAt; return $this; }
    public function getStravaAthleteId(): ?int { return $this->stravaAthleteId; }
    public function setStravaAthleteId(?int $id): static { $this->stravaAthleteId = $id; return $this; }
    public function isStravaConnected(): bool { return null !== $this->stravaAthleteId; }
    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $id): static { $this->stripeCustomerId = $id; return $this; }
    public function getStripeSubscriptionId(): ?string { return $this->stripeSubscriptionId; }
    public function setStripeSubscriptionId(?string $id): static { $this->stripeSubscriptionId = $id; return $this; }
    public function getSubscriptionPlan(): string { return $this->subscriptionPlan; }
    public function setSubscriptionPlan(string $plan): static { $this->subscriptionPlan = $plan; return $this; }
    public function isPro(): bool { return 'pro' === $this->subscriptionPlan; }
    public function isEmailVerified(): bool { return $this->emailVerified; }
    public function setEmailVerified(bool $verified): static { $this->emailVerified = $verified; return $this; }
    public function getEmailVerificationToken(): ?string { return $this->emailVerificationToken; }
    public function setEmailVerificationToken(?string $token): static { $this->emailVerificationToken = $token; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

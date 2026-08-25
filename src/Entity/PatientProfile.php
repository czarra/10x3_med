<?php

namespace App\Entity;

use App\Repository\PatientProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PatientProfileRepository::class)]
#[ORM\Table(name: 'patient_profiles')]
class PatientProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private User $user;

    #[ORM\Column]
    #[Assert\Positive]
    #[Assert\LessThanOrEqual(35)]
    private float $baseDose;

    #[ORM\Column]
    #[Assert\Range(min: 0.1, max: 10.0)]
    private float $insulinWwRatio;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, float $baseDose, float $insulinWwRatio)
    {
        $this->user = $user;
        $this->baseDose = $baseDose;
        $this->insulinWwRatio = $insulinWwRatio;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBaseDose(): float
    {
        return $this->baseDose;
    }

    public function setBaseDose(float $baseDose): static
    {
        $this->baseDose = $baseDose;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getInsulinWwRatio(): float
    {
        return $this->insulinWwRatio;
    }

    public function setInsulinWwRatio(float $insulinWwRatio): static
    {
        $this->insulinWwRatio = $insulinWwRatio;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

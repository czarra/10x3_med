<?php

namespace App\Entity;

use App\Repository\DiaryEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DiaryEntryRepository::class)]
#[ORM\Table(name: 'diary_entries')]
#[Assert\Callback('validateActivityPairing')]
class DiaryEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column]
    #[Assert\GreaterThan(20)]
    private int $glycemiaMgDl;

    #[ORM\Column]
    #[Assert\LessThanOrEqual(value: 'now')]
    private \DateTimeImmutable $measuredAt;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 20)]
    private ?float $ww = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 50)]
    private ?float $insulinDose = null;

    #[ORM\Column(enumType: ActivityIntensity::class, nullable: true)]
    private ?ActivityIntensity $activityIntensity = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 300)]
    private ?int $activityDurationMinutes = null;

    #[ORM\Column]
    private float $insulinWwRatioSnapshot;

    #[ORM\Column]
    private float $baseDoseSnapshot;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, float $insulinWwRatioSnapshot, float $baseDoseSnapshot)
    {
        $this->user = $user;
        $this->insulinWwRatioSnapshot = $insulinWwRatioSnapshot;
        $this->baseDoseSnapshot = $baseDoseSnapshot;
        $this->glycemiaMgDl = 0;
        $this->measuredAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getGlycemiaMgDl(): int
    {
        return $this->glycemiaMgDl;
    }

    public function setGlycemiaMgDl(int $glycemiaMgDl): static
    {
        $this->glycemiaMgDl = $glycemiaMgDl;

        return $this;
    }

    public function getMeasuredAt(): \DateTimeImmutable
    {
        return $this->measuredAt;
    }

    public function setMeasuredAt(\DateTimeImmutable $measuredAt): static
    {
        $this->measuredAt = $measuredAt;

        return $this;
    }

    public function getWw(): ?float
    {
        return $this->ww;
    }

    public function setWw(?float $ww): static
    {
        $this->ww = $ww;

        return $this;
    }

    public function getInsulinDose(): ?float
    {
        return $this->insulinDose;
    }

    public function setInsulinDose(?float $insulinDose): static
    {
        $this->insulinDose = $insulinDose;

        return $this;
    }

    public function getActivityIntensity(): ?ActivityIntensity
    {
        return $this->activityIntensity;
    }

    public function setActivityIntensity(?ActivityIntensity $activityIntensity): static
    {
        $this->activityIntensity = $activityIntensity;

        return $this;
    }

    public function getActivityDurationMinutes(): ?int
    {
        return $this->activityDurationMinutes;
    }

    public function setActivityDurationMinutes(?int $activityDurationMinutes): static
    {
        $this->activityDurationMinutes = $activityDurationMinutes;

        return $this;
    }

    public function getInsulinWwRatioSnapshot(): float
    {
        return $this->insulinWwRatioSnapshot;
    }

    public function getBaseDoseSnapshot(): float
    {
        return $this->baseDoseSnapshot;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function validateActivityPairing(ExecutionContextInterface $context): void
    {
        if (null !== $this->activityIntensity && null === $this->activityDurationMinutes) {
            $context->buildViolation('Podaj czas trwania wysiłku dla wybranej intensywności.')
                ->atPath('activityDurationMinutes')
                ->addViolation();
        }

        if (null === $this->activityIntensity && null !== $this->activityDurationMinutes) {
            $context->buildViolation('Wybierz intensywność wysiłku dla podanego czasu trwania.')
                ->atPath('activityIntensity')
                ->addViolation();
        }
    }
}

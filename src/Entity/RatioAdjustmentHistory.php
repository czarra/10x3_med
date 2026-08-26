<?php

namespace App\Entity;

use App\Repository\RatioAdjustmentHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RatioAdjustmentHistoryRepository::class)]
#[ORM\Table(name: 'ratio_adjustment_histories')]
class RatioAdjustmentHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column]
    private float $oldRatio;

    #[ORM\Column]
    private float $newRatio;

    #[ORM\Column]
    private \DateTimeImmutable $acceptedAt;

    public function __construct(User $user, float $oldRatio, float $newRatio, \DateTimeImmutable $acceptedAt)
    {
        $this->user = $user;
        $this->oldRatio = $oldRatio;
        $this->newRatio = $newRatio;
        $this->acceptedAt = $acceptedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getOldRatio(): float
    {
        return $this->oldRatio;
    }

    public function getNewRatio(): float
    {
        return $this->newRatio;
    }

    public function getAcceptedAt(): \DateTimeImmutable
    {
        return $this->acceptedAt;
    }
}

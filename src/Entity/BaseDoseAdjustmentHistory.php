<?php

namespace App\Entity;

use App\Repository\BaseDoseAdjustmentHistoryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BaseDoseAdjustmentHistoryRepository::class)]
#[ORM\Table(name: 'base_dose_adjustment_histories')]
class BaseDoseAdjustmentHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column]
    private int $oldBaseDose;

    #[ORM\Column]
    private int $newBaseDose;

    #[ORM\Column]
    private \DateTimeImmutable $acceptedAt;

    public function __construct(User $user, int $oldBaseDose, int $newBaseDose, \DateTimeImmutable $acceptedAt)
    {
        $this->user = $user;
        $this->oldBaseDose = $oldBaseDose;
        $this->newBaseDose = $newBaseDose;
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

    public function getOldBaseDose(): int
    {
        return $this->oldBaseDose;
    }

    public function getNewBaseDose(): int
    {
        return $this->newBaseDose;
    }

    public function getAcceptedAt(): \DateTimeImmutable
    {
        return $this->acceptedAt;
    }
}

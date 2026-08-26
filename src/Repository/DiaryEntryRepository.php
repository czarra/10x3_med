<?php

namespace App\Repository;

use App\Entity\DiaryEntry;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiaryEntry>
 */
class DiaryEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiaryEntry::class);
    }

    /**
     * @return DiaryEntry[]
     */
    public function findByUserOrderedByMeasuredAt(User $user, ?\DateTimeImmutable $after = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.measuredAt', 'ASC');

        if (null !== $after) {
            $qb->andWhere('e.measuredAt > :after')
                ->setParameter('after', $after);
        }

        return $qb->getQuery()->getResult();
    }
}

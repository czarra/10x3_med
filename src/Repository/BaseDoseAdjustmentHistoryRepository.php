<?php

namespace App\Repository;

use App\Entity\BaseDoseAdjustmentHistory;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BaseDoseAdjustmentHistory>
 */
class BaseDoseAdjustmentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BaseDoseAdjustmentHistory::class);
    }

    public function findLatestByUser(User $user): ?BaseDoseAdjustmentHistory
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.user = :user')
            ->setParameter('user', $user)
            ->orderBy('h.acceptedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

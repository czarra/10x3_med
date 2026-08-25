<?php

namespace App\Repository;

use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PatientProfile>
 */
class PatientProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PatientProfile::class);
    }

    public function findOneByUser(User $user): ?PatientProfile
    {
        return $this->findOneBy(['user' => $user]);
    }
}

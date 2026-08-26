<?php

namespace App\Tests\Entity;

use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PatientProfileTest extends KernelTestCase
{
    public function testPersistsProfileAndEnforcesUniqueUser(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail(sprintf('user-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        $profile = new PatientProfile($user, 13, 1.2);
        $entityManager->persist($profile);
        $entityManager->flush();

        try {
            $this->assertNotNull($profile->getId());
            $this->assertSame($user, $profile->getUser());
            $this->assertSame(13, $profile->getBaseDose());
            $this->assertSame(1.2, $profile->getInsulinWwRatio());

            $duplicate = new PatientProfile($user, 5, 2.0);
            $entityManager->persist($duplicate);

            $this->expectException(UniqueConstraintViolationException::class);
            $entityManager->flush();
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
            $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
        }
    }

    public function testZeroValuesFailValidation(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var ValidatorInterface $validator */
        $validator = $container->get(ValidatorInterface::class);

        $user = new User();
        $user->setEmail(sprintf('user-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');

        $zeroBaseDose = new PatientProfile($user, 0, 1.0);
        $violations = $validator->validate($zeroBaseDose);
        $this->assertGreaterThan(0, count($violations), 'Expected baseDose=0 to fail validation.');

        $zeroRatio = new PatientProfile($user, 10, 0);
        $violations = $validator->validate($zeroRatio);
        $this->assertGreaterThan(0, count($violations), 'Expected insulinWwRatio=0 to fail validation.');
    }
}

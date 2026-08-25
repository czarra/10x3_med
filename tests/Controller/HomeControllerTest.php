<?php

namespace App\Tests\Controller;

use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class HomeControllerTest extends WebTestCase
{
    public function testHomeRedirectsAuthenticatedUserToProfile(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $profile = new PatientProfile($user, 10.0, 1.0);
        $entityManager->persist($profile);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/');

            $this->assertResponseRedirects('/profil');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager): User
    {
        $user = new User();
        $user->setEmail(sprintf('home-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function cleanupUser(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

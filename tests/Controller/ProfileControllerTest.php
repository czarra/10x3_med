<?php

namespace App\Tests\Controller;

use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProfileControllerTest extends WebTestCase
{
    public function testEditingProfilePersistsNewValuesWithoutPasswordField(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        $profile = new PatientProfile($user, 10.0, 1.0);
        $entityManager->persist($profile);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/profil');

            $form = $crawler->filter('body > form')->form();
            $this->assertFalse($form->has('password'), 'Profile form must not require a password field.');

            $form->setValues([
                'profile_form[baseDose]' => '20',
                'profile_form[insulinWwRatio]' => '2.5',
            ]);
            $client->submit($form);

            $this->assertResponseRedirects('/profil');

            $entityManager->clear();
            $updated = $entityManager->getRepository(PatientProfile::class)->findOneBy(['user' => $user]);
            $this->assertNotNull($updated);
            $this->assertSame(20.0, $updated->getBaseDose());
            $this->assertSame(2.5, $updated->getInsulinWwRatio());
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
        $user->setEmail(sprintf('profile-%s@example.test', uniqid()));
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

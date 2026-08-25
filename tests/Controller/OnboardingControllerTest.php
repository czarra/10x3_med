<?php

namespace App\Tests\Controller;

use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OnboardingControllerTest extends WebTestCase
{
    public function testUserWithoutProfileIsRedirectedFromProfileToOnboarding(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $client->loginUser($user);
            $client->request('GET', '/profil');

            $this->assertResponseRedirects('/onboarding');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testSubmittingZerosReShowsValidationErrorsAndCreatesNoProfile(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/onboarding');

            $form = $crawler->filter('body > form')->form([
                'profile_form[baseDose]' => '0',
                'profile_form[insulinWwRatio]' => '0',
            ]);
            $client->submit($form);

            $this->assertResponseIsUnprocessable();
            $this->assertSelectorExists('form ul li');

            $entityManager->clear();
            $this->assertNull($this->findProfile($entityManager, $user));
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testValidSubmissionCreatesProfileAndRedirectsToProfile(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/onboarding');

            $form = $crawler->filter('body > form')->form([
                'profile_form[baseDose]' => '12.5',
                'profile_form[insulinWwRatio]' => '1.2',
            ]);
            $client->submit($form);

            $this->assertResponseRedirects('/profil');

            $entityManager->clear();
            $profile = $this->findProfile($entityManager, $user);
            $this->assertNotNull($profile);
            $this->assertSame(12.5, $profile->getBaseDose());
            $this->assertSame(1.2, $profile->getInsulinWwRatio());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testUserWithProfileIsRedirectedFromOnboardingToProfile(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $profile = new PatientProfile($user, 10.0, 1.0);
        $entityManager->persist($profile);
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $client->request('GET', '/onboarding');

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
        $user->setEmail(sprintf('onboarding-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function findProfile(EntityManagerInterface $entityManager, User $user): ?PatientProfile
    {
        return $entityManager->getRepository(PatientProfile::class)->findOneBy(['user' => $user]);
    }

    private function cleanupUser(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

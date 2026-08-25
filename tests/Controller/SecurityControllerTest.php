<?php

namespace App\Tests\Controller;

use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SecurityControllerTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public function testValidLoginWithProfileRedirectsToProfile(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $profile = new PatientProfile($user, 10.0, 1.0);
        $entityManager->persist($profile);
        $entityManager->flush();

        try {
            $this->submitLoginForm($client, $user->getEmail(), self::PASSWORD);

            $this->assertResponseRedirects('/profil');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testValidLoginWithoutProfileIsBouncedToOnboarding(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $this->submitLoginForm($client, $user->getEmail(), self::PASSWORD);
            $client->followRedirect();

            $this->assertResponseRedirects('/onboarding');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testWrongPasswordShowsErrorAndDoesNotLogIn(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $this->submitLoginForm($client, $user->getEmail(), 'not-the-right-password');
            $client->followRedirect();

            $this->assertSelectorTextContains('body', 'Invalid credentials');
            $this->assertNull(static::getContainer()->get('security.token_storage')->getToken());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testLogoutThenProfileRedirectsToLogin(): void
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

            $form = $crawler->filter('nav form')->form();
            $client->submit($form);

            $this->assertResponseRedirects('/login');

            $client->request('GET', '/profil');

            $this->assertResponseRedirects('/login');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    private function submitLoginForm(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');

        $form = $crawler->filter('form')->form([
            '_username' => $email,
            '_password' => $password,
        ]);
        $client->submit($form);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager): User
    {
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail(sprintf('security-%s@example.test', uniqid()));
        $user->setPassword($passwordHasher->hashPassword($user, self::PASSWORD));
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

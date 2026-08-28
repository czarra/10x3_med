<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationControllerTest extends WebTestCase
{
    public function testValidRegistrationCreatesUserLogsInAndRedirectsToOnboarding(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $email = sprintf('register-%s@example.test', uniqid());

        try {
            $this->submitRegistrationForm($client, $email, 'Sekretne1!');

            $this->assertResponseRedirects('/onboarding');

            $user = $this->findUserByEmail($entityManager, $email);
            $this->assertNotNull($user);

            $token = static::getContainer()->get('security.token_storage')->getToken();
            $this->assertNotNull($token);
            $this->assertSame($email, $token->getUserIdentifier());
        } finally {
            $this->cleanupUserByEmail($entityManager, $email);
        }
    }

    public function testDuplicateEmailIsRejectedWithFormErrorAndNoSecondRow(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $email = sprintf('register-%s@example.test', uniqid());
        $this->createUser($entityManager, $email);

        try {
            $this->submitRegistrationForm($client, $email, 'Sekretne1!');

            $this->assertResponseIsUnprocessable();

            $entityManager->clear();
            $count = (int) $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM users WHERE email = ?',
                [$email]
            );
            $this->assertSame(1, $count);
        } finally {
            $this->cleanupUserByEmail($entityManager, $email);
        }
    }

    public function testDuplicateEmailErrorDoesNotConfirmAccountExistence(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $email = sprintf('register-%s@example.test', uniqid());
        $this->createUser($entityManager, $email);

        try {
            $this->submitRegistrationForm($client, $email, 'Sekretne1!');

            $this->assertResponseIsUnprocessable();

            $content = (string) $client->getResponse()->getContent();
            $this->assertStringNotContainsString('Istnieje już konto', $content);
            $this->assertStringContainsString('Rejestracja nie powiodła się. Sprawdź wprowadzone dane i spróbuj ponownie.', $content);
        } finally {
            $this->cleanupUserByEmail($entityManager, $email);
        }
    }

    public function testPasswordMissingDigitIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $email = sprintf('register-%s@example.test', uniqid());

        try {
            $this->submitRegistrationForm($client, $email, 'Sekretne!');

            $this->assertResponseIsUnprocessable();
            $this->assertNull($this->findUserByEmail($entityManager, $email));
        } finally {
            $this->cleanupUserByEmail($entityManager, $email);
        }
    }

    public function testPasswordMissingSpecialCharacterIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $email = sprintf('register-%s@example.test', uniqid());

        try {
            $this->submitRegistrationForm($client, $email, 'Sekretne1');

            $this->assertResponseIsUnprocessable();
            $this->assertNull($this->findUserByEmail($entityManager, $email));
        } finally {
            $this->cleanupUserByEmail($entityManager, $email);
        }
    }

    public function testPasswordUnder8CharactersIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $email = sprintf('register-%s@example.test', uniqid());

        try {
            $this->submitRegistrationForm($client, $email, 'Se1!');

            $this->assertResponseIsUnprocessable();
            $this->assertNull($this->findUserByEmail($entityManager, $email));
        } finally {
            $this->cleanupUserByEmail($entityManager, $email);
        }
    }

    private function submitRegistrationForm(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/register');

        $form = $crawler->filter('form')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => $password,
        ]);
        $client->submit($form);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager, string $email): User
    {
        $passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($passwordHasher->hashPassword($user, 'Sekretne1!'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function findUserByEmail(EntityManagerInterface $entityManager, string $email): ?User
    {
        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    private function cleanupUserByEmail(EntityManagerInterface $entityManager, string $email): void
    {
        $entityManager->getConnection()->executeStatement('DELETE FROM users WHERE email = ?', [$email]);
    }
}

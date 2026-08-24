<?php

namespace App\Tests\Entity;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserTest extends KernelTestCase
{
    public function testPersistsHashesPasswordAndResolvesRoles(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $entityManager = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail(sprintf('user-%s@example.test', uniqid()));
        $user->setPassword($passwordHasher->hashPassword($user, 'correct-horse-battery-staple'));

        $entityManager->persist($user);
        $entityManager->flush();

        try {
            $this->assertTrue($passwordHasher->isPasswordValid($user, 'correct-horse-battery-staple'));
            $this->assertContains(User::ROLE_PATIENT, $user->getRoles());
            $this->assertContains('ROLE_USER', $user->getRoles());
        } finally {
            $entityManager->remove($user);
            $entityManager->flush();
        }
    }
}

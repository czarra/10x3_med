<?php

namespace App\Tests\Controller;

use App\Entity\ActivityIntensity;
use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DiaryControllerTest extends WebTestCase
{
    public function testValidMinimalSubmissionPersistsSnapshotAndRedirectsWithFlash(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/dziennik/nowy');

            $form = $crawler->filter('main > form')->form();
            $form->setValues([
                'diary_entry_form[glycemiaMgDl]' => '110',
                'diary_entry_form[measuredAt]' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            ]);
            $client->submit($form);

            $this->assertResponseRedirects('/dziennik/nowy');
            $client->followRedirect();
            $this->assertSelectorTextContains('main', 'Wpis został zapisany.');

            $entityManager->clear();
            $entry = $entityManager->getRepository(DiaryEntry::class)->findOneBy(['user' => $user]);
            $this->assertNotNull($entry);
            $this->assertSame(110, $entry->getGlycemiaMgDl());
            $this->assertSame($profile->getInsulinWwRatio(), $entry->getInsulinWwRatioSnapshot());
            $this->assertSame($profile->getBaseDose(), $entry->getBaseDoseSnapshot());
            $this->assertNull($entry->getWw());
            $this->assertNull($entry->getInsulinDose());
            $this->assertNull($entry->getActivityIntensity());
            $this->assertNull($entry->getActivityDurationMinutes());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testFullSubmissionPersistsAllOptionalFields(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/dziennik/nowy');

            $form = $crawler->filter('main > form')->form();
            $form->setValues([
                'diary_entry_form[glycemiaMgDl]' => '95',
                'diary_entry_form[measuredAt]' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
                'diary_entry_form[ww]' => '4.5',
                'diary_entry_form[insulinDose]' => '6.0',
                'diary_entry_form[activityIntensity]' => ActivityIntensity::Medium->value,
                'diary_entry_form[activityDurationMinutes]' => '30',
            ]);
            $client->submit($form);

            $this->assertResponseRedirects('/dziennik/nowy');

            $entityManager->clear();
            $entry = $entityManager->getRepository(DiaryEntry::class)->findOneBy(['user' => $user]);
            $this->assertNotNull($entry);
            $this->assertSame(95, $entry->getGlycemiaMgDl());
            $this->assertSame(4.5, $entry->getWw());
            $this->assertSame(6.0, $entry->getInsulinDose());
            $this->assertSame(ActivityIntensity::Medium, $entry->getActivityIntensity());
            $this->assertSame(30, $entry->getActivityDurationMinutes());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testInvalidGlucoseReRendersFormWithoutPersisting(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/dziennik/nowy');

            $form = $crawler->filter('main > form')->form();
            $form->setValues([
                'diary_entry_form[glycemiaMgDl]' => '20',
                'diary_entry_form[measuredAt]' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
            ]);
            $client->submit($form);

            $this->assertResponseStatusCodeSame(422);

            $entityManager->clear();
            $entry = $entityManager->getRepository(DiaryEntry::class)->findOneBy(['user' => $user]);
            $this->assertNull($entry);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testProfilelessAuthenticatedUserIsRedirectedToOnboarding(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $client->loginUser($user);
            $client->request('GET', '/dziennik/nowy');

            $this->assertResponseRedirects('/onboarding');
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
        $user->setEmail(sprintf('diary-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createProfile(EntityManagerInterface $entityManager, User $user, int $baseDose, float $insulinWwRatio): PatientProfile
    {
        $profile = new PatientProfile($user, $baseDose, $insulinWwRatio);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $profile;
    }

    private function cleanupUser(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

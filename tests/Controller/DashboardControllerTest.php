<?php

namespace App\Tests\Controller;

use App\Entity\BaseDoseAdjustmentHistory;
use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\RatioAdjustmentHistory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class DashboardControllerTest extends WebTestCase
{
    public function testInsufficientDataShowsNeutralMessages(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 10, 1.0);

        try {
            $client->loginUser($user);
            $client->request('GET', '/pulpit');

            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('main', 'Brak wystarczających danych do zasugerowania zmiany przelicznika');
            $this->assertSelectorTextContains('main', 'Brak wystarczających danych do zasugerowania zmiany dawki bazowej');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testQualifyingRatioScenarioShowsCardWithDisclaimer(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 10, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 95, 170, 3);

            $client->loginUser($user);
            $crawler = $client->request('GET', '/pulpit');

            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('main', 'Ostatnie 3 posiłki poskutkowały zbyt wysoką glikemią po posiłku.');
            $this->assertSelectorTextContains('main', 'konsultacji lekarskiej');
            $this->assertGreaterThan(0, $crawler->selectButton('Zapisz nowy przelicznik w profilu')->count());
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testAcceptRatioUpdatesProfileAndPersistsHistory(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 10, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 95, 170, 3);

            $client->loginUser($user);
            $crawler = $client->request('GET', '/pulpit');
            $form = $crawler->selectButton('Zapisz nowy przelicznik w profilu')->form();
            $client->submit($form);

            $this->assertResponseRedirects('/pulpit');
            $client->followRedirect();
            $this->assertSelectorTextContains('main', 'Przelicznik insulina/WW został zaktualizowany.');

            $entityManager->clear();
            $profile = $entityManager->getRepository(PatientProfile::class)->findOneBy(['user' => $user]);
            $this->assertSame(1.15, $profile->getInsulinWwRatio());

            $history = $entityManager->getRepository(RatioAdjustmentHistory::class)->findOneBy(['user' => $user]);
            $this->assertNotNull($history);
            $this->assertSame(1.0, $history->getOldRatio());
            $this->assertSame(1.15, $history->getNewRatio());
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testAcceptBaseDoseUpdatesProfileAndPersistsHistory(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 10, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 155);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 170);

            $client->loginUser($user);
            $crawler = $client->request('GET', '/pulpit');
            $form = $crawler->selectButton('Zapisz nową dawkę bazową w profilu')->form();
            $client->submit($form);

            $this->assertResponseRedirects('/pulpit');
            $client->followRedirect();
            $this->assertSelectorTextContains('main', 'Dawka bazowa została zaktualizowana.');

            $entityManager->clear();
            $profile = $entityManager->getRepository(PatientProfile::class)->findOneBy(['user' => $user]);
            $this->assertSame(11, $profile->getBaseDose());

            $history = $entityManager->getRepository(BaseDoseAdjustmentHistory::class)->findOneBy(['user' => $user]);
            $this->assertNotNull($history);
            $this->assertSame(10, $history->getOldBaseDose());
            $this->assertSame(11, $history->getNewBaseDose());
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testAcceptWhenSuggestionNoLongerAvailableMakesNoDbChanges(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 10, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 95, 170, 3);

            $client->loginUser($user);
            $crawler = $client->request('GET', '/pulpit');
            $form = $crawler->selectButton('Zapisz nowy przelicznik w profilu')->form();

            // Simulate a race: the suggestion is no longer derivable by the time the accept POST is handled.
            $entityManager->getConnection()->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);

            $client->submit($form);

            $this->assertResponseRedirects('/pulpit');

            $entityManager->clear();
            $profile = $entityManager->getRepository(PatientProfile::class)->findOneBy(['user' => $user]);
            $this->assertSame(1.0, $profile->getInsulinWwRatio());
            $this->assertCount(0, $entityManager->getRepository(RatioAdjustmentHistory::class)->findBy(['user' => $user]));
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testAcceptRatioWithInvalidProfileStateMakesNoDbChanges(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        // baseDose = 40 violates PatientProfile's own Assert\LessThanOrEqual(35).
        // Persisting directly (not through a validated Form) bypasses that check at
        // write time, letting us force the defensive ValidatorInterface::validate()
        // guard in DashboardController::acceptRatio to actually reject and prove it
        // isn't dead code.
        $this->createProfile($entityManager, $user, 40, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 95, 170, 3);

            $client->loginUser($user);
            $crawler = $client->request('GET', '/pulpit');
            $form = $crawler->selectButton('Zapisz nowy przelicznik w profilu')->form();
            $client->submit($form);

            $this->assertResponseRedirects('/pulpit');
            $client->followRedirect();
            $this->assertSelectorTextNotContains('main', 'Przelicznik insulina/WW został zaktualizowany.');

            $entityManager->clear();
            $profile = $entityManager->getRepository(PatientProfile::class)->findOneBy(['user' => $user]);
            $this->assertSame(1.0, $profile->getInsulinWwRatio());
            $this->assertCount(0, $entityManager->getRepository(RatioAdjustmentHistory::class)->findBy(['user' => $user]));
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testProfilelessAuthenticatedUserIsRedirectedToOnboarding(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $client->loginUser($user);
            $client->request('GET', '/pulpit');

            $this->assertResponseRedirects('/onboarding');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testInvalidCsrfTokenOnAcceptPostIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 10, 1.0);

        try {
            $client->loginUser($user);
            $client->request('POST', '/pulpit/przelicznik/akceptuj', ['_csrf_token' => 'invalid-token']);

            $this->assertSame(Response::HTTP_FORBIDDEN, $client->getResponse()->getStatusCode());
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager): User
    {
        $user = new User();
        $user->setEmail(sprintf('dashboard-%s@example.test', uniqid()));
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

    private function createPair(
        EntityManagerInterface $entityManager,
        User $user,
        \DateTimeImmutable $mealMeasuredAt,
        int $beforeGlycemia,
        int $afterGlycemia,
        float $ww,
    ): void {
        $this->createEntry($entityManager, $user, $beforeGlycemia, $mealMeasuredAt, ww: $ww, insulinDose: 4.0);
        $this->createEntry($entityManager, $user, $afterGlycemia, $mealMeasuredAt->modify('+120 minutes'));
    }

    private function createEntry(
        EntityManagerInterface $entityManager,
        User $user,
        int $glycemiaMgDl,
        \DateTimeImmutable $measuredAt,
        ?float $ww = null,
        ?float $insulinDose = null,
    ): DiaryEntry {
        $entry = new DiaryEntry(
            user: $user,
            glycemiaMgDl: $glycemiaMgDl,
            measuredAt: $measuredAt,
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );
        $entry->setWw($ww);
        $entry->setInsulinDose($insulinDose);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function createFastingEntry(EntityManagerInterface $entityManager, User $user, \DateTimeImmutable $day, int $glycemiaMgDl): DiaryEntry
    {
        $entry = new DiaryEntry(
            user: $user,
            glycemiaMgDl: $glycemiaMgDl,
            measuredAt: $day->setTime(7, 0),
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function cleanup(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ratio_adjustment_histories WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM base_dose_adjustment_histories WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

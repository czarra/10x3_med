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

    public function testRiskyActivitySubmissionShowsHypoglycemiaWarningAndDisclaimer(): void
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
                'diary_entry_form[glycemiaMgDl]' => '80',
                'diary_entry_form[measuredAt]' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
                'diary_entry_form[activityIntensity]' => ActivityIntensity::Strong->value,
                'diary_entry_form[activityDurationMinutes]' => '45',
            ]);
            $client->submit($form);

            $this->assertResponseRedirects('/dziennik/nowy');
            $client->followRedirect();
            $this->assertSelectorTextContains('main', 'istnieje ryzyko hipoglikemii');
            $this->assertSelectorTextContains('main', 'Sugestia ma charakter algorytmiczny i nie zastępuje konsultacji lekarskiej.');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testSafeActivitySubmissionShowsNoHypoglycemiaWarning(): void
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
                'diary_entry_form[glycemiaMgDl]' => '180',
                'diary_entry_form[measuredAt]' => (new \DateTimeImmutable())->format('Y-m-d\TH:i'),
                'diary_entry_form[activityIntensity]' => ActivityIntensity::Light->value,
                'diary_entry_form[activityDurationMinutes]' => '15',
            ]);
            $client->submit($form);

            $this->assertResponseRedirects('/dziennik/nowy');
            $client->followRedirect();
            $this->assertStringNotContainsString('istnieje ryzyko hipoglikemii', (string) $client->getResponse()->getContent());
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

    public function testHistoryShowsEmptyStateWhenNoEntries(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $client->loginUser($user);
            $client->request('GET', '/dziennik/historia');

            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('main', 'Brak wpisów w dzienniczku.');
            $this->assertSelectorNotExists('main svg');
            $this->assertSelectorNotExists('main table');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testHistoryShowsChartAndDayGroupsWithFieldFallbacks(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $this->createEntry($entityManager, $user, 65, new \DateTimeImmutable('-1 day'));
            $this->createEntry($entityManager, $user, 200, new \DateTimeImmutable('-2 days'));

            $client->loginUser($user);
            $crawler = $client->request('GET', '/dziennik/historia');

            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('main svg');
            $this->assertSelectorExists('main svg polyline.glycemia-line');
            $this->assertCount(2, $crawler->filter('main table tbody tr'));
            $this->assertSelectorTextContains('main table', '—');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testHistoryPaginatesAcrossMoreThanSevenDayGroups(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $base = new \DateTimeImmutable('-9 days');
            for ($i = 0; $i < 9; ++$i) {
                $this->createEntry($entityManager, $user, 100, $base->modify('+'.$i.' days'));
            }

            $client->loginUser($user);
            $crawler = $client->request('GET', '/dziennik/historia');

            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('main', 'Starsze');
            $this->assertSelectorTextNotContains('main', 'Nowsze');

            $link = $crawler->selectLink('Starsze')->link();
            $client->click($link);

            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('main', 'Nowsze');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testHistoryClampsOutOfRangePageQuery(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $this->createEntry($entityManager, $user, 100, new \DateTimeImmutable('-1 day'));

            $client->loginUser($user);
            $client->request('GET', '/dziennik/historia?page=999');

            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextNotContains('main', 'Brak wpisów w dzienniczku.');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testHistoryProfilelessAuthenticatedUserIsRedirectedToOnboarding(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $client->loginUser($user);
            $client->request('GET', '/dziennik/historia');

            $this->assertResponseRedirects('/onboarding');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    private function createEntry(EntityManagerInterface $entityManager, User $user, int $glycemiaMgDl, \DateTimeImmutable $measuredAt): DiaryEntry
    {
        $entry = new DiaryEntry(
            user: $user,
            glycemiaMgDl: $glycemiaMgDl,
            measuredAt: $measuredAt,
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
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

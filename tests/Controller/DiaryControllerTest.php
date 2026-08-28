<?php

namespace App\Tests\Controller;

use App\Entity\ActivityIntensity;
use App\Entity\DiaryEntry;
use App\Entity\RatioAdjustmentHistory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class DiaryControllerTest extends WebTestCase
{
    use \App\Tests\Support\DiaryFixturesTrait;

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

    public function testHistoryDoesNotExposeAnotherUsersEntries(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $userA = $this->createUser($entityManager);
        $this->createProfile($entityManager, $userA, 15, 1.5);
        $this->createEntry($entityManager, $userA, 111, new \DateTimeImmutable('-1 hour'));

        $userB = $this->createUser($entityManager);
        $this->createProfile($entityManager, $userB, 15, 1.5);
        $this->createEntry($entityManager, $userB, 222, new \DateTimeImmutable('-1 hour'));

        try {
            $client->loginUser($userB);
            $client->request('GET', '/dziennik/historia');

            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('222', (string) $client->getResponse()->getContent());
            $this->assertStringNotContainsString('111', (string) $client->getResponse()->getContent());
        } finally {
            $this->cleanupUser($entityManager, $userA);
            $this->cleanupUser($entityManager, $userB);
        }
    }

    public function testEditHappyPathPrefillsFormAndPersistsChanges(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('-1 hour'));

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', sprintf('/dziennik/%d/edytuj', $entry->getId()));

            $this->assertResponseIsSuccessful();
            $this->assertFormValue('main > form', 'diary_entry_form[glycemiaMgDl]', '110');

            $form = $crawler->filter('main > form')->form();
            $form->setValues([
                'diary_entry_form[glycemiaMgDl]' => '130',
                'diary_entry_form[measuredAt]' => $entry->getMeasuredAt()->format('Y-m-d\TH:i'),
            ]);
            $client->submit($form);

            $this->assertResponseRedirects('/dziennik/historia');
            $client->followRedirect();
            $this->assertSelectorTextContains('main', 'Wpis został zaktualizowany.');

            $entityManager->clear();
            $updated = $entityManager->getRepository(DiaryEntry::class)->find($entry->getId());
            $this->assertNotNull($updated);
            $this->assertSame(130, $updated->getGlycemiaMgDl());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testEditReturns404ForAnotherUsersEntry(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $owner = $this->createUser($entityManager);
        $this->createProfile($entityManager, $owner, 15, 1.5);
        $entry = $this->createEntry($entityManager, $owner, 110, new \DateTimeImmutable('-1 hour'));

        $otherUser = $this->createUser($entityManager);
        $this->createProfile($entityManager, $otherUser, 15, 1.5);

        try {
            $client->loginUser($otherUser);
            $client->request('GET', sprintf('/dziennik/%d/edytuj', $entry->getId()));

            $this->assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanupUser($entityManager, $owner);
            $this->cleanupUser($entityManager, $otherUser);
        }
    }

    public function testEditReturns404ForEntryOutsideEditableWindow(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('-25 hours'), new \DateTimeImmutable('-25 hours'));

        try {
            $client->loginUser($user);
            $client->request('GET', sprintf('/dziennik/%d/edytuj', $entry->getId()));

            $this->assertResponseStatusCodeSame(404);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testEditInvalidGlucoseReRendersFormWithout422AndWithoutPersisting(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('-1 hour'));

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', sprintf('/dziennik/%d/edytuj', $entry->getId()));

            $form = $crawler->filter('main > form')->form();
            $form->setValues([
                'diary_entry_form[glycemiaMgDl]' => '20',
                'diary_entry_form[measuredAt]' => $entry->getMeasuredAt()->format('Y-m-d\TH:i'),
            ]);
            $client->submit($form);

            $this->assertResponseStatusCodeSame(422);

            $entityManager->clear();
            $unchanged = $entityManager->getRepository(DiaryEntry::class)->find($entry->getId());
            $this->assertNotNull($unchanged);
            $this->assertSame(110, $unchanged->getGlycemiaMgDl());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testDeleteHappyPathRemovesEntryAndRedirectsWithFlash(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('-1 hour'));
        $entryId = $entry->getId();

        try {
            $client->loginUser($user);
            $client->request('GET', '/dziennik/historia');
            $client->request('POST', sprintf('/dziennik/%d/usun', $entryId), [
                '_csrf_token' => $this->csrfToken('delete_diary_entry', $client),
            ]);

            $this->assertResponseRedirects('/dziennik/historia');
            $client->followRedirect();
            $this->assertSelectorTextContains('main', 'Wpis został usunięty.');

            $entityManager->clear();
            $this->assertNull($entityManager->getRepository(DiaryEntry::class)->find($entryId));
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testDeleteReturns404ForAnotherUsersEntry(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $owner = $this->createUser($entityManager);
        $this->createProfile($entityManager, $owner, 15, 1.5);
        $entry = $this->createEntry($entityManager, $owner, 110, new \DateTimeImmutable('-1 hour'));

        $otherUser = $this->createUser($entityManager);
        $this->createProfile($entityManager, $otherUser, 15, 1.5);

        try {
            $client->loginUser($otherUser);
            $client->request('GET', '/dziennik/historia');
            $client->request('POST', sprintf('/dziennik/%d/usun', $entry->getId()), [
                '_csrf_token' => $this->csrfToken('delete_diary_entry', $client),
            ]);

            $this->assertResponseStatusCodeSame(404);

            $entityManager->clear();
            $this->assertNotNull($entityManager->getRepository(DiaryEntry::class)->find($entry->getId()));
        } finally {
            $this->cleanupUser($entityManager, $owner);
            $this->cleanupUser($entityManager, $otherUser);
        }
    }

    public function testDeleteReturns404ForEntryOutsideEditableWindow(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('-25 hours'), new \DateTimeImmutable('-25 hours'));

        try {
            $client->loginUser($user);
            $client->request('GET', '/dziennik/historia');
            $client->request('POST', sprintf('/dziennik/%d/usun', $entry->getId()), [
                '_csrf_token' => $this->csrfToken('delete_diary_entry', $client),
            ]);

            $this->assertResponseStatusCodeSame(404);

            $entityManager->clear();
            $this->assertNotNull($entityManager->getRepository(DiaryEntry::class)->find($entry->getId()));
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testDeleteWithInvalidCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('-1 hour'));

        try {
            $client->loginUser($user);
            $client->request('POST', sprintf('/dziennik/%d/usun', $entry->getId()), [
                '_csrf_token' => 'invalid-token',
            ]);

            $this->assertResponseStatusCodeSame(403);

            $entityManager->clear();
            $this->assertNotNull($entityManager->getRepository(DiaryEntry::class)->find($entry->getId()));
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testHistoryShowsControlsForFreshEntryAndHidesForExpiredEntry(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $this->createEntry($entityManager, $user, 111, new \DateTimeImmutable('-1 hour'));
        $this->createEntry($entityManager, $user, 222, new \DateTimeImmutable('-25 hours'), new \DateTimeImmutable('-25 hours'));

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/dziennik/historia');

            $this->assertResponseIsSuccessful();

            $freshRow = $crawler->filter('tr:contains("111")');
            $this->assertSame(1, $freshRow->count());
            $this->assertStringContainsString('Edytuj', $freshRow->text());
            $this->assertStringContainsString('Usuń', $freshRow->text());

            $expiredRow = $crawler->filter('tr:contains("222")');
            $this->assertSame(1, $expiredRow->count());
            $this->assertStringNotContainsString('Edytuj', $expiredRow->text());
            $this->assertStringNotContainsString('Usuń', $expiredRow->text());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testHistoryHidesControlsForEntryConsumedByAcceptedRatioSuggestion(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $entry = $this->createEntry($entityManager, $user, 333, new \DateTimeImmutable('-2 hours'));
        $entityManager->persist(new RatioAdjustmentHistory($user, 1.5, 1.6, new \DateTimeImmutable('-1 hour')));
        $entityManager->flush();

        try {
            $client->loginUser($user);
            $crawler = $client->request('GET', '/dziennik/historia');

            $this->assertResponseIsSuccessful();

            $row = $crawler->filter('tr:contains("333")');
            $this->assertSame(1, $row->count());
            $this->assertStringNotContainsString('Edytuj', $row->text());
            $this->assertStringNotContainsString('Usuń', $row->text());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testExportReturnsCsvForCurrentPageWithHeaderAndDataRows(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $this->createEntry($entityManager, $user, 120, new \DateTimeImmutable('-1 hour'));

            $client->loginUser($user);
            $client->request('GET', '/dziennik/eksport?page=1');

            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
            $this->assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
            $this->assertStringContainsString('dziennik-eksport-strona-1-', (string) $client->getResponse()->headers->get('Content-Disposition'));

            $rows = $this->parseCsvRows($this->streamedContent($client));
            $this->assertSame([
                'Data i godzina',
                'Glikemia (mg/dL)',
                'WW',
                'Insulina (j.)',
                'Intensywność aktywności',
                'Czas aktywności (min)',
            ], $rows[0]);
            $this->assertSame('120', $rows[1][1]);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testExportOnlyIncludesRequestingUsersEntries(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);
        $otherUser = $this->createUser($entityManager);
        $this->createProfile($entityManager, $otherUser, 15, 1.5);

        try {
            $this->createEntry($entityManager, $user, 111, new \DateTimeImmutable('-1 hour'));
            $this->createEntry($entityManager, $otherUser, 999, new \DateTimeImmutable('-1 hour'));

            $client->loginUser($user);
            $client->request('GET', '/dziennik/eksport?page=1');

            $this->assertResponseIsSuccessful();
            $glycemiaValues = array_column(array_slice($this->parseCsvRows($this->streamedContent($client)), 1), 1);
            $this->assertContains('111', $glycemiaValues);
            $this->assertNotContains('999', $glycemiaValues);
        } finally {
            $this->cleanupUser($entityManager, $user);
            $this->cleanupUser($entityManager, $otherUser);
        }
    }

    public function testExportProfilelessAuthenticatedUserIsRedirectedToOnboarding(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);

        try {
            $client->loginUser($user);
            $client->request('GET', '/dziennik/eksport');

            $this->assertResponseRedirects('/onboarding');
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testExportRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dziennik/eksport');

        $this->assertResponseRedirects('/login');
    }

    public function testExportWithNoEntriesReturnsHeaderOnlyCsv(): void
    {
        $client = static::createClient();
        $entityManager = $this->entityManager();
        $user = $this->createUser($entityManager);
        $this->createProfile($entityManager, $user, 15, 1.5);

        try {
            $client->loginUser($user);
            $client->request('GET', '/dziennik/eksport?page=1');

            $this->assertResponseIsSuccessful();
            $rows = $this->parseCsvRows($this->streamedContent($client));
            $this->assertCount(1, $rows);
            $this->assertSame([
                'Data i godzina',
                'Glikemia (mg/dL)',
                'WW',
                'Insulina (j.)',
                'Intensywność aktywności',
                'Czas aktywności (min)',
            ], $rows[0]);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testHistoryShowsExportButtonLinkingToCurrentPage(): void
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
            $crawler = $client->request('GET', '/dziennik/historia?page=2');

            $this->assertResponseIsSuccessful();
            $link = $crawler->selectLink('Eksportuj tę stronę (CSV)')->link();
            $this->assertStringContainsString('/dziennik/eksport?page=2', $link->getUri());
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    private function streamedContent(KernelBrowser $client): string
    {
        return $client->getInternalResponse()->getContent();
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsvRows(string $csv): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $lines = array_filter(explode("\n", $csv), static fn (string $line): bool => '' !== $line);

        return array_values(array_map(static fn (string $line) => str_getcsv($line, ';', '"', ''), $lines));
    }

    private function csrfToken(string $intention, KernelBrowser $client): string
    {
        $requestStack = static::getContainer()->get(RequestStack::class);
        $requestStack->push($client->getRequest());

        try {
            $token = static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken($intention)->getValue();
            $client->getRequest()->getSession()->save();

            return $token;
        } finally {
            $requestStack->pop();
        }
    }
}

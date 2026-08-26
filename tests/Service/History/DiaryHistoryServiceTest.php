<?php

namespace App\Tests\Service\History;

use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Service\History\DiaryHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DiaryHistoryServiceTest extends KernelTestCase
{
    public function testZeroEntriesYieldsEmptyPageWithHasEntriesFalse(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $page = $this->service()->buildPage($user, 1);

            $this->assertFalse($page->hasEntries);
            $this->assertSame(1, $page->totalPages);
            $this->assertSame(1, $page->currentPage);
            $this->assertSame([], $page->dayGroups);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testSingleDayEntriesGroupIntoOneDay(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $day = new \DateTimeImmutable('2026-08-20 08:00:00');
            $this->createEntry($entityManager, $user, 100, $day);
            $this->createEntry($entityManager, $user, 110, $day->modify('+2 hours'));

            $page = $this->service()->buildPage($user, 1);

            $this->assertTrue($page->hasEntries);
            $this->assertCount(1, $page->dayGroups);
            $this->assertCount(2, $page->dayGroups[0]->entries);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testSameDayEntriesOrderNewestFirstWithinGroup(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $day = new \DateTimeImmutable('2026-08-20 08:00:00');
            $this->createEntry($entityManager, $user, 100, $day);
            $this->createEntry($entityManager, $user, 110, $day->modify('+2 hours'));

            $page = $this->service()->buildPage($user, 1);

            $entries = $page->dayGroups[0]->entries;
            $this->assertSame(110, $entries[0]->getGlycemiaMgDl());
            $this->assertSame(100, $entries[1]->getGlycemiaMgDl());
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testDayGroupsOrderNewestDayFirst(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $this->createEntry($entityManager, $user, 100, new \DateTimeImmutable('2026-08-18 08:00:00'));
            $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('2026-08-20 08:00:00'));
            $this->createEntry($entityManager, $user, 120, new \DateTimeImmutable('2026-08-19 08:00:00'));

            $page = $this->service()->buildPage($user, 1);

            $this->assertSame('2026-08-20', $page->dayGroups[0]->date->format('Y-m-d'));
            $this->assertSame('2026-08-19', $page->dayGroups[1]->date->format('Y-m-d'));
            $this->assertSame('2026-08-18', $page->dayGroups[2]->date->format('Y-m-d'));
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testMoreThanSevenDayGroupsPaginateCorrectly(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $base = new \DateTimeImmutable('2026-08-01 08:00:00');
            for ($i = 0; $i < 9; ++$i) {
                $this->createEntry($entityManager, $user, 100, $base->modify('+'.$i.' days'));
            }

            $page1 = $this->service()->buildPage($user, 1);
            $page2 = $this->service()->buildPage($user, 2);

            $this->assertSame(2, $page1->totalPages);
            $this->assertCount(7, $page1->dayGroups);
            $this->assertSame('2026-08-09', $page1->dayGroups[0]->date->format('Y-m-d'));
            $this->assertSame('2026-08-03', $page1->dayGroups[6]->date->format('Y-m-d'));

            $this->assertCount(2, $page2->dayGroups);
            $this->assertSame('2026-08-02', $page2->dayGroups[0]->date->format('Y-m-d'));
            $this->assertSame('2026-08-01', $page2->dayGroups[1]->date->format('Y-m-d'));
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testRequestedPageClampsBelowOne(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $this->createEntry($entityManager, $user, 100, new \DateTimeImmutable('2026-08-20 08:00:00'));

            $page = $this->service()->buildPage($user, 0);

            $this->assertSame(1, $page->currentPage);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testRequestedPageClampsAboveTotalPages(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $this->createEntry($entityManager, $user, 100, new \DateTimeImmutable('2026-08-20 08:00:00'));

            $page = $this->service()->buildPage($user, 999);

            $this->assertSame(1, $page->currentPage);
            $this->assertSame(1, $page->totalPages);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    private function service(): DiaryHistoryService
    {
        return self::getContainer()->get(DiaryHistoryService::class);
    }

    private function boot(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager): User
    {
        $user = new User();
        $user->setEmail(sprintf('diary-history-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
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

    private function cleanup(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

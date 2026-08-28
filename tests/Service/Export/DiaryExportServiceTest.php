<?php

namespace App\Tests\Service\Export;

use App\Entity\ActivityIntensity;
use App\Service\Export\DiaryExportService;
use App\Service\History\DiaryHistoryPage;
use App\Service\History\DiaryHistoryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DiaryExportServiceTest extends KernelTestCase
{
    use \App\Tests\Support\DiaryFixturesTrait;

    public function testHeaderRowIsWrittenWithBomAndPolishSeparator(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager, 'diary-export');

        try {
            $page = $this->historyService()->buildPage($user, 1);

            $content = $this->writeToString($page);

            $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
            $rows = $this->parseRows($content);
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

    public function testDecimalValuesUseCommaSeparator(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager, 'diary-export');

        try {
            $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('2026-08-20 08:00:00'));
            $entry->setWw(4.5);
            $entry->setInsulinDose(6.0);
            $entityManager->flush();

            $page = $this->historyService()->buildPage($user, 1);
            $content = $this->writeToString($page);

            $row = $this->parseRows($content)[1];
            $this->assertSame('4,5', $row[2]);
            $this->assertSame('6', $row[3]);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testMissingWwAndInsulinDoseRenderAsEmptyColumns(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager, 'diary-export');

        try {
            $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('2026-08-20 08:00:00'));

            $page = $this->historyService()->buildPage($user, 1);
            $content = $this->writeToString($page);

            $row = $this->parseRows($content)[1];
            $this->assertSame('110', $row[1]);
            $this->assertSame('', $row[2]);
            $this->assertSame('', $row[3]);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testMissingActivityRendersAsTwoEmptyColumns(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager, 'diary-export');

        try {
            $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('2026-08-20 08:00:00'));
            $entry->setWw(2.0);
            $entry->setInsulinDose(3.0);
            $entityManager->flush();

            $page = $this->historyService()->buildPage($user, 1);
            $content = $this->writeToString($page);

            $row = $this->parseRows($content)[1];
            $this->assertSame('2', $row[2]);
            $this->assertSame('3', $row[3]);
            $this->assertSame('', $row[4]);
            $this->assertSame('', $row[5]);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testActivityIntensityAndDurationAreIncludedWhenPresent(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager, 'diary-export');

        try {
            $entry = $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('2026-08-20 08:00:00'));
            $entry->setActivityIntensity(ActivityIntensity::Medium);
            $entry->setActivityDurationMinutes(30);
            $entityManager->flush();

            $page = $this->historyService()->buildPage($user, 1);
            $content = $this->writeToString($page);

            $row = $this->parseRows($content)[1];
            $this->assertSame('medium', $row[4]);
            $this->assertSame('30', $row[5]);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    public function testRowOrderMatchesDayGroupsOrder(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager, 'diary-export');

        try {
            $this->createEntry($entityManager, $user, 100, new \DateTimeImmutable('2026-08-18 08:00:00'));
            $this->createEntry($entityManager, $user, 110, new \DateTimeImmutable('2026-08-20 08:00:00'));
            $this->createEntry($entityManager, $user, 120, new \DateTimeImmutable('2026-08-19 08:00:00'));

            $page = $this->historyService()->buildPage($user, 1);
            $content = $this->writeToString($page);

            $rows = $this->parseRows($content);
            $this->assertSame(['110', '120', '100'], [$rows[1][1], $rows[2][1], $rows[3][1]]);
        } finally {
            $this->cleanupUser($entityManager, $user);
        }
    }

    private function writeToString(DiaryHistoryPage $page): string
    {
        $handle = fopen('php://memory', 'r+');
        $this->service()->writeCsv($page, $handle);
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return false === $content ? '' : $content;
    }

    /**
     * @return list<list<string>>
     */
    private function parseRows(string $csv): array
    {
        $csv = str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
        $lines = array_filter(explode("\n", $csv), static fn (string $line): bool => '' !== $line);

        return array_values(array_map(static fn (string $line) => str_getcsv($line, ';', '"', ''), $lines));
    }

    private function service(): DiaryExportService
    {
        return self::getContainer()->get(DiaryExportService::class);
    }

    private function historyService(): DiaryHistoryService
    {
        return self::getContainer()->get(DiaryHistoryService::class);
    }

    private function boot(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }
}

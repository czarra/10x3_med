<?php

namespace App\Tests\Service\Chart;

use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Service\Chart\GlucoseHistoryChartService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GlucoseHistoryChartServiceTest extends KernelTestCase
{
    public function testZeroEntriesYieldsBandsButNoPoints(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $chart = $this->service()->buildFor($user, new \DateTimeImmutable());

            $this->assertFalse($chart->hasPoints);
            $this->assertSame('', $chart->polylinePoints);
            $this->assertCount(3, $chart->zoneBands);
            $this->assertCount(7, $chart->xAxisLabels);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testReadingJustBelowHypoBoundaryFallsInHypoBand(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $now = new \DateTimeImmutable();
            $this->createEntry($entityManager, $user, GlucoseHistoryChartService::HYPO_MAX_MGDL, $now->modify('-1 day'));

            $chart = $this->service()->buildFor($user, $now);

            $point = $chart->points[0];
            $hipoBand = $this->bandByClass($chart, 'zone-hipo');

            $this->assertGreaterThanOrEqual($hipoBand->y, $point->y);
            $this->assertLessThanOrEqual($hipoBand->y + $hipoBand->height, $point->y);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testReadingJustAboveHypoBoundaryFallsInNormaBand(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $now = new \DateTimeImmutable();
            $this->createEntry($entityManager, $user, GlucoseHistoryChartService::HYPO_MAX_MGDL + 1, $now->modify('-1 day'));

            $chart = $this->service()->buildFor($user, $now);

            $point = $chart->points[0];
            $normaBand = $this->bandByClass($chart, 'zone-norma');

            $this->assertGreaterThanOrEqual($normaBand->y, $point->y);
            $this->assertLessThanOrEqual($normaBand->y + $normaBand->height, $point->y);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testReadingJustBelowHyperBoundaryFallsInNormaBand(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $now = new \DateTimeImmutable();
            $this->createEntry($entityManager, $user, GlucoseHistoryChartService::HYPER_MIN_MGDL - 1, $now->modify('-1 day'));

            $chart = $this->service()->buildFor($user, $now);

            $point = $chart->points[0];
            $normaBand = $this->bandByClass($chart, 'zone-norma');

            $this->assertGreaterThanOrEqual($normaBand->y, $point->y);
            $this->assertLessThanOrEqual($normaBand->y + $normaBand->height, $point->y);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testReadingAtHyperBoundaryFallsInHyperBand(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $now = new \DateTimeImmutable();
            $this->createEntry($entityManager, $user, GlucoseHistoryChartService::HYPER_MIN_MGDL, $now->modify('-1 day'));

            $chart = $this->service()->buildFor($user, $now);

            $point = $chart->points[0];
            $hiperBand = $this->bandByClass($chart, 'zone-hiper');

            $this->assertGreaterThanOrEqual($hiperBand->y, $point->y);
            $this->assertLessThanOrEqual($hiperBand->y + $hiperBand->height, $point->y);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testReadingOlderThanSevenDaysIsExcludedFromPointsButBandsRemain(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $now = new \DateTimeImmutable();
            $this->createEntry($entityManager, $user, 100, $now->modify('-10 days'));

            $chart = $this->service()->buildFor($user, $now);

            $this->assertFalse($chart->hasPoints);
            $this->assertSame([], $chart->points);
            $this->assertCount(3, $chart->zoneBands);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testOutOfAxisRangeReadingClampsToNearestEdge(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);

        try {
            $now = new \DateTimeImmutable();
            $this->createEntry($entityManager, $user, 2000, $now->modify('-1 day'));

            $chart = $this->service()->buildFor($user, $now);

            $point = $chart->points[0];
            $this->assertGreaterThanOrEqual(0.0, $point->y);
            $this->assertLessThanOrEqual((float) GlucoseHistoryChartService::VIEWBOX_HEIGHT, $point->y);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    private function bandByClass(\App\Service\Chart\GlucoseHistoryChart $chart, string $cssClass): \App\Service\Chart\ChartZoneBand
    {
        foreach ($chart->zoneBands as $band) {
            if ($band->cssClass === $cssClass) {
                return $band;
            }
        }

        throw new \RuntimeException(\sprintf('Band with class "%s" not found.', $cssClass));
    }

    private function service(): GlucoseHistoryChartService
    {
        return self::getContainer()->get(GlucoseHistoryChartService::class);
    }

    private function boot(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager): User
    {
        $user = new User();
        $user->setEmail(sprintf('glucose-chart-%s@example.test', uniqid()));
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

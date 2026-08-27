<?php

namespace App\Tests\Service\Editability;

use App\Entity\BaseDoseAdjustmentHistory;
use App\Entity\DiaryEntry;
use App\Entity\RatioAdjustmentHistory;
use App\Entity\User;
use App\Repository\BaseDoseAdjustmentHistoryRepository;
use App\Repository\RatioAdjustmentHistoryRepository;
use App\Service\Editability\DiaryEntryEditabilityService;
use PHPUnit\Framework\TestCase;

class DiaryEntryEditabilityServiceTest extends TestCase
{
    private const NOW = '2026-01-15 12:00:00';

    public function testEditableWithinRecencyWindowAndNoHistory(): void
    {
        $entry = $this->createEntry(createdAt: $this->now()->modify('-1 hour'));

        $result = $this->service()->isEditable($entry, $this->now());

        $this->assertTrue($result);
    }

    public function testJustUnder24hIsEditable(): void
    {
        $entry = $this->createEntry(createdAt: $this->now()->modify('-23 hours -59 minutes -59 seconds'));

        $result = $this->service()->isEditable($entry, $this->now());

        $this->assertTrue($result);
    }

    public function testExactlyAt24hIsEditable(): void
    {
        $entry = $this->createEntry(createdAt: $this->now()->modify('-24 hours'));

        $result = $this->service()->isEditable($entry, $this->now());

        $this->assertTrue($result);
    }

    public function testJustOver24hIsNotEditable(): void
    {
        $entry = $this->createEntry(createdAt: $this->now()->modify('-24 hours -1 second'));

        $result = $this->service()->isEditable($entry, $this->now());

        $this->assertFalse($result);
    }

    public function testExpiredEntryDoesNotQueryHistoryRepositories(): void
    {
        $entry = $this->createEntry(createdAt: $this->now()->modify('-25 hours'));

        $ratioRepository = $this->createMock(RatioAdjustmentHistoryRepository::class);
        $ratioRepository->expects($this->never())->method('findLatestByUser');
        $baseDoseRepository = $this->createMock(BaseDoseAdjustmentHistoryRepository::class);
        $baseDoseRepository->expects($this->never())->method('findLatestByUser');

        $service = new DiaryEntryEditabilityService($ratioRepository, $baseDoseRepository);

        $this->assertFalse($service->isEditable($entry, $this->now()));
    }

    public function testRatioCutoffAtMeasuredAtLocksEntry(): void
    {
        $measuredAt = $this->now()->modify('-2 hours');
        $entry = $this->createEntry(createdAt: $this->now()->modify('-1 hour'), measuredAt: $measuredAt);

        $result = $this->service(ratioCutoff: $measuredAt)->isEditable($entry, $this->now());

        $this->assertFalse($result);
    }

    public function testRatioCutoffJustBeforeMeasuredAtAllowsEdit(): void
    {
        $measuredAt = $this->now()->modify('-2 hours');
        $entry = $this->createEntry(createdAt: $this->now()->modify('-1 hour'), measuredAt: $measuredAt);

        $result = $this->service(ratioCutoff: $measuredAt->modify('-1 second'))->isEditable($entry, $this->now());

        $this->assertTrue($result);
    }

    public function testRatioCutoffJustAfterMeasuredAtLocksEntry(): void
    {
        $measuredAt = $this->now()->modify('-2 hours');
        $entry = $this->createEntry(createdAt: $this->now()->modify('-1 hour'), measuredAt: $measuredAt);

        $result = $this->service(ratioCutoff: $measuredAt->modify('+1 second'))->isEditable($entry, $this->now());

        $this->assertFalse($result);
    }

    public function testBaseDoseCutoffAtMeasuredAtLocksEntryIndependently(): void
    {
        $measuredAt = $this->now()->modify('-2 hours');
        $entry = $this->createEntry(createdAt: $this->now()->modify('-1 hour'), measuredAt: $measuredAt);

        $result = $this->service(baseDoseCutoff: $measuredAt)->isEditable($entry, $this->now());

        $this->assertFalse($result);
    }

    public function testBaseDoseCutoffJustBeforeMeasuredAtAllowsEdit(): void
    {
        $measuredAt = $this->now()->modify('-2 hours');
        $entry = $this->createEntry(createdAt: $this->now()->modify('-1 hour'), measuredAt: $measuredAt);

        $result = $this->service(baseDoseCutoff: $measuredAt->modify('-1 second'))->isEditable($entry, $this->now());

        $this->assertTrue($result);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private function service(?\DateTimeImmutable $ratioCutoff = null, ?\DateTimeImmutable $baseDoseCutoff = null): DiaryEntryEditabilityService
    {
        $user = new User();

        $ratioRepository = $this->createStub(RatioAdjustmentHistoryRepository::class);
        $ratioRepository->method('findLatestByUser')->willReturn(
            null !== $ratioCutoff ? new RatioAdjustmentHistory($user, 1.0, 1.1, $ratioCutoff) : null,
        );

        $baseDoseRepository = $this->createStub(BaseDoseAdjustmentHistoryRepository::class);
        $baseDoseRepository->method('findLatestByUser')->willReturn(
            null !== $baseDoseCutoff ? new BaseDoseAdjustmentHistory($user, 10, 12, $baseDoseCutoff) : null,
        );

        return new DiaryEntryEditabilityService($ratioRepository, $baseDoseRepository);
    }

    private function createEntry(\DateTimeImmutable $createdAt, ?\DateTimeImmutable $measuredAt = null): DiaryEntry
    {
        $entry = new DiaryEntry(
            user: new User(),
            glycemiaMgDl: 100,
            measuredAt: $measuredAt ?? $createdAt,
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );

        (new \ReflectionProperty(DiaryEntry::class, 'createdAt'))->setValue($entry, $createdAt);

        return $entry;
    }
}

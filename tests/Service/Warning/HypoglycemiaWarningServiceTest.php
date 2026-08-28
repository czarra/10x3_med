<?php

namespace App\Tests\Service\Warning;

use App\Entity\ActivityIntensity;
use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Service\Warning\HypoglycemiaWarningService;
use PHPUnit\Framework\TestCase;

class HypoglycemiaWarningServiceTest extends TestCase
{
    public function testNoActivityIntensityYieldsNoneRegardlessOfGlycemia(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 50, activityIntensity: null, insulinDose: 10.0);

        $result = $this->service()->evaluate($entry);

        $this->assertFalse($result->available);
        $this->assertNull($result->message);
    }

    public function testLightJustBelowThresholdWarns(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 89, activityIntensity: ActivityIntensity::Light);

        $result = $this->service()->evaluate($entry);

        $this->assertTrue($result->available);
        $this->assertNotNull($result->message);
    }

    public function testLightExactlyAtThresholdYieldsNone(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 90, activityIntensity: ActivityIntensity::Light);

        $result = $this->service()->evaluate($entry);

        $this->assertFalse($result->available);
    }

    public function testLightJustAboveThresholdYieldsNone(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 91, activityIntensity: ActivityIntensity::Light);

        $result = $this->service()->evaluate($entry);

        $this->assertFalse($result->available);
    }

    public function testMediumJustBelowThresholdWarns(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 109, activityIntensity: ActivityIntensity::Medium);

        $result = $this->service()->evaluate($entry);

        $this->assertTrue($result->available);
    }

    public function testMediumExactlyAtThresholdYieldsNone(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 110, activityIntensity: ActivityIntensity::Medium);

        $result = $this->service()->evaluate($entry);

        $this->assertFalse($result->available);
    }

    public function testMediumJustAboveThresholdYieldsNone(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 111, activityIntensity: ActivityIntensity::Medium);

        $result = $this->service()->evaluate($entry);

        $this->assertFalse($result->available);
    }

    public function testStrongJustBelowThresholdWarns(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 139, activityIntensity: ActivityIntensity::Strong);

        $result = $this->service()->evaluate($entry);

        $this->assertTrue($result->available);
    }

    public function testStrongExactlyAtThresholdYieldsNone(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 140, activityIntensity: ActivityIntensity::Strong);

        $result = $this->service()->evaluate($entry);

        $this->assertFalse($result->available);
    }

    public function testStrongJustAboveThresholdYieldsNone(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 141, activityIntensity: ActivityIntensity::Strong);

        $result = $this->service()->evaluate($entry);

        $this->assertFalse($result->available);
    }

    public function testInsulinDoseShiftsResultFromNoneToWarn(): void
    {
        // 150 alone is above the Medium threshold (110) -> would be none without insulin.
        $entry = $this->createEntry(glycemiaMgDl: 150, activityIntensity: ActivityIntensity::Medium, insulinDose: 1.5);

        $result = $this->service()->evaluate($entry);

        // 150 - 1.5 * 45 = 82.5 < 110
        $this->assertTrue($result->available);
    }

    public function testStrongWithInsulinDoseWarns(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 200, activityIntensity: ActivityIntensity::Strong, insulinDose: 2.0);

        $result = $this->service()->evaluate($entry);

        // 200 - 2.0 * 45 = 110 < 140
        $this->assertTrue($result->available);
    }

    public function testInsulinAdjustedProjectedGlycemiaExactlyAtThresholdYieldsNone(): void
    {
        $entry = $this->createEntry(glycemiaMgDl: 200, activityIntensity: ActivityIntensity::Medium, insulinDose: 2.0);

        $result = $this->service()->evaluate($entry);

        // 200 - 2.0 * 45 = 110, not < 110
        $this->assertFalse($result->available);
    }

    private function service(): HypoglycemiaWarningService
    {
        return new HypoglycemiaWarningService();
    }

    private function createEntry(int $glycemiaMgDl, ?ActivityIntensity $activityIntensity, ?float $insulinDose = null): DiaryEntry
    {
        $entry = new DiaryEntry(
            user: new User(),
            glycemiaMgDl: $glycemiaMgDl,
            measuredAt: new \DateTimeImmutable(),
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );
        $entry->setInsulinDose($insulinDose);
        $entry->setActivityIntensity($activityIntensity);
        if (null !== $activityIntensity) {
            $entry->setActivityDurationMinutes(30);
        }

        return $entry;
    }
}

<?php

namespace App\Tests\Service\Suggestion;

use App\Entity\BaseDoseAdjustmentHistory;
use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\User;
use App\Service\Suggestion\BaseDoseSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class BaseDoseSuggestionServiceTest extends KernelTestCase
{
    public function testThreeHighFastingDaysSuggestHigherBaseDose(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 155);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 170);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(10, $result->currentBaseDose);
            $this->assertSame(11, $result->suggestedBaseDose);
            $this->assertStringContainsString('zbyt wysoki', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testThreeLowFastingDaysSuggestLowerBaseDose(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 80);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 75);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 85);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(9, $result->suggestedBaseDose);
            $this->assertStringContainsString('zbyt niski', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testLargeExcessClampsStepToTwoUnits(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 250);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 240);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 260);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(12, $result->suggestedBaseDose);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testInBandDayBreaksTheStreak(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 150);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 110);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 160);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testMissingCalendarDayBreaksTheStreak(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 160);
            // day +1 intentionally has no entries at all.
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+3 days'), 160);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, 'A calendar day with no entries at all must break the streak.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testStreakIncludesLastDayEvenWhenItsEntryTimeIsEarlierThanTheFirstDaysEntry(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            // The day-stepping cursor inherits its time-of-day from the first entry (20:00).
            // The last day's entry is at 06:00 — earlier in the day — which must not cause
            // the scan to stop one calendar day early and miss this final qualifying day.
            $base = (new \DateTimeImmutable('-10 days'))->setTime(20, 0);
            $this->createFastingEntryAt($entityManager, $user, $base, 160);
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+1 day')->setTime(6, 0), 155);
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+2 days')->setTime(6, 0), 170);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available, 'The last calendar day must not be dropped when its entry time-of-day is earlier than the cursor\'s inherited time.');
            $this->assertSame(11, $result->suggestedBaseDose);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFewerThanThreeQualifyingDaysYieldsNone(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 170);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testRetriggerCutoffExcludesPreAcceptanceDays(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-20 days');
            $this->createFastingEntry($entityManager, $user, $base, 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 155);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 170);

            $history = new BaseDoseAdjustmentHistory(
                user: $user,
                oldBaseDose: 10,
                newBaseDose: 11,
                acceptedAt: $base->modify('+3 days'),
            );
            $entityManager->persist($history);
            $entityManager->flush();

            $this->createFastingEntry($entityManager, $user, $base->modify('+10 days'), 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+11 days'), 170);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, 'Fasting days before the acceptance cutoff must not count toward a new suggestion.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFastingGapIsVacuouslySatisfiedWhenNoPriorEntryExists(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-3 days');
            $this->createFastingEntry($entityManager, $user, $base, 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 155);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 170);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available, 'The very first entries in a user\'s history have no prior insulin-bearing entry, so the gap condition is vacuously satisfied.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testSuggestedValueClampsToEntityRange(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 34);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 250);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 240);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 260);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(35, $result->suggestedBaseDose);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testEmptyHistoryYieldsNone(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, 'A user with zero diary entries must yield no suggestion.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFastingGapSatisfiedIncludesCandidate(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = (new \DateTimeImmutable('-10 days'))->setTime(23, 0);
            $this->createInsulinBearingEntry($entityManager, $user, $base, 120);

            // 8h after the marker: satisfies FASTING_GAP_HOURS (6h), so this day's entry
            // becomes a fasting candidate.
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+1 day')->setTime(7, 0), 160);
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+2 days')->setTime(7, 0), 155);
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+3 days')->setTime(7, 0), 170);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available, 'A fasting entry >= FASTING_GAP_HOURS after the last insulin-bearing entry must qualify as a candidate.');
            $this->assertSame(11, $result->suggestedBaseDose);
            $this->assertStringContainsString('zbyt wysoki', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFastingGapViolatedExcludesCandidate(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = (new \DateTimeImmutable('-10 days'))->setTime(23, 0);
            $this->createInsulinBearingEntry($entityManager, $user, $base, 120);

            // Only 5h after the marker: violates FASTING_GAP_HOURS (6h), so this day's
            // entry never becomes a candidate at all.
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+1 day')->setTime(4, 0), 160);
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+2 days')->setTime(7, 0), 160);
            $this->createFastingEntryAt($entityManager, $user, $base->modify('+3 days')->setTime(7, 0), 160);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, 'A gap-violating day is never a candidate, leaving only 2 real candidates - fewer than REQUIRED_CONSECUTIVE_DAYS.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFastingAtBandHighBoundaryIsNotHigh(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 145);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 145);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 145);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, '145 equals BAND_HIGH exactly and must not classify as high (strict >).');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFastingJustAboveBandHighIsHigh(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 146);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 146);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 146);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available, '146 is 1 above BAND_HIGH and must classify as high.');
            $this->assertSame(11, $result->suggestedBaseDose);
            $this->assertStringContainsString('zbyt wysoki', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFastingAtBandLowBoundaryIsNotLow(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 95);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 95);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 95);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, '95 equals BAND_LOW exactly and must not classify as low (strict <).');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    /**
     * Verifies implemented behavior, not a PRD-mandated rule: the PRD only states the
     * high-fasting-glycemia example (prd.md:116); the symmetric low-direction branch is
     * an implementation extrapolation, not PRD text.
     */
    public function testFastingJustBelowBandLowIsLow(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createFastingEntry($entityManager, $user, $base, 94);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 94);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 94);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available, '94 is 1 below BAND_LOW and must classify as low.');
            $this->assertSame(9, $result->suggestedBaseDose);
            $this->assertStringContainsString('zbyt niski', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testDirectionFlipBreaksStreak(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            // A high day immediately followed by a 3-day low streak: the direction flip
            // (not an in-band gap) must reset the run rather than merging counts.
            $this->createFastingEntry($entityManager, $user, $base, 160);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 80);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 75);
            $this->createFastingEntry($entityManager, $user, $base->modify('+3 days'), 85);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(9, $result->suggestedBaseDose);
            $this->assertStringContainsString('zbyt niski', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testStreakUsesMostRecentThreeDaysNotFirstThree(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            // 4 consecutive high days. First-3 window (300,150,150) avg excess 80 -> step
            // +2 -> dose 12. Most-recent-3 window (150,150,150) avg excess 30 -> step +1
            // -> dose 11. Only the most-recent-3 result must be produced.
            $this->createFastingEntry($entityManager, $user, $base, 300);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 150);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 150);
            $this->createFastingEntry($entityManager, $user, $base->modify('+3 days'), 150);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(11, $result->suggestedBaseDose, 'Must use the most recent 3 days (150,150,150), not the first 3 (300,150,150).');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testSuggestedValueClampsToLowerBound(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 2);

        try {
            $base = new \DateTimeImmutable('-10 days');
            // Glycemia at the entity's floor (21, Assert\Range(min: 21)) on all 3 days:
            // avg excess -99 -> stepRaw -1.98 -> step -2. baseDose 2 + (-2) = 0, clamped to 1.
            $this->createFastingEntry($entityManager, $user, $base, 21);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 21);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 21);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(1, $result->suggestedBaseDose);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testSameCalendarDayMultipleEntriesUsesFirstOnly(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 10);

        try {
            $base = new \DateTimeImmutable('-10 days');
            // Same calendar day, two entries: an earlier qualifying high reading (07:00)
            // and a later in-band reading (20:00) that would break the streak if used
            // instead. Only the earlier entry may become the day's candidate.
            $this->createFastingEntryAt($entityManager, $user, $base->setTime(7, 0), 160);
            $this->createFastingEntryAt($entityManager, $user, $base->setTime(20, 0), 110);
            $this->createFastingEntry($entityManager, $user, $base->modify('+1 day'), 155);
            $this->createFastingEntry($entityManager, $user, $base->modify('+2 days'), 170);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available, 'The first entry per calendar date must be used as the candidate, not a later same-day entry.');
            $this->assertSame(11, $result->suggestedBaseDose);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    private function createInsulinBearingEntry(EntityManagerInterface $entityManager, User $user, \DateTimeImmutable $measuredAt, int $glycemiaMgDl): DiaryEntry
    {
        $entry = new DiaryEntry(
            user: $user,
            glycemiaMgDl: $glycemiaMgDl,
            measuredAt: $measuredAt,
            insulinWwRatioSnapshot: 1.0,
            baseDoseSnapshot: 10,
        );
        $entry->setInsulinDose(5.0);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function service(): BaseDoseSuggestionService
    {
        return self::getContainer()->get(BaseDoseSuggestionService::class);
    }

    private function boot(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager): User
    {
        $user = new User();
        $user->setEmail(sprintf('base-dose-suggestion-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createProfile(EntityManagerInterface $entityManager, User $user, int $baseDose): PatientProfile
    {
        $profile = new PatientProfile($user, $baseDose, 1.0);
        $entityManager->persist($profile);
        $entityManager->flush();

        return $profile;
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

    private function createFastingEntryAt(EntityManagerInterface $entityManager, User $user, \DateTimeImmutable $measuredAt, int $glycemiaMgDl): DiaryEntry
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
        $connection->executeStatement('DELETE FROM base_dose_adjustment_histories WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

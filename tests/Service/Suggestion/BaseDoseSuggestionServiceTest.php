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

    private function cleanup(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM base_dose_adjustment_histories WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

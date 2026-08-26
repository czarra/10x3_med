<?php

namespace App\Tests\Service\Suggestion;

use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\RatioAdjustmentHistory;
use App\Entity\User;
use App\Service\Suggestion\InsulinWwRatioSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class InsulinWwRatioSuggestionServiceTest extends KernelTestCase
{
    public function testRisingDeltasSuggestHigherRatio(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 95, 170, 3);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(1.0, $result->currentRatio);
            $this->assertSame(1.15, $result->suggestedRatio);
            $this->assertStringContainsString('zbyt wysoką glikemią', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testRisingDeltasClampToMaxStep(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 2);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 100, 180, 2);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 100, 180, 2);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(1.3, $result->suggestedRatio);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testMixedDirectionDeltasYieldNone(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 160, 4); // +60 rise
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 150, 100, 4); // -50 fall
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 100, 130, 4); // +30 below threshold

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFallingDeltasClampToMinStep(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 150, 90, 5);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 140, 85, 6);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 160, 100, 4);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(0.95, $result->suggestedRatio);
            $this->assertStringContainsString('zbyt niską glikemią', (string) $result->context);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testFewerThanThreePairsYieldsNone(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testUnmatchedMealIsExcludedFromPairing(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);

            // Isolated meal with no reading anywhere near +120min — must not count as a third pair.
            $this->createEntry($entityManager, $user, 90, $base->modify('+5 days'), ww: 4.0, insulinDose: 4.0);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, 'Unmatched meal must not be counted as a complete pair.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testZeroWwMealIsExcludedFromPairing(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);

            // A ww=0 meal (e.g. protein-only/correction-only) would otherwise complete
            // a third pair; it must not be counted, or division by zero corrupts the result.
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 95, 170, 0.0);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, 'A ww=0 meal must not be counted as a complete pair.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testRetriggerCutoffExcludesPreAcceptancePairs(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 1.0);

        try {
            $base = new \DateTimeImmutable('-20 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 110, 185, 5);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 95, 170, 3);

            $history = new RatioAdjustmentHistory(
                user: $user,
                oldRatio: 1.0,
                newRatio: 1.15,
                acceptedAt: $base->modify('+3 days'),
            );
            $entityManager->persist($history);
            $entityManager->flush();

            $this->createPair($entityManager, $user, $base->modify('+10 days'), 100, 180, 4);
            $this->createPair($entityManager, $user, $base->modify('+11 days'), 110, 185, 5);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertFalse($result->available, 'Pairs before the acceptance cutoff must not count toward a new suggestion.');
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    public function testSuggestedValueClampsToEntityRange(): void
    {
        $entityManager = $this->boot();
        $user = $this->createUser($entityManager);
        $profile = $this->createProfile($entityManager, $user, 9.9);

        try {
            $base = new \DateTimeImmutable('-10 days');
            $this->createPair($entityManager, $user, $base, 100, 180, 2);
            $this->createPair($entityManager, $user, $base->modify('+1 day'), 100, 180, 2);
            $this->createPair($entityManager, $user, $base->modify('+2 days'), 100, 180, 2);

            $result = $this->service()->suggestFor($user, $profile);

            $this->assertTrue($result->available);
            $this->assertSame(10.0, $result->suggestedRatio);
        } finally {
            $this->cleanup($entityManager, $user);
        }
    }

    private function service(): InsulinWwRatioSuggestionService
    {
        return self::getContainer()->get(InsulinWwRatioSuggestionService::class);
    }

    private function boot(): EntityManagerInterface
    {
        self::bootKernel();

        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function createUser(EntityManagerInterface $entityManager): User
    {
        $user = new User();
        $user->setEmail(sprintf('ratio-suggestion-%s@example.test', uniqid()));
        $user->setPassword('irrelevant-hash');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    private function createProfile(EntityManagerInterface $entityManager, User $user, float $insulinWwRatio): PatientProfile
    {
        $profile = new PatientProfile($user, 10, $insulinWwRatio);
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

    private function cleanup(EntityManagerInterface $entityManager, User $user): void
    {
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM ratio_adjustment_histories WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM diary_entries WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM patient_profiles WHERE user_id = ?', [$user->getId()]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [$user->getId()]);
    }
}

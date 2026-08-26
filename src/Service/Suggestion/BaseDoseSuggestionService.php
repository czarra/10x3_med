<?php

namespace App\Service\Suggestion;

use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\User;
use App\Repository\BaseDoseAdjustmentHistoryRepository;
use App\Repository\DiaryEntryRepository;

final class BaseDoseSuggestionService
{
    public const FASTING_GAP_HOURS = 6;
    public const TARGET_GLYCEMIA = 120;
    public const BAND_LOW = 95;
    public const BAND_HIGH = 145;
    public const STEP_CLAMP_MIN = -2.0;
    public const STEP_CLAMP_MAX = 2.0;
    public const MIN_MAGNITUDE = 0.5;
    public const REQUIRED_CONSECUTIVE_DAYS = 3;

    public function __construct(
        private readonly DiaryEntryRepository $diaryEntryRepository,
        private readonly BaseDoseAdjustmentHistoryRepository $baseDoseAdjustmentHistoryRepository,
    ) {
    }

    public function suggestFor(User $user, PatientProfile $profile): BaseDoseSuggestionResult
    {
        $cutoffDate = $this->baseDoseAdjustmentHistoryRepository->findLatestByUser($user)?->getAcceptedAt();

        // Unfiltered, full history: fasting-day classification depends on the whole
        // timeline, independent of the re-trigger cutoff (see plan's Critical Implementation Details).
        $entries = $this->diaryEntryRepository->findByUserOrderedByMeasuredAt($user);
        if ([] === $entries) {
            return BaseDoseSuggestionResult::none();
        }

        $fastingCandidates = $this->buildFastingCandidates($entries);

        $cutoffDateStr = $cutoffDate?->format('Y-m-d');
        $minDate = $entries[0]->getMeasuredAt();
        $maxDate = $entries[\count($entries) - 1]->getMeasuredAt();

        $startDate = $minDate;
        if (null !== $cutoffDateStr) {
            $dayAfterCutoff = $cutoffDate->modify('+1 day');
            if ($dayAfterCutoff > $startDate) {
                $startDate = $dayAfterCutoff;
            }
        }

        $run = $this->findMostRecentRun($fastingCandidates, $startDate, $maxDate);
        if (null === $run) {
            return BaseDoseSuggestionResult::none();
        }

        $nadwyzkas = array_map(
            static fn (array $day): int => $day['glycemia'] - self::TARGET_GLYCEMIA,
            $run,
        );
        $avg = array_sum($nadwyzkas) / \count($nadwyzkas);
        $krokRaw = $avg * SuggestionScaling::FACTOR;
        $krokRaw = min(max($krokRaw, self::STEP_CLAMP_MIN), self::STEP_CLAMP_MAX);

        if (abs($krokRaw) <= self::MIN_MAGNITUDE) {
            return BaseDoseSuggestionResult::none();
        }

        $krok = (int) round($krokRaw);
        $currentBaseDose = $profile->getBaseDose();
        $newBaseDose = $currentBaseDose + $krok;
        $newBaseDose = min(max($newBaseDose, 1), 35);

        $context = 'high' === $run[0]['direction']
            ? 'Poziom cukru na czczo przez ostatnie 3 dni był zbyt wysoki.'
            : 'Poziom cukru na czczo przez ostatnie 3 dni był zbyt niski.';

        return BaseDoseSuggestionResult::suggest($currentBaseDose, $newBaseDose, $context);
    }

    /**
     * @param DiaryEntry[] $entries
     *
     * @return array<string, int> calendar date (Y-m-d) => fasting glycemia, for qualifying dates only
     */
    private function buildFastingCandidates(array $entries): array
    {
        $result = [];
        $seenDates = [];
        $lastInsulinBearingAt = null;

        foreach ($entries as $entry) {
            $dateStr = $entry->getMeasuredAt()->format('Y-m-d');

            if (!isset($seenDates[$dateStr])) {
                $seenDates[$dateStr] = true;

                // No prior insulin/WW-bearing entry at all: the gap condition is
                // vacuously satisfied (nothing to violate), not a silent default.
                $qualifies = null === $lastInsulinBearingAt
                    || ($entry->getMeasuredAt()->getTimestamp() - $lastInsulinBearingAt->getTimestamp()) >= self::FASTING_GAP_HOURS * 3600;

                if ($qualifies) {
                    $result[$dateStr] = $entry->getGlycemiaMgDl();
                }
            }

            if (null !== $entry->getWw() || null !== $entry->getInsulinDose()) {
                $lastInsulinBearingAt = $entry->getMeasuredAt();
            }
        }

        return $result;
    }

    /**
     * @param array<string, int> $fastingCandidates
     *
     * @return array<array{date: string, glycemia: int, direction: string}>|null
     */
    private function findMostRecentRun(array $fastingCandidates, \DateTimeImmutable $startDate, \DateTimeImmutable $maxDate): ?array
    {
        $bestRun = null;
        $currentRun = [];

        $cursor = $startDate;
        while ($cursor <= $maxDate) {
            $dateStr = $cursor->format('Y-m-d');
            $glycemia = $fastingCandidates[$dateStr] ?? null;

            $direction = null;
            if (null !== $glycemia) {
                if ($glycemia > self::BAND_HIGH) {
                    $direction = 'high';
                } elseif ($glycemia < self::BAND_LOW) {
                    $direction = 'low';
                }
            }

            if (null === $direction) {
                $currentRun = [];
            } else {
                if ([] !== $currentRun && $currentRun[\count($currentRun) - 1]['direction'] !== $direction) {
                    $currentRun = [];
                }

                $currentRun[] = ['date' => $dateStr, 'glycemia' => $glycemia, 'direction' => $direction];

                if (\count($currentRun) >= self::REQUIRED_CONSECUTIVE_DAYS) {
                    $bestRun = \array_slice($currentRun, -self::REQUIRED_CONSECUTIVE_DAYS);
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        return $bestRun;
    }
}

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

        $excesses = array_map(
            static fn (array $day): int => $day['glycemia'] - self::TARGET_GLYCEMIA,
            $run,
        );
        $avg = array_sum($excesses) / \count($excesses);
        $stepRaw = $avg * SuggestionScaling::FACTOR;
        // STEP_CLAMP_MIN (-2.0) is unreachable via clinically-valid input: DiaryEntry's
        // Assert\Range(min: 21) on glycemiaMgDl caps the most negative 3-day average
        // excess at -99 (all days at the floor), giving stepRaw = -1.98, which never
        // crosses -2.0 — and round(-1.98) already equals the clamped result, so no test
        // could distinguish clamped from unclamped behavior without a glycemia value no
        // patient could submit through the real form. Not covered by a test; re-check if
        // BAND_LOW/TARGET_GLYCEMIA/FACTOR/STEP_CLAMP_MIN or the entity's Range change.
        $stepRaw = min(max($stepRaw, self::STEP_CLAMP_MIN), self::STEP_CLAMP_MAX);

        // MIN_MAGNITUDE (0.5) is unreachable via the public API under today's constants:
        // the minimum qualifying per-day excess is 26 mg/dL (BAND_HIGH/LOW = TARGET_GLYCEMIA
        // +/- 25, strict >/< classification), giving stepRaw = 26 * 0.02 = 0.52, which already
        // exceeds MIN_MAGNITUDE. Not covered by a test; re-check if BAND_HIGH/BAND_LOW/FACTOR/
        // MIN_MAGNITUDE change.
        if (abs($stepRaw) <= self::MIN_MAGNITUDE) {
            return BaseDoseSuggestionResult::none();
        }

        $step = (int) round($stepRaw);
        $currentBaseDose = $profile->getBaseDose();
        $newBaseDose = $currentBaseDose + $step;
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

        // Normalize to midnight: the loop only ever compares calendar dates
        // (via ->format('Y-m-d')), but $startDate/$maxDate carry whatever
        // time-of-day their source entry/acceptance happened to have. Comparing
        // full timestamps with mismatched times can end the loop a day early.
        $cursor = $startDate->setTime(0, 0);
        $maxDate = $maxDate->setTime(0, 0);
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

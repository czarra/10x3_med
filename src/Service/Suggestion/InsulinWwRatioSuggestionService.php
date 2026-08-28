<?php

namespace App\Service\Suggestion;

use App\Entity\DiaryEntry;
use App\Entity\PatientProfile;
use App\Entity\User;
use App\Repository\DiaryEntryRepository;
use App\Repository\RatioAdjustmentHistoryRepository;

final class InsulinWwRatioSuggestionService
{
    public const REQUIRED_PAIRS = 3;
    public const AFTER_MEAL_TARGET_MINUTES = 120;
    public const AFTER_MEAL_TOLERANCE_MINUTES = 30;
    public const RATIO_THRESHOLD_MGDL = 45;
    public const RATIO_MIN_STEP = 0.05;
    public const RATIO_MAX_STEP = 0.3;
    public const RATIO_STEP_ROUNDING = 0.05;

    public function __construct(
        private readonly DiaryEntryRepository $diaryEntryRepository,
        private readonly RatioAdjustmentHistoryRepository $ratioAdjustmentHistoryRepository,
    ) {
    }

    public function suggestFor(User $user, PatientProfile $profile): RatioSuggestionResult
    {
        $cutoff = $this->ratioAdjustmentHistoryRepository->findLatestByUser($user)?->getAcceptedAt();
        $entries = $this->diaryEntryRepository->findByUserOrderedByMeasuredAt($user, $cutoff);

        $pairs = $this->buildMealPairs($entries);
        if (\count($pairs) < self::REQUIRED_PAIRS) {
            return RatioSuggestionResult::none();
        }

        $lastPairs = \array_slice($pairs, -self::REQUIRED_PAIRS);

        $deltas = array_map(
            static fn (array $pair): int => $pair['after']->getGlycemiaMgDl() - $pair['before']->getGlycemiaMgDl(),
            $lastPairs,
        );

        $risingPairs = [];
        $fallingPairs = [];
        foreach ($lastPairs as $i => $pair) {
            if ($deltas[$i] > self::RATIO_THRESHOLD_MGDL) {
                $risingPairs[] = ['pair' => $pair, 'delta' => $deltas[$i]];
            } elseif ($deltas[$i] < -self::RATIO_THRESHOLD_MGDL) {
                $fallingPairs[] = ['pair' => $pair, 'delta' => $deltas[$i]];
            }
        }

        if (\count($risingPairs) >= 2) {
            $direction = 'rise';
            $qualifying = $risingPairs;
        } elseif (\count($fallingPairs) >= 2) {
            $direction = 'fall';
            $qualifying = $fallingPairs;
        } else {
            return RatioSuggestionResult::none();
        }

        $ratios = array_map(
            static function (array $entry): float {
                $ww = $entry['pair']['before']->getWw();
                $excess = abs($entry['delta']) - self::RATIO_THRESHOLD_MGDL;

                return $excess / $ww;
            },
            $qualifying,
        );

        $avg = array_sum($ratios) / \count($ratios);
        $stepRaw = $avg * SuggestionScaling::FACTOR;
        $stepClamped = min(max($stepRaw, self::RATIO_MIN_STEP), self::RATIO_MAX_STEP);
        $step = round(round($stepClamped / self::RATIO_STEP_ROUNDING) * self::RATIO_STEP_ROUNDING, 2);

        $currentRatio = $profile->getInsulinWwRatio();
        $newRatio = 'rise' === $direction ? $currentRatio + $step : $currentRatio - $step;
        $newRatio = min(max($newRatio, 0.1), 10.0);

        $context = 'rise' === $direction
            ? 'Ostatnie 3 posiłki poskutkowały zbyt wysoką glikemią po posiłku.'
            : 'Ostatnie 3 posiłki poskutkowały zbyt niską glikemią po posiłku.';

        return RatioSuggestionResult::suggest($currentRatio, $newRatio, $context);
    }

    /**
     * @param DiaryEntry[] $entries
     *
     * @return array<array{before: DiaryEntry, after: DiaryEntry}>
     */
    private function buildMealPairs(array $entries): array
    {
        $pairs = [];
        foreach ($entries as $meal) {
            if (null === $meal->getWw() || $meal->getWw() <= 0.0 || null === $meal->getInsulinDose()) {
                continue;
            }

            $target = $meal->getMeasuredAt()->modify('+'.self::AFTER_MEAL_TARGET_MINUTES.' minutes');
            $windowStart = $target->modify('-'.self::AFTER_MEAL_TOLERANCE_MINUTES.' minutes');
            $windowEnd = $target->modify('+'.self::AFTER_MEAL_TOLERANCE_MINUTES.' minutes');

            $best = null;
            $bestDiff = null;
            foreach ($entries as $candidate) {
                if ($candidate->getMeasuredAt() < $windowStart || $candidate->getMeasuredAt() > $windowEnd) {
                    continue;
                }

                $diff = abs($candidate->getMeasuredAt()->getTimestamp() - $target->getTimestamp());

                if (null === $bestDiff
                    || $diff < $bestDiff
                    || ($diff === $bestDiff && $candidate->getMeasuredAt() < $best->getMeasuredAt())
                ) {
                    $best = $candidate;
                    $bestDiff = $diff;
                }
            }

            if (null !== $best) {
                $pairs[] = ['before' => $meal, 'after' => $best];
            }
        }

        return $pairs;
    }
}

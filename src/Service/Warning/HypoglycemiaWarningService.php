<?php

namespace App\Service\Warning;

use App\Entity\ActivityIntensity;
use App\Entity\DiaryEntry;

final class HypoglycemiaWarningService
{
    public const THRESHOLD_LIGHT_MGDL = 90;
    public const THRESHOLD_MEDIUM_MGDL = 110;
    public const THRESHOLD_STRONG_MGDL = 140;
    public const INSULIN_DROP_PER_UNIT_MGDL = 45;

    private const BASE_MESSAGE = 'Uwaga: istnieje ryzyko hipoglikemii po tym wysiłku fizycznym. Zalecana jest wzmożona kontrola poziomu cukru oraz rozważenie dodatkowych WW.';

    public function evaluate(DiaryEntry $entry): HypoglycemiaWarningResult
    {
        $intensity = $entry->getActivityIntensity();
        if (null === $intensity) {
            return HypoglycemiaWarningResult::none();
        }

        $threshold = match ($intensity) {
            ActivityIntensity::Light => self::THRESHOLD_LIGHT_MGDL,
            ActivityIntensity::Medium => self::THRESHOLD_MEDIUM_MGDL,
            ActivityIntensity::Strong => self::THRESHOLD_STRONG_MGDL,
        };

        $projectedGlycemia = $entry->getGlycemiaMgDl() - ($entry->getInsulinDose() ?? 0.0) * self::INSULIN_DROP_PER_UNIT_MGDL;

        if ($projectedGlycemia < $threshold) {
            return HypoglycemiaWarningResult::warn(self::BASE_MESSAGE);
        }

        return HypoglycemiaWarningResult::none();
    }
}

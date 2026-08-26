<?php

namespace App\Service\Chart;

final class ChartZoneBand
{
    public function __construct(
        public readonly float $y,
        public readonly float $height,
        public readonly string $cssClass,
        public readonly string $label,
    ) {
    }
}

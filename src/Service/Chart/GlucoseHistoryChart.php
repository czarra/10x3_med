<?php

namespace App\Service\Chart;

final class GlucoseHistoryChart
{
    /**
     * @param ChartZoneBand[]                       $zoneBands
     * @param ChartPoint[]                          $points
     * @param array<array{x: float, label: string}> $xAxisLabels
     */
    public function __construct(
        public readonly int $viewBoxWidth,
        public readonly int $viewBoxHeight,
        public readonly array $zoneBands,
        public readonly array $points,
        public readonly string $polylinePoints,
        public readonly bool $hasPoints,
        public readonly array $xAxisLabels,
    ) {
    }
}

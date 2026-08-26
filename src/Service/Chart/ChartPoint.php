<?php

namespace App\Service\Chart;

final class ChartPoint
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly int $glycemiaMgDl,
        public readonly \DateTimeImmutable $measuredAt,
    ) {
    }
}

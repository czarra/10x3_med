<?php

namespace App\Service\Chart;

use App\Entity\User;
use App\Repository\DiaryEntryRepository;

final class GlucoseHistoryChartService
{
    public const HYPO_MAX_MGDL = 69;
    public const HYPER_MIN_MGDL = 181;
    public const VIEWBOX_WIDTH = 700;
    public const VIEWBOX_HEIGHT = 300;
    public const Y_AXIS_MIN_MGDL = 40;
    public const Y_AXIS_MAX_MGDL = 300;
    private const WINDOW_DAYS = 7;

    public function __construct(
        private readonly DiaryEntryRepository $diaryEntryRepository,
    ) {
    }

    public function buildFor(User $user, \DateTimeImmutable $now): GlucoseHistoryChart
    {
        $windowStart = $now->modify('-'.self::WINDOW_DAYS.' days');
        $entries = $this->diaryEntryRepository->findByUserOrderedByMeasuredAt($user, $windowStart);

        $points = [];
        foreach ($entries as $entry) {
            $points[] = new ChartPoint(
                x: $this->mapToX($entry->getMeasuredAt(), $windowStart, $now),
                y: $this->mapToY($entry->getGlycemiaMgDl()),
                glycemiaMgDl: $entry->getGlycemiaMgDl(),
                measuredAt: $entry->getMeasuredAt(),
            );
        }

        $polylinePoints = implode(' ', array_map(
            static fn (ChartPoint $point): string => \sprintf('%s,%s', $point->x, $point->y),
            $points,
        ));

        $xAxisLabels = [];
        for ($i = 0; $i < self::WINDOW_DAYS; ++$i) {
            $day = $windowStart->modify('+'.$i.' days');
            $xAxisLabels[] = [
                'x' => $this->mapToX($day, $windowStart, $now),
                'label' => $day->format('d.m'),
            ];
        }

        return new GlucoseHistoryChart(
            viewBoxWidth: self::VIEWBOX_WIDTH,
            viewBoxHeight: self::VIEWBOX_HEIGHT,
            zoneBands: $this->buildZoneBands(),
            points: $points,
            polylinePoints: $polylinePoints,
            hasPoints: [] !== $points,
            xAxisLabels: $xAxisLabels,
        );
    }

    /**
     * @return ChartZoneBand[]
     */
    private function buildZoneBands(): array
    {
        $hipoTop = $this->mapToY(self::HYPO_MAX_MGDL);
        $hipoBottom = $this->mapToY(self::Y_AXIS_MIN_MGDL);
        $normaTop = $this->mapToY(self::HYPER_MIN_MGDL - 1);
        $normaBottom = $hipoTop;
        $hiperTop = $this->mapToY(self::Y_AXIS_MAX_MGDL);
        $hiperBottom = $normaTop;

        return [
            new ChartZoneBand(
                y: $hiperTop,
                height: $hiperBottom - $hiperTop,
                cssClass: 'zone-hiper',
                label: 'Hiperglikemia',
            ),
            new ChartZoneBand(
                y: $normaTop,
                height: $normaBottom - $normaTop,
                cssClass: 'zone-norma',
                label: 'Norma',
            ),
            new ChartZoneBand(
                y: $hipoTop,
                height: $hipoBottom - $hipoTop,
                cssClass: 'zone-hipo',
                label: 'Hipoglikemia',
            ),
        ];
    }

    private function mapToX(\DateTimeImmutable $measuredAt, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): float
    {
        $totalSeconds = $windowEnd->getTimestamp() - $windowStart->getTimestamp();
        if ($totalSeconds <= 0) {
            return 0.0;
        }

        $elapsedSeconds = $measuredAt->getTimestamp() - $windowStart->getTimestamp();
        $ratio = min(max($elapsedSeconds / $totalSeconds, 0.0), 1.0);

        return round($ratio * self::VIEWBOX_WIDTH, 2);
    }

    private function mapToY(int $glycemiaMgDl): float
    {
        $clamped = min(max($glycemiaMgDl, self::Y_AXIS_MIN_MGDL), self::Y_AXIS_MAX_MGDL);
        $ratio = ($clamped - self::Y_AXIS_MIN_MGDL) / (self::Y_AXIS_MAX_MGDL - self::Y_AXIS_MIN_MGDL);

        // Invert: higher glycemia must map to a smaller y (SVG y=0 is the top).
        return round((1 - $ratio) * self::VIEWBOX_HEIGHT, 2);
    }
}

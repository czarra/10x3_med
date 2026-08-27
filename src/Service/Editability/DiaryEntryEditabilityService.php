<?php

namespace App\Service\Editability;

use App\Entity\DiaryEntry;
use App\Repository\BaseDoseAdjustmentHistoryRepository;
use App\Repository\RatioAdjustmentHistoryRepository;

final class DiaryEntryEditabilityService
{
    private const RECENCY_WINDOW_HOURS = 24;

    public function __construct(
        private readonly RatioAdjustmentHistoryRepository $ratioAdjustmentHistoryRepository,
        private readonly BaseDoseAdjustmentHistoryRepository $baseDoseAdjustmentHistoryRepository,
    ) {
    }

    public function isEditable(DiaryEntry $entry, \DateTimeImmutable $now): bool
    {
        $recencyLimit = $entry->getCreatedAt()->modify('+'.self::RECENCY_WINDOW_HOURS.' hours');
        if ($now > $recencyLimit) {
            return false;
        }

        $user = $entry->getUser();
        $measuredAt = $entry->getMeasuredAt();

        $ratioCutoff = $this->ratioAdjustmentHistoryRepository->findLatestByUser($user)?->getAcceptedAt();
        if (null !== $ratioCutoff && $measuredAt <= $ratioCutoff) {
            return false;
        }

        $baseDoseCutoff = $this->baseDoseAdjustmentHistoryRepository->findLatestByUser($user)?->getAcceptedAt();
        if (null !== $baseDoseCutoff && $measuredAt <= $baseDoseCutoff) {
            return false;
        }

        return true;
    }
}

<?php

namespace App\Service\History;

final class DiaryHistoryPage
{
    /**
     * @param DiaryDayGroup[] $dayGroups
     */
    public function __construct(
        public readonly array $dayGroups,
        public readonly int $currentPage,
        public readonly int $totalPages,
        public readonly bool $hasEntries,
    ) {
    }
}

<?php

namespace App\Service\History;

use App\Entity\User;
use App\Repository\DiaryEntryRepository;

final class DiaryHistoryService
{
    public const DAYS_PER_PAGE = 7;

    public function __construct(
        private readonly DiaryEntryRepository $diaryEntryRepository,
    ) {
    }

    public function buildPage(User $user, int $requestedPage): DiaryHistoryPage
    {
        $entries = $this->diaryEntryRepository->findByUserOrderedByMeasuredAtDesc($user);
        $hasEntries = [] !== $entries;

        $groupedByDay = [];
        foreach ($entries as $entry) {
            $dayKey = $entry->getMeasuredAt()->format('Y-m-d');
            $groupedByDay[$dayKey][] = $entry;
        }

        $dayGroups = [];
        foreach ($groupedByDay as $dayKey => $dayEntries) {
            $dayGroups[] = new DiaryDayGroup(new \DateTimeImmutable($dayKey), $dayEntries);
        }

        $totalPages = max(1, (int) ceil(\count($dayGroups) / self::DAYS_PER_PAGE));
        $currentPage = min(max($requestedPage, 1), $totalPages);

        $offset = ($currentPage - 1) * self::DAYS_PER_PAGE;
        $pageDayGroups = \array_slice($dayGroups, $offset, self::DAYS_PER_PAGE);

        return new DiaryHistoryPage($pageDayGroups, $currentPage, $totalPages, $hasEntries);
    }
}

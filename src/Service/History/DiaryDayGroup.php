<?php

namespace App\Service\History;

use App\Entity\DiaryEntry;

final class DiaryDayGroup
{
    /**
     * @param DiaryEntry[] $entries
     */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly array $entries,
    ) {
    }
}

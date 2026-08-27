<?php

namespace App\Service\Export;

use App\Entity\DiaryEntry;
use App\Service\History\DiaryHistoryPage;

final class DiaryExportService
{
    /**
     * @param resource $handle
     */
    public function writeCsv(DiaryHistoryPage $historyPage, $handle): void
    {
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Data i godzina',
            'Glikemia (mg/dL)',
            'WW',
            'Insulina (j.)',
            'Intensywność aktywności',
            'Czas aktywności (min)',
        ], separator: ';', escape: '');

        foreach ($historyPage->dayGroups as $dayGroup) {
            foreach ($dayGroup->entries as $entry) {
                fputcsv($handle, $this->rowFor($entry), separator: ';', escape: '');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function rowFor(DiaryEntry $entry): array
    {
        $activityIntensity = $entry->getActivityIntensity();

        return [
            $entry->getMeasuredAt()->format('d.m.Y H:i'),
            (string) $entry->getGlycemiaMgDl(),
            $this->formatDecimal($entry->getWw()),
            $this->formatDecimal($entry->getInsulinDose()),
            null === $activityIntensity ? '' : $activityIntensity->value,
            null === $entry->getActivityDurationMinutes() ? '' : (string) $entry->getActivityDurationMinutes(),
        ];
    }

    private function formatDecimal(?float $value): string
    {
        return null === $value ? '' : str_replace('.', ',', (string) $value);
    }
}

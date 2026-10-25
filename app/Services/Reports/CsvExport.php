<?php

namespace App\Services\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Streams report rows as a CSV download. Cells that a spreadsheet would run as a formula are defused with a leading quote. */
class CsvExport
{
    /** @param  iterable<int, array<string, mixed>>  $rows */
    public function download(string $filename, array $columns, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn (string $column) => self::cell($row[$column] ?? ''), $columns), ',', '"', '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($text) ? "'".$text : $text;
    }
}

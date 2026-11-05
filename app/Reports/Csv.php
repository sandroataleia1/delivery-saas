<?php

namespace App\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Planilha que abre direto no Excel em português: ponto e vírgula, BOM e vírgula decimal. */
class Csv
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $output = fopen('php://output', 'w');

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ';', '"', '');

            foreach ($rows as $row) {
                fputcsv($output, array_map(self::cell(...), $row), ';', '"', '');
            }

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }

    /** Nome de cliente como "=HYPERLINK(...)" viraria fórmula ao abrir no Excel. */
    private static function cell(mixed $value): string
    {
        $text = (string) $value;

        if (preg_match('/^-?\d[\d.,]*$/', $text)) {
            return $text;
        }

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'$text" : $text;
    }
}

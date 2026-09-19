<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Csv;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writing a file somebody downloads, a row at a time.
 *
 * Streamed rather than built: an export held in memory is an export that
 * works for the first customer to ask for one and times out for the
 * largest. The byte order mark is written because Excel reads a UTF-8 file
 * without one as the current codepage and mangles every accented name.
 */
final class Writer
{
    private const BOM = "\xEF\xBB\xBF";

    private function __construct() {}

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int|string, mixed>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return new StreamedResponse(self::writer($headers, $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.self::safeName($filename).'"',
            'Cache-Control' => 'no-store, no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int|string, mixed>>  $rows
     */
    public static function toString(array $headers, iterable $rows): string
    {
        ob_start();
        (self::writer($headers, $rows))();

        return (string) ob_get_clean();
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int|string, mixed>>  $rows
     */
    private static function writer(array $headers, iterable $rows): Closure
    {
        return function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, self::BOM);
            fputcsv($handle, $headers, escape: '');

            foreach ($rows as $row) {
                fputcsv($handle, array_map(self::cell(...), array_values($row)), escape: '');
            }

            fclose($handle);
        };
    }

    /**
     * A leading =, +, - or @ makes a spreadsheet treat the cell as a formula,
     * so a customer name can run a command on the machine that opens it.
     */
    private static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };

        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }

    private static function safeName(string $filename): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);

        return str_ends_with($name, '.csv') ? $name : $name.'.csv';
    }
}

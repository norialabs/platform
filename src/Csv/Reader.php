<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Csv;

use Generator;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Reading the file somebody actually has: a UTF-8 byte order mark from
 * Excel, semicolon or tab separators from older exports, headers that are
 * padded, mixed case and duplicated.
 *
 * Pure - a path in, rows out, no database and no container - so the awkward
 * cases are unit tests.
 */
final class Reader
{
    /** Enough rows to recognise what a column holds without reading somebody's whole customer list. */
    public const SAMPLE_ROWS = 8;

    /**
     * fgetcsv reads until a newline, so a 200MB file on one line would
     * exhaust a worker before any row limit could refuse it. Passed to
     * fgetcsv so the memory is never allocated at all.
     */
    public const MAX_LINE_BYTES = 1_048_576;

    private const DELIMITERS = [',', ';', "\t", '|'];

    private const BOM = "\xEF\xBB\xBF";

    /**
     * A ceiling on an uploaded file. An import that arrives in a request
     * body has to be bounded before it is decoded, not after.
     */
    public const MAX_BYTES = 5_242_880;

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $sample
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $sample,
        public readonly string $delimiter,
    ) {}

    /**
     * The same file already in memory, as an upload arrives.
     *
     * A product that reads a CSV out of a request body has nothing to give
     * a path-based reader but a temporary file it then has to clean up.
     */
    public static function previewContent(string $csv): self
    {
        return self::previewStream(self::memory($csv));
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    public static function rowsContent(string $csv): Generator
    {
        yield from self::rowsIn(self::memory($csv));
    }

    /** Data rows, not counting the header. */
    public static function countContent(string $csv): int
    {
        $count = 0;

        foreach (self::rowsContent($csv) as $ignored) {
            $count++;
        }

        return $count;
    }

    /**
     * An upload arrives base64 encoded as often as it arrives as a file,
     * and is bounded before it is decoded: a caller that decodes first has
     * already allocated whatever was sent.
     */
    public static function decode(string $data): string
    {
        $encodedCeiling = (int) ceil(self::maxBytes() * 4 / 3) + 1_024;

        if (strlen($data) > $encodedCeiling) {
            throw new RuntimeException('That file is not readable, or is too large.');
        }

        $decoded = base64_decode($data, true);

        if ($decoded === false || strlen($decoded) > self::maxBytes()) {
            throw new RuntimeException('That file is not readable, or is too large.');
        }

        return $decoded;
    }

    /**
     * Rules for the two shapes an upload arrives in.
     *
     * @return array<string, list<string>>
     */
    public static function uploadRules(string $file = 'csv', string $encoded = 'data_base64'): array
    {
        $bytes = self::maxBytes();

        return [
            $encoded => ['required_without:'.$file, 'string', 'max:'.((int) ceil($bytes * 4 / 3))],
            $file => ['required_without:'.$encoded, 'string', 'max:'.$bytes],
        ];
    }

    public static function maxBytes(): int
    {
        return Config::integer('noria.csv.max_bytes', self::MAX_BYTES);
    }

    /** The headers and a few rows, which is all a mapping needs to be decided. */
    public static function preview(string $path): self
    {
        return self::previewStream(self::open($path));
    }

    /** @param resource $handle */
    private static function previewStream($handle): self
    {
        $delimiter = self::sniffStream($handle);

        $headers = self::headersFrom($handle, $delimiter);
        $sample = [];

        while (count($sample) < self::SAMPLE_ROWS && ($row = fgetcsv($handle, self::MAX_LINE_BYTES, $delimiter, escape: '')) !== false) {
            if (self::isBlank($row)) {
                continue;
            }

            $sample[] = self::combine($headers, $row);
        }

        fclose($handle);

        return new self($headers, $sample, $delimiter);
    }

    /**
     * A generator rather than an array: 50,000 rows held in memory alongside
     * the models each one creates is an import that works in testing and
     * dies in production.
     *
     * @return Generator<int, array<string, string>>
     */
    public static function rows(string $path): Generator
    {
        yield from self::rowsIn(self::open($path));
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, array<string, string>>
     */
    private static function rowsIn($handle): Generator
    {
        $delimiter = self::sniffStream($handle);
        $headers = self::headersFrom($handle, $delimiter);

        // One-based and counting the header, because that is what the spreadsheet shows.
        $line = 1;

        try {
            while (($row = fgetcsv($handle, self::MAX_LINE_BYTES, $delimiter, escape: '')) !== false) {
                $line++;

                if (self::isBlank($row)) {
                    continue;
                }

                yield $line => self::combine($headers, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Counted from the header line rather than asked for: a semicolon
     * separated export looks to a comma parser like one very wide column,
     * and then fails about a missing name field.
     */
    public static function sniff(string $path): string
    {
        $handle = self::open($path);

        try {
            return self::sniffStream($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read from the handle and rewound, so the caller reads the header
     * itself rather than losing it to the sniff.
     *
     * @param  resource  $handle
     */
    private static function sniffStream($handle): string
    {
        $at = ftell($handle);
        $first = fgets($handle, self::MAX_LINE_BYTES);
        fseek($handle, $at === false ? 0 : $at);

        if ($first === false) {
            return ',';
        }

        $first = self::stripBom($first);
        $best = ',';
        $most = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = substr_count($first, $delimiter);

            if ($count > $most) {
                $most = $count;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * A header reduced to the form a mapping is keyed on. "Business Name",
     * "business name" and "Business  Name " are one column to everybody
     * except a string comparison.
     */
    public static function key(string $header): string
    {
        $key = mb_strtolower(trim(self::stripBom($header)));
        $key = (string) preg_replace('/[^\p{L}\p{N}]+/u', '_', $key);

        return trim($key, '_');
    }

    /**
     * @param  resource  $handle
     * @return list<string>
     */
    private static function headersFrom($handle, string $delimiter): array
    {
        $row = fgetcsv($handle, self::MAX_LINE_BYTES, $delimiter, escape: '');

        if ($row === false) {
            throw new RuntimeException('That file has no header row.');
        }

        $headers = [];
        $seen = [];

        foreach ($row as $index => $value) {
            $header = trim(self::stripBom((string) $value));

            // An unnamed column still has data under it. Named by position so it can be mapped.
            if ($header === '') {
                $header = 'Column '.($index + 1);
            }

            // Two columns called Phone are two columns, not one overwriting the other per row.
            $occurrence = ($seen[$header] ?? 0) + 1;
            $seen[$header] = $occurrence;

            $headers[] = $occurrence === 1 ? $header : "{$header} ({$occurrence})";
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string|null>  $row
     * @return array<string, string>
     */
    private static function combine(array $headers, array $row): array
    {
        $combined = [];

        foreach ($headers as $index => $header) {
            // A short row is normal: half these tools drop trailing empty columns.
            $combined[$header] = trim((string) ($row[$index] ?? ''));
        }

        return $combined;
    }

    /** @param list<string|null> $row */
    private static function isBlank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, self::BOM) ? substr($value, strlen(self::BOM)) : $value;
    }

    /**
     * @return resource
     */
    private static function memory(string $csv)
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new RuntimeException('That file could not be read.');
        }

        fwrite($handle, $csv);
        rewind($handle);

        return $handle;
    }

    /** @return resource */
    private static function open(string $path)
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('That file could not be read.');
        }

        return $handle;
    }
}

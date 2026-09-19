<?php

declare(strict_types=1);

namespace NoriaLabs\Platform\Csv;

use Generator;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use RuntimeException;

final class Reader
{
    public const SAMPLE_ROWS = 8;

    public const MAX_LINE_BYTES = 1_048_576;

    private const DELIMITERS = [',', ';', "\t", '|'];

    private const BOM = "\xEF\xBB\xBF";

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

    public static function countContent(string $csv): int
    {
        $count = 0;

        foreach (self::rowsContent($csv) as $ignored) {
            $count++;
        }

        return $count;
    }

    public static function decode(string $data): string
    {
        $encodedCeiling = (int) ceil(self::maxBytes() * 4 / 3) + 1_024;

        if (strlen($data) > $encodedCeiling) {
            throw new InvalidArgumentException('That file is not readable, or is too large.');
        }

        $decoded = base64_decode($data, true);

        if ($decoded === false || strlen($decoded) > self::maxBytes()) {
            throw new InvalidArgumentException('That file is not readable, or is too large.');
        }

        return $decoded;
    }

    /**
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

            if ($header === '') {
                $header = 'Column '.($index + 1);
            }

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

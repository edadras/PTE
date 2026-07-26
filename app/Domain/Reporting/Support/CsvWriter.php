<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use RuntimeException;

/**
 * Minimal streaming CSV writer built on fputcsv.
 *
 * No spreadsheet library on purpose: the export is a flat table, and pulling in
 * a writer that materialises the whole sheet in memory would put a hard ceiling
 * on how many students an academy may export.
 *
 * Two details make the file open correctly in Excel, which is what academy staff
 * actually use: a UTF-8 BOM (without it Persian names arrive as mojibake) and
 * CRLF line endings.
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";

    /** @var resource */
    private $handle;

    private int $rows = 0;

    private bool $closed = false;

    public function __construct(private readonly string $path, bool $withBom = true)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open [{$path}] for writing.");
        }

        $this->handle = $handle;

        if ($withBom) {
            fwrite($this->handle, self::BOM);
        }
    }

    /** A writer over a fresh temporary file. The caller owns the file. */
    public static function temporary(string $prefix = 'pte-export'): self
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the export.');
        }

        return new self($path);
    }

    /**
     * @param  array<int, string>  $headers
     */
    public function headers(array $headers): self
    {
        $this->write($headers);
        // The header is structure, not data — it must not inflate row_count.
        $this->rows--;

        return $this;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    public function write(array $row): self
    {
        fputcsv($this->handle, array_map($this->stringify(...), $row), ',', '"', '\\', "\r\n");
        $this->rows++;

        return $this;
    }

    /**
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public function writeAll(iterable $rows): self
    {
        foreach ($rows as $row) {
            $this->write($row);
        }

        return $this;
    }

    public function rowCount(): int
    {
        return max(0, $this->rows);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): string
    {
        if (! $this->closed) {
            fclose($this->handle);
            $this->closed = true;
        }

        return $this->path;
    }

    public function contents(): string
    {
        $this->close();

        $contents = file_get_contents($this->path);

        return $contents === false ? '' : $contents;
    }

    public function discard(): void
    {
        $this->close();

        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * Neutralise formula injection. A cell beginning with =, +, - or @ is
     * executed by Excel when the file is opened, and student-supplied text ends
     * up in these exports.
     */
    private function stringify(mixed $value): string
    {
        $string = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }
}

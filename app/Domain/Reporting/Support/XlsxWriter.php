<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use BackedEnum;
use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Minimal streaming XLSX writer with no library dependency.
 *
 * An .xlsx file is a ZIP of XML parts; this class writes exactly the parts a
 * conforming reader needs — [Content_Types].xml, the package and workbook
 * relationships, workbook, one worksheet, styles and a shared-strings part —
 * and nothing else. Rows stream to a temporary worksheet file as they are
 * written, so an export never has to hold the whole sheet in memory; the parts
 * are only zipped together on close().
 *
 * Strings are written as inline strings rather than shared strings on purpose:
 * a shared-string table has to be deduplicated in memory, which would reinstate
 * the memory ceiling the streaming design exists to remove. The (empty)
 * sharedStrings part is still emitted so the package is structurally complete.
 *
 * Single sheet only: every report this platform produces is one flat table, and
 * a second sheet would buy nothing but a second temporary file.
 *
 * Formula injection is neutralised exactly the way CsvWriter does it, so the
 * same student-supplied cell renders identically in both export formats.
 */
final class XlsxWriter
{
    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const RELATIONSHIP_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var resource */
    private $sheet;

    private readonly string $sheetTemp;

    private readonly string $sheetName;

    private int $rows = 0;

    private int $nextRow = 1;

    private bool $closed = false;

    public function __construct(private readonly string $path, string $sheetName = 'Report')
    {
        $this->sheetName = $this->safeSheetName($sheetName);

        $temp = tempnam(sys_get_temp_dir(), 'pte-xlsx-sheet');

        if ($temp === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the worksheet.');
        }

        $this->sheetTemp = $temp;

        $handle = fopen($temp, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open [{$temp}] for writing.");
        }

        $this->sheet = $handle;

        fwrite(
            $this->sheet,
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::SPREADSHEET_NS.'" xmlns:r="'.self::RELATIONSHIP_NS.'"><sheetData>',
        );
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
     * Zero-based column index to A1-style letters: 0 => A, 25 => Z, 26 => AA.
     */
    public static function columnLetters(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $index--;
            $letters = chr(65 + $index % 26).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    /**
     * @param  array<int, string>  $headers
     */
    public function headers(array $headers): self
    {
        $this->writeRow($headers, header: true);

        return $this;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    public function write(array $row): self
    {
        $this->writeRow($row, header: false);
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
        return $this->rows;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): string
    {
        if ($this->closed) {
            return $this->path;
        }

        fwrite($this->sheet, '</sheetData></worksheet>');
        fclose($this->sheet);

        try {
            $this->assemble();
        } finally {
            @unlink($this->sheetTemp);
        }

        $this->closed = true;

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
        try {
            $this->close();
        } finally {
            if (is_file($this->sheetTemp)) {
                @unlink($this->sheetTemp);
            }

            if (is_file($this->path)) {
                @unlink($this->path);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function writeRow(array $row, bool $header): void
    {
        $number = $this->nextRow++;
        $xml = '<row r="'.$number.'">';

        $column = 0;

        foreach ($row as $value) {
            $xml .= $this->cell($column++, $number, $value, $header);
        }

        $xml .= '</row>';

        fwrite($this->sheet, $xml);
    }

    private function cell(int $column, int $row, mixed $value, bool $header): string
    {
        $reference = self::columnLetters($column).$row;

        // Real numbers become numeric cells; the header row stays text even if
        // a column is ever named "2026", because a styled numeric header is a
        // lie about the data below it.
        if (! $header && (is_int($value) || (is_float($value) && is_finite($value)))) {
            return '<c r="'.$reference.'"><v>'.$value.'</v></c>';
        }

        $style = $header ? ' s="1"' : '';
        $string = $this->stringify($value);

        if ($string === '') {
            return '<c r="'.$reference.'"'.$style.'/>';
        }

        return '<c r="'.$reference.'"'.$style.' t="inlineStr">'
            .'<is><t xml:space="preserve">'.$this->escape($string).'</t></is>'
            .'</c>';
    }

    /**
     * Mirrors CsvWriter::stringify(), including the formula-injection guard:
     * a cell that begins with =, +, -, @, tab or CR is stored as a plain
     * string with a leading apostrophe, exactly as the CSV export renders it,
     * so the two formats never disagree about hostile input.
     */
    private function stringify(mixed $value): string
    {
        $string = match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };

        if ($string !== '' && in_array($string[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$string;
        }

        return $string;
    }

    /**
     * Characters outside the XML 1.0 production (most control characters) are
     * stripped rather than escaped: escaping them would still produce a file
     * every reader rejects as corrupt.
     */
    private function escape(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $value = (string) preg_replace(
            '/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $value,
        );

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function assemble(): void
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create the workbook at [{$this->path}].");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->packageRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/sharedStrings.xml', $this->sharedStrings());
        $zip->addFile($this->sheetTemp, 'xl/worksheets/sheet1.xml');

        if (! $zip->close()) {
            throw new RuntimeException("Unable to finalise the workbook at [{$this->path}].");
        }
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>';
    }

    private function packageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::RELATIONSHIP_NS.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::SPREADSHEET_NS.'" xmlns:r="'.self::RELATIONSHIP_NS.'">'
            .'<sheets><sheet name="'.$this->escape($this->sheetName).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.self::RELATIONSHIP_NS.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::RELATIONSHIP_NS.'/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId3" Type="'.self::RELATIONSHIP_NS.'/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>';
    }

    /**
     * The smallest stylesheet Excel accepts: style 0 is the implicit default,
     * style 1 is the bold font the header row uses. The second fill (gray125)
     * is required by the spec even though nothing references it.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::SPREADSHEET_NS.'">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="2">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sharedStrings(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="'.self::SPREADSHEET_NS.'" count="0" uniqueCount="0"/>';
    }

    private function safeSheetName(string $name): string
    {
        // Excel rejects these characters in a sheet name and caps it at 31.
        $name = trim((string) str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));

        $name = mb_substr($name, 0, 31);

        return $name === '' ? 'Report' : $name;
    }
}

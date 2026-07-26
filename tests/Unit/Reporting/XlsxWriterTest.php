<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\Domain\Reporting\Enums\ReportStatus;
use App\Domain\Reporting\Support\XlsxWriter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use ZipArchive;

/**
 * The writer is judged the only way that matters: the produced file is read
 * back with ZipArchive and its XML parts parsed, so a regression that corrupts
 * the package fails here instead of on a teacher's laptop.
 */
final class XlsxWriterTest extends TestCase
{
    private const SPREADSHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = tempnam(sys_get_temp_dir(), 'pte-xlsx-test');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    #[Test]
    public function it_produces_a_package_whose_every_part_is_wellformed_xml(): void
    {
        $writer = new XlsxWriter($this->path);
        $writer->headers(['id', 'name'])->write([1, 'Reza'])->close();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path) === true);

        $parts = [
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/sharedStrings.xml',
            'xl/worksheets/sheet1.xml',
        ];

        foreach ($parts as $part) {
            $raw = $zip->getFromName($part);

            $this->assertNotFalse($raw, "The package is missing {$part}.");
            $this->assertInstanceOf(
                SimpleXMLElement::class,
                simplexml_load_string((string) $raw),
                "{$part} is not well-formed XML.",
            );
        }

        $zip->close();
    }

    #[Test]
    public function values_types_and_references_survive_the_round_trip(): void
    {
        $writer = new XlsxWriter($this->path);

        $writer
            ->headers(['id', 'name', 'score', 'status', 'when', 'note'])
            ->write([7, 'رضا کریمی', 42.5, ReportStatus::Ready, new DateTimeImmutable('2026-07-26 10:00:00'), null])
            ->close();

        $this->assertSame(1, $writer->rowCount());

        $sheet = $this->sheet();

        // Header row: bold style, inline strings, A1-style references.
        $this->assertSame('1', (string) $this->cell($sheet, 'A1')['s']);
        $this->assertSame('id', $this->text($sheet, 'A1'));
        $this->assertSame('note', $this->text($sheet, 'F1'));

        // Integers and floats are numeric cells (a <v>, no inlineStr type).
        $id = $this->cell($sheet, 'A2');
        $this->assertNull($id['t'] ?? null);
        $this->assertSame('7', (string) $id->v);
        $this->assertSame('42.5', (string) $this->cell($sheet, 'C2')->v);

        // Everything else is a string, converted the way the CSV path does it.
        $this->assertSame('رضا کریمی', $this->text($sheet, 'B2'));
        $this->assertSame(ReportStatus::Ready->value, $this->text($sheet, 'D2'));
        $this->assertSame('2026-07-26 10:00:00', $this->text($sheet, 'E2'));

        // NULL is an empty cell, not the string "null".
        $this->assertSame('', trim((string) $this->cell($sheet, 'F2')->is->t));
    }

    #[Test]
    public function column_references_are_correct_past_z(): void
    {
        $this->assertSame('A', XlsxWriter::columnLetters(0));
        $this->assertSame('Z', XlsxWriter::columnLetters(25));
        $this->assertSame('AA', XlsxWriter::columnLetters(26));
        $this->assertSame('AB', XlsxWriter::columnLetters(27));
        $this->assertSame('AZ', XlsxWriter::columnLetters(51));
        $this->assertSame('BA', XlsxWriter::columnLetters(52));
        $this->assertSame('ZZ', XlsxWriter::columnLetters(701));
        $this->assertSame('AAA', XlsxWriter::columnLetters(702));

        $writer = new XlsxWriter($this->path);
        $writer->write(array_fill(0, 28, 'x'))->close();

        $this->assertSame('x', $this->text($this->sheet(), 'AB1'));
    }

    #[Test]
    public function a_cell_that_looks_like_a_formula_is_neutralised_like_the_csv_path(): void
    {
        $writer = new XlsxWriter($this->path);
        $writer->write(['=cmd|/c calc', '+1', '@SUM(A1)'])->close();

        $sheet = $this->sheet();

        $this->assertSame("'=cmd|/c calc", $this->text($sheet, 'A1'));
        $this->assertSame("'+1", $this->text($sheet, 'B1'));
        $this->assertSame("'@SUM(A1)", $this->text($sheet, 'C1'));

        // And no cell anywhere carries a formula element.
        $sheet->registerXPathNamespace('s', self::SPREADSHEET_NS);
        $this->assertSame([], $sheet->xpath('//s:f'));
    }

    #[Test]
    public function markup_is_escaped_and_illegal_xml_characters_are_stripped(): void
    {
        $writer = new XlsxWriter($this->path);
        $writer->write(['<b>&"bold"</b>', "a\x00b\x07c", "tab\tkept"])->close();

        $sheet = $this->sheet();

        $this->assertSame('<b>&"bold"</b>', $this->text($sheet, 'A1'));
        // NUL and BEL are illegal in XML 1.0 in any encoding; they must vanish
        // rather than corrupt the part.
        $this->assertSame('abc', $this->text($sheet, 'B1'));
        $this->assertSame("tab\tkept", $this->text($sheet, 'C1'));
    }

    #[Test]
    public function it_streams_an_iterable_without_collecting_it(): void
    {
        $rows = static function (): iterable {
            for ($i = 1; $i <= 500; $i++) {
                yield [$i, 'row-'.$i];
            }
        };

        $writer = new XlsxWriter($this->path);
        $writer->headers(['n', 'label'])->writeAll($rows())->close();

        $this->assertSame(500, $writer->rowCount());

        $sheet = $this->sheet();

        $this->assertSame('500', (string) $this->cell($sheet, 'A501')->v);
        $this->assertSame('row-500', $this->text($sheet, 'B501'));
    }

    #[Test]
    public function discard_removes_the_file(): void
    {
        $writer = XlsxWriter::temporary();
        $path = $writer->path();

        $writer->write(['x']);
        $writer->discard();

        $this->assertFileDoesNotExist($path);
    }

    private function sheet(): SimpleXMLElement
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->path) === true);

        $raw = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $sheet = simplexml_load_string($raw);
        $this->assertInstanceOf(SimpleXMLElement::class, $sheet);

        return $sheet;
    }

    private function cell(SimpleXMLElement $sheet, string $reference): SimpleXMLElement
    {
        $sheet->registerXPathNamespace('s', self::SPREADSHEET_NS);

        $matches = $sheet->xpath("//s:c[@r='{$reference}']");

        $this->assertNotEmpty($matches, "No cell at {$reference}.");

        return $matches[0];
    }

    private function text(SimpleXMLElement $sheet, string $reference): string
    {
        $cell = $this->cell($sheet, $reference);

        $this->assertSame('inlineStr', (string) $cell['t'], "Cell {$reference} is not an inline string.");

        return (string) $cell->is->t;
    }
}

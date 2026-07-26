<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Domain\Commerce\Data\InvoiceLine;
use App\Domain\Commerce\Models\Invoice;
use App\Domain\Commerce\Services\InvoiceGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InvoiceGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private int $academyA;

    private int $academyB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academyA = $this->academy('invoice-a');
        $this->academyB = $this->academy('invoice-b');
    }

    private function academy(string $slug): int
    {
        return (int) DB::table('academies')->insertGetId([
            'slug' => $slug,
            'name' => $slug,
            'status' => 'active',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function generator(int $taxRateBp = 900): InvoiceGenerator
    {
        return new InvoiceGenerator($taxRateBp);
    }

    /**
     * @return array<int, InvoiceLine>
     */
    private function lines(int $unitPrice = 2_500_000): array
    {
        return [InvoiceLine::of('Professional plan — Monthly', $unitPrice)];
    }

    #[Test]
    public function it_numbers_invoices_sequentially_per_academy(): void
    {
        $generator = $this->generator();

        $first = $generator->issue($this->academyA, $this->lines());
        $second = $generator->issue($this->academyA, $this->lines());
        $third = $generator->issue($this->academyA, $this->lines());

        $this->assertSame([1, 2, 3], [$first->sequence, $second->sequence, $third->sequence]);
        $this->assertSame(
            [$first->number, $second->number, $third->number],
            array_unique([$first->number, $second->number, $third->number]),
        );
    }

    #[Test]
    public function each_academy_gets_its_own_sequence(): void
    {
        $generator = $this->generator();

        $generator->issue($this->academyA, $this->lines());
        $generator->issue($this->academyA, $this->lines());
        $forB = $generator->issue($this->academyB, $this->lines());

        $this->assertSame(1, $forB->sequence);
        $this->assertStringContainsString('-'.$this->academyB.'-', $forB->number);
    }

    #[Test]
    public function invoice_numbers_are_globally_unique(): void
    {
        $generator = $this->generator();

        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $generator->issue($this->academyA, $this->lines())->number;
            $numbers[] = $generator->issue($this->academyB, $this->lines())->number;
        }

        $this->assertCount(10, array_unique($numbers));
    }

    #[Test]
    public function the_sequence_survives_a_row_inserted_by_another_process(): void
    {
        $generator = $this->generator();
        $generator->issue($this->academyA, $this->lines());

        // Stand in for a concurrent issuer that got there first.
        DB::table('invoices')->insert([
            'academy_id' => $this->academyA,
            'number' => 'INV-'.$this->academyA.'-'.now()->format('Y').'-000009',
            'sequence' => 9,
            'amount' => 1,
            'tax' => 0,
            'total' => 1,
            'tax_rate_bp' => 0,
            'currency' => 'IRR',
            'status' => Invoice::STATUS_ISSUED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $next = $generator->issue($this->academyA, $this->lines());

        $this->assertSame(10, $next->sequence);
    }

    #[Test]
    public function the_unique_index_makes_a_duplicate_sequence_impossible(): void
    {
        $generator = $this->generator();
        $issued = $generator->issue($this->academyA, $this->lines());

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('invoices')->insert([
            'academy_id' => $this->academyA,
            'number' => 'INV-DUPLICATE',
            'sequence' => $issued->sequence,
            'amount' => 1,
            'tax' => 0,
            'total' => 1,
            'tax_rate_bp' => 0,
            'currency' => 'IRR',
            'status' => Invoice::STATUS_ISSUED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function it_computes_tax_and_totals_in_integer_minor_units(): void
    {
        $invoice = $this->generator(900)->issue($this->academyA, $this->lines(2_500_000));

        $this->assertSame(2_500_000, $invoice->amount);
        $this->assertSame(225_000, $invoice->tax);
        $this->assertSame(2_725_000, $invoice->total);
        $this->assertSame(900, $invoice->tax_rate_bp);
        $this->assertIsInt($invoice->total);
    }

    #[Test]
    public function a_zero_tax_rate_produces_no_tax_line_value(): void
    {
        $invoice = $this->generator(0)->issue($this->academyA, $this->lines(990_000));

        $this->assertSame(0, $invoice->tax);
        $this->assertSame(990_000, $invoice->total);
    }

    #[Test]
    public function it_sums_multiple_lines(): void
    {
        $invoice = $this->generator(0)->issue($this->academyA, [
            InvoiceLine::of('Plan', 2_500_000),
            InvoiceLine::of('Extra seats', 100_000, 3),
        ]);

        $this->assertSame(2_800_000, $invoice->amount);
        $this->assertCount(2, $invoice->lines());
        $this->assertSame(300_000, $invoice->lines()[1]->total);
    }

    #[Test]
    public function peek_does_not_consume_a_number(): void
    {
        $generator = $this->generator();

        $peeked = $generator->peekNextNumber($this->academyA);
        $issued = $generator->issue($this->academyA, $this->lines());

        $this->assertSame($peeked, $issued->number);
        $this->assertSame(1, $issued->sequence);
    }

    #[Test]
    public function a_voided_invoice_keeps_its_number(): void
    {
        $generator = $this->generator();
        $invoice = $generator->issue($this->academyA, $this->lines());
        $number = $invoice->number;

        $generator->void($invoice);
        $next = $generator->issue($this->academyA, $this->lines());

        $this->assertSame(Invoice::STATUS_VOID, $invoice->fresh()?->status);
        $this->assertSame($number, $invoice->fresh()?->number);
        $this->assertSame(2, $next->sequence);
    }

    #[Test]
    public function it_renders_an_html_invoice(): void
    {
        $invoice = $this->generator()->issue($this->academyA, $this->lines());

        $html = $this->generator()->render($invoice);

        $this->assertStringContainsString($invoice->number, $html);
        $this->assertStringContainsString('<table', $html);
    }
}

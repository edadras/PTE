<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

/**
 * Allow-list sanitiser for tenant-authored custom CSS.
 *
 * The stylesheet is rendered inside every student- and staff-facing page, so
 * it is treated as hostile input: comments are removed *first* (so a payload
 * split by them re-forms and gets caught), at-rules are dropped wholesale, and
 * only declarations whose property is on the allow-list and whose value is
 * free of executable vectors survive.
 *
 * @see docs/03-white-label.md §2 · docs/12-security-and-compliance.md §6
 */
final class CssSanitizer
{
    /** @var array<int, string> exact property names an academy may set */
    public const ALLOWED_PROPERTIES = [
        'align-items',
        'background',
        'background-color',
        'border',
        'border-radius',
        'box-shadow',
        'color',
        'display',
        'flex',
        'flex-direction',
        'flex-wrap',
        'font',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'gap',
        'height',
        'justify-content',
        'letter-spacing',
        'line-height',
        'max-height',
        'max-width',
        'min-height',
        'min-width',
        'opacity',
        'outline',
        'text-align',
        'text-decoration',
        'text-transform',
        'visibility',
        'width',
    ];

    /** @var array<int, string> allow-listed property families (prefix match) */
    public const ALLOWED_PREFIXES = [
        'border-top',
        'border-right',
        'border-bottom',
        'border-left',
        'border-start',
        'border-end',
        'margin',
        'padding',
    ];

    /**
     * Executable / exfiltration vectors. Checked after comment stripping so an
     * obfuscated `ur/**\/l(` cannot slip through, and against a backslash-free
     * value so `\75 rl(` cannot either.
     *
     * @var array<int, string>
     */
    private const DENIED_PATTERNS = [
        '/url\s*\(/i',
        '/@import/i',
        '/expression\s*\(/i',
        '/behavior\s*:/i',
        '/behaviour\s*:/i',
        '/javascript\s*:/i',
        '/vbscript\s*:/i',
        '/-moz-binding/i',
        '/data\s*:/i',
        '/[<>]/',
    ];

    /**
     * @return array{css: string, stripped: array<int, string>}
     */
    public function sanitize(string $css): array
    {
        $stripped = [];

        $css = str_replace(["\0", "\r"], '', $css);
        $css = $this->stripComments($css, $stripped);
        $css = $this->stripAtRules($css, $stripped);

        $clean = [];

        foreach ($this->rules($css) as [$selector, $body]) {
            $declarations = $this->cleanDeclarations($body, $stripped);

            if ($declarations === []) {
                continue;
            }

            if (! $this->selectorIsSafe($selector)) {
                $stripped[] = $selector.' { … }';

                continue;
            }

            $clean[] = $selector." {\n    ".implode(";\n    ", $declarations).";\n}";
        }

        return [
            'css' => implode("\n\n", $clean),
            'stripped' => array_values(array_unique($stripped)),
        ];
    }

    /**
     * @param  array<int, string>  $stripped
     */
    private function stripComments(string $css, array &$stripped): string
    {
        // A comment inside an identifier is only ever there to break a token
        // apart; recording it tells the author why the payload disappeared.
        if (preg_match('/\S\/\*.*?\*\/\S/s', $css) === 1) {
            $stripped[] = '/* … */';
        }

        return preg_replace('/\/\*.*?\*\//s', '', $css) ?? '';
    }

    /**
     * Every at-rule goes: @import fetches, @font-face and @media can smuggle
     * url(), and none of them is needed for brand tweaks.
     *
     * @param  array<int, string>  $stripped
     */
    private function stripAtRules(string $css, array &$stripped): string
    {
        return (string) preg_replace_callback(
            '/@[a-zA-Z-]+[^;{]*(;|\{(?:[^{}]|\{[^{}]*\})*\})?/s',
            static function (array $matches) use (&$stripped): string {
                $stripped[] = trim(mb_substr($matches[0], 0, 60));

                return '';
            },
            $css,
        );
    }

    /**
     * @return array<int, array{0: string, 1: string}> [selector, body] pairs
     */
    private function rules(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $matches, PREG_SET_ORDER);

        $rules = [];

        foreach ($matches as $match) {
            $selector = trim(preg_replace('/\s+/', ' ', $match[1]) ?? '');

            if ($selector !== '') {
                $rules[] = [$selector, $match[2]];
            }
        }

        return $rules;
    }

    /**
     * @param  array<int, string>  $stripped
     * @return array<int, string> surviving `property: value` strings
     */
    private function cleanDeclarations(string $body, array &$stripped): array
    {
        $declarations = [];

        foreach (explode(';', $body) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '') {
                continue;
            }

            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            $property = strtolower(trim((string) $property));
            $value = trim((string) $value);

            if ($property === '' || $value === '' || ! $this->propertyIsAllowed($property)) {
                $stripped[] = $declaration;

                continue;
            }

            if (! $this->valueIsSafe($property.': '.$value)) {
                $stripped[] = $declaration;

                continue;
            }

            $declarations[] = $property.': '.$value;
        }

        return $declarations;
    }

    private function propertyIsAllowed(string $property): bool
    {
        if (in_array($property, self::ALLOWED_PROPERTIES, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($property === $prefix || str_starts_with($property, $prefix.'-')) {
                return true;
            }
        }

        return false;
    }

    private function valueIsSafe(string $declaration): bool
    {
        // Backslash escapes exist in CSS solely to encode characters that would
        // otherwise be recognised — exactly what an attacker needs here.
        if (str_contains($declaration, '\\')) {
            return false;
        }

        foreach (self::DENIED_PATTERNS as $pattern) {
            if (preg_match($pattern, $declaration) === 1) {
                return false;
            }
        }

        return true;
    }

    private function selectorIsSafe(string $selector): bool
    {
        return preg_match('/^[a-zA-Z0-9\s.,#:>~+*_\[\]="\'()-]+$/', $selector) === 1
            && stripos($selector, 'expression') === false;
    }
}

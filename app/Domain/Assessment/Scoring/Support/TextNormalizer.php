<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Scoring\Support;

/**
 * Shared text canonicalisation for every deterministic scorer.
 *
 * The whole point of algorithmic grading is that a student is never punished for
 * something the exam does not measure. Dictation tests spelling and word order,
 * not capitalisation, punctuation or whether the speaker said "don't" and the
 * student wrote "do not" — so all three are normalised away before comparison.
 */
final class TextNormalizer
{
    /**
     * Expanded first, so both sides of a comparison end up in the same long form.
     *
     * @var array<string, string>
     */
    private const CONTRACTIONS = [
        "won't" => 'will not',
        "can't" => 'can not',
        'cannot' => 'can not',
        "shan't" => 'shall not',
        "ain't" => 'is not',
        "n't" => ' not',
        "'re" => ' are',
        "'ve" => ' have',
        "'ll" => ' will',
        "'m" => ' am',
        "'d" => ' would',
        // "'s" is deliberately absent: it is ambiguous between "is" and a
        // possessive, and expanding it would turn "the library's hours" into a
        // three-word phrase and skew the dictation denominator.
    ];

    /** Lowercase, de-punctuated, single-spaced, contraction-free. */
    public function normalize(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $value = mb_strtolower(trim($text));

        // Typographic apostrophes and dashes come from phones and Word alike.
        $value = strtr($value, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{02BC}" => "'",
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-',
        ]);

        $value = strtr($value, self::CONTRACTIONS);

        // Keep letters and digits only; everything else becomes a boundary.
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * @return array<int, string>
     */
    public function words(?string $text): array
    {
        $normalized = $this->normalize($text);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    public function wordCount(?string $text): int
    {
        return count($this->words($text));
    }

    public function equals(?string $a, ?string $b): bool
    {
        return $this->normalize($a) === $this->normalize($b) && $this->normalize($a) !== '';
    }

    /** Whole-word phrase containment — "ten" must not match "often". */
    public function containsPhrase(?string $haystack, ?string $needle): bool
    {
        $haystack = $this->normalize($haystack);
        $needle = $this->normalize($needle);

        if ($haystack === '' || $needle === '') {
            return false;
        }

        return str_contains(" {$haystack} ", " {$needle} ");
    }

    /**
     * Longest common subsequence length between two word lists.
     *
     * Used by dictation scoring: it credits every word the student got right
     * *in the right order*, which is what PTE rewards, while an unordered set
     * intersection would hand full marks to a scrambled sentence.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public function longestCommonSubsequence(array $a, array $b): int
    {
        $rows = count($a);
        $cols = count($b);

        if ($rows === 0 || $cols === 0) {
            return 0;
        }

        // Single-row DP: dictation answers are short, but this keeps a long
        // transcript from allocating an n×m table.
        $previous = array_fill(0, $cols + 1, 0);

        for ($i = 1; $i <= $rows; $i++) {
            $current = array_fill(0, $cols + 1, 0);

            for ($j = 1; $j <= $cols; $j++) {
                $current[$j] = $a[$i - 1] === $b[$j - 1]
                    ? $previous[$j - 1] + 1
                    : max($previous[$j], $current[$j - 1]);
            }

            $previous = $current;
        }

        return $previous[$cols];
    }

    /**
     * Words present in $expected that never appear in $given, counting repeats.
     *
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $given
     * @return array<int, string>
     */
    public function missingWords(array $expected, array $given): array
    {
        $pool = array_count_values($given);
        $missing = [];

        foreach ($expected as $word) {
            if (($pool[$word] ?? 0) > 0) {
                $pool[$word]--;

                continue;
            }

            $missing[] = $word;
        }

        return $missing;
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $given
     * @return array<int, string>
     */
    public function extraWords(array $expected, array $given): array
    {
        return $this->missingWords($given, $expected);
    }

    /** Normalises anything a JSON answer payload may hold into a comparable key. */
    public function key(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return is_string($value) ? $this->normalize($value) : '';
    }
}

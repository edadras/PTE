<?php

declare(strict_types=1);

namespace App\Domain\AI\Support;

/**
 * Word-level comparison of a transcript against the target text.
 *
 * Free, deterministic and exact — everything this produces would otherwise be
 * guessed by the model, badly and at a price (docs/06 §5, step 5).
 */
final class TextComparator
{
    /**
     * Word error rate: (substitutions + deletions + insertions) / reference
     * words, the standard ASR metric. Not capped at 1.0 — a student who says
     * three times as much as the target genuinely scores above 1.
     */
    public function wordErrorRate(string $reference, string $hypothesis): float
    {
        $ref = $this->tokenize($reference);
        $hyp = $this->tokenize($hypothesis);

        if ($ref === []) {
            return $hyp === [] ? 0.0 : 1.0;
        }

        $operations = $this->operations($ref, $hyp);

        $errors = $operations['substitutions'] + $operations['deletions'] + $operations['insertions'];

        return round($errors / count($ref), 4);
    }

    /**
     * Reference words the student never said.
     *
     * @return array<int, string>
     */
    public function missingWords(string $reference, string $hypothesis): array
    {
        return $this->align($reference, $hypothesis)['missing'];
    }

    /**
     * Words the student added that are not in the reference.
     *
     * @return array<int, string>
     */
    public function extraWords(string $reference, string $hypothesis): array
    {
        return $this->align($reference, $hypothesis)['extra'];
    }

    /**
     * Substituted words close enough to the target to be a pronunciation slip
     * rather than a different word — "particularly" heard as "peculiarly" is a
     * pronunciation problem; heard as "however" is a reading problem.
     *
     * @return array<int, array{expected: string, heard: string, distance: int}>
     */
    public function mispronouncedCandidates(string $reference, string $hypothesis): array
    {
        $candidates = [];

        foreach ($this->align($reference, $hypothesis)['substitutions'] as $pair) {
            $expected = $pair['expected'];
            $heard = $pair['heard'];
            $distance = levenshtein($expected, $heard);
            $tolerance = max(1, (int) floor(mb_strlen($expected) * 0.4));

            if ($distance <= $tolerance) {
                $candidates[] = ['expected' => $expected, 'heard' => $heard, 'distance' => $distance];
            }
        }

        return $candidates;
    }

    /**
     * Full alignment in one pass — the prompt builder needs all of it and the
     * dynamic-programming table is not worth computing four times.
     *
     * @return array{
     *     missing: array<int, string>,
     *     extra: array<int, string>,
     *     substitutions: array<int, array{expected: string, heard: string}>,
     *     matched: int,
     *     reference_words: int,
     *     hypothesis_words: int,
     *     word_error_rate: float,
     *     accuracy: float
     * }
     */
    public function align(string $reference, string $hypothesis): array
    {
        $ref = $this->tokenize($reference);
        $hyp = $this->tokenize($hypothesis);

        $operations = $this->operations($ref, $hyp);
        $refCount = count($ref);
        $errors = $operations['substitutions'] + $operations['deletions'] + $operations['insertions'];

        return [
            'missing' => $operations['missing'],
            'extra' => $operations['extra'],
            'substitutions' => $operations['substitution_pairs'],
            'matched' => $operations['matches'],
            'reference_words' => $refCount,
            'hypothesis_words' => count($hyp),
            'word_error_rate' => $refCount === 0 ? ($hyp === [] ? 0.0 : 1.0) : round($errors / $refCount, 4),
            'accuracy' => $refCount === 0 ? 0.0 : round($operations['matches'] / $refCount, 4),
        ];
    }

    /**
     * Lowercased words with punctuation stripped. Contractions keep their
     * apostrophe ("don't" is one word), numbers survive.
     *
     * @return array<int, string>
     */
    public function tokenize(string $text): array
    {
        $normalised = mb_strtolower(trim($text));

        // Unicode apostrophes to ASCII first, so "don’t" and "don't" tokenize alike.
        $normalised = str_replace(['’', '‘', '`'], "'", $normalised);
        $normalised = preg_replace("/[^\p{L}\p{N}'\s-]+/u", ' ', $normalised) ?? '';
        $normalised = preg_replace("/(?<!\p{L})'|'(?!\p{L})/u", ' ', $normalised) ?? '';

        $tokens = preg_split('/\s+/u', trim($normalised), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : array_values($tokens);
    }

    /**
     * Levenshtein alignment over word sequences with a backtrace.
     *
     * @param  array<int, string>  $ref
     * @param  array<int, string>  $hyp
     * @return array{
     *     substitutions: int, deletions: int, insertions: int, matches: int,
     *     missing: array<int, string>, extra: array<int, string>,
     *     substitution_pairs: array<int, array{expected: string, heard: string}>
     * }
     */
    private function operations(array $ref, array $hyp): array
    {
        $n = count($ref);
        $m = count($hyp);

        /** @var array<int, array<int, int>> $cost */
        $cost = [];

        for ($i = 0; $i <= $n; $i++) {
            $cost[$i] = array_fill(0, $m + 1, 0);
            $cost[$i][0] = $i;
        }

        for ($j = 0; $j <= $m; $j++) {
            $cost[0][$j] = $j;
        }

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $substitution = $cost[$i - 1][$j - 1] + ($ref[$i - 1] === $hyp[$j - 1] ? 0 : 1);
                $deletion = $cost[$i - 1][$j] + 1;
                $insertion = $cost[$i][$j - 1] + 1;

                $cost[$i][$j] = min($substitution, $deletion, $insertion);
            }
        }

        $substitutions = 0;
        $deletions = 0;
        $insertions = 0;
        $matches = 0;
        $missing = [];
        $extra = [];
        $pairs = [];

        $i = $n;
        $j = $m;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $ref[$i - 1] === $hyp[$j - 1] && $cost[$i][$j] === $cost[$i - 1][$j - 1]) {
                $matches++;
                $i--;
                $j--;

                continue;
            }

            if ($i > 0 && $j > 0 && $cost[$i][$j] === $cost[$i - 1][$j - 1] + 1) {
                $substitutions++;
                $pairs[] = ['expected' => $ref[$i - 1], 'heard' => $hyp[$j - 1]];
                $i--;
                $j--;

                continue;
            }

            if ($i > 0 && $cost[$i][$j] === $cost[$i - 1][$j] + 1) {
                $deletions++;
                $missing[] = $ref[$i - 1];
                $i--;

                continue;
            }

            $insertions++;
            $extra[] = $hyp[$j - 1];
            $j--;
        }

        return [
            'substitutions' => $substitutions,
            'deletions' => $deletions,
            'insertions' => $insertions,
            'matches' => $matches,
            'missing' => array_reverse($missing),
            'extra' => array_reverse($extra),
            'substitution_pairs' => array_reverse($pairs),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Domain\AI\Support\TextComparator;
use PHPUnit\Framework\TestCase;

/**
 * Word error rate is the number the speaking prompt leans on hardest, so it is
 * pinned to hand-counted examples: if this drifts, every Read Aloud score
 * drifts with it and nothing else would notice.
 */
final class TextComparatorTest extends TestCase
{
    private TextComparator $comparator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comparator = new TextComparator;
    }

    public function test_identical_text_has_no_error(): void
    {
        $text = 'The rapid growth of urban populations has strained public transport.';

        $this->assertSame(0.0, $this->comparator->wordErrorRate($text, $text));
    }

    public function test_case_and_punctuation_are_ignored(): void
    {
        $this->assertSame(0.0, $this->comparator->wordErrorRate(
            'The rapid growth, of course, has strained transport.',
            'the rapid growth of course has strained transport',
        ));
    }

    public function test_one_substitution_in_nine_words(): void
    {
        $wer = $this->comparator->wordErrorRate(
            'the quick brown fox jumps over the lazy dog',
            'the quick brown fox jumped over the lazy dog',
        );

        $this->assertSame(0.1111, $wer);
    }

    public function test_deletions_and_insertions_both_count(): void
    {
        // reference: 5 words; one deleted ("a"), one inserted ("very")
        $wer = $this->comparator->wordErrorRate(
            'i have a dream today',
            'i have very dream today extra',
        );

        // 1 substitution (a → very) + 1 insertion (extra) = 2 / 5
        $this->assertSame(0.4, $wer);
    }

    public function test_an_empty_attempt_scores_a_full_error_rate(): void
    {
        $this->assertSame(1.0, $this->comparator->wordErrorRate('one two three', ''));
        $this->assertSame(0.0, $this->comparator->wordErrorRate('', ''));
    }

    public function test_it_reports_missing_and_extra_words(): void
    {
        $reference = 'renewable energy sources are becoming increasingly affordable';
        $hypothesis = 'renewable energy are becoming affordable and cheap';

        $this->assertSame(
            ['sources', 'increasingly'],
            $this->comparator->missingWords($reference, $hypothesis),
        );

        $this->assertSame(
            ['and', 'cheap'],
            $this->comparator->extraWords($reference, $hypothesis),
        );
    }

    public function test_contractions_survive_tokenisation(): void
    {
        $this->assertSame(
            ["don't", 'worry', 'about', 'it'],
            $this->comparator->tokenize("Don’t worry — about it!"),
        );
    }

    public function test_near_miss_substitutions_are_flagged_as_pronunciation_candidates(): void
    {
        $candidates = $this->comparator->mispronouncedCandidates(
            'the unprecedented decision was announced',
            'the unpresidented decision was announced',
        );

        $this->assertCount(1, $candidates);
        $this->assertSame('unprecedented', $candidates[0]['expected']);
        $this->assertSame('unpresidented', $candidates[0]['heard']);
    }

    public function test_a_completely_different_word_is_not_a_pronunciation_candidate(): void
    {
        $candidates = $this->comparator->mispronouncedCandidates(
            'the unprecedented decision was announced',
            'the however decision was announced',
        );

        $this->assertSame([], $candidates);
    }

    public function test_alignment_reports_accuracy_and_counts(): void
    {
        $alignment = $this->comparator->align(
            'a b c d e',
            'a b x d e',
        );

        $this->assertSame(4, $alignment['matched']);
        $this->assertSame(5, $alignment['reference_words']);
        $this->assertSame(5, $alignment['hypothesis_words']);
        $this->assertSame(0.2, $alignment['word_error_rate']);
        $this->assertSame(0.8, $alignment['accuracy']);
        $this->assertSame([['expected' => 'c', 'heard' => 'x']], $alignment['substitutions']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Domain\Assessment\Scoring\Support\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function test_it_lowercases_strips_punctuation_and_collapses_whitespace(): void
    {
        $normalizer = new TextNormalizer;

        $this->assertSame(
            'the library will be closed on monday',
            $normalizer->normalize('  The library,  will be  closed — on Monday!  ')
        );
    }

    public function test_it_tolerates_common_contractions(): void
    {
        $normalizer = new TextNormalizer;

        $this->assertTrue($normalizer->equals("they don't agree", 'they do not agree'));
        $this->assertTrue($normalizer->equals("we've seen it", 'we have seen it'));
        $this->assertTrue($normalizer->equals("it won't work", 'it will not work'));
    }

    public function test_it_counts_words_in_order(): void
    {
        $normalizer = new TextNormalizer;

        $expected = $normalizer->words('the quick brown fox');
        $given = $normalizer->words('the brown fox');

        $this->assertSame(3, $normalizer->longestCommonSubsequence($expected, $given));
        $this->assertSame(['quick'], $normalizer->missingWords($expected, $given));
    }

    public function test_phrase_matching_respects_word_boundaries(): void
    {
        $normalizer = new TextNormalizer;

        $this->assertTrue($normalizer->containsPhrase('it is a thermometer', 'thermometer'));
        $this->assertFalse($normalizer->containsPhrase('it happens often', 'ten'));
    }

    public function test_an_empty_string_normalises_to_nothing(): void
    {
        $normalizer = new TextNormalizer;

        $this->assertSame('', $normalizer->normalize(null));
        $this->assertSame([], $normalizer->words('   '));
    }
}

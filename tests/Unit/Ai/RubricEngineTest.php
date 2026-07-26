<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Domain\AI\Exceptions\InvalidRubricException;
use App\Domain\AI\Models\AiRubric;
use App\Domain\AI\Services\RubricEngine;
use PHPUnit\Framework\TestCase;

/**
 * The weighting maths is the part of the product a customer would sue over, and
 * it is the part ADR-007 deliberately keeps out of the model. It is therefore
 * tested against hand-computed numbers, not against snapshots.
 */
final class RubricEngineTest extends TestCase
{
    private RubricEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new RubricEngine;
    }

    public function test_it_applies_documented_weights_to_a_ninety_point_scale(): void
    {
        $rubric = $this->rubric();

        // 24.6 + 18.5 + 17.6 + 12.75 + 9.0 = 82.45 → 82.45% of 90 = 74.205
        $breakdown = $this->engine->apply($rubric, [
            'pronunciation' => 82,
            'fluency' => 74,
            'vocabulary' => 88,
            'grammar' => 85,
            'content' => 90,
        ]);

        $this->assertSame(82.45, round($breakdown->rawTotal, 2));
        $this->assertSame(74.0, $breakdown->scaledScore);
        $this->assertSame(24.6, round($breakdown->weightedScores['pronunciation'], 2));
        $this->assertTrue($breakdown->isComplete());
    }

    public function test_a_perfect_response_reaches_the_top_of_the_scale(): void
    {
        $breakdown = $this->engine->apply($this->rubric(), array_fill_keys(
            ['pronunciation', 'fluency', 'vocabulary', 'grammar', 'content'],
            100,
        ));

        $this->assertSame(100.0, $breakdown->rawTotal);
        $this->assertSame(90.0, $breakdown->scaledScore);
    }

    public function test_scores_outside_zero_to_one_hundred_are_clamped(): void
    {
        $breakdown = $this->engine->apply($this->rubric(), [
            'pronunciation' => 150,
            'fluency' => -20,
            'vocabulary' => 100,
            'grammar' => 100,
            'content' => 100,
        ]);

        $this->assertSame(100.0, $breakdown->rawScores['pronunciation']);
        $this->assertSame(0.0, $breakdown->rawScores['fluency']);
        // 30 + 0 + 20 + 15 + 10 = 75
        $this->assertSame(75.0, $breakdown->rawTotal);
    }

    public function test_a_missing_criterion_is_renormalised_rather_than_scored_zero(): void
    {
        $breakdown = $this->engine->apply($this->rubric(), [
            'pronunciation' => 80,
            'fluency' => 80,
            'vocabulary' => 80,
            'grammar' => 80,
            // content (weight 10) omitted by the model
        ]);

        $this->assertSame(['content'], $breakdown->missingCriteria);
        $this->assertFalse($breakdown->isComplete());
        // 80 over the 90 points of weight actually scored, not 72 out of 100.
        $this->assertSame(80.0, round($breakdown->rawTotal, 4));
    }

    public function test_numeric_strings_from_a_model_are_accepted(): void
    {
        $breakdown = $this->engine->apply($this->rubric(), [
            'pronunciation' => '60',
            'fluency' => '60',
            'vocabulary' => '60',
            'grammar' => '60',
            'content' => '60',
        ]);

        $this->assertSame(60.0, $breakdown->rawTotal);
        $this->assertSame(54.0, $breakdown->scaledScore);
    }

    public function test_weights_that_do_not_sum_to_one_hundred_are_rejected(): void
    {
        $rubric = $this->rubric([
            ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 50],
            ['key' => 'fluency', 'label' => 'Fluency', 'weight' => 40],
        ]);

        $this->expectException(InvalidRubricException::class);

        $this->engine->apply($rubric, ['pronunciation' => 80, 'fluency' => 80]);
    }

    public function test_duplicate_criteria_are_rejected_on_validation(): void
    {
        $this->expectException(InvalidRubricException::class);

        $this->engine->validateCriteria([
            ['key' => 'fluency', 'weight' => 50],
            ['key' => 'fluency', 'weight' => 50],
        ]);
    }

    public function test_rounding_mode_is_honoured(): void
    {
        $scores = array_fill_keys(['pronunciation', 'fluency', 'vocabulary', 'grammar', 'content'], 77);

        $floor = $this->rubric();
        $floor->rounding = AiRubric::ROUNDING_FLOOR;

        $tenth = $this->rubric();
        $tenth->rounding = AiRubric::ROUNDING_TENTH;

        // 77% of 90 = 69.3
        $this->assertSame(69.0, $this->engine->apply($floor, $scores)->scaledScore);
        $this->assertSame(69.3, $this->engine->apply($tenth, $scores)->scaledScore);
    }

    public function test_a_non_zero_scale_minimum_shifts_the_whole_range(): void
    {
        $rubric = $this->rubric();
        $rubric->scale_min = 10;
        $rubric->scale_max = 90;

        $scores = array_fill_keys(['pronunciation', 'fluency', 'vocabulary', 'grammar', 'content'], 50);

        // 10 + 0.5 * (90 - 10) = 50
        $this->assertSame(50.0, $this->engine->apply($rubric, $scores)->scaledScore);
    }

    public function test_rescoring_reuses_the_stored_raw_scores(): void
    {
        $original = $this->engine->apply($this->rubric(), [
            'pronunciation' => 90,
            'fluency' => 50,
            'vocabulary' => 70,
            'grammar' => 70,
            'content' => 70,
        ]);

        $reweighted = $this->rubric([
            ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 10],
            ['key' => 'fluency', 'label' => 'Fluency', 'weight' => 60],
            ['key' => 'vocabulary', 'label' => 'Vocabulary', 'weight' => 10],
            ['key' => 'grammar', 'label' => 'Grammar', 'weight' => 10],
            ['key' => 'content', 'label' => 'Content', 'weight' => 10],
        ]);

        // 90*.1 + 50*.6 + 70*.3 = 60
        $this->assertSame(60.0, $this->engine->rescore($reweighted, $original)->rawTotal);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $criteria
     */
    private function rubric(?array $criteria = null): AiRubric
    {
        $rubric = new AiRubric;

        $rubric->forceFill([
            'task_key' => 'speaking.read_aloud',
            'criteria' => $criteria ?? [
                ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 30],
                ['key' => 'fluency', 'label' => 'Oral fluency', 'weight' => 25],
                ['key' => 'vocabulary', 'label' => 'Word accuracy', 'weight' => 20],
                ['key' => 'grammar', 'label' => 'Structural fidelity', 'weight' => 15],
                ['key' => 'content', 'label' => 'Coverage', 'weight' => 10],
            ],
            'scale_min' => 0,
            'scale_max' => 90,
            'rounding' => AiRubric::ROUNDING_NEAREST,
        ]);

        return $rubric;
    }
}

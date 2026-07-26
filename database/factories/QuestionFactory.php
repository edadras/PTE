<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Learning\Enums\Difficulty;
use App\Domain\Learning\Enums\QuestionStatus;
use App\Domain\Learning\Enums\QuestionType;
use App\Domain\Learning\Models\Question;
use App\Domain\Learning\Models\QuestionBank;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Produces questions whose content actually satisfies QuestionContentValidator,
 * so a factory-built question can be practised and scored in a feature test.
 *
 * @extends Factory<Question>
 */
final class QuestionFactory extends Factory
{
    protected $model = Question::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = QuestionType::WriteFromDictation;
        $difficulty = Difficulty::Medium;

        return [
            'bank_id' => QuestionBank::factory(),
            'module_key' => $type->module(),
            'type' => $type,
            'difficulty' => $difficulty,
            'difficulty_index' => $difficulty->defaultIndex(),
            'title' => null,
            'content' => self::contentFor($type),
            'correct_answer' => null,
            'metadata' => null,
            'tags' => [],
            'status' => QuestionStatus::Draft,
            'usage_count' => 0,
            'avg_score' => null,
        ];
    }

    public function ofType(QuestionType $type): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'module_key' => $type->module(),
            'content' => self::contentFor($type),
        ]);
    }

    public function difficulty(Difficulty $difficulty): self
    {
        return $this->state(fn (array $attributes): array => [
            'difficulty' => $difficulty,
            'difficulty_index' => $difficulty->defaultIndex(),
        ]);
    }

    public function withDifficultyIndex(float $index): self
    {
        return $this->state(fn (array $attributes): array => ['difficulty_index' => $index]);
    }

    /** Published *and* approved — the only combination QuestionSelector serves. */
    public function published(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => QuestionStatus::Published,
            'approved_at' => Carbon::now(),
            'published_at' => Carbon::now(),
        ]);
    }

    public function pendingReview(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => QuestionStatus::PendingReview]);
    }

    public function inBank(QuestionBank $bank): self
    {
        return $this->state(fn (array $attributes): array => ['bank_id' => $bank->getKey()]);
    }

    /**
     * Minimal content that passes validation for every type.
     *
     * @return array<string, mixed>
     */
    public static function contentFor(QuestionType $type): array
    {
        return match ($type) {
            QuestionType::ReadAloud => [
                'text' => 'The rapid growth of urban populations has placed unprecedented pressure on city infrastructure worldwide.',
                'prep_seconds' => 40,
                'record_seconds' => 40,
            ],
            QuestionType::RepeatSentence => [
                'audio_key' => 'questions/sample/repeat-sentence.mp3',
                'transcript' => 'The seminar has been moved to the lecture theatre on the second floor.',
                'record_seconds' => 15,
            ],
            QuestionType::DescribeImage => [
                'image_key' => 'questions/sample/bar-chart.png',
                'prep_seconds' => 25,
                'record_seconds' => 40,
                'key_points' => ['overall trend', 'highest value', 'comparison'],
            ],
            QuestionType::RetellLecture => [
                'audio_key' => 'questions/sample/lecture.mp3',
                'transcript' => 'Today we will look at how coastal erosion reshapes shorelines over decades, and at the engineering responses that have been tried.',
                'prep_seconds' => 10,
                'record_seconds' => 40,
            ],
            QuestionType::AnswerShortQuestion => [
                'audio_key' => 'questions/sample/asq.mp3',
                'transcript' => 'What do we call the frozen form of water?',
                'accepted_answers' => ['ice'],
                'record_seconds' => 10,
            ],
            QuestionType::SummarizeSpokenText => [
                'audio_key' => 'questions/sample/sst.mp3',
                'transcript' => 'The lecturer explains that bees navigate using polarised light, that this ability degrades in cloudy conditions, and that researchers have replicated the mechanism in small autonomous drones.',
                'min_words' => 50,
                'max_words' => 70,
                'duration_minutes' => 10,
            ],
            QuestionType::WriteFromDictation => [
                'audio_key' => 'questions/sample/wfd.mp3',
                'transcript' => 'The university library will be closed on Monday.',
                'play_count' => 1,
            ],
            QuestionType::MultipleChoiceListening => [
                'prompt' => 'What is the main purpose of the talk?',
                'audio_key' => 'questions/sample/mcq-l.mp3',
                'multiple' => false,
            ],
            QuestionType::MultipleChoiceReading => [
                'prompt' => 'What is the main idea of the passage?',
                'passage' => 'Coral reefs occupy less than one per cent of the ocean floor, yet they shelter roughly a quarter of all marine species. Their decline therefore carries consequences far beyond the reefs themselves.',
                'multiple' => false,
            ],
            QuestionType::HighlightIncorrectWords => [
                'audio_key' => 'questions/sample/hiw.mp3',
                'display_text' => 'The committee agreed that the new policy would be reviewed after twelve months of operation in the northern region.',
                'spoken_text' => 'The committee agreed that the new policy would be reviewed after twelve weeks of operation in the northern region.',
                'errors' => [
                    ['index' => 12, 'shown' => 'months', 'spoken' => 'weeks'],
                ],
                'play_count' => 1,
            ],
            QuestionType::FillInBlanksListening => [
                'audio_key' => 'questions/sample/fib-l.mp3',
                'passage' => 'Researchers have found that regular {{1}} improves memory retention in adults of every {{2}} group they studied.',
                'blanks' => [
                    ['position' => 1, 'answers' => ['exercise'], 'options' => []],
                    ['position' => 2, 'answers' => ['age'], 'options' => []],
                ],
                'input_mode' => 'text',
            ],
            QuestionType::FillInBlanksReading => [
                'passage' => 'The industrial revolution {{1}} the way goods were produced and, in doing so, permanently {{2}} the structure of European society.',
                'blanks' => [
                    ['position' => 1, 'answers' => ['transformed'], 'options' => ['transformed', 'transported', 'translated', 'transferred']],
                    ['position' => 2, 'answers' => ['altered'], 'options' => ['altered', 'alerted', 'allotted', 'allowed']],
                ],
                'input_mode' => 'dropdown',
            ],
            QuestionType::FillInBlanksReadingWriting => [
                'passage' => 'Scientists remain {{1}} about the long-term effects, largely because the available data {{2}} only two decades.',
                'blanks' => [
                    ['position' => 1, 'answers' => ['cautious'], 'options' => ['cautious', 'careless', 'curious', 'certain']],
                    ['position' => 2, 'answers' => ['spans'], 'options' => ['spans', 'spends', 'spares', 'sparks']],
                ],
                'input_mode' => 'drag',
            ],
            QuestionType::SelectMissingWord => [
                'audio_key' => 'questions/sample/smw.mp3',
                'transcript' => 'The speaker argues that the most reliable indicator of long-term academic success is not raw ability but ...',
            ],
            QuestionType::ReorderParagraphs => [
                'paragraphs' => [
                    ['key' => 'A', 'text' => 'Its leaves fold inward within seconds of being touched.'],
                    ['key' => 'B', 'text' => 'The sensitive plant is native to Central and South America.'],
                    ['key' => 'C', 'text' => 'Botanists believe this reflex evolved to deter grazing insects.'],
                ],
                'correct_order' => ['B', 'A', 'C'],
            ],
            QuestionType::SummarizeWrittenText => [
                'passage' => 'Urban planners increasingly treat trees as infrastructure rather than decoration. A mature street tree lowers surface temperatures, absorbs storm water that would otherwise overwhelm drains, and measurably reduces respiratory illness in the surrounding blocks. Cities that budget for canopy maintenance therefore recover the cost several times over in avoided health and drainage spending.',
                'min_words' => 5,
                'max_words' => 75,
                'duration_minutes' => 10,
            ],
            QuestionType::Essay => [
                'prompt' => 'Some people believe that university education should be free for all citizens. Others argue that students should contribute to the cost. Discuss both views and give your own opinion.',
                'min_words' => 200,
                'max_words' => 300,
                'duration_minutes' => 20,
            ],
        };
    }
}

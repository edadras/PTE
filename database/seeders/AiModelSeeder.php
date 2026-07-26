<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiModel;
use Illuminate\Database\Seeder;

/**
 * The catalogue academies choose from.
 *
 * Prices are list price per one million tokens in USD (per audio minute for the
 * ASR rows) and are the single input to every cost figure in the product, so
 * they are kept honest here rather than approximated in code. Re-running the
 * seeder updates prices in place without touching anyone's model selection.
 */
final class AiModelSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $index => $model) {
            AiModel::query()->updateOrCreate(
                ['model_key' => $model['model_key']],
                $model + ['sort_order' => $index * 10],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function catalogue(): array
    {
        return [
            [
                'provider' => AiProvider::Gemini->value,
                'model_key' => 'gemini-2.5-pro',
                'display_name' => 'Gemini 2.5 Pro',
                'capabilities' => ['json_mode' => true, 'vision' => true, 'audio' => true, 'reasoning' => true],
                'input_price_per_1m' => 1.25,
                'output_price_per_1m' => 10.00,
                'max_input_tokens' => 1_048_576,
                'max_output_tokens' => 65_536,
                'is_active' => true,
                'is_default_for' => [
                    AiTaskKey::SpeakingReadAloud->value,
                    AiTaskKey::SpeakingRepeatSentence->value,
                    AiTaskKey::SpeakingDescribeImage->value,
                    AiTaskKey::SpeakingRetellLecture->value,
                    AiTaskKey::ListeningSummarizeSpoken->value,
                    AiTaskKey::FeedbackOverall->value,
                ],
            ],
            [
                'provider' => AiProvider::Gemini->value,
                'model_key' => 'gemini-2.5-flash',
                'display_name' => 'Gemini 2.5 Flash',
                'capabilities' => ['json_mode' => true, 'vision' => true, 'audio' => true],
                'input_price_per_1m' => 0.30,
                'output_price_per_1m' => 2.50,
                'max_input_tokens' => 1_048_576,
                'max_output_tokens' => 65_536,
                'is_active' => true,
                'is_default_for' => [
                    AiTaskKey::GrammarCheck->value,
                    AiTaskKey::VocabularyExplain->value,
                    AiTaskKey::ReportWeeklySummary->value,
                ],
            ],
            [
                // The economy-mode target: objective metrics are computed in PHP,
                // so practice scoring does not need a frontier model (docs/06 §7.3).
                'provider' => AiProvider::Gemini->value,
                'model_key' => 'gemini-2.5-flash-lite',
                'display_name' => 'Gemini 2.5 Flash-Lite',
                'capabilities' => ['json_mode' => true, 'vision' => true],
                'input_price_per_1m' => 0.10,
                'output_price_per_1m' => 0.40,
                'max_input_tokens' => 1_048_576,
                'max_output_tokens' => 65_536,
                'is_active' => true,
                'is_default_for' => [],
            ],
            [
                'provider' => AiProvider::OpenAi->value,
                'model_key' => 'gpt-5',
                'display_name' => 'GPT-5',
                'capabilities' => ['json_mode' => true, 'vision' => true, 'reasoning' => true],
                'input_price_per_1m' => 1.25,
                'output_price_per_1m' => 10.00,
                'max_input_tokens' => 400_000,
                'max_output_tokens' => 128_000,
                'is_active' => true,
                'is_default_for' => [
                    AiTaskKey::WritingEssay->value,
                    AiTaskKey::WritingSummarizeText->value,
                ],
            ],
            [
                'provider' => AiProvider::OpenAi->value,
                'model_key' => 'gpt-5-mini',
                'display_name' => 'GPT-5 mini',
                'capabilities' => ['json_mode' => true, 'vision' => true],
                'input_price_per_1m' => 0.25,
                'output_price_per_1m' => 2.00,
                'max_input_tokens' => 400_000,
                'max_output_tokens' => 128_000,
                'is_active' => true,
                'is_default_for' => [],
            ],
            [
                'provider' => AiProvider::Anthropic->value,
                'model_key' => 'claude-sonnet-4-5',
                'display_name' => 'Claude Sonnet 4.5',
                'capabilities' => ['json_mode' => true, 'vision' => true, 'tools' => true],
                'input_price_per_1m' => 3.00,
                'output_price_per_1m' => 15.00,
                'max_input_tokens' => 200_000,
                'max_output_tokens' => 64_000,
                'is_active' => true,
                'is_default_for' => [AiTaskKey::ChatAssistant->value],
            ],
            [
                'provider' => AiProvider::Whisper->value,
                'model_key' => 'whisper-large-v3',
                'display_name' => 'Whisper large-v3',
                'capabilities' => ['transcription' => true, 'word_timestamps' => true],
                'input_price_per_1m' => 0,
                'output_price_per_1m' => 0,
                'price_per_audio_minute' => 0.006,
                'is_active' => true,
                'is_default_for' => [AiTaskKey::Transcription->value],
            ],
            [
                'provider' => AiProvider::GoogleStt->value,
                'model_key' => 'google-stt-latest-short',
                'display_name' => 'Google Speech-to-Text (short)',
                'capabilities' => ['transcription' => true, 'word_timestamps' => true, 'word_confidence' => true],
                'input_price_per_1m' => 0,
                'output_price_per_1m' => 0,
                'price_per_audio_minute' => 0.016,
                'is_active' => true,
                'is_default_for' => [],
            ],
        ];
    }
}

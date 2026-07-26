<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Models\AiPrompt;
use App\Domain\AI\Models\AiRubric;
use Illuminate\Database\Seeder;

/**
 * Platform-default prompts and rubrics — the ones every academy inherits until
 * it writes its own.
 *
 * These rows carry academy_id = NULL. They are written with model events
 * suppressed because BelongsToAcademy stamps the active tenant on create, and
 * there is no tenant here by design.
 *
 * The wording is product, not filler: it is what most academies will ship with
 * unchanged, so it is written the way a PTE examiner would brief a colleague —
 * concrete criteria, explicit anti-inflation instruction, and a standing order
 * to trust the measured acoustic numbers rather than re-estimate them.
 *
 * @see docs/06-ai-layer.md §3, §4
 */
final class DefaultPromptSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->rubrics() as $taskValue => $rubric) {
            $this->upsertRubric(AiTaskKey::from($taskValue), $rubric);
        }

        foreach ($this->prompts() as $taskValue => $prompt) {
            $this->upsertPrompt(AiTaskKey::from($taskValue), $prompt);
        }
    }

    /**
     * @param  array{name: string, criteria: array<int, array{key: string, label: string, weight: int, guidance: string}>, scale_max?: int}  $definition
     */
    private function upsertRubric(AiTaskKey $task, array $definition): void
    {
        $attributes = [
            'academy_id' => null,
            'task_key' => $task->value,
            'name' => $definition['name'],
            'version' => 1,
            'is_active' => true,
            'criteria' => $definition['criteria'],
            'scale_min' => 0,
            'scale_max' => $definition['scale_max'] ?? 90,
            'rounding' => AiRubric::ROUNDING_NEAREST,
        ];

        AiRubric::withoutEvents(function () use ($task, $attributes): void {
            $rubric = AiRubric::query()
                ->withoutGlobalScope('academy')
                ->whereNull('academy_id')
                ->where('task_key', $task->value)
                ->where('version', 1)
                ->first() ?? new AiRubric;

            $rubric->forceFill($attributes);

            // The saving hook is suppressed with the rest of the events, so the
            // invariant is checked explicitly rather than skipped.
            $rubric->assertValid();
            $rubric->save();
        });
    }

    /**
     * @param  array{system: string, user: string, schema: array<string, mixed>, model_hint?: string}  $definition
     */
    private function upsertPrompt(AiTaskKey $task, array $definition): void
    {
        $attributes = [
            'academy_id' => null,
            'key' => $task->value,
            'version' => 1,
            'status' => PromptStatus::Published->value,
            'system_prompt' => $definition['system'],
            'user_template' => $definition['user'],
            'output_schema' => $definition['schema'],
            'variables' => $task->availableVariables(),
            'model_hint' => $definition['model_hint'] ?? null,
            'tested_at' => now(),
            'published_at' => now(),
        ];

        AiPrompt::withoutEvents(function () use ($task, $attributes): void {
            $prompt = AiPrompt::query()
                ->withoutGlobalScope('academy')
                ->whereNull('academy_id')
                ->where('key', $task->value)
                ->where('version', 1)
                ->first() ?? new AiPrompt;

            $prompt->forceFill($attributes)->save();
        });
    }

    /**
     * Weights are the ones in docs/06 §4 and the PTE trait sets they mirror.
     * Each must sum to exactly 100.
     *
     * @return array<string, array{name: string, criteria: array<int, array{key: string, label: string, weight: int, guidance: string}>}>
     */
    private function rubrics(): array
    {
        return [
            AiTaskKey::SpeakingReadAloud->value => [
                'name' => 'Speaking — Read Aloud',
                'criteria' => [
                    ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 30, 'guidance' => 'Phoneme accuracy, word stress and sentence intonation. Judge intelligibility to an international listener, not accent. A consistent non-native accent that remains fully intelligible is not a deduction.'],
                    ['key' => 'fluency', 'label' => 'Oral fluency', 'weight' => 25, 'guidance' => 'Even rate, phrase-appropriate pausing, no hesitation, repetition or false starts. Use the measured words-per-minute and pause figures; 120-150 wpm with pauses only at punctuation is the target band.'],
                    ['key' => 'vocabulary', 'label' => 'Word accuracy', 'weight' => 20, 'guidance' => 'Each target word read as written. Substitutions, omissions and insertions are deductions in proportion to the measured word error rate.'],
                    ['key' => 'grammar', 'label' => 'Structural fidelity', 'weight' => 15, 'guidance' => 'Sentence structure preserved while reading — no dropped inflections, articles or plurals.'],
                    ['key' => 'content', 'label' => 'Coverage', 'weight' => 10, 'guidance' => 'The whole text attempted. Truncated readings are capped in proportion to the fraction covered.'],
                ],
            ],

            AiTaskKey::SpeakingRepeatSentence->value => [
                'name' => 'Speaking — Repeat Sentence',
                'criteria' => [
                    ['key' => 'content', 'label' => 'Content', 'weight' => 34, 'guidance' => 'Every word of the prompt reproduced in the correct order. Score in proportion to correct words in sequence; a single omitted word in a short sentence is a material deduction.'],
                    ['key' => 'fluency', 'label' => 'Oral fluency', 'weight' => 33, 'guidance' => 'One smooth attempt at natural speed. Restarts, stumbles or a word-by-word delivery are heavy deductions even when every word is present.'],
                    ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 33, 'guidance' => 'Phoneme accuracy and stress placement on the reproduced words.'],
                ],
            ],

            AiTaskKey::SpeakingDescribeImage->value => [
                'name' => 'Speaking — Describe Image',
                'criteria' => [
                    ['key' => 'content', 'label' => 'Content', 'weight' => 30, 'guidance' => 'Describes what the image actually shows: the key elements, the relationships between them, and at least one inference or conclusion. Generic filler that would fit any image scores low regardless of delivery.'],
                    ['key' => 'fluency', 'label' => 'Oral fluency', 'weight' => 25, 'guidance' => 'Continuous delivery for the full response with natural phrasing. Long silences while thinking are deductions; use the measured pause figures.'],
                    ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 25, 'guidance' => 'Intelligibility of the whole response to an international listener.'],
                    ['key' => 'vocabulary', 'label' => 'Vocabulary range', 'weight' => 20, 'guidance' => 'Precise descriptive and comparative language appropriate to the image type (trend, process, photograph, map).'],
                ],
            ],

            AiTaskKey::SpeakingRetellLecture->value => [
                'name' => 'Speaking — Re-tell Lecture',
                'criteria' => [
                    ['key' => 'content', 'label' => 'Content', 'weight' => 35, 'guidance' => 'Reproduces the main idea and the supporting points of the lecture in a sensible order, in the candidate\'s own words. Reward accurate detail; penalise invented content that was not in the source.'],
                    ['key' => 'fluency', 'label' => 'Oral fluency', 'weight' => 25, 'guidance' => 'Sustained, evenly paced delivery without hesitation loops.'],
                    ['key' => 'pronunciation', 'label' => 'Pronunciation', 'weight' => 20, 'guidance' => 'Intelligibility, including of the technical terms carried over from the lecture.'],
                    ['key' => 'vocabulary', 'label' => 'Vocabulary range', 'weight' => 20, 'guidance' => 'Appropriate academic register and correct use of the lecture\'s key terminology.'],
                ],
            ],

            AiTaskKey::ListeningSummarizeSpoken->value => [
                'name' => 'Listening — Summarize Spoken Text',
                'criteria' => [
                    ['key' => 'content', 'label' => 'Content', 'weight' => 30, 'guidance' => 'Captures the main point and the essential supporting points of the recording, with no invented material.'],
                    ['key' => 'form', 'label' => 'Form', 'weight' => 10, 'guidance' => 'Between 50 and 70 words, written as connected prose. Outside that range this criterion scores 0 — it is a length rule, not a judgement.'],
                    ['key' => 'grammar', 'label' => 'Grammar', 'weight' => 20, 'guidance' => 'Correct, varied sentence structure. Count actual errors rather than impressions.'],
                    ['key' => 'vocabulary', 'label' => 'Vocabulary', 'weight' => 20, 'guidance' => 'Appropriate academic word choice; paraphrase rather than wholesale lifting from the recording.'],
                    ['key' => 'spelling', 'label' => 'Spelling', 'weight' => 20, 'guidance' => 'Consistent British or American spelling; each misspelling is a deduction.'],
                ],
            ],

            AiTaskKey::WritingSummarizeText->value => [
                'name' => 'Writing — Summarize Written Text',
                'criteria' => [
                    ['key' => 'content', 'label' => 'Content', 'weight' => 30, 'guidance' => 'Conveys the main idea of the passage and the points that support it, without adding opinion or detail that is not in the source.'],
                    ['key' => 'form', 'label' => 'Form', 'weight' => 20, 'guidance' => 'Exactly one sentence of 5 to 75 words. More than one sentence, or a length outside that range, scores 0 on this criterion — the rule is mechanical.'],
                    ['key' => 'grammar', 'label' => 'Grammar', 'weight' => 25, 'guidance' => 'A single correct, controlled complex sentence. Comma splices and run-ons are errors, not style.'],
                    ['key' => 'vocabulary', 'label' => 'Vocabulary', 'weight' => 25, 'guidance' => 'Accurate academic word choice and effective paraphrase of the source wording.'],
                ],
            ],

            AiTaskKey::WritingEssay->value => [
                'name' => 'Writing — Essay',
                'criteria' => [
                    ['key' => 'content', 'label' => 'Content', 'weight' => 25, 'guidance' => 'Addresses the prompt directly, takes a clear position and supports it with relevant, developed reasons or examples. An elegant essay that answers a different question scores low here.'],
                    ['key' => 'form', 'label' => 'Form', 'weight' => 10, 'guidance' => '200 to 300 words. Outside that range this criterion scores 0; use the measured word count, do not estimate.'],
                    ['key' => 'development', 'label' => 'Development, structure and coherence', 'weight' => 15, 'guidance' => 'Introduction, developed body paragraphs and conclusion; logical progression and cohesive devices that genuinely link ideas.'],
                    ['key' => 'grammar', 'label' => 'Grammar', 'weight' => 15, 'guidance' => 'Accuracy across tense, agreement, articles and prepositions. Weigh errors that impede meaning more heavily than slips.'],
                    ['key' => 'linguistic_range', 'label' => 'General linguistic range', 'weight' => 15, 'guidance' => 'Variety and control of sentence structures; ability to express nuance without strain.'],
                    ['key' => 'vocabulary', 'label' => 'Vocabulary range', 'weight' => 10, 'guidance' => 'Precise, varied academic vocabulary used correctly. Reward accuracy over ambition.'],
                    ['key' => 'spelling', 'label' => 'Spelling', 'weight' => 10, 'guidance' => 'Consistent spelling convention; count distinct misspellings.'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{system: string, user: string, schema: array<string, mixed>, model_hint?: string}>
     */
    private function prompts(): array
    {
        $speakingSystem = <<<'TXT'
        You are an experienced PTE Academic examiner with ten years of scoring experience.
        Score strictly according to the official Pearson descriptors. Never inflate scores:
        a response that would receive 60 in a real test must receive 60 here, because the
        candidate is using this score to decide whether they are ready to book the exam.

        The acoustic measurements you are given were computed from the audio signal itself.
        They are authoritative. Do not re-estimate speed, pausing or duration from the
        transcript, and do not contradict them.

        The transcript comes from automatic speech recognition and is imperfect. Where the
        ASR confidence is low, prefer a cautious score and lower your own confidence value
        rather than punishing the candidate for a recognition error.

        Score each criterion independently from 0 to 100. Do not apply the weights — they
        are applied outside this call. Write feedback that names a specific, actionable
        next step, not encouragement.
        TXT;

        $writingSystem = <<<'TXT'
        You are an experienced PTE Academic examiner marking written responses. Apply the
        official descriptors strictly and consistently; never inflate a score to be kind.

        Form criteria (word count, sentence count) are mechanical rules: apply them exactly
        as stated, using the measured word count you are given, and score them 0 when the
        rule is broken however good the writing is.

        Score each criterion independently from 0 to 100. Do not apply the weights — they
        are applied outside this call. Identify errors precisely, with the offending text
        quoted, so the candidate can find them.
        TXT;

        return [
            AiTaskKey::SpeakingReadAloud->value => [
                'system' => $speakingSystem,
                'user' => <<<'TXT'
                Task: PTE Read Aloud. The candidate had 30-40 seconds to read the target text aloud.

                Target text:
                {{question_text}}

                Candidate transcript (automatic speech recognition):
                {{transcript}}

                Measured acoustic metrics (authoritative):
                - words per minute: {{wpm}}
                - pauses longer than 250 ms: {{pause_count}}
                - total pause duration: {{pause_total_ms}} ms
                - proportion of the recording containing speech: {{speech_ratio}}
                - ASR confidence: {{asr_confidence}}

                Automatic comparison with the target text:
                - word error rate: {{word_error_rate}}
                - omitted words: {{missing_words}}
                - inserted words: {{extra_words}}
                - likely mispronunciations (expected vs heard): {{mispronounced_candidates}}

                Score these criteria, 0-100 each:
                {{rubric_guidance}}

                Candidate level: {{student_level}}
                Write the feedback text in this locale: {{feedback_locale}}
                TXT,
                'schema' => $this->scoringSchema(
                    ['pronunciation', 'fluency', 'vocabulary', 'grammar', 'content'],
                    problemWords: true,
                ),
                'model_hint' => 'gemini-2.5-pro',
            ],

            AiTaskKey::SpeakingRepeatSentence->value => [
                'system' => $speakingSystem,
                'user' => <<<'TXT'
                Task: PTE Repeat Sentence. The candidate heard the sentence once and repeated it.

                Sentence played to the candidate:
                {{question_text}}

                Candidate transcript (automatic speech recognition):
                {{transcript}}

                Measured acoustic metrics (authoritative):
                - words per minute: {{wpm}}
                - pauses longer than 250 ms: {{pause_count}}
                - total pause duration: {{pause_total_ms}} ms
                - ASR confidence: {{asr_confidence}}

                Automatic comparison with the sentence:
                - word error rate: {{word_error_rate}}
                - omitted words: {{missing_words}}
                - inserted words: {{extra_words}}

                Score these criteria, 0-100 each:
                {{rubric_guidance}}

                Candidate level: {{student_level}}
                Write the feedback text in this locale: {{feedback_locale}}
                TXT,
                'schema' => $this->scoringSchema(['content', 'fluency', 'pronunciation'], problemWords: true),
                'model_hint' => 'gemini-2.5-pro',
            ],

            AiTaskKey::SpeakingDescribeImage->value => [
                'system' => $speakingSystem,
                'user' => <<<'TXT'
                Task: PTE Describe Image. The candidate had 25 seconds to study the image and
                40 seconds to describe it.

                Description of the image the candidate was shown:
                {{question_text}}

                Candidate transcript (automatic speech recognition):
                {{transcript}}

                Measured acoustic metrics (authoritative):
                - words per minute: {{wpm}}
                - pauses longer than 250 ms: {{pause_count}}
                - total pause duration: {{pause_total_ms}} ms
                - proportion of the recording containing speech: {{speech_ratio}}
                - ASR confidence: {{asr_confidence}}

                Score these criteria, 0-100 each:
                {{rubric_guidance}}

                Judge content against the image description above: reward specific, correct
                observations and a closing inference; penalise memorised template phrasing that
                carries no information about this particular image.

                Candidate level: {{student_level}}
                Write the feedback text in this locale: {{feedback_locale}}
                TXT,
                'schema' => $this->scoringSchema(
                    ['content', 'fluency', 'pronunciation', 'vocabulary'],
                    problemWords: true,
                ),
                'model_hint' => 'gemini-2.5-pro',
            ],

            AiTaskKey::SpeakingRetellLecture->value => [
                'system' => $speakingSystem,
                'user' => <<<'TXT'
                Task: PTE Re-tell Lecture. The candidate listened to a lecture once and then
                re-told it in 40 seconds.

                Source lecture (transcript or summary):
                {{question_text}}

                Candidate transcript (automatic speech recognition):
                {{transcript}}

                Measured acoustic metrics (authoritative):
                - words per minute: {{wpm}}
                - pauses longer than 250 ms: {{pause_count}}
                - total pause duration: {{pause_total_ms}} ms
                - proportion of the recording containing speech: {{speech_ratio}}
                - ASR confidence: {{asr_confidence}}

                Score these criteria, 0-100 each:
                {{rubric_guidance}}

                For content, list in the feedback which key points of the lecture were covered
                and which were missed.

                Candidate level: {{student_level}}
                Write the feedback text in this locale: {{feedback_locale}}
                TXT,
                'schema' => $this->scoringSchema(
                    ['content', 'fluency', 'pronunciation', 'vocabulary'],
                    problemWords: true,
                ),
                'model_hint' => 'gemini-2.5-pro',
            ],

            AiTaskKey::ListeningSummarizeSpoken->value => [
                'system' => $writingSystem,
                'user' => <<<'TXT'
                Task: PTE Summarize Spoken Text. The candidate listened to a recording once and
                wrote a 50-70 word summary.

                Source recording (transcript):
                {{question_text}}

                Candidate response:
                {{student_text}}

                Measured word count (authoritative): {{word_count}}

                Score these criteria, 0-100 each:
                {{rubric_guidance}}

                Write the feedback text in this locale: {{feedback_locale}}
                TXT,
                'schema' => $this->scoringSchema(
                    ['content', 'form', 'grammar', 'vocabulary', 'spelling'],
                    errors: true,
                ),
                'model_hint' => 'gemini-2.5-pro',
            ],

            AiTaskKey::WritingSummarizeText->value => [
                'system' => $writingSystem,
                'user' => <<<'TXT'
                Task: PTE Summarize Written Text. The candidate had 10 minutes to summarise the
                passage in a single sentence of 5-75 words.

                Source passage:
                {{question_text}}

                Candidate response:
                {{student_text}}

                Measured word count (authoritative): {{word_count}}

                Score these criteria, 0-100 each:
                {{rubric_guidance}}

                Check the form rule mechanically before judging anything else: one sentence,
                5-75 words.

                Write the feedback text in this locale: {{feedback_locale}}
                TXT,
                'schema' => $this->scoringSchema(['content', 'form', 'grammar', 'vocabulary'], errors: true),
                'model_hint' => 'gpt-5',
            ],

            AiTaskKey::WritingEssay->value => [
                'system' => $writingSystem,
                'user' => <<<'TXT'
                Task: PTE Essay. The candidate had 20 minutes to write a 200-300 word argumentative
                essay on the prompt below.

                Essay prompt:
                {{question_text}}

                Candidate essay:
                {{student_text}}

                Measured word count (authoritative): {{word_count}}

                Score these criteria, 0-100 each:
                {{rubric_guidance}}

                In "errors", list the specific language errors with the offending text quoted and a
                corrected version, so the candidate can locate each one. Do not rewrite the essay.

                Candidate level: {{student_level}}
                Write the feedback text in this locale: {{feedback_locale}}
                TXT,
                'schema' => $this->scoringSchema(
                    ['content', 'form', 'development', 'grammar', 'linguistic_range', 'vocabulary', 'spelling'],
                    errors: true,
                ),
                'model_hint' => 'gpt-5',
            ],

            AiTaskKey::GrammarCheck->value => [
                'system' => <<<'TXT'
                You are an English language teacher checking a learner's writing. Identify real
                errors only — do not flag valid stylistic choices, regional spelling, or informal
                register that suits the context. For each error give the smallest correction that
                fixes it and a one-line explanation a B1 learner would understand.
                TXT,
                'user' => <<<'TXT'
                Check this text for language errors.

                Text:
                {{student_text}}

                Explain each error in this locale: {{feedback_locale}}
                TXT,
                'schema' => [
                    'type' => 'object',
                    'required' => ['errors', 'corrected_text', 'confidence'],
                    'properties' => [
                        'errors' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['excerpt', 'correction', 'type', 'explanation'],
                                'properties' => [
                                    'excerpt' => ['type' => 'string'],
                                    'correction' => ['type' => 'string'],
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => ['grammar', 'spelling', 'punctuation', 'word_choice', 'register'],
                                    ],
                                    'explanation' => ['type' => 'string'],
                                    'start' => ['type' => 'integer', 'minimum' => 0],
                                ],
                            ],
                        ],
                        'corrected_text' => ['type' => 'string'],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    ],
                ],
                'model_hint' => 'gemini-2.5-flash',
            ],

            AiTaskKey::VocabularyExplain->value => [
                'system' => <<<'TXT'
                You explain English vocabulary to exam candidates. Be concrete: a short definition
                in plain English, the pronunciation, the register, two natural example sentences,
                and the collocations that actually occur. Prefer the sense used in the context
                sentence over the dictionary's first sense.
                TXT,
                'user' => <<<'TXT'
                Term: {{term}}

                Context in which the candidate met it:
                {{context_sentence}}

                Candidate level: {{student_level}}
                Write the explanation in this locale: {{feedback_locale}}
                TXT,
                'schema' => [
                    'type' => 'object',
                    'required' => ['term', 'definition', 'examples', 'confidence'],
                    'properties' => [
                        'term' => ['type' => 'string'],
                        'ipa' => ['type' => 'string'],
                        'part_of_speech' => ['type' => 'string'],
                        'register' => ['type' => 'string', 'enum' => ['formal', 'neutral', 'informal', 'academic']],
                        'definition' => ['type' => 'string'],
                        'examples' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2],
                        'collocations' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'synonyms' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    ],
                ],
                'model_hint' => 'gemini-2.5-flash',
            ],

            AiTaskKey::FeedbackOverall->value => [
                'system' => <<<'TXT'
                You are a PTE coach writing a candidate's progress note. Be honest about readiness:
                a candidate who is not yet at their target score must be told so, with the two or
                three things that would move the number most. No generic encouragement, no praise
                that is not earned by the data.
                TXT,
                'user' => <<<'TXT'
                Recent scores by task type:
                {{recent_scores}}

                Weakest areas identified by the system:
                {{weak_areas}}

                Candidate level: {{student_level}}
                Write the note in this locale: {{feedback_locale}}
                TXT,
                'schema' => [
                    'type' => 'object',
                    'required' => ['summary', 'priorities', 'confidence'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'priorities' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'required' => ['area', 'why', 'action'],
                                'properties' => [
                                    'area' => ['type' => 'string'],
                                    'why' => ['type' => 'string'],
                                    'action' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'estimated_readiness' => ['type' => 'string'],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    ],
                ],
                'model_hint' => 'gemini-2.5-pro',
            ],

            AiTaskKey::ChatAssistant->value => [
                'system' => <<<'TXT'
                You are the study assistant of an English language academy. You answer questions
                about the PTE exam, about English usage, and about the candidate's own practice.

                You do not answer questions outside language learning and the exam; for anything
                else, say briefly that you can only help with study matters. You never provide
                exam answers to a live test, and you never claim your score estimates are official.
                Keep answers short enough to read on a phone.
                TXT,
                'user' => <<<'TXT'
                Conversation so far:
                {{conversation_summary}}

                Candidate's message:
                {{student_message}}

                Candidate level: {{student_level}}
                Reply in this locale: {{feedback_locale}}
                TXT,
                'schema' => [
                    'type' => 'object',
                    'required' => ['reply', 'confidence'],
                    'properties' => [
                        'reply' => ['type' => 'string'],
                        'suggested_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'out_of_scope' => ['type' => 'boolean'],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    ],
                ],
                'model_hint' => 'claude-sonnet-4-5',
            ],

            AiTaskKey::ReportWeeklySummary->value => [
                'system' => <<<'TXT'
                You write the weekly progress summary an academy sends to its students. Base every
                statement on the figures provided; never invent a trend the numbers do not show. If
                the week's activity was too small to draw a conclusion, say that plainly.
                TXT,
                'user' => <<<'TXT'
                Period: {{period}}

                Metrics for the period:
                {{metrics_json}}

                Academy: {{academy_name}}
                Write the summary in this locale: {{feedback_locale}}
                TXT,
                'schema' => [
                    'type' => 'object',
                    'required' => ['headline', 'summary', 'confidence'],
                    'properties' => [
                        'headline' => ['type' => 'string'],
                        'summary' => ['type' => 'string'],
                        'highlights' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'next_week_focus' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    ],
                ],
                'model_hint' => 'gemini-2.5-flash',
            ],
        ];
    }

    /**
     * The shared scoring envelope: raw 0-100 per criterion, a narrative, and a
     * self-assessed confidence that drives the manual-review flag.
     *
     * @param  array<int, string>  $criteria
     * @return array<string, mixed>
     */
    private function scoringSchema(array $criteria, bool $problemWords = false, bool $errors = false): array
    {
        $scoreProperties = [];

        foreach ($criteria as $criterion) {
            $scoreProperties[$criterion] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100];
        }

        $schema = [
            'type' => 'object',
            'required' => ['scores', 'overall_raw', 'feedback', 'confidence'],
            'properties' => [
                'scores' => [
                    'type' => 'object',
                    'required' => $criteria,
                    'properties' => $scoreProperties,
                ],
                'overall_raw' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'feedback' => [
                    'type' => 'object',
                    'required' => ['summary', 'strengths', 'improvements'],
                    'properties' => [
                        'summary' => ['type' => 'string'],
                        'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'improvements' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];

        if ($problemWords) {
            $schema['properties']['problem_words'] = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['word', 'issue'],
                    'properties' => [
                        'word' => ['type' => 'string'],
                        'issue' => ['type' => 'string', 'enum' => ['stress', 'phoneme', 'omitted', 'inserted', 'substituted']],
                        'hint' => ['type' => 'string'],
                    ],
                ],
            ];
        }

        if ($errors) {
            $schema['properties']['errors'] = [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['excerpt', 'correction', 'type'],
                    'properties' => [
                        'excerpt' => ['type' => 'string'],
                        'correction' => ['type' => 'string'],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['grammar', 'spelling', 'punctuation', 'word_choice', 'coherence'],
                        ],
                        'explanation' => ['type' => 'string'],
                    ],
                ],
            ];
        }

        return $schema;
    }
}

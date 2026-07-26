<?php

declare(strict_types=1);

return [

    /*
    | Task names as an academy sees them in the AI settings and prompt editor.
    | Nested to match the dotted AiTaskKey values.
    */
    'tasks' => [
        'speaking' => [
            'read_aloud' => 'Speaking — Read Aloud',
            'repeat_sentence' => 'Speaking — Repeat Sentence',
            'describe_image' => 'Speaking — Describe Image',
            'retell_lecture' => 'Speaking — Re-tell Lecture',
        ],
        'writing' => [
            'essay' => 'Writing — Essay',
            'summarize_text' => 'Writing — Summarize Written Text',
        ],
        'listening' => [
            'summarize_spoken' => 'Listening — Summarize Spoken Text',
        ],
        'grammar' => [
            'check' => 'Grammar check',
        ],
        'vocabulary' => [
            'explain' => 'Vocabulary explanation',
        ],
        'feedback' => [
            'overall' => 'Overall feedback',
        ],
        'chat' => [
            'assistant' => 'Chat assistant',
        ],
        'report' => [
            'weekly_summary' => 'Weekly summary',
        ],
        'transcription' => 'Speech transcription',
    ],

    'criteria' => [
        'pronunciation' => 'Pronunciation',
        'fluency' => 'Oral fluency',
        'vocabulary' => 'Vocabulary',
        'grammar' => 'Grammar',
        'content' => 'Content',
        'form' => 'Form',
        'spelling' => 'Spelling',
        'development' => 'Development, structure and coherence',
        'linguistic_range' => 'General linguistic range',
    ],

    'providers' => [
        'gemini' => 'Google Gemini',
        'openai' => 'OpenAI',
        'anthropic' => 'Anthropic',
        'whisper' => 'Whisper (speech recognition)',
        'google_stt' => 'Google Speech-to-Text',
    ],

    'prompt_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'request_status' => [
        'success' => 'Success',
        'failed' => 'Failed',
        'timeout' => 'Timed out',
        'rate_limited' => 'Rate limited',
    ],

    /*
    | Student-facing copy. A student never sees a technical error: whatever
    | broke, the message is that the result is on its way (docs/06 §8).
    */
    'messages' => [
        'result_delayed' => 'Your result is being prepared and will be ready shortly.',
        'scoring_in_progress' => 'We are scoring your answer. This usually takes less than a minute.',
        'manual_review' => 'Your teacher is reviewing this answer and will confirm the score.',
        'ai_generated_disclaimer' => 'This score was produced by AI and may differ from an official Pearson result.',
        'low_confidence_note' => 'This score is provisional — your teacher will confirm it.',
        'noisy_warning' => 'We could score your answer, but the recording was noisy. A quieter room will give a more accurate result.',

        'audio' => [
            'too_short' => 'Your recording was too short. Please record again and speak for at least a few seconds.',
            'too_long' => 'Your recording was longer than this task allows. Please record a shorter answer.',
            'silent' => 'We could not hear anything in your recording. Please check your microphone and try again.',
            'unreadable' => 'We could not open your recording. Please send it again.',
        ],
    ],

    /*
    | Panel-facing copy for the academy and the platform operator.
    */
    'admin' => [
        'quota_exhausted' => 'Your AI quota for this period is used up. Algorithmic practice keeps working; AI scoring resumes when the quota resets or is topped up.',
        'byok_hint' => 'With your own API key you pay the provider directly and your AI usage is not deducted from your plan.',
        'rubric_weight_error' => 'Criterion weights must add up to exactly 100.',
        'prompt_untested' => 'This prompt must be tested on at least three real samples before it can be published.',
        'cost_anomaly' => 'AI spend for :academy has grown :percent% compared with last month.',
        'scoring_drift' => 'Re-scoring last week\'s answers for :task differed by :deviation points on average.',
    ],

];

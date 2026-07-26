<?php

declare(strict_types=1);

return [

    'session_type' => [
        'practice' => 'Practice',
        'exam' => 'Exam',
    ],

    'session_status' => [
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'abandoned' => 'Abandoned',
        'submitted' => 'Submitted',
        'scoring' => 'Being scored',
        'scored' => 'Scored',
        'expired' => 'Expired',
    ],

    'scoring_status' => [
        'pending' => 'Waiting to be scored',
        'scoring' => 'Scoring',
        'scored' => 'Scored',
        'failed' => 'Scoring failed',
        'manual_review' => 'Awaiting teacher review',
    ],

    'scored_by' => [
        'ai' => 'AI',
        'teacher' => 'Teacher',
        'system' => 'Automatic',
    ],

    'exam_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'selection_mode' => [
        'manual' => 'Hand-picked',
        'random' => 'Random with filters',
        'pool' => 'Random from a pool',
    ],

    'practice_access' => [
        'all' => 'Everyone',
        'subscribers' => 'Active subscribers only',
        'free_then_subscribe' => 'A few free sessions, then subscription',
    ],

    'practice_selection' => [
        'random' => 'Random',
        'sequential' => 'In order',
        'adaptive' => 'Adaptive to level',
    ],

    'feedback' => [
        'perfect' => 'Perfect — every part correct.',
        'strong' => 'Very close. Only a couple of details were missed.',
        'correct' => 'Correct.',
        'partial' => 'Partly correct. Review the highlighted parts.',
        'incorrect' => 'Not correct this time.',
        'weak' => 'Most of this answer was missed — worth practising again.',
        'no_answer' => 'No answer was submitted.',
        'no_speech' => 'No speech was detected in the recording.',
        'skipped' => 'You skipped this question.',
        'over_selected' => 'Too many words were selected; wrong picks cost marks here.',
        'needs_review' => 'This answer needs a teacher to look at it.',
        'scoring_failed' => 'Scoring failed. Your teacher has been notified.',
    ],

    'report' => [
        'title' => 'Report card',
        'total' => 'Total score',
        'passed' => 'Passed',
        'failed' => 'Not passed',
        'sections' => 'Sections',
        'strengths' => 'Strengths',
        'weaknesses' => 'Needs practice',
        'pending' => ':count answer(s) are still being scored.',
    ],

    'errors' => [
        'daily_limit_reached' => 'You have reached your daily practice limit (:limit). Come back tomorrow.',
        'subscription_required' => 'An active subscription is required to continue practising.',
        'no_questions' => 'There are no questions available for this practice right now.',
        'invalid_answer' => 'That answer does not match what this question expects.',
        'session_not_active' => 'This session is no longer accepting answers.',
        'exam_unavailable' => 'This exam is not available.',
        'exam_not_published' => 'This exam has not been published yet.',
        'exam_window_closed' => 'This exam is outside its scheduled window.',
        'exam_attempts_exhausted' => 'You have used all :allowed allowed attempt(s) for this exam.',
        'exam_empty' => 'This exam has no questions yet.',
        'override_reason_required' => 'A reason is required when changing a score.',
    ],

];

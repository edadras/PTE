<?php

declare(strict_types=1);

return [

    'question_status' => [
        'draft' => 'Draft',
        'pending_review' => 'Pending review',
        'approved' => 'Approved',
        'published' => 'Published',
        'rejected' => 'Rejected',
    ],

    'difficulty' => [
        'easy' => 'Easy',
        'medium' => 'Medium',
        'hard' => 'Hard',
    ],

    'media_kind' => [
        'audio' => 'Audio',
        'image' => 'Image',
        'video' => 'Video',
    ],

    'course_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'selection_mode' => [
        'random' => 'Random',
        'sequential' => 'Sequential',
        'adaptive' => 'Adaptive (by level)',
    ],

    'bank' => [
        'title' => 'Question bank',
        'default' => 'Default bank',
        'question_count' => ':count questions',
        'cloned' => 'Bank ":name" was copied.',
        'starter_pack' => 'Starter pack',
    ],

    'question' => [
        'difficulty_index' => 'Measured difficulty',
        'usage_count' => 'Times practised',
        'avg_score' => 'Average score',
        'approved_by' => 'Approved by :name',
        'rejected_note' => 'Reason for rejection',
        'not_enough' => 'There are not enough published questions for this practice type yet.',
    ],

    'import' => [
        'title' => 'Import questions',
        'running' => 'Importing…',
        'completed' => ':imported of :total rows imported.',
        'failed' => 'Import failed. :failed of :total rows have errors.',
        'rolled_back' => 'Nothing was imported — every row must be valid.',
        'row_error' => 'Line :line: :message',
        'unsupported_format' => 'Binary Excel files are not supported. Save the sheet as CSV (UTF-8) and import that.',
        'rollback_done' => ':count imported questions were withdrawn.',
    ],

    'module' => [
        'enabled' => 'Module :name is now enabled.',
        'disabled' => 'Module :name is now disabled. No content was deleted.',
        'not_enabled' => 'Module :name is not enabled for this academy.',
        'not_available' => 'Module :name has not shipped yet.',
        'plan_required' => 'Module :name requires the :plan plan.',
    ],

    'validation' => [
        'invalid_content' => 'This question cannot be saved: :errors',
    ],

];

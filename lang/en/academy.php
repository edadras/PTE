<?php

declare(strict_types=1);

return [

    'status' => [
        'active' => 'Active',
        'suspended' => 'Suspended',
        'deleted' => 'Deleted',
    ],

    'domain_type' => [
        'subdomain' => 'Platform subdomain',
        'custom' => 'Custom domain',
    ],

    'ssl_status' => [
        'pending' => 'Pending',
        'issued' => 'Issued',
        'failed' => 'Failed',
    ],

    'dark_mode' => [
        'light' => 'Light',
        'dark' => 'Dark',
        'auto' => 'Follow system',
    ],

    'student_status' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'blocked' => 'Blocked',
    ],

    'student_source' => [
        'telegram' => 'Telegram',
        'web' => 'Web',
        'import' => 'Import',
        'api' => 'API',
    ],

    'membership_status' => [
        'active' => 'Active',
        'invited' => 'Invited',
        'suspended' => 'Suspended',
    ],

    'class_group_status' => [
        'planned' => 'Planned',
        'active' => 'Active',
        'completed' => 'Completed',
        'archived' => 'Archived',
    ],

    'placeholder_groups' => [
        'student' => 'Student',
        'academy' => 'Academy',
        'time' => 'Date and time',
        'progress' => 'Progress',
        'subscription' => 'Subscription',
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform message defaults
    |--------------------------------------------------------------------------
    |
    | Used whenever an academy has not customised the message itself. Improving
    | a default here reaches every academy that never edited it.
    |
    | @see docs/03-white-label.md §6
    |
    */

    'templates' => [
        'welcome' => "Hi {first_name} 👋\nWelcome to {academy_name}. Ready to start today's practice?",
        'menu_header' => 'What would you like to do?',
        'practice_started' => "Let's go! Take your time and answer carefully.",
        'practice_completed' => 'Nice work, {first_name}! Your answer has been recorded.',
        'score_ready' => 'Your score is ready: {last_score}. Tap to see the details.',
        'exam_reminder' => 'Reminder: your exam starts at {time} on {today}.',
        'daily_nudge' => 'A few minutes of practice today keeps your {streak_days}-day streak alive, {first_name}.',
        'subscription_expiring' => 'Your subscription expires in {days_remaining} days ({expires_at}).',
        'subscription_expired' => 'Your subscription has expired. Renew it to continue practising.',
        'payment_success' => 'Payment received — thank you! Your {plan_name} plan is now active.',
        'support_greeting' => 'Hi {first_name}, how can we help? Send us your question and the team will reply.',
        'error_generic' => 'Something went wrong. Please try again in a moment.',
        'quota_exceeded' => 'You have reached your practice limit for now. Please try again later.',
    ],

];

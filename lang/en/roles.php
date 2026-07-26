<?php

declare(strict_types=1);

return [

    'owner' => [
        'name' => 'Academy owner',
        'description' => 'Full access inside their own academy: brand, bot, staff, content, AI and billing.',
    ],

    'manager' => [
        'name' => 'Academy manager',
        'description' => 'Day-to-day operations: students, exams, question bank and reports.',
    ],

    'teacher' => [
        'name' => 'Teacher',
        'description' => 'Reviews and grades the answers of students in their own class groups.',
    ],

    'support' => [
        'name' => 'Support',
        'description' => 'Answers tickets and troubleshoots student conversations. Cannot change scores or settings.',
    ],

    'super_admin' => [
        'name' => 'Super admin',
        'description' => 'Platform staff. Outside the tenant role system.',
    ],

    'custom' => [
        'name' => 'Custom role',
        'description' => 'A role composed by the academy owner from the allowed permissions.',
    ],

];

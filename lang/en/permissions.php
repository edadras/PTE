<?php

declare(strict_types=1);

return [

    'scopes' => [
        'academy' => 'Academy',
        'platform' => 'Platform',
    ],

    'groups' => [
        'branding' => 'Brand and settings',
        'telegram' => 'Telegram',
        'users' => 'Staff and roles',
        'students' => 'Students',
        'content' => 'Content and question bank',
        'assessment' => 'Exams and grading',
        'ai' => 'Artificial intelligence',
        'reports' => 'Reports',
        'billing' => 'Billing',
        'support' => 'Support',
        'modules' => 'Modules',
        'system' => 'System',
        'platform' => 'Platform administration',
    ],

    'items' => [

        'academy.brand.view' => 'View brand',
        'academy.brand.update' => 'Edit brand',
        'academy.settings.view' => 'View settings',
        'academy.settings.update' => 'Edit settings',
        'academy.domain.manage' => 'Manage domains',

        'telegram.bot.view' => 'View bot settings',
        'telegram.bot.update' => 'Edit bot settings (includes token)',
        'telegram.menu.view' => 'View bot menu',
        'telegram.menu.update' => 'Edit bot menu',
        'telegram.flow.view' => 'View flows',
        'telegram.flow.update' => 'Edit flows',
        'telegram.flow.publish' => 'Publish flows',
        'telegram.broadcast.send' => 'Send broadcasts',

        'users.staff.view' => 'View staff',
        'users.staff.invite' => 'Invite staff',
        'users.staff.remove' => 'Remove staff',
        'users.roles.view' => 'View roles',
        'users.roles.manage' => 'Manage roles',

        'students.view' => 'View students',
        'students.create' => 'Create students',
        'students.update' => 'Edit students',
        'students.delete' => 'Delete students',
        'students.import' => 'Import students',
        'students.export' => 'Export students',

        'courses.view' => 'View courses',
        'courses.manage' => 'Manage courses',
        'lessons.view' => 'View lessons',
        'lessons.manage' => 'Manage lessons',
        'questions.view' => 'View questions',
        'questions.create' => 'Create questions',
        'questions.update' => 'Edit questions',
        'questions.delete' => 'Delete questions',
        'questions.import' => 'Import questions',
        'questions.approve' => 'Approve questions',
        'question_banks.manage' => 'Manage question banks',

        'exams.view' => 'View exams',
        'exams.create' => 'Create exams',
        'exams.update' => 'Edit exams',
        'exams.delete' => 'Delete exams',
        'exams.publish' => 'Publish exams',
        'exams.schedule' => 'Schedule exams',
        'practice.configure' => 'Configure practice',
        'answers.view' => 'View answers',
        'answers.grade' => 'Grade answers',
        'answers.override_ai_score' => 'Override AI score',
        'scores.view' => 'View scores',
        'scores.publish' => 'Publish scores',

        'ai.settings.view' => 'View AI settings',
        'ai.settings.update' => 'Edit AI settings',
        'ai.prompts.view' => 'View prompts',
        'ai.prompts.update' => 'Edit prompts',
        'ai.prompts.publish' => 'Publish prompts',
        'ai.rubrics.manage' => 'Manage rubrics',
        'ai.usage.view' => 'View AI usage',

        'reports.dashboard.view' => 'View dashboard',
        'reports.detailed.view' => 'View detailed reports',
        'reports.export_excel' => 'Export to Excel',
        'reports.financial.view' => 'View financial reports',

        'billing.view' => 'View billing',
        'billing.manage' => 'Manage billing',
        'billing.payment_methods' => 'Manage payment methods',
        'subscriptions.students.manage' => 'Manage student subscriptions',

        'support.tickets.view' => 'View tickets',
        'support.tickets.reply' => 'Reply to tickets',
        'support.tickets.close' => 'Close tickets',
        'support.conversations.view' => 'View bot conversations',

        'modules.view' => 'View modules',
        'modules.toggle' => 'Enable or disable modules',

        'audit.view' => 'View audit log',
        'api_keys.manage' => 'Manage API keys',
        'webhooks.manage' => 'Manage webhooks',

        'platform.academies.manage' => 'Create, suspend and delete academies',
        'platform.analytics.view' => 'View platform analytics',
        'platform.plans.manage' => 'Manage plans and pricing',
        'platform.billing.manage' => 'Manage platform billing',
        'platform.logs.view' => 'View system logs',
        'platform.infra.manage' => 'Manage infrastructure and queues',
        'platform.ai.manage' => 'Manage AI models and platform keys',
        'platform.impersonate' => 'Impersonate an academy',
        'platform.modules.manage' => 'Manage modules',
        'platform.view_all' => 'Query across all academies',

    ],

];

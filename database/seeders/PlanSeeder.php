<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Commerce\Enums\PlanKey;
use App\Domain\Commerce\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The plan catalogue from docs/09 §1.
 *
 * Prices are integer minor units of IRR — the Rial has no subunit in practice,
 * so 2_500_000 is exactly ۲٬۵۰۰٬۰۰۰ ریال.
 *
 * `limits` keys are UsageMetric values plus a few structural caps; null always
 * means unlimited. `features` are booleans and scalars the panel reads to
 * decide what to show.
 */
final class PlanSeeder extends Seeder
{
    private const GB = 1024;

    public function run(): void
    {
        foreach ($this->plans() as $plan) {
            Plan::query()->updateOrCreate(['key' => $plan['key']], $plan);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plans(): array
    {
        return [
            [
                'key' => PlanKey::Trial->value,
                'name' => 'Trial',
                'description' => '14 days of Professional, no card required.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'IRR',
                'limits' => array_merge($this->professionalLimits(), [
                    // The whole point of the trial cap: evaluate the product
                    // without letting a bot loop run up a real AI bill.
                    'ai_requests' => 50,
                    'ai_cost_usd' => 200,
                    'asr_minutes' => 50,
                    'broadcasts' => 1,
                ]),
                'features' => array_merge($this->professionalFeatures(), [
                    'sla' => null,
                    'support' => 'email',
                ]),
                'is_public' => false,
                'sort_order' => PlanKey::Trial->sortOrder(),
                'is_active' => true,
            ],
            [
                'key' => PlanKey::Starter->value,
                'name' => 'Starter',
                'description' => 'For a single academy finding its feet.',
                'price_monthly' => 2_500_000,
                'price_yearly' => 25_000_000,
                'currency' => 'IRR',
                'limits' => [
                    'active_students' => 100,
                    'staff_users' => 3,
                    'questions' => 500,
                    'ai_requests' => 1_000,
                    // Not separately capped: the request ceiling is the real
                    // control (docs/09 §6, lesson 3).
                    'ai_tokens' => null,
                    // US cents. A backstop against a runaway month, not a sales
                    // limit — sized from the ~$0.48/active student in docs/09 §6.
                    'ai_cost_usd' => 6_000,
                    'asr_minutes' => 300,
                    'storage_mb' => 5 * self::GB,
                    'broadcasts' => 1,
                    'telegram_bots' => 1,
                    'modules' => 2,
                    'custom_roles' => 0,
                    // Mock exams dominate AI cost, so the low plan caps them.
                    'mock_exams_per_student' => 1,
                ],
                'features' => [
                    'flow_builder' => false,
                    'custom_domain' => false,
                    'white_label' => false,
                    'byok' => false,
                    'rest_api' => false,
                    'outgoing_webhooks' => false,
                    'dedicated_database' => false,
                    'manual_grading' => false,
                    'priority_support' => false,
                    'sla' => null,
                    'support' => 'email',
                ],
                'is_public' => true,
                'sort_order' => PlanKey::Starter->sortOrder(),
                'is_active' => true,
            ],
            [
                'key' => PlanKey::Professional->value,
                'name' => 'Professional',
                'description' => 'The full platform for a growing academy.',
                'price_monthly' => 7_500_000,
                'price_yearly' => 75_000_000,
                'currency' => 'IRR',
                'limits' => $this->professionalLimits(),
                'features' => $this->professionalFeatures(),
                'is_public' => true,
                'sort_order' => PlanKey::Professional->sortOrder(),
                'is_active' => true,
            ],
            [
                'key' => PlanKey::Enterprise->value,
                'name' => 'Enterprise',
                'description' => 'Negotiated. Dedicated database, custom modules, BYOK.',
                // Null is "quoted per customer", not free — SubscribeAcademy
                // refuses to self-serve a plan without a list price.
                'price_monthly' => null,
                'price_yearly' => null,
                'currency' => 'IRR',
                'limits' => [
                    'active_students' => null,
                    'staff_users' => null,
                    'questions' => null,
                    'ai_requests' => null,
                    'ai_tokens' => null,
                    'ai_cost_usd' => null,
                    'asr_minutes' => null,
                    'storage_mb' => 500 * self::GB,
                    'broadcasts' => null,
                    'telegram_bots' => null,
                    'modules' => null,
                    'custom_roles' => null,
                    'mock_exams_per_student' => null,
                ],
                'features' => [
                    'flow_builder' => true,
                    'custom_domain' => true,
                    'white_label' => true,
                    'byok' => true,
                    'rest_api' => true,
                    'outgoing_webhooks' => true,
                    'dedicated_database' => true,
                    'manual_grading' => true,
                    'custom_modules' => true,
                    'priority_support' => true,
                    'sla' => 99.9,
                    'support' => 'dedicated_manager',
                ],
                'is_public' => true,
                'sort_order' => PlanKey::Enterprise->sortOrder(),
                'is_active' => true,
            ],
        ];
    }

    /**
     * @return array<string, int|null>
     */
    private function professionalLimits(): array
    {
        return [
            'active_students' => 1_000,
            'staff_users' => 15,
            'questions' => 5_000,
            'ai_requests' => 10_000,
            'ai_tokens' => null,
            'ai_cost_usd' => 30_000,
            'asr_minutes' => 3_000,
            'storage_mb' => 50 * self::GB,
            'broadcasts' => 10,
            'telegram_bots' => 1,
            'modules' => null,
            'custom_roles' => 5,
            'mock_exams_per_student' => 4,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function professionalFeatures(): array
    {
        return [
            'flow_builder' => true,
            'custom_domain' => true,
            'white_label' => true,
            'byok' => true,
            'rest_api' => true,
            'outgoing_webhooks' => true,
            'dedicated_database' => false,
            'manual_grading' => true,
            'priority_support' => true,
            'sla' => 99.0,
            'support' => 'priority',
        ];
    }
}

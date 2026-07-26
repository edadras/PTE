<?php

declare(strict_types=1);

namespace App\Filament\Academy\Support;

use App\Domain\AI\Enums\PromptStatus;
use App\Domain\AI\Models\AiPrompt;
use App\Domain\AI\Services\AiGateway;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Drives the prompt builder's test-run / publish / rollback buttons.
 *
 * The call itself is AiGateway's; this only records the outcome on the prompt
 * row so `isPublishable()` — the guardrail in docs/06 §3 — can answer honestly.
 */
final class PromptTester
{
    /** How many recorded successful runs a prompt needs before publishing. */
    public const REQUIRED_SAMPLES = 3;

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array{ok: bool, message: string}
     */
    public function run(AiPrompt $prompt, array $variables): array
    {
        try {
            $response = $this->gateway->run($prompt->key, $variables);
        } catch (Throwable $e) {
            $this->recordResult($prompt, false, $e->getMessage(), $variables);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $text = mb_substr($response->text ?? '', 0, 2000);

        $this->recordResult($prompt, true, $text, $variables);

        $samples = count(array_filter(
            (array) ($prompt->test_results ?? []),
            static fn (mixed $row): bool => is_array($row) && ($row['ok'] ?? false) === true,
        ));

        return [
            'ok' => true,
            'message' => __('panel.prompts.notify.test_body', [
                'samples' => $samples,
                'required' => self::REQUIRED_SAMPLES,
                'output' => mb_substr($text, 0, 400),
            ]),
        ];
    }

    public function publish(AiPrompt $prompt): void
    {
        DB::transaction(function () use ($prompt): void {
            AiPrompt::query()
                ->where('academy_id', TenantContext::id())
                ->where('key', $prompt->key->value)
                ->whereKeyNot($prompt->getKey())
                ->where('status', PromptStatus::Published->value)
                ->update(['status' => PromptStatus::Archived->value]);

            $prompt->forceFill([
                'status' => PromptStatus::Published,
                'published_at' => now(),
            ])->save();
        });
    }

    /** Re-publish an archived version of the same key. */
    public function rollback(int $promptId): bool
    {
        $prompt = AiPrompt::query()
            ->where('academy_id', TenantContext::id())
            ->whereKey($promptId)
            ->first();

        if (! $prompt instanceof AiPrompt) {
            return false;
        }

        $this->publish($prompt);

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function versionOptions(AiPrompt $prompt): array
    {
        return AiPrompt::query()
            ->where('academy_id', TenantContext::id())
            ->where('key', $prompt->key->value)
            ->orderByDesc('version')
            ->get(['id', 'version', 'status'])
            ->mapWithKeys(fn (AiPrompt $row): array => [
                $row->getKey() => sprintf('v%d — %s', $row->version, $row->status->label()),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function recordResult(AiPrompt $prompt, bool $ok, string $output, array $variables): void
    {
        $results = (array) ($prompt->test_results ?? []);

        $results[] = [
            'ok' => $ok,
            'at' => now()->toIso8601String(),
            'by' => auth()->id(),
            'variables' => array_keys($variables),
            'output' => mb_substr($output, 0, 2000),
        ];

        $successes = count(array_filter(
            $results,
            static fn (mixed $row): bool => is_array($row) && ($row['ok'] ?? false) === true,
        ));

        $prompt->forceFill([
            // Keep the last ten runs; the column is an audit aid, not a log.
            'test_results' => array_slice($results, -10),
            'tested_at' => $successes >= self::REQUIRED_SAMPLES ? now() : $prompt->tested_at,
        ])->save();
    }
}

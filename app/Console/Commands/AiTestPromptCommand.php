<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesAcademy;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Services\AiGateway;
use App\Domain\AI\Services\PromptRenderer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Render (and optionally run) an academy's prompt for one task key.
 *
 * Rendering without calling a provider is the default and the common case: it
 * answers "what exactly are we sending?" — including the non-removable platform
 * preamble and the candidate-material delimiters — for free and instantly.
 * `--run` costs money, so it has to be asked for.
 *
 * @see docs/10-infrastructure-and-ops.md §8 · docs/06-ai-layer.md §3
 */
final class AiTestPromptCommand extends Command
{
    use ResolvesAcademy;

    protected $signature = 'ai:test-prompt
        {academy : Academy id or slug}
        {key : Task key, e.g. writing.essay}
        {--var=* : Override a variable, name=value}
        {--run : Actually call the provider (this costs money)}
        {--json : Emit the rendered prompt as JSON}';

    protected $description = 'Render an academy prompt for a task key, and optionally send it to the provider.';

    public function handle(PromptRenderer $renderer, AiGateway $gateway): int
    {
        $academy = $this->requireAcademy($this->argument('academy'), withTrashed: false);

        if ($academy === null) {
            return self::FAILURE;
        }

        $task = AiTaskKey::tryFrom((string) $this->argument('key'));

        if (! $task instanceof AiTaskKey) {
            $this->components->error(__('reports.console.unknown_task', ['key' => (string) $this->argument('key')]));
            $this->line(implode(', ', array_map(static fn (AiTaskKey $t): string => $t->value, AiTaskKey::cases())));

            return self::INVALID;
        }

        return TenantContext::runFor($academy, function () use ($academy, $task, $renderer, $gateway): int {
            $variables = $this->variables($task);

            try {
                $rendered = $renderer->render($task, $variables, (int) $academy->getKey());
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            if ($this->option('json') === true) {
                $this->line((string) json_encode([
                    'task' => $rendered->task->value,
                    'prompt_id' => $rendered->promptId,
                    'prompt_version' => $rendered->promptVersion,
                    'is_platform_default' => $rendered->isPlatformDefault,
                    'unresolved_variables' => $rendered->unresolvedVariables,
                    'system_prompt' => $rendered->systemPrompt,
                    'user_prompt' => $rendered->userPrompt,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $this->components->twoColumnDetail(__('reports.console.prompt_version'), (string) ($rendered->promptVersion ?? '—'));
                $this->components->twoColumnDetail(
                    __('reports.console.prompt_source'),
                    $rendered->isPlatformDefault
                        ? __('reports.console.platform_default')
                        : __('reports.console.academy_custom')
                );

                if ($rendered->unresolvedVariables !== []) {
                    $this->components->warn(__('reports.console.unresolved_variables', [
                        'names' => implode(', ', $rendered->unresolvedVariables),
                    ]));
                }

                $this->newLine();
                $this->line('<fg=cyan>--- SYSTEM ---</>');
                $this->line($rendered->systemPrompt);
                $this->newLine();
                $this->line('<fg=cyan>--- USER ---</>');
                $this->line($rendered->userPrompt);
            }

            if ($this->option('run') !== true) {
                return self::SUCCESS;
            }

            try {
                $response = $gateway->run($task, $variables);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('<fg=green>--- RESPONSE ---</>');
            $this->line($response->text);
            $this->components->twoColumnDetail(__('reports.console.latency'), $response->latencyMs.' ms');
            $this->components->twoColumnDetail(
                __('reports.console.tokens'),
                $response->promptTokens.' + '.$response->completionTokens
            );

            return self::SUCCESS;
        });
    }

    /**
     * Placeholder values for every variable the task offers, so the rendered
     * prompt is complete rather than full of holes.
     *
     * @return array<string, mixed>
     */
    private function variables(AiTaskKey $task): array
    {
        $variables = [];

        foreach ($task->availableVariables() as $name) {
            $variables[$name] = '{'.$name.'}';
        }

        /** @var array<int, string> $overrides */
        $overrides = (array) $this->option('var');

        foreach ($overrides as $override) {
            if (str_contains($override, '=')) {
                [$name, $value] = explode('=', $override, 2);
                $variables[trim($name)] = $value;
            }
        }

        return $variables;
    }
}

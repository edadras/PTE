<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\AiTaskKey;

final readonly class RenderedPrompt
{
    /**
     * @param  array<string, mixed>  $outputSchema
     * @param  array<int, string>  $unresolvedVariables
     */
    public function __construct(
        public AiTaskKey $task,
        public string $systemPrompt,
        public string $userPrompt,
        public array $outputSchema = [],
        public ?int $promptId = null,
        public ?int $promptVersion = null,
        public ?string $modelHint = null,
        public bool $isPlatformDefault = true,
        public array $unresolvedVariables = [],
    ) {}
}

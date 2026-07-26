<?php

declare(strict_types=1);

namespace App\Domain\AI\Data;

use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Enums\AiTaskKey;
use App\Domain\AI\Models\AiModel;
use App\Domain\AI\Services\ProviderResolver;

/**
 * The outcome of "what should this academy's next call actually hit".
 *
 * @see ProviderResolver
 */
final readonly class ResolvedModel
{
    /**
     * @param  array<int, string>  $fallbackChain  model keys, primary first
     */
    public function __construct(
        public AiTaskKey $task,
        public AiProvider $provider,
        public string $modelKey,
        public AiModel $model,
        public float $temperature,
        public int $maxOutputTokens,
        public array $fallbackChain,
        public bool $usesOwnKey = false,
        public ?string $apiKey = null,
        public bool $cacheEnabled = true,
        public bool $economyMode = false,
    ) {}

    public function hasKey(): bool
    {
        return filled($this->apiKey);
    }

    /**
     * BYOK spend does not touch our margin, so it is metered but not charged
     * against the platform quota.
     */
    public function billableToPlatform(): bool
    {
        return ! $this->usesOwnKey;
    }
}

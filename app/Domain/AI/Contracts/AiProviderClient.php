<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\AiCompletionRequest;
use App\Domain\AI\Data\AiCompletionResponse;
use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Exceptions\ProviderUnavailableException;

/**
 * The lowest common denominator across text models (ADR-005): a system message,
 * a user message, a JSON schema, a temperature and a token cap. Vendor-specific
 * extras stay behind the implementation.
 */
interface AiProviderClient
{
    /**
     * @throws ProviderUnavailableException on transport failure, 5xx, 429 or timeout
     */
    public function complete(AiCompletionRequest $request): AiCompletionResponse;

    public function supports(string $modelKey): bool;

    public function provider(): AiProvider;
}

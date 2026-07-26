<?php

declare(strict_types=1);

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\TranscriptionRequest;
use App\Domain\AI\Data\TranscriptionResult;
use App\Domain\AI\Enums\AiProvider;
use App\Domain\AI\Exceptions\ProviderUnavailableException;

interface TranscriptionClient
{
    /**
     * @throws ProviderUnavailableException
     */
    public function transcribe(TranscriptionRequest $request): TranscriptionResult;

    public function supports(string $modelKey): bool;

    public function provider(): AiProvider;
}

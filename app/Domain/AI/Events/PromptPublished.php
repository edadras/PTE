<?php

declare(strict_types=1);

namespace App\Domain\AI\Events;

use App\Domain\AI\Models\AiPrompt;
use Illuminate\Foundation\Events\Dispatchable;

final class PromptPublished
{
    use Dispatchable;

    public function __construct(public readonly AiPrompt $prompt) {}
}

<?php

declare(strict_types=1);

namespace App\Domain\AI\Events;

use App\Domain\AI\Models\AiRubric;
use Illuminate\Foundation\Events\Dispatchable;

final class RubricPublished
{
    use Dispatchable;

    public function __construct(public readonly AiRubric $rubric) {}
}

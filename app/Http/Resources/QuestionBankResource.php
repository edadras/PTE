<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Learning\Models\QuestionBank;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * @mixin QuestionBank
 */
final class QuestionBankResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->getKey(),
            'name' => $this->name,
            'module' => $this->module_key instanceof BackedEnum ? $this->module_key->value : $this->module_key,
            'description' => $this->description,
            'is_default' => (bool) $this->is_default,
            'question_count' => (int) $this->question_count,
        ];
    }
}

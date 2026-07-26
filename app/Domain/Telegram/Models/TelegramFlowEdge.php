<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $flow_id
 * @property string $from_node
 * @property string $to_node
 * @property array<string, mixed>|null $condition
 * @property bool $is_default
 */
final class TelegramFlowEdge extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'condition' => 'array',
            'sort_order' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<TelegramFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(TelegramFlow::class, 'flow_id');
    }

    /** An edge with no condition is always taken. */
    public function isUnconditional(): bool
    {
        return blank($this->condition);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\FlowNodeType;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $flow_id
 * @property string $node_key
 * @property FlowNodeType $type
 * @property array<string, mixed>|null $config
 */
final class TelegramFlowNode extends Model
{
    use BelongsToAcademy;
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => FlowNodeType::class,
            'config' => 'array',
            'position_x' => 'integer',
            'position_y' => 'integer',
        ];
    }

    /** @return BelongsTo<TelegramFlow, $this> */
    public function flow(): BelongsTo
    {
        return $this->belongsTo(TelegramFlow::class, 'flow_id');
    }

    /** @return HasMany<TelegramFlowEdge, $this> */
    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(TelegramFlowEdge::class, 'from_node', 'node_key')
            ->where('flow_id', $this->flow_id)
            ->orderBy('sort_order');
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}

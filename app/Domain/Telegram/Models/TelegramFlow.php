<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Models;

use App\Domain\Telegram\Enums\FlowNodeType;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Tenancy\Concerns\BelongsToAcademy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $academy_id
 * @property string $name
 * @property PublishStatus $status
 * @property bool $is_active
 * @property string|null $entry_node_key
 */
final class TelegramFlow extends Model
{
    use BelongsToAcademy;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PublishStatus::class,
            'trigger_config' => 'array',
            'version' => 'integer',
            'is_active' => 'boolean',
            'max_hops' => 'integer',
            'wait_timeout_seconds' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<TelegramFlowNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(TelegramFlowNode::class, 'flow_id');
    }

    /** @return HasMany<TelegramFlowEdge, $this> */
    public function edges(): HasMany
    {
        return $this->hasMany(TelegramFlowEdge::class, 'flow_id')->orderBy('sort_order');
    }

    public function entryNode(): ?TelegramFlowNode
    {
        if (filled($this->entry_node_key)) {
            return $this->nodes()->where('node_key', $this->entry_node_key)->first();
        }

        return $this->nodes()->where('type', FlowNodeType::Trigger)->first();
    }

    public function maxHops(): int
    {
        return $this->max_hops ?? (int) config('pte.telegram.max_flow_hops', 50);
    }

    public function waitTimeoutSeconds(): int
    {
        // Docs §6: 30 minutes by default, then the state is cleaned up.
        return $this->wait_timeout_seconds ?? 1800;
    }

    /** @param  Builder<self>  $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublishStatus::Published)->where('is_active', true);
    }
}

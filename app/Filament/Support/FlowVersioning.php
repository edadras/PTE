<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Domain\Telegram\Models\TelegramFlowEdge;
use App\Domain\Telegram\Models\TelegramFlowNode;
use Illuminate\Support\Facades\DB;

/**
 * Copies a flow (nodes and edges included) into a new draft version, so the
 * live graph keeps running while the next iteration is edited — the same
 * draft/publish/rollback cycle PublishMenu gives menus.
 *
 * Rollback itself is just PublishFlow::handle() on an older version; only the
 * duplication has no domain home yet, which is why it lives here.
 */
final class FlowVersioning
{
    public function draftFrom(TelegramFlow $flow): TelegramFlow
    {
        return DB::transaction(function () use ($flow): TelegramFlow {
            $nextVersion = (int) TelegramFlow::query()
                ->where('name', $flow->name)
                ->max('version') + 1;

            /** @var TelegramFlow $draft */
            $draft = TelegramFlow::query()->create([
                ...collect($flow->only([
                    'name', 'key', 'description', 'trigger_type', 'trigger_config',
                    'entry_node_key', 'max_hops', 'wait_timeout_seconds',
                ]))->all(),
                'status' => PublishStatus::Draft,
                'version' => $nextVersion,
                'is_active' => false,
                'published_at' => null,
                'created_by' => auth()->id(),
            ]);

            foreach ($flow->nodes()->get() as $node) {
                /** @var TelegramFlowNode $node */
                TelegramFlowNode::query()->create([
                    'flow_id' => $draft->getKey(),
                    'node_key' => $node->node_key,
                    'type' => $node->type,
                    'title' => $node->title,
                    'config' => $node->config,
                    'position_x' => $node->position_x,
                    'position_y' => $node->position_y,
                ]);
            }

            foreach ($flow->edges()->get() as $edge) {
                /** @var TelegramFlowEdge $edge */
                TelegramFlowEdge::query()->create([
                    'flow_id' => $draft->getKey(),
                    'from_node' => $edge->from_node,
                    'to_node' => $edge->to_node,
                    'condition' => $edge->condition,
                    'label' => $edge->label,
                    'sort_order' => $edge->sort_order,
                    'is_default' => $edge->is_default,
                ]);
            }

            return $draft;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Enums\FlowNodeType;
use App\Domain\Telegram\Enums\PublishStatus;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Domain\Telegram\Models\TelegramFlowNode;
use App\Domain\Telegram\Services\FlowEngine;
use App\Domain\Telegram\Support\SafeUrl;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Validates a flow graph and makes it live.
 *
 * All the structural checks happen here rather than at runtime, because a graph
 * that loops forever or points at an internal IP is a bug the academy should
 * hear about while they are still in the editor — not while a student is
 * halfway through a conversation.
 *
 * @see docs/04-telegram-layer.md §6
 */
final class PublishFlow
{
    public function __construct(private readonly FlowEngine $engine) {}

    public function handle(TelegramFlow $flow, ?int $userId = null): TelegramFlow
    {
        $errors = $this->validate($flow);

        if ($errors !== []) {
            throw new RuntimeException('Flow cannot be published: '.implode(' ', $errors));
        }

        DB::transaction(function () use ($flow, $userId): void {
            TelegramFlow::query()
                ->where('trigger_type', $flow->trigger_type)
                ->whereKeyNot($flow->getKey())
                ->update(['is_active' => false]);

            $flow->forceFill([
                'status' => PublishStatus::Published,
                'is_active' => true,
                'published_at' => now(),
                'created_by' => $flow->created_by ?? $userId,
            ])->save();
        });

        return $flow->refresh();
    }

    /**
     * @return array<int, string> human-readable problems, empty when publishable
     */
    public function validate(TelegramFlow $flow): array
    {
        $errors = [];

        $nodes = $flow->nodes()->get();

        if ($nodes->isEmpty()) {
            return [__('telegram.flow_error.empty')];
        }

        if (! $flow->entryNode() instanceof TelegramFlowNode) {
            $errors[] = __('telegram.flow_error.no_entry');
        }

        if ($nodes->every(static fn (TelegramFlowNode $n): bool => $n->type !== FlowNodeType::End)) {
            $errors[] = __('telegram.flow_error.no_end');
        }

        $dangling = $this->engine->danglingEdges($flow);

        if ($dangling !== []) {
            $errors[] = __('telegram.flow_error.dangling', ['nodes' => implode(', ', $dangling)]);
        }

        $cycles = $this->engine->detectCycles($flow);

        if ($cycles !== []) {
            $errors[] = __('telegram.flow_error.cycle', [
                'nodes' => implode(' → ', $cycles[0]),
            ]);
        }

        foreach ($this->unsafeWebhookNodes($nodes->all()) as $nodeKey) {
            $errors[] = __('telegram.flow_error.unsafe_webhook', ['node' => $nodeKey]);
        }

        return $errors;
    }

    /**
     * @param  array<int, TelegramFlowNode>  $nodes
     * @return array<int, string>
     */
    private function unsafeWebhookNodes(array $nodes): array
    {
        $unsafe = [];

        foreach ($nodes as $node) {
            if ($node->type !== FlowNodeType::Webhook) {
                continue;
            }

            $url = $node->setting('url');

            if (! is_string($url) || ! SafeUrl::isSafe($url)) {
                $unsafe[] = $node->node_key;
            }
        }

        return $unsafe;
    }

    public function unpublish(TelegramFlow $flow): TelegramFlow
    {
        $flow->forceFill(['is_active' => false, 'status' => PublishStatus::Archived])->save();

        return $flow;
    }
}

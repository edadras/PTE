<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Data\IncomingUpdate;
use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Enums\FlowNodeType;
use App\Domain\Telegram\Jobs\ContinueFlow;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Domain\Telegram\Models\TelegramFlowEdge;
use App\Domain\Telegram\Models\TelegramFlowNode;
use App\Domain\Telegram\Support\CallbackData;
use App\Domain\Telegram\Support\ConditionEvaluator;
use App\Domain\Telegram\Support\SafeUrl;
use App\Domain\Tenancy\Data\PlaceholderContext;
use App\Domain\Tenancy\Services\PlaceholderRenderer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Walks a flow graph, one update at a time.
 *
 * Three guard rails, all mandatory (docs §6):
 *   - a hard hop limit per run, so a mis-wired graph cannot loop forever;
 *   - a wait timeout, so an abandoned conversation frees its state;
 *   - an SSRF-guarded, 5-second-capped HTTP call for `webhook` nodes.
 *
 * Cycle detection happens at publish time (PublishFlow → detectCycles), not
 * here: catching it at runtime means the student already saw the damage.
 */
final class FlowEngine
{
    private const WEBHOOK_TIMEOUT = 5;

    public function __construct(
        private readonly ConversationState $state,
        private readonly MessageSender $sender,
        private readonly PlaceholderRenderer $placeholders,
    ) {}

    /**
     * Enter a flow at its entry node.
     *
     * @param  array<string, mixed>  $context
     */
    public function start(TelegramFlow $flow, TelegramBot $bot, int $chatId, array $context = []): void
    {
        $node = $flow->entryNode();

        if (! $node instanceof TelegramFlowNode) {
            Log::warning('Flow has no entry node.', ['flow_id' => $flow->getKey()]);

            return;
        }

        $this->state->set($chatId, [
            'flow_id' => (int) $flow->getKey(),
            'node_id' => $node->node_key,
            'step' => 0,
            'context' => $context,
            'history' => $this->state->history($chatId),
        ]);

        $this->execute($flow, $bot, $chatId, $node, $context);
    }

    /**
     * Continue a parked flow with the student's latest update.
     */
    public function resume(TelegramBot $bot, IncomingUpdate $update): bool
    {
        $chatId = $update->chatId;

        if ($chatId === null) {
            return false;
        }

        $flowId = $this->state->flowId($chatId);

        if ($flowId === null) {
            return false;
        }

        $flow = TelegramFlow::query()->find($flowId);

        if (! $flow instanceof TelegramFlow) {
            $this->state->clear($chatId);

            return false;
        }

        // An abandoned conversation must not hold the student hostage.
        if ($this->state->isStale($chatId, $flow->waitTimeoutSeconds())) {
            $this->abandon($bot, $chatId);

            return false;
        }

        $nodeKey = $this->state->nodeId($chatId);
        $node = $nodeKey === null ? null : $this->node($flow, $nodeKey);

        if (! $node instanceof TelegramFlowNode) {
            $this->state->clear($chatId);

            return false;
        }

        $context = $this->captureAnswer($node, $update, $this->state->context($chatId));
        $this->state->patch($chatId, ['context' => $context]);

        $next = $this->nextNode($flow, $node, $context);

        if (! $next instanceof TelegramFlowNode) {
            $this->finish($chatId);

            return true;
        }

        $this->execute($flow, $bot, $chatId, $next, $context);

        return true;
    }

    /**
     * Walk forward from a node until the flow blocks or ends.
     *
     * @param  array<string, mixed>  $context
     */
    public function execute(
        TelegramFlow $flow,
        TelegramBot $bot,
        int $chatId,
        TelegramFlowNode $node,
        array $context,
        int $hops = 0,
    ): void {
        $maxHops = $flow->maxHops();
        $current = $node;

        while ($hops < $maxHops) {
            $hops++;

            $this->state->patch($chatId, [
                'flow_id' => (int) $flow->getKey(),
                'node_id' => $current->node_key,
                'step' => $hops,
                'context' => $context,
            ]);

            $context = $this->runNode($flow, $bot, $chatId, $current, $context);

            if ($current->type->isTerminal()) {
                $this->finish($chatId);

                return;
            }

            // Blocking nodes park the run; the next update (or ContinueFlow for
            // a delay) picks it up from the persisted state.
            if ($current->type->isBlocking()) {
                return;
            }

            $next = $this->nextNode($flow, $current, $context);

            if (! $next instanceof TelegramFlowNode) {
                $this->finish($chatId);

                return;
            }

            $current = $next;
        }

        Log::warning('Flow hop limit reached; aborting run.', [
            'flow_id' => $flow->getKey(),
            'chat_id' => $chatId,
            'max_hops' => $maxHops,
        ]);

        $this->finish($chatId);
    }

    // ------------------------------------------------------------------ nodes

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runNode(
        TelegramFlow $flow,
        TelegramBot $bot,
        int $chatId,
        TelegramFlowNode $node,
        array $context,
    ): array {
        return match ($node->type) {
            FlowNodeType::Trigger => $context,
            FlowNodeType::Message => $this->runMessage($bot, $chatId, $node, $context),
            FlowNodeType::Question => $this->runQuestion($bot, $chatId, $node, $context),
            FlowNodeType::Condition => $context,
            FlowNodeType::Action => $this->runAction($bot, $chatId, $node, $context),
            FlowNodeType::Ai => $this->runAi($node, $context),
            FlowNodeType::Delay => $this->runDelay($flow, $chatId, $node, $context),
            FlowNodeType::Handoff => $this->runHandoff($bot, $chatId, $node, $context),
            FlowNodeType::Webhook => $this->runWebhook($node, $context),
            FlowNodeType::End => $this->runEnd($bot, $chatId, $node, $context),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runMessage(TelegramBot $bot, int $chatId, TelegramFlowNode $node, array $context): array
    {
        $text = $this->renderText($node->setting('text', ''), $context);

        if ($text === '') {
            return $context;
        }

        $photo = $node->setting('photo');

        $message = is_string($photo) && $photo !== ''
            ? OutgoingMessage::photo($chatId, $photo, $text)
            : OutgoingMessage::text($chatId, $text);

        $this->sender->queue($message->withKeyboard($this->keyboardFor($node)), $bot->academy_id);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runQuestion(TelegramBot $bot, int $chatId, TelegramFlowNode $node, array $context): array
    {
        $text = $this->renderText($node->setting('text', ''), $context);

        if ($text !== '') {
            $this->sender->queue(
                OutgoingMessage::text($chatId, $text)->withKeyboard($this->keyboardFor($node)),
                $bot->academy_id
            );
        }

        return $context;
    }

    /**
     * Business actions are owned by other contexts; the flow node records the
     * intent and an application listener performs it. Keeping the engine free
     * of cross-domain calls is what lets Assessment and Commerce evolve without
     * touching the Telegram layer.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runAction(TelegramBot $bot, int $chatId, TelegramFlowNode $node, array $context): array
    {
        $action = $node->setting('action');

        if (! is_string($action) || $action === '') {
            return $context;
        }

        $context['last_action'] = $action;
        $context['pending_actions'][] = [
            'action' => $action,
            'params' => is_array($node->setting('params')) ? $node->setting('params') : [],
            'chat_id' => $chatId,
            'bot_id' => (int) $bot->getKey(),
            'at' => now()->toIso8601String(),
        ];

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runAi(TelegramFlowNode $node, array $context): array
    {
        // The AI gateway (docs/06) owns quota, caching and provider fallback.
        // Until it is wired in, the node is a recorded no-op rather than a
        // silent unmetered call.
        $context['pending_ai'][] = [
            'prompt' => $this->renderText($node->setting('prompt', ''), $context),
            'node_key' => $node->node_key,
        ];

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runDelay(TelegramFlow $flow, int $chatId, TelegramFlowNode $node, array $context): array
    {
        $seconds = max(1, (int) $node->setting('seconds', 60));

        ContinueFlow::dispatch(
            (int) $flow->academy_id,
            (int) $flow->getKey(),
            $chatId,
            $node->node_key,
        )
            ->delay(now()->addSeconds($seconds))
            ->onQueue((string) config('pte.queues.telegram_out', 'telegram-out'));

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runHandoff(TelegramBot $bot, int $chatId, TelegramFlowNode $node, array $context): array
    {
        $context['handoff'] = [
            'requested_at' => now()->toIso8601String(),
            'note' => $node->setting('note'),
        ];

        $this->sender->queue(
            OutgoingMessage::text($chatId, __('telegram.flow.handoff')),
            $bot->academy_id
        );

        return $context;
    }

    /**
     * Call an academy-configured external endpoint.
     *
     * The URL comes from tenant input, so it goes through SafeUrl (HTTPS only,
     * no private/link-local/metadata addresses) and is capped at five seconds —
     * a slow CRM must not hold a queue worker.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runWebhook(TelegramFlowNode $node, array $context): array
    {
        $url = $node->setting('url');

        if (! is_string($url) || $url === '') {
            return $context;
        }

        try {
            $safe = SafeUrl::validate($url);
        } catch (InvalidArgumentException $e) {
            Log::warning('Flow webhook node blocked by the SSRF guard.', [
                'node_key' => $node->node_key,
                'reason' => $e->getMessage(),
            ]);

            $context['webhook_error'] = 'blocked';

            return $context;
        }

        $body = is_array($node->setting('body')) ? $node->setting('body') : [];
        $headers = is_array($node->setting('headers')) ? $node->setting('headers') : [];

        try {
            $response = Http::timeout(self::WEBHOOK_TIMEOUT)
                ->connectTimeout(self::WEBHOOK_TIMEOUT)
                ->withHeaders(array_map('strval', $headers))
                // Pin the addresses resolved during validation so a rebinding
                // attack cannot swing the connection to an internal host.
                ->withOptions(['allow_redirects' => false, 'curl' => [
                    CURLOPT_RESOLVE => $safe->curlResolveEntries(),
                ]])
                ->post($safe->url, $body + ['context' => $context]);

            $context['webhook_status'] = $response->status();
            $context['webhook_response'] = is_array($response->json()) ? $response->json() : null;
        } catch (Throwable $e) {
            Log::warning('Flow webhook node failed.', [
                'node_key' => $node->node_key,
                'error' => $e->getMessage(),
            ]);

            $context['webhook_error'] = 'unreachable';
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function runEnd(TelegramBot $bot, int $chatId, TelegramFlowNode $node, array $context): array
    {
        $text = $this->renderText($node->setting('text', ''), $context);

        if ($text !== '') {
            $this->sender->queue(OutgoingMessage::text($chatId, $text), $bot->academy_id);
        }

        return $context;
    }

    // ---------------------------------------------------------------- routing

    /**
     * @param  array<string, mixed>  $context
     */
    private function nextNode(TelegramFlow $flow, TelegramFlowNode $node, array $context): ?TelegramFlowNode
    {
        /** @var Collection<int, TelegramFlowEdge> $edges */
        $edges = TelegramFlowEdge::query()
            ->where('flow_id', $flow->getKey())
            ->where('from_node', $node->node_key)
            ->orderBy('sort_order')
            ->get();

        $fallback = null;

        foreach ($edges as $edge) {
            if ($edge->is_default) {
                $fallback ??= $edge;

                continue;
            }

            if (ConditionEvaluator::passes($edge->condition, $context)) {
                return $this->node($flow, $edge->to_node);
            }
        }

        return $fallback instanceof TelegramFlowEdge ? $this->node($flow, $fallback->to_node) : null;
    }

    private function node(TelegramFlow $flow, string $nodeKey): ?TelegramFlowNode
    {
        return TelegramFlowNode::query()
            ->where('flow_id', $flow->getKey())
            ->where('node_key', $nodeKey)
            ->first();
    }

    /**
     * Store whatever the student just sent under the question node's variable.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function captureAnswer(TelegramFlowNode $node, IncomingUpdate $update, array $context): array
    {
        if ($node->type !== FlowNodeType::Question) {
            return $context;
        }

        $variable = $node->setting('variable');
        $variable = is_string($variable) && $variable !== '' ? $variable : $node->node_key;

        $value = match (true) {
            $update->callbackData !== null => CallbackData::decode($update->callbackData)?->param
                ?? $update->callbackData,
            $update->fileId() !== null => $update->fileId(),
            default => $update->text,
        };

        $context[$variable] = $value;
        $context['last_answer'] = $value;

        return $context;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function keyboardFor(TelegramFlowNode $node): ?array
    {
        $buttons = $node->setting('buttons');

        if (! is_array($buttons) || $buttons === []) {
            return null;
        }

        $rows = [];

        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }

            $label = (string) ($button['label'] ?? $button['text'] ?? '');

            if ($label === '') {
                continue;
            }

            if (isset($button['url']) && is_string($button['url'])) {
                $rows[] = [['text' => $label, 'url' => $button['url']]];

                continue;
            }

            $rows[] = [[
                'text' => $label,
                'callback_data' => CallbackData::make(
                    action: 'flw',
                    target: $node->node_key,
                    param: (string) ($button['value'] ?? $label),
                )->encode(),
            ]];
        }

        return $rows === [] ? null : ['inline_keyboard' => $rows];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderText(mixed $template, array $context): string
    {
        if (! is_string($template) || $template === '') {
            return '';
        }

        $bag = PlaceholderContext::make($context)
            ->withMoment(timezone: TenantContext::get()?->timezone);

        $academy = TenantContext::get();

        if ($academy !== null) {
            $bag = $bag->forAcademy($academy);
        }

        return $this->placeholders->render($template, $bag);
    }

    private function finish(int $chatId): void
    {
        $this->state->clear($chatId);
    }

    private function abandon(TelegramBot $bot, int $chatId): void
    {
        $this->state->clear($chatId);

        $this->sender->queue(
            OutgoingMessage::text($chatId, __('telegram.flow.timed_out')),
            $bot->academy_id
        );
    }

    // ------------------------------------------------------- publish-time checks

    /**
     * Find loops that can spin without the student ever being asked anything.
     *
     * A cycle through a `question` or `delay` node is legitimate — a retry loop
     * waits for input each time round. A cycle made only of instantaneous nodes
     * is the one that burns the hop limit on every run, so only those are
     * reported.
     *
     * @return array<int, array<int, string>> each entry is a node-key cycle
     */
    public function detectCycles(TelegramFlow $flow): array
    {
        /** @var array<string, FlowNodeType> $types */
        $types = $flow->nodes()->get()
            ->mapWithKeys(static fn (TelegramFlowNode $n): array => [$n->node_key => $n->type])
            ->all();

        /** @var array<string, array<int, string>> $adjacency */
        $adjacency = [];

        foreach ($flow->edges()->get() as $edge) {
            $from = $edge->from_node;

            // Edges leaving a blocking node cannot close an instantaneous loop.
            if (($types[$from] ?? null)?->isBlocking() === true) {
                continue;
            }

            $adjacency[$from][] = $edge->to_node;
        }

        $cycles = [];
        $state = [];
        $stack = [];

        $visit = static function (string $node) use (&$visit, &$state, &$stack, &$cycles, $adjacency): void {
            $state[$node] = 'open';
            $stack[] = $node;

            foreach ($adjacency[$node] ?? [] as $next) {
                if (($state[$next] ?? null) === 'open') {
                    $start = array_search($next, $stack, true);
                    $cycles[] = array_values(array_slice($stack, $start === false ? 0 : $start));

                    continue;
                }

                if (! isset($state[$next])) {
                    $visit($next);
                }
            }

            array_pop($stack);
            $state[$node] = 'closed';
        };

        foreach (array_keys($types) as $nodeKey) {
            if (! isset($state[$nodeKey])) {
                $visit($nodeKey);
            }
        }

        return $cycles;
    }

    /**
     * Node keys referenced by an edge but never defined.
     *
     * @return array<int, string>
     */
    public function danglingEdges(TelegramFlow $flow): array
    {
        $known = $flow->nodes()->pluck('node_key')->all();
        $missing = [];

        foreach ($flow->edges()->get() as $edge) {
            foreach ([$edge->from_node, $edge->to_node] as $key) {
                if (! in_array($key, $known, true)) {
                    $missing[] = $key;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /** Used by ContinueFlow to resume a delay node without an inbound update. */
    public function continueFrom(TelegramFlow $flow, TelegramBot $bot, int $chatId, string $nodeKey): void
    {
        $node = $this->node($flow, $nodeKey);

        if (! $node instanceof TelegramFlowNode) {
            $this->state->clear($chatId);

            return;
        }

        $context = $this->state->context($chatId);
        $next = $this->nextNode($flow, $node, $context);

        if (! $next instanceof TelegramFlowNode) {
            $this->finish($chatId);

            return;
        }

        $this->execute($flow, $bot, $chatId, $next, $context);
    }
}

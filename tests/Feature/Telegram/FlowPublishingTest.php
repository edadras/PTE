<?php

declare(strict_types=1);

namespace Tests\Feature\Telegram;

use App\Domain\Telegram\Actions\PublishFlow;
use App\Domain\Telegram\Enums\FlowNodeType;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Domain\Telegram\Models\TelegramFlowEdge;
use App\Domain\Telegram\Models\TelegramFlowNode;
use App\Domain\Tenancy\Models\Academy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Publishing is where a flow stops being a draft and starts answering real
 * students, so every way a broken graph can reach production is checked here
 * rather than at run time — a flow that fails mid-conversation strands the
 * student with no way out.
 *
 * @see docs/04-telegram-layer.md §6
 */
final class FlowPublishingTest extends TestCase
{
    use RefreshDatabase;

    private Academy $academy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->academy = Academy::factory()->create(['slug' => 'flows']);
        TenantContext::set($this->academy);
    }

    #[Test]
    public function a_well_formed_flow_publishes(): void
    {
        $flow = $this->flow();
        $this->node($flow, 'start', FlowNodeType::Trigger);
        $this->node($flow, 'greet', FlowNodeType::Message, ['text' => 'Hello']);
        $this->node($flow, 'done', FlowNodeType::End);
        $this->edge($flow, 'start', 'greet');
        $this->edge($flow, 'greet', 'done');

        $published = app(PublishFlow::class)->handle($flow);

        $this->assertSame([], app(PublishFlow::class)->validate($flow->refresh()));
        $this->assertNotNull($published->published_at);
    }

    #[Test]
    public function an_empty_flow_is_refused(): void
    {
        $this->assertNotSame([], app(PublishFlow::class)->validate($this->flow()));
    }

    #[Test]
    public function a_flow_with_no_exit_is_refused(): void
    {
        $flow = $this->flow();
        $this->node($flow, 'start', FlowNodeType::Trigger);
        $this->node($flow, 'greet', FlowNodeType::Message, ['text' => 'Hello']);
        $this->edge($flow, 'start', 'greet');

        $this->assertNotSame([], app(PublishFlow::class)->validate($flow));
    }

    #[Test]
    public function an_edge_pointing_nowhere_is_refused(): void
    {
        $flow = $this->flow();
        $this->node($flow, 'start', FlowNodeType::Trigger);
        $this->node($flow, 'done', FlowNodeType::End);
        $this->edge($flow, 'start', 'ghost');

        $this->assertNotSame([], app(PublishFlow::class)->validate($flow));
    }

    #[Test]
    public function an_instantaneous_cycle_is_refused_at_publish_time(): void
    {
        // Two message nodes pointing at each other never yield to the student,
        // so the hop limit would be the only thing stopping it at run time.
        $flow = $this->flow();
        $this->node($flow, 'start', FlowNodeType::Trigger);
        $this->node($flow, 'a', FlowNodeType::Message, ['text' => 'A']);
        $this->node($flow, 'b', FlowNodeType::Message, ['text' => 'B']);
        $this->node($flow, 'done', FlowNodeType::End);
        $this->edge($flow, 'start', 'a');
        $this->edge($flow, 'a', 'b');
        $this->edge($flow, 'b', 'a');

        $this->assertNotSame([], app(PublishFlow::class)->validate($flow));

        $this->expectException(RuntimeException::class);
        app(PublishFlow::class)->handle($flow);
    }

    #[Test]
    public function a_webhook_node_aimed_at_the_private_network_is_refused(): void
    {
        $flow = $this->flow();
        $this->node($flow, 'start', FlowNodeType::Trigger);
        $this->node($flow, 'hook', FlowNodeType::Webhook, ['url' => 'http://169.254.169.254/latest/meta-data/']);
        $this->node($flow, 'done', FlowNodeType::End);
        $this->edge($flow, 'start', 'hook');
        $this->edge($flow, 'hook', 'done');

        $this->assertNotSame([], app(PublishFlow::class)->validate($flow));
    }

    #[Test]
    public function flows_never_cross_the_academy_boundary(): void
    {
        $this->flow();

        $other = Academy::factory()->create(['slug' => 'other-flows']);
        TenantContext::runFor($other, function (): void {
            $this->assertSame(0, TelegramFlow::query()->count());
        });
    }

    private function flow(): TelegramFlow
    {
        return TelegramFlow::query()->create([
            'name' => 'Lead capture',
            'trigger_type' => 'start',
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function node(TelegramFlow $flow, string $key, FlowNodeType $type, array $config = []): TelegramFlowNode
    {
        return TelegramFlowNode::query()->create([
            'flow_id' => $flow->getKey(),
            'node_key' => $key,
            'type' => $type,
            'config' => $config,
        ]);
    }

    private function edge(TelegramFlow $flow, string $from, string $to): TelegramFlowEdge
    {
        return TelegramFlowEdge::query()->create([
            'flow_id' => $flow->getKey(),
            'from_node' => $from,
            'to_node' => $to,
        ]);
    }
}

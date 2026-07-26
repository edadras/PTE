<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Enums;

/**
 * Node kinds available in the visual flow builder.
 *
 * @see docs/04-telegram-layer.md §6
 */
enum FlowNodeType: string
{
    case Trigger = 'trigger';
    case Message = 'message';
    case Question = 'question';
    case Condition = 'condition';
    case Action = 'action';
    case Ai = 'ai';
    case Delay = 'delay';
    case Handoff = 'handoff';
    case Webhook = 'webhook';
    case End = 'end';

    public function label(): string
    {
        return __('telegram.flow_node.'.$this->value);
    }

    /**
     * Nodes that park the run and wait for the student.
     *
     * The engine stops walking the graph here and persists state instead; the
     * next update resumes from this node.
     */
    public function isBlocking(): bool
    {
        return in_array($this, [self::Question, self::Delay, self::Handoff], true);
    }

    /** Nodes that end a run — no outgoing edge is followed. */
    public function isTerminal(): bool
    {
        return $this === self::End;
    }

    /** Nodes whose outgoing edges are chosen by evaluating edge conditions. */
    public function isBranching(): bool
    {
        return $this === self::Condition || $this === self::Question;
    }
}

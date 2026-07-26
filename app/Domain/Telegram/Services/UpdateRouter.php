<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Services;

use App\Domain\Telegram\Data\DeepLinkPayload;
use App\Domain\Telegram\Data\IncomingUpdate;
use App\Domain\Telegram\Data\RoutingDecision;
use App\Domain\Telegram\Data\VisibilityContext;
use App\Domain\Telegram\Enums\UpdateType;
use App\Domain\Telegram\Models\TelegramMenuItem;
use App\Domain\Telegram\Support\CallbackData;

/**
 * Decides what an inbound update *means*, in the order laid out in docs/04 §4:
 *
 *     /start [payload]  →  global command  →  callback query
 *                       →  active flow     →  menu label  →  fallback
 *
 * Global commands are checked before flow state on purpose: a student stuck
 * halfway through a flow must always be able to type /cancel or /menu and get
 * out. A bot you can get trapped in is a bot people stop using.
 */
final class UpdateRouter
{
    /**
     * Commands that must work from any state, including mid-flow.
     */
    public const GLOBAL_COMMANDS = ['/help', '/menu', '/cancel', '/support'];

    public function __construct(
        private readonly ConversationState $state,
        private readonly DeepLinkResolver $deepLinks,
        private readonly MenuEngine $menus,
    ) {}

    public function route(IncomingUpdate $update, VisibilityContext $context, ?int $academyId = null): RoutingDecision
    {
        if (! $update->type->isConversational()) {
            return $this->routeNonConversational($update);
        }

        if ($update->chatId === null) {
            return RoutingDecision::ignore('no_chat');
        }

        // 1. /start, with or without a deep-link payload.
        if ($update->command() === '/start') {
            $payload = $this->deepLinks->resolve($update->commandArgument());

            return $payload instanceof DeepLinkPayload && $payload->isKnown()
                ? RoutingDecision::deepLink($payload)
                : RoutingDecision::start();
        }

        // 2. Global commands — highest priority, from any state.
        $command = $update->command();

        if ($command !== null && in_array($command, self::GLOBAL_COMMANDS, true)) {
            return RoutingDecision::globalCommand($command);
        }

        // 3. Callback queries carry their own routing information.
        if ($update->type === UpdateType::CallbackQuery) {
            $data = $update->callbackData === null ? null : CallbackData::decode($update->callbackData);

            return $data instanceof CallbackData
                ? RoutingDecision::callback($data)
                : RoutingDecision::fallback();
        }

        // 4. An active flow consumes the message.
        $flowId = $this->state->flowId($update->chatId, $academyId);

        if ($flowId !== null) {
            return RoutingDecision::flowResume(
                $flowId,
                $this->state->nodeId($update->chatId, $academyId)
            );
        }

        // 5. Text that matches a button on the live keyboard.
        if ($update->text !== null) {
            $item = $this->menus->matchLabel($update->text, $context, $academyId);

            if ($item instanceof TelegramMenuItem) {
                return RoutingDecision::menuItem((int) $item->getKey());
            }
        }

        // 6. Anything else: unknown commands, free text, media outside a flow.
        return RoutingDecision::fallback();
    }

    private function routeNonConversational(IncomingUpdate $update): RoutingDecision
    {
        return match ($update->type) {
            UpdateType::SuccessfulPayment, UpdateType::PreCheckoutQuery => RoutingDecision::make(
                RoutingDecision::INTENT_PAYMENT,
                ['type' => $update->type->value]
            ),
            UpdateType::MyChatMember, UpdateType::ChatMember => RoutingDecision::make(
                RoutingDecision::INTENT_CHAT_MEMBER,
                ['status' => data_get($update->payload, $update->type->value.'.new_chat_member.status')]
            ),
            default => RoutingDecision::ignore($update->type->value),
        };
    }

    /**
     * The command list registered with setMyCommands.
     *
     * @return array<int, array{command: string, description: string}>
     */
    public static function botCommands(): array
    {
        return [
            ['command' => 'start', 'description' => __('telegram.command.start')],
            ['command' => 'menu', 'description' => __('telegram.command.menu')],
            ['command' => 'help', 'description' => __('telegram.command.help')],
            ['command' => 'support', 'description' => __('telegram.command.support')],
            ['command' => 'cancel', 'description' => __('telegram.command.cancel')],
        ];
    }
}

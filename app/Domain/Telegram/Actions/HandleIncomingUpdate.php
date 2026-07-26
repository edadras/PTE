<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Actions;

use App\Domain\Telegram\Data\IncomingUpdate;
use App\Domain\Telegram\Data\OutgoingMessage;
use App\Domain\Telegram\Data\RoutingDecision;
use App\Domain\Telegram\Data\VisibilityContext;
use App\Domain\Telegram\Enums\MenuActionType;
use App\Domain\Telegram\Enums\MenuType;
use App\Domain\Telegram\Enums\MessageDirection;
use App\Domain\Telegram\Events\MenuActionInvoked;
use App\Domain\Telegram\Events\StudentStartedBot;
use App\Domain\Telegram\Models\TelegramBot;
use App\Domain\Telegram\Models\TelegramFlow;
use App\Domain\Telegram\Models\TelegramIdentity;
use App\Domain\Telegram\Models\TelegramMenuItem;
use App\Domain\Telegram\Models\TelegramMessage;
use App\Domain\Telegram\Services\ConversationState;
use App\Domain\Telegram\Services\FlowEngine;
use App\Domain\Telegram\Services\MenuEngine;
use App\Domain\Telegram\Services\MessageSender;
use App\Domain\Telegram\Services\TelegramClient;
use App\Domain\Telegram\Services\UpdateRouter;
use App\Domain\Telegram\Support\CallbackData;
use App\Domain\Tenancy\TenantContext;

/**
 * The conversation orchestrator: takes a routed update and makes the bot reply.
 *
 * Every branch that needs work owned by another context raises an event rather
 * than calling into it, which is what keeps this class stable while Assessment,
 * Commerce and Support keep changing.
 *
 * @see docs/04-telegram-layer.md §4
 */
final class HandleIncomingUpdate
{
    public function __construct(
        private readonly UpdateRouter $router,
        private readonly MenuEngine $menus,
        private readonly MessageSender $sender,
        private readonly ConversationState $state,
        private readonly FlowEngine $flows,
        private readonly TelegramClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(TelegramBot $bot, array $payload): void
    {
        $update = IncomingUpdate::fromPayload($payload);

        if ($update->chatId === null || $update->userId === null) {
            return;
        }

        $identity = $this->syncIdentity($bot, $update);
        $this->recordInbound($bot, $update, $identity);

        $context = VisibilityContext::make(TenantContext::get(), $identity->student);

        $decision = $this->router->route($update, $context, $bot->academy_id);

        // Acknowledge the tap immediately; the spinner on the button is the
        // only feedback the student gets while the queue catches up.
        if ($update->callbackQueryId !== null) {
            $this->client->forBot($bot)->answerCallbackQuery($update->callbackQueryId);
        }

        match ($decision->intent) {
            RoutingDecision::INTENT_DEEP_LINK, RoutingDecision::INTENT_START => $this->handleStart($bot, $identity, $update, $context, $decision),
            RoutingDecision::INTENT_GLOBAL_COMMAND => $this->handleGlobalCommand($bot, $identity, $update, $context, $decision),
            RoutingDecision::INTENT_CALLBACK => $this->handleCallback($bot, $identity, $update, $context, $decision),
            RoutingDecision::INTENT_FLOW_RESUME => $this->handleFlowResume($bot, $update),
            RoutingDecision::INTENT_MENU_ITEM => $this->runMenuItem($bot, $identity, $update, $context, (int) $decision->get('menu_item_id')),
            RoutingDecision::INTENT_FALLBACK => $this->handleFallback($bot, $update, $context),
            default => null,
        };

        $identity->touchInteraction();
    }

    // ---------------------------------------------------------------- branches

    private function handleStart(
        TelegramBot $bot,
        TelegramIdentity $identity,
        IncomingUpdate $update,
        VisibilityContext $context,
        RoutingDecision $decision,
    ): void {
        // /start is also the universal escape hatch — drop any parked flow.
        $this->state->clear((int) $update->chatId);

        StudentStartedBot::dispatch($bot, $identity, $decision->deepLink);

        $this->send($bot, OutgoingMessage::text(
            (int) $update->chatId,
            __('telegram.welcome', ['name' => $identity->displayName()])
        ));

        $this->sendMainMenu($bot, (int) $update->chatId, $context);
    }

    private function handleGlobalCommand(
        TelegramBot $bot,
        TelegramIdentity $identity,
        IncomingUpdate $update,
        VisibilityContext $context,
        RoutingDecision $decision,
    ): void {
        $chatId = (int) $update->chatId;

        match ($decision->get('command')) {
            '/menu' => $this->menuCommand($bot, $chatId, $context),
            '/cancel' => $this->cancelCommand($bot, $chatId, $context),
            '/support' => $this->supportCommand($bot, $identity, $chatId),
            default => $this->send($bot, OutgoingMessage::text($chatId, __('telegram.help'))),
        };
    }

    private function menuCommand(TelegramBot $bot, int $chatId, VisibilityContext $context): void
    {
        $this->state->clear($chatId);
        $this->sendMainMenu($bot, $chatId, $context);
    }

    private function cancelCommand(TelegramBot $bot, int $chatId, VisibilityContext $context): void
    {
        $this->state->clear($chatId);

        $this->send($bot, OutgoingMessage::text($chatId, __('telegram.cancelled')));
        $this->sendMainMenu($bot, $chatId, $context);
    }

    private function supportCommand(TelegramBot $bot, TelegramIdentity $identity, int $chatId): void
    {
        $this->send($bot, OutgoingMessage::text($chatId, __('telegram.support_greeting')));

        MenuActionInvoked::dispatch($bot, $identity, MenuActionType::ContactSupport, [], $chatId);
    }

    private function handleCallback(
        TelegramBot $bot,
        TelegramIdentity $identity,
        IncomingUpdate $update,
        VisibilityContext $context,
        RoutingDecision $decision,
    ): void {
        $callback = $decision->callback;

        if (! $callback instanceof CallbackData) {
            return;
        }

        // Flow buttons carry their node key; hand them straight to the engine.
        if ($callback->action === 'flw' && $this->state->flowId((int) $update->chatId) !== null) {
            $this->handleFlowResume($bot, $update);

            return;
        }

        $itemId = is_numeric($callback->target) ? (int) $callback->target : null;

        if ($itemId !== null) {
            $this->runMenuItem($bot, $identity, $update, $context, $itemId);

            return;
        }

        $this->handleFallback($bot, $update, $context);
    }

    private function handleFlowResume(TelegramBot $bot, IncomingUpdate $update): void
    {
        $this->flows->resume($bot, $update);
    }

    private function handleFallback(TelegramBot $bot, IncomingUpdate $update, VisibilityContext $context): void
    {
        $this->send($bot, OutgoingMessage::text((int) $update->chatId, __('telegram.fallback')));
        $this->sendMainMenu($bot, (int) $update->chatId, $context);
    }

    // ------------------------------------------------------------ menu actions

    private function runMenuItem(
        TelegramBot $bot,
        TelegramIdentity $identity,
        IncomingUpdate $update,
        VisibilityContext $context,
        int $itemId,
    ): void {
        $item = $this->menus->findItem($itemId, $bot->academy_id);
        $chatId = (int) $update->chatId;

        if (! $item instanceof TelegramMenuItem || ! $item->is_enabled) {
            $this->handleFallback($bot, $update, $context);

            return;
        }

        /** @var array<string, mixed> $payload */
        $payload = $item->action_payload ?? [];

        match ($item->action_type) {
            MenuActionType::SendMessage => $this->send($bot, OutgoingMessage::text(
                $chatId,
                (string) ($payload['text'] ?? '')
            )),

            MenuActionType::OpenModule => $this->sendSubmenu($bot, $chatId, $context, $itemId),

            MenuActionType::RunFlow => $this->startFlow($bot, $chatId, $payload),

            // Handled entirely by the inline button itself.
            MenuActionType::OpenUrl, MenuActionType::OpenWebapp => null,

            default => MenuActionInvoked::dispatch($bot, $identity, $item->action_type, $payload, $chatId),
        };

        $this->state->push($chatId, 'item:'.$itemId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function startFlow(TelegramBot $bot, int $chatId, array $payload): void
    {
        $flowId = $payload['flow_id'] ?? null;

        if (! is_numeric($flowId)) {
            return;
        }

        $flow = TelegramFlow::query()->published()->find((int) $flowId);

        if ($flow instanceof TelegramFlow) {
            $this->flows->start($flow, $bot, $chatId);
        }
    }

    private function sendSubmenu(TelegramBot $bot, int $chatId, VisibilityContext $context, int $parentItemId): void
    {
        $keyboard = $this->menus->render(MenuType::Inline, $context, $parentItemId, $bot->academy_id)
            ?? $this->menus->render(MenuType::Main, $context, $parentItemId, $bot->academy_id);

        if ($keyboard === null) {
            $this->send($bot, OutgoingMessage::text($chatId, __('telegram.empty_menu')));

            return;
        }

        $this->send($bot, OutgoingMessage::text($chatId, __('telegram.menu_header'))->withKeyboard($keyboard));
    }

    private function sendMainMenu(TelegramBot $bot, int $chatId, VisibilityContext $context): void
    {
        $keyboard = $this->menus->render(MenuType::Main, $context, null, $bot->academy_id);

        if ($keyboard === null) {
            $this->send($bot, OutgoingMessage::text($chatId, __('telegram.empty_menu')));

            return;
        }

        $this->send($bot, OutgoingMessage::text($chatId, __('telegram.menu_header'))->withKeyboard($keyboard));
    }

    private function send(TelegramBot $bot, OutgoingMessage $message): void
    {
        $this->sender->queue($message, $bot->academy_id);
    }

    // ------------------------------------------------------------- persistence

    private function syncIdentity(TelegramBot $bot, IncomingUpdate $update): TelegramIdentity
    {
        /** @var TelegramIdentity $identity */
        $identity = TelegramIdentity::query()->firstOrNew(
            ['telegram_user_id' => $update->userId],
            ['chat_id' => $update->chatId, 'linked_at' => null],
        );

        $identity->fill(array_filter([
            'chat_id' => $update->chatId,
            'username' => $update->username,
            'first_name' => $update->firstName,
            'last_name' => $update->lastName,
            'language_code' => $update->languageCode,
        ], static fn (mixed $value): bool => $value !== null));

        // Any inbound message proves the student has not blocked the bot.
        if ($identity->is_blocked) {
            $identity->is_blocked = false;
            $identity->blocked_at = null;
        }

        $identity->save();

        return $identity;
    }

    private function recordInbound(TelegramBot $bot, IncomingUpdate $update, TelegramIdentity $identity): void
    {
        $media = $update->media();

        TelegramMessage::query()->create([
            'academy_id' => $bot->academy_id,
            'telegram_bot_id' => $bot->getKey(),
            'student_id' => $identity->student_id,
            'chat_id' => $update->chatId,
            'direction' => MessageDirection::In,
            'message_type' => $media['kind'] ?? 'text',
            'content' => $update->text === null ? null : mb_substr($update->text, 0, 4000),
            'meta' => $media === null ? null : ['file_id' => $update->fileId()],
            'telegram_message_id' => $update->messageId,
            'status' => 'received',
            'created_at' => now(),
        ]);
    }
}

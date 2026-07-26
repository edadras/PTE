<?php

declare(strict_types=1);

/*
 * Every string the bot can say, and every label the panel shows for a Telegram
 * enum. Academies may override the conversational ones through message
 * templates (docs/03 §6); these are the fallbacks.
 */

return [

    // --- conversation ------------------------------------------------------

    'welcome' => "Hello :name! 👋\nWelcome. Choose an option below to get started.",
    'menu_header' => 'What would you like to do?',
    'empty_menu' => 'This bot has not been set up yet. Please contact your academy.',
    'help' => "Here is what I can do:\n\n/menu — main menu\n/cancel — stop what you are doing\n/support — talk to a human\n/help — this message",
    'cancelled' => 'Cancelled. You are back at the main menu.',
    'support_greeting' => 'Tell us what you need and our team will get back to you.',
    'fallback' => 'I did not understand that. Use the menu below, or send /help.',
    'connected_test_message' => '✅ Your bot is connected and working.',
    'back_to_menu' => '🏠 Back to menu',

    'flow' => [
        'handoff' => 'Connecting you with our team — one moment.',
        'timed_out' => 'That conversation timed out. Send /menu to start again.',
    ],

    // --- connection errors (shown to the academy owner) --------------------

    'connect_error' => [
        'invalid_token' => 'That token is not valid. Make sure you copied the whole string from BotFather.',
        'token_in_use' => 'This bot is already connected to another academy.',
        'temporary' => 'Temporary problem reaching Telegram — we will retry automatically up to five times.',
        'rate_limited' => 'Telegram is rate-limiting this bot. Please try again in a few minutes.',
        'foreign_webhook' => 'This bot was previously connected to :host. Continuing will disconnect it from there.',
        'generic' => 'The bot could not be connected. Please try again.',
    ],

    // --- flow publishing ---------------------------------------------------

    'flow_error' => [
        'empty' => 'This flow has no nodes.',
        'no_entry' => 'This flow has no trigger node to start from.',
        'no_end' => 'This flow has no end node.',
        'dangling' => 'These nodes are referenced by a connection but do not exist: :nodes',
        'cycle' => 'This flow contains an endless loop: :nodes',
        'unsafe_webhook' => 'The webhook node ":node" has a URL that is not allowed. Use an https:// address on a public host.',
    ],

    // --- bot commands ------------------------------------------------------

    'command' => [
        'start' => 'Start over',
        'menu' => 'Main menu',
        'help' => 'How to use this bot',
        'support' => 'Contact support',
        'cancel' => 'Cancel the current step',
    ],

    // --- enum labels -------------------------------------------------------

    'menu_type' => [
        'main' => 'Main keyboard',
        'inline' => 'Inline buttons',
        'command' => 'Command list',
        'persistent' => 'Persistent keyboard',
    ],

    'menu_action' => [
        'open_url' => 'Open a link',
        'send_message' => 'Send a message',
        'open_module' => 'Open a module',
        'start_practice' => 'Start practice',
        'start_exam' => 'Start an exam',
        'open_course' => 'Open a course',
        'buy_plan' => 'Buy a plan',
        'contact_support' => 'Contact support',
        'run_flow' => 'Run a flow',
        'open_webapp' => 'Open the mini app',
        'run_command' => 'Run a command',
    ],

    'update_type' => [
        'message' => 'Message',
        'edited_message' => 'Edited message',
        'callback_query' => 'Button tap',
        'inline_query' => 'Inline query',
        'pre_checkout_query' => 'Pre-checkout',
        'successful_payment' => 'Payment',
        'my_chat_member' => 'Bot membership change',
        'chat_member' => 'Member change',
        'channel_post' => 'Channel post',
        'unknown' => 'Unknown',
    ],

    'health' => [
        'ok' => 'Healthy',
        'degraded' => 'Degraded',
        'failing' => 'Failing',
    ],

    'flow_node' => [
        'trigger' => 'Trigger',
        'message' => 'Message',
        'question' => 'Question',
        'condition' => 'Condition',
        'action' => 'Action',
        'ai' => 'AI',
        'delay' => 'Delay',
        'handoff' => 'Human handoff',
        'webhook' => 'Webhook',
        'end' => 'End',
    ],

    'direction' => [
        'in' => 'Incoming',
        'out' => 'Outgoing',
    ],

    'publish_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'broadcast_status' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'running' => 'Sending',
        'paused' => 'Paused',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'failed' => 'Failed',
    ],

];

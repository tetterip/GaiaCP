<?php

// Merged into config by App\Plugins\PluginManager (values already in config win).
return [
    // Settings → Telegram: the bot's token (from @BotFather, stored encrypted), the chat its messages go to (a person or
    // a group of admins), the events posted there, and whether that chat may ask the bot (/owed, /today, …).
    'gaiacp.telegram' => [
        'bot_token' => null,
        'chat_id' => null,
        'events' => ['order.placed', 'payment.recorded', 'ticket.opened', 'ticket.client_replied', 'server.down', 'server.up', 'provisioning.failed'],
        'commands' => false,
    ],
];

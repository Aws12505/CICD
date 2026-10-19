<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Terminal gateway
    |--------------------------------------------------------------------------
    |
    | The Node gateway in terminal-gateway/ holds browser terminal sessions.
    | It redeems tokens with this shared secret, which must match its own
    | TERMINAL_GATEWAY_SECRET. Unset, the terminal is switched off.
    |
    */

    'gateway_secret' => env('TERMINAL_GATEWAY_SECRET'),

    // Where the browser connects. A path is resolved against the page's own
    // host, with wss:// on https; host nginx proxies it to the gateway.
    'websocket_url' => env('TERMINAL_WEBSOCKET_URL', '/terminal/ws'),

    // A token is good for one connection within this many seconds.
    'token_ttl' => 60,

];

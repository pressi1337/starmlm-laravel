<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * ffmpeg is a SYSTEM program (installed on the server OS, not via composer)
     * used to compress uploaded videos. Leave as "ffmpeg" when it's on the
     * system PATH. On shared hosting where you can't install system packages,
     * drop a static build somewhere and set the absolute path instead, e.g.
     * FFMPEG_PATH=/home/youruser/bin/ffmpeg
     */
    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),

        // Quality dial for uploaded videos. Lower CRF = better quality, bigger
        // file. 24 is near-identical to the source while still ~5x smaller than
        // a raw phone upload; 26-28 shrink further but can soften fine text.
        'crf' => env('FFMPEG_CRF', 24),

        // Max output height. 720p is already sharp on a phone; 1080p sources
        // are downscaled, smaller sources are left as-is (never upscaled).
        'height' => env('FFMPEG_HEIGHT', 720),
    ],

    /*
     * Turns login IP addresses into a city / ISP for the admin Login History
     * page. Nothing to install — it's a plain HTTP call, batched (one request
     * per page of results), cached for 30 days, and the answer is stored on the
     * login_logs row so each IP is only ever fetched once.
     *
     * ip-api.com's free tier is HTTP-only and allows 15 batch calls a minute,
     * which is far more than an admin browsing a list will ever use. Only the
     * IP address is sent — no user data. Set IP_LOCATION_ENABLED=false to turn
     * the lookups off entirely; the page then just shows the raw IP.
     */
    'ip_location' => [
        'enabled'  => env('IP_LOCATION_ENABLED', true),
        'endpoint' => env('IP_LOCATION_ENDPOINT', 'http://ip-api.com/batch'),
        'timeout'  => env('IP_LOCATION_TIMEOUT', 5),
    ],

    /*
     * Omniware (Federal Bank) payment gateway. Used today by the admin
     * console's Payment Gateway page (test payments).
     *
     * The SALT signs every request and response; it must only ever live here
     * on the server. Never send it to a frontend or bake it into the APK.
     *
     * mode: TEST while the merchant account is in demo, LIVE once approved.
     * callback_base_url: public origin of THIS API that the gateway posts the
     *   result back to (e.g. https://api.starupworld.com). Left empty, it falls
     *   back to the host the payment was started from — APP_URL on this
     *   deployment is not reliable, so it is deliberately not used.
     */
    'omniware' => [
        'api_url'           => env('OMNIWARE_API_URL', 'https://pgbiz.omniware.in'),
        'api_key'           => env('OMNIWARE_API_KEY'),
        'salt'              => env('OMNIWARE_SALT'),
        'mode'              => env('OMNIWARE_MODE', 'TEST'),
        'callback_base_url' => env('OMNIWARE_CALLBACK_BASE_URL'),
        // Hosts the admin test-payment page may be served from; the gateway
        // return sends the browser back there. Comma-separated host suffixes.
        'admin_hosts'       => env('OMNIWARE_ADMIN_HOSTS', 'starupworld.com,starup.in,startup.co.in,localhost,127.0.0.1'),
        'timeout'           => (int) env('OMNIWARE_TIMEOUT', 20),
    ],

];

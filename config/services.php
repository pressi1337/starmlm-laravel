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

];

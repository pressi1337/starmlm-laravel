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

        /*
         * WHEN the encode runs. The work is identical in every mode — only
         * what holds it differs, and that turns out to decide whether uploads
         * succeed at all.
         *
         *   'queue' (default) — handed to a queue worker, so the web request
         *       returns immediately. Needs "php artisan queue:work" running;
         *       without a worker the upload still succeeds, the video just
         *       stays at its original size until something compresses it
         *       (see 'off' below).
         *
         *   'defer' — after the response, in the PHP-FPM worker that served
         *       the upload. DO NOT USE unless you have verified it on your
         *       server: measured on this one, the encode held the final chunk
         *       request open until nginx returned 504 after 64s, so EVERY real
         *       video upload failed at 100% even though the file had merged
         *       fine. fastcgi_finish_request() is called first to try to
         *       release the response, but if PHP has it disabled the request
         *       blocks anyway and uploads break.
         *
         *   'off' — don't compress during upload at all. Combine with a cron
         *       running the backfill command, which is idempotent, keeps the
         *       originals, and only replaces a file when the result is
         *       genuinely smaller:
         *           * /10 * * * *  php artisan videos:compress
         *
         * See UPLOAD_PERFORMANCE.md.
         */
        'mode' => env('VIDEO_COMPRESS_MODE', 'spawn'),

        /*
         * The PHP **CLI** binary, used by 'spawn' mode to run artisan.
         *
         * PHP_BINARY cannot be used for this: under PHP-FPM it points at the
         * FPM binary (e.g. /usr/sbin/php-fpm8.2), which will not run artisan.
         * "php" works whenever the CLI is on the web user's PATH; set an
         * absolute path if it is not.
         */
        'php_binary' => env('PHP_CLI_BINARY', 'php'),
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
     * Worldline ePayments India (Paynimo / TPSL) payment gateway, "Single URL"
     * integration. Used by the admin console's Payment Gateway page.
     *
     * The encryption key and IV encrypt every request and decrypt every
     * response; they must only ever live here on the server. Never send them
     * to a frontend or bake them into the APK.
     *
     * merchant_code / scheme_code: issued by Worldline per environment.
     * enc_key / enc_iv: exactly 16 characters each (AES-128-CBC).
     * mode: TEST while on the test merchant code, LIVE once approved. In TEST
     *   the gateway only accepts amounts between ₹1 and ₹10, and the test bank
     *   login is test / test.
     * callback_base_url: public origin of THIS API that the gateway posts the
     *   result back to (e.g. https://api.starupworld.com). Left empty, it falls
     *   back to the host the payment was started from — APP_URL on this
     *   deployment is not reliable, so it is deliberately not used.
     * itc: optional extra client meta sent as {itc:...}; leave unset normally.
     */
    'worldline' => [
        'service_url'       => env('WORLDLINE_SERVICE_URL', 'https://www.tpsl-india.in/PaymentGateway/services/TransactionDetailsNew'),
        'merchant_code'     => env('WORLDLINE_MERCHANT_CODE'),
        'scheme_code'       => env('WORLDLINE_SCHEME_CODE'),
        'enc_key'           => env('WORLDLINE_ENC_KEY'),
        'enc_iv'            => env('WORLDLINE_ENC_IV'),
        'mode'              => env('WORLDLINE_MODE', 'TEST'),
        'itc'               => env('WORLDLINE_ITC'),
        // Bank / payment-option code (tpsl_bank_cd). Required by merchant
        // setups that do not show Worldline's own payment-options page — a
        // payment without it fails with TPPGE006 "Bank ID is mandatory".
        // Worldline supplies the code list for the merchant.
        'bank_code'         => env('WORLDLINE_BANK_CODE'),
        'callback_base_url' => env('WORLDLINE_CALLBACK_BASE_URL'),
        // Hosts the admin test-payment page may be served from; the gateway
        // return sends the browser back there. Comma-separated host suffixes.
        'admin_hosts'       => env('WORLDLINE_ADMIN_HOSTS', 'starupworld.com,starup.in,startup.co.in,localhost,127.0.0.1'),
        'timeout'           => (int) env('WORLDLINE_TIMEOUT', 30),
    ],

];

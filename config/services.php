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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        // OAuth client ID(s) issued to the Flutter app (Android + "web" client
        // used by google_sign_in under the hood). Accepts a comma-separated
        // list since Android release/debug builds get different client ids.
        'client_ids' => array_filter(array_map('trim', explode(',', (string) env('GOOGLE_CLIENT_IDS', '')))),
    ],

    'aws' => [
        'bucket' => env('AWS_BUCKET'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'staging_prefix' => env('AWS_STAGING_PREFIX', 'staging'),
        'media_prefix' => env('AWS_MEDIA_PREFIX', 'media'),
        'inventory_prefix' => env('AWS_INVENTORY_PREFIX', 'inventory'),

        'cloudfront' => [
            'domain' => env('CLOUDFRONT_DOMAIN'),
            'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID'),
            'private_key_path' => env('CLOUDFRONT_PRIVATE_KEY_PATH'),
            'signed_url_ttl_seconds' => env('CLOUDFRONT_SIGNED_URL_TTL', 600),
        ],

        'rekognition' => [
            'enabled' => env('REKOGNITION_ENABLED', true),
            'face_collection_id' => env('REKOGNITION_FACE_COLLECTION_ID', 'rvmi-media-people'),
            'min_face_confidence' => env('REKOGNITION_MIN_FACE_CONFIDENCE', 80),
        ],
    ],

    'fcm' => [
        // Firebase Cloud Messaging HTTP v1 service account (JSON key file path).
        'credentials_path' => env('FCM_CREDENTIALS_PATH'),
        'project_id' => env('FCM_PROJECT_ID'),
    ],

    'media_pipeline' => [
        // How many leftover pending_processing rows the 5-minute sweeper
        // retries per tick. Normal uploads are processed on complete().
        // Kept small because shared hosting cron has a short execution budget.
        'process_batch_size' => env('MEDIA_PROCESS_BATCH_SIZE', 8),
    ],

];

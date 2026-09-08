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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'node' => [
        'binary' => env('NODE_BINARY', '/usr/bin/node'),
        'npm' => env('NPM_BINARY', '/usr/bin/npm'),
    ],

    'crm_portal' => [
        'tenant_id' => (int) env('CRM_PORTAL_TENANT_ID', 0),
        'school_sis_ids' => array_map('intval', array_filter(explode(',', (string) env('CRM_PORTAL_SCHOOL_SIS_IDS', '')))),
        'identity_url' => env('CRM_PORTAL_IDENTITY_URL'),
        'enabled' => (bool) env('CRM_PORTAL_ENABLED', false),
        'webhook_url' => env('CRM_PORTAL_WEBHOOK_URL'),
        'webhook_secret' => env('CRM_PORTAL_WEBHOOK_SECRET'),
        'token' => env('CRM_PORTAL_API_TOKEN'),
    ],

];

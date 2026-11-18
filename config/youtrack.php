<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | YouTrack base URL
    |--------------------------------------------------------------------------
    |
    | Root URL of your YouTrack instance, e.g. https://youtrack.example.com. A trailing
    | slash or a trailing "/api" is stripped automatically. If YouTrack runs
    | under a context path (https://host/youtrack), include it here.
    |
    */
    'base_url' => env('YOUTRACK_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Permanent token
    |--------------------------------------------------------------------------
    |
    | Create it in YouTrack: Profile > Account Security > New token.
    | It is sent as "Authorization: Bearer <token>".
    |
    */
    'token' => env('YOUTRACK_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Default project
    |--------------------------------------------------------------------------
    |
    | Project short name (e.g. "SUP") or database id (e.g. "0-3") used when
    | no project is passed to Issues::create().
    |
    */
    'default_project' => env('YOUTRACK_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | State field name
    |--------------------------------------------------------------------------
    |
    | Name of the custom field that holds the issue status. "State" is the
    | YouTrack default; change it if your projects use a different name.
    |
    */
    'state_field' => env('YOUTRACK_STATE_FIELD', 'State'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    |
    | "retries" and "retry_delay" (milliseconds) apply to GET requests only,
    | on connection errors and on HTTP 429/502/503/504. Writes are never
    | retried, so a timeout cannot create duplicate issues.
    |
    */
    'timeout' => (int) env('YOUTRACK_TIMEOUT', 15),
    'retries' => (int) env('YOUTRACK_RETRIES', 2),
    'retry_delay' => (int) env('YOUTRACK_RETRY_DELAY', 300),
    'verify_ssl' => (bool) env('YOUTRACK_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Project id cache
    |--------------------------------------------------------------------------
    |
    | Project short names are resolved to database ids once and cached for
    | this many seconds (0 disables the cache).
    |
    */
    'cache_ttl' => (int) env('YOUTRACK_CACHE_TTL', 3600),

];

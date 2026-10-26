<?php

return [

    'enabled' => env('GEO_LOCK_ENABLED', true),

    /*
    | Roles that can always open the panel, including the rules page.
    | Keep an administrator here so a rule cannot lock the panel shut.
    */
    'bypass_roles' => ['super_admin'],

    /*
    | Roles that can see and save Geo Lock Rules.
    */
    'manager_roles' => ['super_admin'],

    /*
    | Trust X-Geo-Country and X-Geo-Region only when a proxy you control
    | sets them and strips client-supplied copies. Cloudflare's CF-IPCountry
    | is read when this is on.
    */
    'trust_headers' => env('GEO_LOCK_TRUST_HEADERS', false),

    /*
    | Private and loopback addresses have no public country. The local
    | override is for this machine only, so a demo can stand in for a region.
    */
    'local_country' => env('GEO_LOCK_LOCAL_COUNTRY'),

    'local_region' => env('GEO_LOCK_LOCAL_REGION'),

    /*
    | Days to keep a denial. Zero keeps every row.
    */
    'log_days' => (int) env('GEO_LOCK_LOG_DAYS', 90),

];

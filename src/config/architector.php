<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Architector Admin Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the admin dashboard settings for the Architector package.
    |
    */

    'api_endpoint' => env('ARCHITECTOR_API_ENDPOINT', ''),

    'api_token' => env('ARCHITECTOR_API_TOKEN', ''),

    'default_color' => env('ARCHITECTOR_DEFAULT_COLOR', 'basic'),

    'default_design' => env('ARCHITECTOR_DEFAULT_DESIGN', 'gridlayouts'),

    'default_route' => env('ARCHITECTOR_DEFAULT_ROUTE', '/admin/dashboard'),

    /*
    |--------------------------------------------------------------------------
    | Icon Library Configuration
    |--------------------------------------------------------------------------
    |
    | Specify whether to use Blade Heroicons or fallback to built-in glyphs
    |
    */
    'use_icons' => env('ARCHITECTOR_USE_ICONS', true),

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    |
    | Control API-driven dashboard mode
    |
    */
    'enable_api_mode' => env('ARCHITECTOR_ENABLE_API_MODE', true),

    'enable_crud_mode' => env('ARCHITECTOR_ENABLE_CRUD_MODE', true),

];

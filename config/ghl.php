<?php

return [
    'client_id'       => env('GHL_CLIENT_ID', ''),
    'client_secret'   => env('GHL_CLIENT_SECRET', ''),
    'redirect_uri'    => env('GHL_REDIRECT_URI', 'http://localhost:8000/callback'),
    'version_id'      => env('GHL_VERSION_ID', ''),
    'api_version'     => env('GHL_API_VERSION', 'v3'),
    'scopes'          => env('GHL_SCOPES', 'locations.readonly contacts.readonly contacts.write workflows.readonly'),
    'shared_secret'   => env('GHL_SHARED_SECRET', ''),
    'base_url'        => env('GHL_BASE_URL', 'https://services.leadconnectorhq.com'),
    'marketplace_url' => env('GHL_MARKETPLACE_URL', 'https://marketplace.leadconnectorhq.com'),
];

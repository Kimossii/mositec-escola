<?php

return [

    'ssr' => [

        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),

        'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),

        'ensure_runtime_exists' => (bool) env('INERTIA_SSR_ENSURE_RUNTIME_EXISTS', false),

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),

        'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', false),

    ],

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Module-owned pages (e.g. "Autenticacao/Login") are resolved by a custom
    | resolver in resources/js/app.js that strips the module segment before
    | looking under Modules/{Module}/resources/js/Pages. FileViewFinder can't
    | replicate that per-name stripping, so it can't reliably verify these
    | files exist — disabled rather than left giving false negatives.
    |
    */

    'testing' => [

        'ensure_pages_exist' => false,

    ],

    'expose_shared_prop_keys' => true,

    'history' => [

        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),

    ],

];

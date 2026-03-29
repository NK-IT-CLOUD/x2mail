<?php

return [
    'routes' => [
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET'
        ],
        [
            'name' => 'page#indexPost',
            'url' => '/',
            'verb' => 'POST'
        ],
        [
            'name' => 'page#appGet',
            'url' => '/app/',
            'verb' => 'GET'
        ],
        [
            'name' => 'page#appPost',
            'url' => '/app/',
            'verb' => 'POST'
        ],
        [
            'name' => 'fetch#setAdmin',
            'url' => '/fetch/setAdmin',
            'verb' => 'POST'
        ],
        [
            'name' => 'fetch#upgrade',
            'url' => '/fetch/upgrade',
            'verb' => 'POST'
        ],
        [
            'name' => 'setup#getConfig',
            'url' => '/setup/config',
            'verb' => 'GET'
        ],
        [
            'name' => 'setup#preflightCheck',
            'url' => '/setup/preflight',
            'verb' => 'POST'
        ],
        [
            'name' => 'setup#saveSetup',
            'url' => '/setup/save',
            'verb' => 'POST'
        ],
        [
            'name' => 'setup#deleteDomain',
            'url' => '/setup/delete',
            'verb' => 'POST'
        ]
    ]
];

<?php

declare(strict_types=1);

use App\Modules\Directory\Controllers\DirectoryController;

return [
    'id' => 'directory',
    'name' => 'Directory',
    'version' => trim(@file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),

    'nav' => [
        [
            'label' => 'nav.directory',
            'icon' => 'bi-person-lines-fill',
            'route' => '/directory/contacts',
            'group' => 'members',
            'order' => 60,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\App\Core\Router $router): void {
        $router->get('/directory', [DirectoryController::class, 'index'], 'directory.index');
        $router->get('/directory/contacts', [DirectoryController::class, 'index'], 'directory.contacts');
    },

    'permissions' => [
        'directory.read' => 'View the organisation directory and contact list',
    ],
];

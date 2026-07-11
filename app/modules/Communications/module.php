<?php

declare(strict_types=1);

use App\Modules\Communications\Controllers\ArticleController;
use App\Modules\Communications\Controllers\EmailController;

return [
    'id' => 'communications',
    'name' => 'Communications',
    'version' => trim(@file_get_contents(ROOT_PATH . '/VERSION') ?: '0.0.0'),

    'nav' => [
        [
            'label' => 'nav.articles',
            'icon' => 'bi-megaphone',
            'route' => '/articles',
            'group' => 'communications',
            'order' => 20,
            'requires_auth' => true,
        ],
        [
            'label' => 'nav.email_composer',
            'icon' => 'bi-envelope',
            'route' => '/admin/email',
            'group' => 'communications',
            'order' => 30,
            'requires_auth' => true,
        ],
    ],

    'routes' => function (\App\Core\Router $router): void {
        // Public article routes
        $router->get('/articles', [ArticleController::class, 'index'], 'articles.index');
        $router->get('/articles/{slug}', [ArticleController::class, 'show'], 'articles.show');

        // Admin article management
        $router->get('/admin/articles', [ArticleController::class, 'adminIndex'], 'articles.admin_index');
        $router->get('/admin/articles/create', [ArticleController::class, 'create'], 'articles.create');
        $router->post('/admin/articles', [ArticleController::class, 'store'], 'articles.store');
        $router->get('/admin/articles/{id:\d+}/edit', [ArticleController::class, 'edit'], 'articles.edit');
        $router->post('/admin/articles/{id:\d+}', [ArticleController::class, 'update'], 'articles.update');
        $router->post('/admin/articles/{id:\d+}/publish', [ArticleController::class, 'publish'], 'articles.publish');
        $router->post('/admin/articles/{id:\d+}/unpublish', [ArticleController::class, 'unpublish'], 'articles.unpublish');
        $router->post('/admin/articles/{id:\d+}/delete', [ArticleController::class, 'delete'], 'articles.delete');

        // Email
        $router->get('/admin/email', [EmailController::class, 'compose'], 'email.compose');
        $router->post('/admin/email/send', [EmailController::class, 'send'], 'email.send');
        $router->get('/admin/email/log', [EmailController::class, 'log'], 'email.log');
    },

    'permissions' => [
        'communications.read' => 'View articles and communications',
        'communications.write' => 'Create, edit, and manage articles and emails',
    ],

    'cron' => [
        \App\Modules\Communications\Cron\EmailQueueHandler::class,
    ],
];

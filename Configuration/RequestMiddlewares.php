<?php

use Causal\FalProtect\Middleware\HttpRangeFileMiddleware;

return [
    'frontend' => [
        HttpRangeFileMiddleware::MIDDLEWARE_IDENTIFIER => [
            'target' => HttpRangeFileMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];

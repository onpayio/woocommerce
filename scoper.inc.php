<?php

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'WoocommerceOnpay',
    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->in('vendor'),
        Finder::create()->append([
            'composer.json',
        ]),
    ],
    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
    'patchers' => [
        static function (string $filePath, string $prefix, string $contents): string {
            // league/oauth2-client builds grant class names via string concatenation,
            // which PHP-Scoper cannot see. Patch both possible escapings that may
            // appear after PHP-Scoper's AST printer runs.
            if (str_ends_with(str_replace('\\', '/', $filePath), 'league/oauth2-client/src/Grant/GrantFactory.php')) {
                $contents = str_replace(
                    "'League\\OAuth2\\Client\\Grant\\\\'",
                    "'" . $prefix . "\\League\\OAuth2\\Client\\Grant\\\\'",
                    $contents
                );
                $contents = str_replace(
                    "'League\\\\OAuth2\\\\Client\\\\Grant\\\\'",
                    "'" . $prefix . "\\\\League\\\\OAuth2\\\\Client\\\\Grant\\\\'",
                    $contents
                );
            }
            return $contents;
        },
    ],
];

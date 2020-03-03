<?php
/**
 * module.config.php - WP Bridge Config
 *
 * Main Config File for Basket Wordpress Bridge
 *
 * @category Config
 * @package Basket\Wordpress
 * @author Verein onePlace
 * @copyright (C) 2020  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

namespace OnePlace\Basket\WP\Bridge;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;
use Laminas\ServiceManager\Factory\InvokableFactory;

return [
    # Contact Module - Routes
    'router' => [
        'routes' => [
            'basket-wp-bridge' => [
                'type'    => Segment::class,
                'options' => [
                    'route' => '/basket/wordpress[/:action[/:id]]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                        'id'     => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => Controller\ApiController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
            'basket-wp-bridge-setup' => [
                'type'    => Segment::class,
                'options' => [
                    'route' => '/basket/wordpress/setup[/:action[/:id]]',
                    'constraints' => [
                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                        'id'     => '[0-9]+',
                    ],
                    'defaults' => [
                        'controller' => Controller\InstallController::class,
                        'action'     => 'checkdb',
                    ],
                ],
            ],
        ],
    ],

    # View Settings
    'view_manager' => [
        'template_path_stack' => [
            'basket-wordpress' => __DIR__ . '/../view',
        ],
    ],
];

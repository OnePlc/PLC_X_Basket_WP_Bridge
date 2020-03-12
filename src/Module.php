<?php
/**
 * Module.php - Module Class
 *
 * Module Class File for Basket Wordpress Bridge Plugin
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

use Application\Controller\CoreEntityController;
use Laminas\Mvc\MvcEvent;
use Laminas\Db\ResultSet\ResultSet;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\EventManager\EventInterface as Event;
use Laminas\ModuleManager\ModuleManager;
use OnePlace\Basket\Model\BasketTable;
use OnePlace\Job\Model\JobTable;


class Module {
    /**
     * Module Version
     *
     * @since 1.0.0
     */
    const VERSION = '1.0.0';

    /**
     * Load module config file
     *
     * @since 1.0.0
     * @return array
     */
    public function getConfig() : array {
        return include __DIR__ . '/../config/module.config.php';
    }

    public function onBootstrap(Event $e)
    {
        // This method is called once the MVC bootstrapping is complete
        $application = $e->getApplication();
        $container    = $application->getServiceManager();
        $oDbAdapter = $container->get(AdapterInterface::class);
        $tableGateway = $container->get(BasketTable::class);

        # Register Filter Plugin Hook
        CoreEntityController::addHook('basket-view-before',(object)['sFunction'=>'attachBasketSteps','oItem'=>new Controller\PluginController($oDbAdapter,$tableGateway,$container)]);
        //CoreEntityController::addHook('contacthistory-add-before-save',(object)['sFunction'=>'attachHistoryToContact','oItem'=>new HistoryController($oDbAdapter,$tableGateway,$container)]);
    }

    /**
     * Load Controllers
     */
    public function getControllerConfig() : array {
        return [
            'factories' => [
                # Plugin Example Controller
                Controller\ApiController::class => function($container) {
                    $oDbAdapter = $container->get(AdapterInterface::class);
                    $tableGateway = $container->get(BasketTable::class);
                    $oBasketStepTbl = new TableGateway('basket_step', $oDbAdapter);
                    $oBasketPosTbl = new TableGateway('basket_position', $oDbAdapter);
                    $oJobTbl = $container->get(JobTable::class);
                    $oJobPosTbl = new TableGateway('job_position', $oDbAdapter);

                    # hook start
                    # hook end
                    return new Controller\ApiController(
                        $oDbAdapter,
                        $tableGateway,
                        $container,
                        [
                            'basket-step' => $oBasketStepTbl,
                            'basket-position' => $oBasketPosTbl,
                            'job' => $oJobTbl,
                            'job-position' => $oJobPosTbl,
                        ]
                    );
                },
                Controller\InstallController::class => function($container) {
                    $oDbAdapter = $container->get(AdapterInterface::class);
                    $tableGateway = $container->get(BasketTable::class);

                    # hook start
                    # hook end
                    return new Controller\InstallController(
                        $oDbAdapter,
                        $tableGateway,
                        $container
                    );
                },
                Controller\PluginController::class => function($container) {
                    $oDbAdapter = $container->get(AdapterInterface::class);
                    $tableGateway = $container->get(BasketTable::class);

                    # hook start
                    # hook end
                    return new Controller\PluginController(
                        $oDbAdapter,
                        $tableGateway,
                        $container,
                        []
                    );
                },
            ],
        ];
    }
}

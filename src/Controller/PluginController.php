<?php
/**
 * PluginController.php - Hook Controller
 *
 * Hook Controller for Basket WP Bridge
 *
 * @category Controller
 * @package Basket\Wordpress
 * @author Verein onePlace
 * @copyright (C) 2020  Verein onePlace <admin@1plc.ch>
 * @license https://opensource.org/licenses/BSD-3-Clause
 * @version 1.0.0
 * @since 1.0.0
 */

declare(strict_types=1);

namespace OnePlace\Basket\WP\Bridge\Controller;

use Application\Controller\CoreController;
use Application\Model\CoreEntityModel;
use OnePlace\Basket\Model\BasketTable;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\ResultSet\ResultSet;

class PluginController extends CoreController {
    protected $aPluginTables;

    /**
     * BasketController constructor.
     *
     * @param AdapterInterface $oDbAdapter
     * @param BasketTable $oTableGateway
     * @since 1.0.0
     */
    public function __construct(AdapterInterface $oDbAdapter, BasketTable $oTableGateway, $oServiceManager,$aPluginTables = [])
    {
        $this->oTableGateway = $oTableGateway;
        $this->sSingleForm = 'basket-single';
        $this->aPluginTables = $aPluginTables;

        parent::__construct($oDbAdapter, $oTableGateway, $oServiceManager);

        if ($oTableGateway) {
            # Attach TableGateway to Entity Models
            if (! isset(CoreEntityModel::$aEntityTables[$this->sSingleForm])) {
                CoreEntityModel::$aEntityTables[$this->sSingleForm] = $oTableGateway;
            }
        }
    }

    public function attachBasketSteps($oItem = false) {
        $oBasketStepTbl = new TableGateway('basket_step', CoreController::$oDbAdapter);

        $aSteps = [];
        if($oItem) {
            $oMySteps = $oBasketStepTbl->select(['basket_idfs' => $oItem->getID()]);
            if(count($oMySteps) > 0) {
                foreach($oMySteps as $oSt) {
                    $aSteps[] = $oSt;
                }
            }
        }
        # Pass Data to View - which will pass it to our partial
        return [
            # must be named aPartialExtraData
            'aPartialExtraData' => [
                # must be name of your partial
                'basket_steps'=> [
                    'aSteps'=>$aSteps,
                ]
            ]
        ];
    }
}

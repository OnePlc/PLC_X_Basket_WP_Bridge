<?php
/**
 * WordpressController.php - Main Controller
 *
 * Main Controller for Basket Wordpress Plugin
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

use Application\Controller\CoreEntityController;
use Application\Model\CoreEntityModel;
use http\Exception\RuntimeException;
use OnePlace\Basket\Model\BasketTable;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;

class ApiController extends CoreEntityController {
    /**
     * Basket Table Object
     *
     * @since 1.0.0
     */
    protected $oTableGateway;

    /**
     * BasketController constructor.
     *
     * @param AdapterInterface $oDbAdapter
     * @param BasketTable $oTableGateway
     * @since 1.0.0
     */
    public function __construct(AdapterInterface $oDbAdapter,BasketTable $oTableGateway,$oServiceManager) {
        $this->oTableGateway = $oTableGateway;
        $this->sSingleForm = 'basket-single';
        parent::__construct($oDbAdapter,$oTableGateway,$oServiceManager);

        if($oTableGateway) {
            # Attach TableGateway to Entity Models
            if(!isset(CoreEntityModel::$aEntityTables[$this->sSingleForm])) {
                CoreEntityModel::$aEntityTables[$this->sSingleForm] = $oTableGateway;
            }
        }
    }

    public function addAction() {
        $this->layout('layout/json');

        echo 'add item to basket - open basket - and so on ...';

        return false;
    }

    public function getAction() {
        $this->layout('layout/json');

        $sShopSessionID = $_REQUEST['shop_session_id'];

        try {
            $oBasketExists = $this->oTableGateway->getSingle($sShopSessionID,'shop_session_id');
            if($oBasketExists->shop_session_id != $sShopSessionID) {
                throw new \RuntimeException('Not really the same basket...');
            }
        } catch(\RuntimeException $e) {
            $aResponse = ['state'=>'success','message'=>'Your Basket is empty'];
            echo json_encode($aResponse);
            return false;
        }

        $aResponse = ['state'=>'success','message'=>'open basket found','oBasket'=>$oBasketExists];
        echo json_encode($aResponse);

        return false;
    }
}

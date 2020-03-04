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
use OnePlace\Basket\Position\Model\PositionTable;

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

        $iItemID = $_REQUEST['shop_item_id'];
        $sItemType = $_REQUEST['shop_item_type'];
        $sShopSessionID = $_REQUEST['shop_session_id'];

        $oBasket = false;
        try {
            $oBasketExists = $this->oTableGateway->getSingle($sShopSessionID,'shop_session_id');
            if($oBasketExists->shop_session_id != $sShopSessionID) {
                throw new \RuntimeException('Not really the same basket...');
            }
            $oBasket = $oBasketExists;
        } catch(\RuntimeException $e) {
            # Get State Tag
            $oStateTag = CoreEntityController::$aCoreTables['core-tag']->select(['tag_key' => 'state']);
            if(count($oStateTag) > 0) {
                $oStateTag = $oStateTag->current();

                # Get Basket "new" Entity State Tag
                $oNewState = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                    'entity_form_idfs' => 'basket-single',
                    'tag_idfs' => $oStateTag->Tag_ID,
                    'tag_value' => 'new',
                ]);

                if(count($oNewState) > 0) {
                    $oNewState = $oNewState->current();

                    # basket is empty - create it
                    $oBasket = $this->oTableGateway->generateNew();
                    $aBasketData = [
                        'state_idfs' => $oNewState->Entitytag_ID,
                        'job_idfs' => 0,
                        'contact_idfs' => 0,
                        'deliverymethod_idfs' => 0,
                        'paymentmethod_idfs' => 0,
                        'label' => 'New Basket',
                        'comment' => 'Created by WP Bridge',
                        'payment_id' => '',
                        'payment_session_id' => '',
                        'shop_session_id' => $sShopSessionID,
                        'created_by' => 1,
                        'created_date' => date('Y-m-d H:i:s',time()),
                        'modified_by' => 1,
                        'modified_date' => date('Y-m-d H:i:s',time()),
                    ];

                    $oBasket->exchangeArray($aBasketData);

                    $iBasketID = $this->oTableGateway->saveSingle($oBasket);
                    $oBasket = $this->oTableGateway->getSingle($iBasketID);
                }
            }
        }

        if($oBasket) {
            $oBasketPosTbl = CoreEntityController::$oServiceManager->get(PositionTable::class);
            $oPos = $oBasketPosTbl->generateNew();

            $aPosData = [
                'basket_idfs' => $oBasket->getID(),
                'article_idfs' => $iItemID,
                'amount' => 1,
                'price' => 0,
                'comment' => '',
                'created_by' => 1,
                'created_date' => date('Y-m-d H:i:s',time()),
                'modified_by' => 1,
                'modified_date' => date('Y-m-d H:i:s',time()),
            ];

            $oPos->exchangeArray($aPosData);
            $oBasketPosTbl->saveSingle($oPos);
        }

        $aResponse = ['state'=>'success','message'=>'item added to basket','oBasket'=>$oBasket];
        echo json_encode($aResponse);

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

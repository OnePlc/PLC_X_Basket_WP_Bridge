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
use OnePlace\Article\Model\ArticleTable;
use OnePlace\Article\Variant\Model\VariantTable;
use OnePlace\Basket\Model\BasketTable;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use OnePlace\Basket\Position\Model\PositionTable;
use OnePlace\Contact\Address\Model\AddressTable;
use OnePlace\Contact\Model\ContactTable;

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
        $fItemAmount = $_REQUEST['shop_item_amount'];
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
                'article_type' => $sItemType,
                'amount' => (float)$fItemAmount,
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

        $aResponse = ['state'=>'success','message'=> $fItemAmount.' items added to basket','oBasket'=>$oBasket];
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

        $aPositions = [];
        $oPosTbl = CoreEntityController::$oServiceManager->get(PositionTable::class);
        $oArticleTbl = CoreEntityController::$oServiceManager->get(ArticleTable::class);
        $oVariantTbl = CoreEntityController::$oServiceManager->get(VariantTable::class);

        $oBasketPositions = $oPosTbl->fetchAll(false,['basket_idfs' => $oBasketExists->getID()]);
        if(count($oBasketPositions) > 0) {
            foreach($oBasketPositions as $oPos) {
                switch($oPos->article_type) {
                    case 'variant':
                        $oPos->oVariant = $oVariantTbl->getSingle($oPos->article_idfs);
                        $oPos->oArticle = $oArticleTbl->getSingle($oPos->oVariant->article_idfs);
                        break;
                    default:
                        break;
                }
                $aPositions[] = $oPos;
            }
        }
        $aResponse = ['state'=>'success','message'=>'open basket found','basket'=>$oBasketExists,'items'=>$aPositions];
        echo json_encode($aResponse);

        return false;
    }

    public function checkoutAction() {
        $this->layout('layout/json');

        $sShopSessionID = $_REQUEST['shop_session_id'];

        $oTagDelMet = CoreEntityController::$aCoreTables['core-tag']->select(['tag_key' => 'deliverymethod']);
        $aDeliveryMethods = [];
        if(count($oTagDelMet) > 0) {
            $oTagDelMet = $oTagDelMet->current();

            $oDeliveryMethodsDB = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'entity_form_idfs' => 'basket-single',
                'tag_idfs' => $oTagDelMet->Tag_ID,
            ]);
            if(count($oDeliveryMethodsDB) > 0) {
                foreach($oDeliveryMethodsDB as $oDel) {
                    $aDeliveryMethods[] = (object)['id' => $oDel->Entitytag_ID,'label' => $oDel->tag_value];
                }
            }
        }

        try {
            $oBasketExists = $this->oTableGateway->getSingle($sShopSessionID,'shop_session_id');
            if($oBasketExists->shop_session_id != $sShopSessionID) {
                throw new \RuntimeException('Not really the same basket...');
            }
        } catch(\RuntimeException $e) {
            $aResponse = ['state'=>'error','message'=>'No matching open basket found'];
            echo json_encode($aResponse);
            return false;
        }

        if($oBasketExists->contact_idfs != 0) {
            $oContactTbl = CoreEntityController::$oServiceManager->get(ContactTable::class);
            $oAddressTbl = CoreEntityController::$oServiceManager->get(AddressTable::class);
            try {
                $oContactExists = $oContactTbl->getSingle($oBasketExists->contact_idfs);
            } catch (\RuntimeException $e) {
                $aResponse = ['state' => 'success', 'message' => 'checkout started', 'basket' => $oBasketExists,'deliverymethods' => $aDeliveryMethods];
                echo json_encode($aResponse);

                return false;
            }

            $oAddress = $oAddressTbl->getSingle($oContactExists->getID(),'contact_idfs');
            $oContactExists->address = $oAddress;

            $aResponse = ['state'=>'success','message'=>'checkout started again','basket'=>$oBasketExists,'contact'=>$oContactExists,'deliverymethods' => $aDeliveryMethods];
            echo json_encode($aResponse);

            return false;
        } else {
            $aResponse = ['state' => 'success', 'message' => 'checkout started', 'basket' => $oBasketExists,'deliverymethods' => $aDeliveryMethods];
            echo json_encode($aResponse);

            return false;
        }
    }

    public function paymentAction() {
        $this->layout('layout/json');

        $sShopSessionID = $_REQUEST['shop_session_id'];

        try {
            $oBasketExists = $this->oTableGateway->getSingle($sShopSessionID,'shop_session_id');
            if($oBasketExists->shop_session_id != $sShopSessionID) {
                throw new \RuntimeException('Not really the same basket...');
            }
        } catch(\RuntimeException $e) {
            $aResponse = ['state'=>'error','message'=>'No matching open basket found'];
            echo json_encode($aResponse);
            return false;
        }

        $oContactTbl = CoreEntityController::$oServiceManager->get(ContactTable::class);
        $oAddressTbl = CoreEntityController::$oServiceManager->get(AddressTable::class);

        $aContactData = [];
        if(isset($_REQUEST['email'])) {
            $aContactData['email_private'] = $_REQUEST['email'];
            $aContactData['firstname'] = $_REQUEST['firstname'];
            $aContactData['lastname'] = $_REQUEST['lastname'];
            $aContactData['phone_private'] = $_REQUEST['phone'];
            $aContactData['salutation_idfs'] = $_REQUEST['salutation'];

            try {
                $oContactExists = $oContactTbl->getSingle($aContactData['email_private'],'email_private');
            } catch(\RuntimeException $e) {
                $oNewContact = $oContactTbl->generateNew();
                $oNewContact->exchangeArray($aContactData);
                $iContactID = $oContactTbl->saveSingle($oNewContact);
                $oContactExists = $oContactTbl->getSingle($iContactID);

                $aAddressData = [
                    'contact_idfs' => $iContactID,
                    'street' => $_REQUEST['street'],
                    'zip' => $_REQUEST['zip'],
                    'city' => $_REQUEST['city'],
                ];
                $oNewAddress = $oAddressTbl->generateNew();
                $oNewAddress->exchangeArray($aAddressData);
                $iAddressID = $oAddressTbl->saveSingle($oNewAddress);

                $this->oTableGateway->updateAttribute('contact_idfs',$iContactID,'Basket_ID',$oBasketExists->getID());
            }
        } else {
            $oContactExists = $oContactTbl->getSingle($oBasketExists->contact_idfs);
        }

        if(isset($_REQUEST['deliverymethod'])) {
            $iDeliveryMethodID = $_REQUEST['deliverymethod'];
            $this->oTableGateway->updateAttribute('deliverymethod_idfs', $iDeliveryMethodID, 'Basket_ID', $oBasketExists->getID());
        }

        $oTagDelMet = CoreEntityController::$aCoreTables['core-tag']->select(['tag_key' => 'paymentmethod']);
        $aPaymentMethods = [];
        if(count($oTagDelMet) > 0) {
            $oTagDelMet = $oTagDelMet->current();

            $oDeliveryMethodsDB = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'entity_form_idfs' => 'basket-single',
                'tag_idfs' => $oTagDelMet->Tag_ID,
            ]);
            if(count($oDeliveryMethodsDB) > 0) {
                foreach($oDeliveryMethodsDB as $oDel) {
                    $aPaymentMethods[] = (object)[
                        'id' => $oDel->Entitytag_ID,
                        'label' => $oDel->tag_value,
                        'icon' => $oDel->tag_icon,
                    ];
                }
            }
        }

        /**
         * Load Payment Method
         */
        $aPay = ['id' => 0,'label' => '-','icon' => ''];
        $oPaymentMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
            'Entitytag_ID' => $oBasketExists->paymentmethod_idfs,
        ]);
        if(count($oPaymentMethod) > 0) {
            $oPaymentMethod = $oPaymentMethod->current();
            $aPay = [
                'id' => $oPaymentMethod->Entitytag_ID,
                'label' => $oPaymentMethod->tag_value,
                'icon' => $oPaymentMethod->tag_icon
            ];
        }

        $aResponse = [
            'state' => 'success',
            'message' => 'contact saved',
            'basket' => $oBasketExists,
            'contact' => $oContactExists,
            'paymentmethods' => $aPaymentMethods,
            'paymentmethodselected' => $aPay,
        ];
        echo json_encode($aResponse);

        return false;
    }

    public function confirmAction() {
        $this->layout('layout/json');

        $sShopSessionID = $_REQUEST['shop_session_id'];

        /**
         * Load Basket
         */
        try {
            $oBasketExists = $this->oTableGateway->getSingle($sShopSessionID,'shop_session_id');
            if($oBasketExists->shop_session_id != $sShopSessionID) {
                throw new \RuntimeException('Not really the same basket...');
            }
        } catch(\RuntimeException $e) {
            $aResponse = ['state' => 'error','message' => 'No matching open basket found'];
            echo json_encode($aResponse);
            return false;
        }

        /**
         * Load Contact
         */
        $oContactExists = [];
        if($oBasketExists->contact_idfs != 0) {
            $oContactTbl = CoreEntityController::$oServiceManager->get(ContactTable::class);
            $oAddressTbl = CoreEntityController::$oServiceManager->get(AddressTable::class);
            try {
                $oContactExists = $oContactTbl->getSingle($oBasketExists->contact_idfs);
            } catch (\RuntimeException $e) {
                $aResponse = ['state' => 'success', 'message' => 'checkout started', 'basket' => $oBasketExists,'deliverymethods' => $aDeliveryMethods];
                echo json_encode($aResponse);

                return false;
            }

            $oAddress = $oAddressTbl->getSingle($oContactExists->getID(),'contact_idfs');
            $oContactExists->address = $oAddress;
        }

        /**
         * Update Payment Method
         */
        $iPaymentMethodID = $_REQUEST['paymentmethod'];
        $this->oTableGateway->updateAttribute('paymentmethod_idfs',$iPaymentMethodID,'Basket_ID',$oBasketExists->getID());

        /**
         * Load Payment Method
         */
        $aPay = ['id' => 0,'label' => '-','icon' => ''];
        $oPaymentMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
            'Entitytag_ID' => $oBasketExists->paymentmethod_idfs,
        ]);
        if(count($oPaymentMethod) > 0) {
            $oPaymentMethod = $oPaymentMethod->current();
            $aPay = [
                'id' => $oPaymentMethod->Entitytag_ID,
                'label' => $oPaymentMethod->tag_value,
                'icon' => $oPaymentMethod->tag_icon
            ];
        }

        /**
         * Load Delivery Method
         */
        $aDelivery = ['id' => 0,'label' => '-','icon' => ''];
        $oDeliveryMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
            'Entitytag_ID' => $oBasketExists->deliverymethod_idfs,
        ]);
        if(count($oDeliveryMethod) > 0) {
            $oDeliveryMethod = $oDeliveryMethod->current();
            $aDelivery = [
                'id' => $oDeliveryMethod->Entitytag_ID,
                'label' => $oDeliveryMethod->tag_value,
                'icon' => $oDeliveryMethod->tag_icon
            ];
        }

        $aPositions = [];
        $oPosTbl = CoreEntityController::$oServiceManager->get(PositionTable::class);
        $oArticleTbl = CoreEntityController::$oServiceManager->get(ArticleTable::class);
        $oVariantTbl = CoreEntityController::$oServiceManager->get(VariantTable::class);

        $oBasketPositions = $oPosTbl->fetchAll(false,['basket_idfs' => $oBasketExists->getID()]);
        if(count($oBasketPositions) > 0) {
            foreach($oBasketPositions as $oPos) {
                switch($oPos->article_type) {
                    case 'variant':
                        $oPos->oVariant = $oVariantTbl->getSingle($oPos->article_idfs);
                        $oPos->oArticle = $oArticleTbl->getSingle($oPos->oVariant->article_idfs);
                        break;
                    default:
                        break;
                }
                $aPositions[] = $oPos;
            }
        }

        $aResponse = [
            'state' => 'success',
            'message' => 'paymentmethod saved',
            'basket' => $oBasketExists,
            'paymentmethod' => $aPay,
            'deliverymethod' => $aDelivery,
            'contact' => $oContactExists,
            'positions' => $aPositions,
        ];
        echo json_encode($aResponse);

        return false;
    }
}

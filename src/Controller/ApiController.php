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
    protected $aPluginTables;

    /**
     * BasketController constructor.
     *
     * @param AdapterInterface $oDbAdapter
     * @param BasketTable $oTableGateway
     * @since 1.0.0
     */
    public function __construct(AdapterInterface $oDbAdapter,BasketTable $oTableGateway,$oServiceManager,$aPluginTables = []) {
        $this->oTableGateway = $oTableGateway;
        $this->sSingleForm = 'basket-single';
        parent::__construct($oDbAdapter,$oTableGateway,$oServiceManager);
        $this->aPluginTables = $aPluginTables;

        if($oTableGateway) {
            # Attach TableGateway to Entity Models
            if(!isset(CoreEntityModel::$aEntityTables[$this->sSingleForm])) {
                CoreEntityModel::$aEntityTables[$this->sSingleForm] = $oTableGateway;
            }
        }
    }

    /**
     * Add New Item to Basket
     *
     * Adds New Item to Basket. Creates Basket
     * if no open Basket is found for the provided
     * session_id
     *
     * @return bool JSON - no viewfile
     * @since 1.0.0
     */
    public function addAction() {
        $this->layout('layout/json');

        # Get Data of Item that should be added
        $iItemID = $_REQUEST['shop_item_id'];
        $sItemType = $_REQUEST['shop_item_type'];
        $fItemAmount = $_REQUEST['shop_item_amount'];
        $sShopSessionID = $_REQUEST['shop_session_id'];

        # Check if there is already an open basket for this session
        $oBasket = false;
        try {
            $oBasketExists = $this->oTableGateway->getSingle($sShopSessionID,'shop_session_id');
            if($oBasketExists->shop_session_id != $sShopSessionID) {
                throw new \RuntimeException('Not really the same basket...');
            }
            # yes there is
            $oBasket = $oBasketExists;
        } catch(\RuntimeException $e) {
            # there is no basket - lets create one

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

                # only proceed of we have state tag present
                if(count($oNewState) > 0) {
                    $oNewState = $oNewState->current();

                    # Generate new open basket
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

                    # Save Basket to DB
                    $iBasketID = $this->oTableGateway->saveSingle($oBasket);
                    $oBasket = $this->oTableGateway->getSingle($iBasketID);
                }
            }
        }

        # Only proceed if basket is present
        if($oBasket) {
            $oBasketPosTbl = CoreEntityController::$oServiceManager->get(PositionTable::class);
            # generate new basket position
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

            # save to database
            $oPos->exchangeArray($aPosData);
            $oBasketPosTbl->saveSingle($oPos);
        }

        # json response for api
        $aResponse = ['state'=>'success','message'=> $fItemAmount.' items added to basket','oBasket'=>$oBasket];
        echo json_encode($aResponse);

        return false;
    }

    /**
     * Get open basket for session
     *
     * Loads Basket by session_id.
     * If found, it also checks for positions
     * and returns them.
     *
     * @return bool
     * @since 1.0.0
     */
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

        # we have a basket - lets check for positions
        $aPositions = [];
        $oPosTbl = CoreEntityController::$oServiceManager->get(PositionTable::class);
        $oArticleTbl = CoreEntityController::$oServiceManager->get(ArticleTable::class);
        $oVariantTbl = CoreEntityController::$oServiceManager->get(VariantTable::class);

        # attach positions
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

    /**
     * Start Checkout (Address Form)
     *
     * Shows Address Form for Checkout
     *
     * @return bool
     * @since 1.0.0
     */
    public function checkoutAction() {
        $this->layout('layout/json');

        $sShopSessionID = $_REQUEST['shop_session_id'];

        # check if we have delivery methods in database
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

        # get open basket
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

        # Update Basket Last Modified Date
        $this->oTableGateway->updateAttribute('modified_date',date('Y-m-d H:i:s',time()),'Basket_ID',$oBasketExists->getID());

        # if there is already a contact attached - try to load it
        if($oBasketExists->contact_idfs != 0) {
            $oContactTbl = CoreEntityController::$oServiceManager->get(ContactTable::class);
            $oAddressTbl = CoreEntityController::$oServiceManager->get(AddressTable::class);
            try {
                $oContactExists = $oContactTbl->getSingle($oBasketExists->contact_idfs);
            } catch (\RuntimeException $e) {
                # this should actually not happen at all
                $this->aPluginTables['basket-step']->insert([
                    'basket_idfs' => $oBasketExists->getID(),
                    'label' => 'Checkout started',
                    'step_key' => 'checkout_init',
                    'comment' => '(contact not found) - init again',
                    'date_created' => date('Y-m-d H:i:s',time()),
                ]);
                $aResponse = ['state' => 'success', 'message' => 'checkout started', 'basket' => $oBasketExists,'deliverymethods' => $aDeliveryMethods];
                echo json_encode($aResponse);

                return false;
            }

            # get contact address
            $oAddress = $oAddressTbl->getSingle($oContactExists->getID(),'contact_idfs');
            $oContactExists->address = $oAddress;

            # add step for repeat
            $this->aPluginTables['basket-step']->insert([
                'basket_idfs' => $oBasketExists->getID(),
                'label' => 'Checkout repeat',
                'step_key' => 'checkout_repeat',
                'comment' => '',
                'date_created' => date('Y-m-d H:i:s',time()),
            ]);

            $aResponse = ['state'=>'success','message'=>'checkout started again','basket'=>$oBasketExists,'contact'=>$oContactExists,'deliverymethods' => $aDeliveryMethods];
            echo json_encode($aResponse);

            return false;
        } else {
            # should be first time we start checkout or at least no data was provided in further tries
            $this->aPluginTables['basket-step']->insert([
                'basket_idfs' => $oBasketExists->getID(),
                'label' => 'Checkout started',
                'step_key' => 'checkout_init',
                'comment' => '',
                'date_created' => date('Y-m-d H:i:s',time()),
            ]);
            $aResponse = ['state' => 'success', 'message' => 'checkout started', 'basket' => $oBasketExists,'deliverymethods' => $aDeliveryMethods];
            echo json_encode($aResponse);

            return false;
        }
    }

    /**
     * Select Payment Method Form
     *
     * Shows Payment Method Form and
     * Saves Contact Data from last
     * step if found.
     *
     * @return bool
     * @since 1.0.0
     */
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
        # check if we come from last step (address form)
        if(isset($_REQUEST['email'])) {
            # payment selection init
            $this->aPluginTables['basket-step']->insert([
                'basket_idfs' => $oBasketExists->getID(),
                'label' => 'Payment Selection',
                'step_key' => 'payselect_init',
                'comment' => '',
                'date_created' => date('Y-m-d H:i:s',time()),
            ]);

            $aContactData['email_private'] = $_REQUEST['email'];
            $aContactData['firstname'] = $_REQUEST['firstname'];
            $aContactData['lastname'] = $_REQUEST['lastname'];
            $aContactData['phone_private'] = $_REQUEST['phone'];
            $aContactData['salutation_idfs'] = $_REQUEST['salutation'];

            try {
                $oContactExists = $oContactTbl->getSingle($aContactData['email_private'],'email_private');
            } catch(\RuntimeException $e) {
                # create a new contact
                $oNewContact = $oContactTbl->generateNew();
                $oNewContact->exchangeArray($aContactData);
                $iContactID = $oContactTbl->saveSingle($oNewContact);
                $oContactExists = $oContactTbl->getSingle($iContactID);

                # create a new address for contact
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
        if($oBasketExists->paymentmethod_idfs != 0) {
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
                $this->aPluginTables['basket-step']->insert([
                    'basket_idfs' => $oBasketExists->getID(),
                    'label' => 'Payment Selection',
                    'step_key' => 'payselect_repeat',
                    'comment' => 'Current Method '.$oPaymentMethod->tag_value,
                    'date_created' => date('Y-m-d H:i:s',time()),
                ]);
            }
        }

        # generate JSON response for api
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

    /**
     * Show Order Confirmation Page
     *
     * @return bool
     * @since 1.0.0
     */
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
        $iPaymentMethodID = $oBasketExists->paymentmethod_idfs;
        if(isset($_REQUEST['paymentmethod'])) {
            $iPaymentMethodID = $_REQUEST['paymentmethod'];
            $this->oTableGateway->updateAttribute('paymentmethod_idfs', $iPaymentMethodID, 'Basket_ID', $oBasketExists->getID());
        }
        /**
         * Load Payment Method
         */
        $aPay = ['id' => 0,'label' => '-','icon' => ''];
        if($iPaymentMethodID != 0) {
            $oPaymentMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'Entitytag_ID' => $iPaymentMethodID,
            ]);
            if (count($oPaymentMethod) > 0) {
                $oPaymentMethod = $oPaymentMethod->current();
                $aPay = [
                    'id' => $oPaymentMethod->Entitytag_ID,
                    'label' => $oPaymentMethod->tag_value,
                    'icon' => $oPaymentMethod->tag_icon
                ];
            }
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

        $this->aPluginTables['basket-step']->insert([
            'basket_idfs' => $oBasketExists->getID(),
            'label' => 'Confirm Order',
            'step_key' => 'confirm_order',
            'comment' => 'Payment Method '.$aPay['label'].', Delivery Method: '.$aDelivery['label'].', Positions: '.count($aPositions),
            'date_created' => date('Y-m-d H:i:s',time()),
        ]);

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

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

    private function tryToGetBasket($sShopSessionID) {
        $oBasketExists = $this->oTableGateway->fetchAll(false,[
            'shop_session_id-like' => $sShopSessionID,
            'is_archived_idfs' => 0]);
        if(count($oBasketExists) > 0) {
            foreach($oBasketExists as $oBasket) {
                return $oBasket;
            }
        } else {
            throw new \RuntimeException('No open basket with that id');
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
        $fCustomPrice = $_REQUEST['shop_item_customprice'];
        $sItemComment = $_REQUEST['shop_item_comment'];
        $sShopSessionID = $_REQUEST['shop_session_id'];
        $iRefID = isset($_REQUEST['shop_item_ref_idfs']) ? (int)$_REQUEST['shop_item_ref_idfs'] : 0;
        $sRefType = isset($_REQUEST['shop_item_ref_type']) ? $_REQUEST['shop_item_ref_type'] : 'none';

        # Check if there is already an open basket for this session
        $oBasket = false;
        try {
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
            if(is_object($oBasketExists)) {
                if ($oBasketExists->shop_session_id != $sShopSessionID) {
                    throw new \RuntimeException('Not really the same basket...');
                }
                # yes there is
                $oBasket = $oBasketExists;
                var_dump($oBasket);
            } else {
                throw new \RuntimeException('Not valid basket');
            }
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
                    'tag_key' => 'new',
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
                        'comment' => '',
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
            # if there is no comment - check if we already have to same article in basket
            $bGenNewPos = true;
            if($sItemComment == '') {
                $oSameInBasket = $oBasketPosTbl->fetchAll(false,[
                    'basket_idfs' => $oBasket->getID(),
                    'article_idfs' => $iItemID,
                    'comment' => '',
                    'ref_idfs' => $iRefID,
                    'ref_type' => $sRefType,
                ]);
                if(count($oSameInBasket) > 0) {
                    $bGenNewPos = false;
                    foreach($oSameInBasket as $oSame) {
                        $iNewAmount = $oSame->amount+(float)$fItemAmount;
                        $oBasketPosTbl->updateAttribute('amount',$iNewAmount,'Position_ID',$oSame->getID());

                        $this->addBasketStep($oBasket,'basket_updatepos','Update Amount +'.$fItemAmount.' for position '.$oSame->getID(),'Before '.$oSame->amount);
                        break;
                    }

                }
            }
            $sStepLabel = 'Add '.$fItemAmount.' article';
            if($bGenNewPos) {
                switch($sItemType) {
                    case 'variant':
                    case 'event':
                    case 'article':
                        try {
                            $oArticleTbl = CoreEntityController::$oServiceManager->get(ArticleTable::class);
                            $oVariantTbl = CoreEntityController::$oServiceManager->get(VariantTable::class);
                            if($fCustomPrice == 0) {
                                $oVar = $oVariantTbl->getSingle($iItemID);
                                if(is_object($oVar)) {
                                    $fCustomPrice = $oVar->price;
                                }
                                $oBaseArt = $oArticleTbl->getSingle($oVar->article_idfs);
                                $sStepLabel .= ' '.$oBaseArt->getLabel().': '.$oVar->getLabel();
                            }
                        } catch(\RuntimeException $e) {
                            # error loading tables
                        }
                        break;
                    default:
                        break;
                }
                # generate new basket position
                $oPos = $oBasketPosTbl->generateNew();
                $aPosData = [
                    'basket_idfs' => $oBasket->getID(),
                    'article_idfs' => $iItemID,
                    'article_type' => $sItemType,
                    'ref_idfs' => $iRefID,
                    'ref_type' => $sRefType,
                    'amount' => (float)$fItemAmount,
                    'price' => (float)$fCustomPrice,
                    'comment' => $sItemComment,
                    'created_by' => 1,
                    'created_date' => date('Y-m-d H:i:s', time()),
                    'modified_by' => 1,
                    'modified_date' => date('Y-m-d H:i:s', time()),
                ];

                # save to database
                $oPos->exchangeArray($aPosData);
                $oBasketPosTbl->saveSingle($oPos);

                $this->addBasketStep($oBasket,'basket_additem',$sStepLabel,$sItemComment);
            }
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
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
        } catch(\RuntimeException $e) {
            $aResponse = ['state'=>'success','message'=>'Your Basket is empty'];
            echo json_encode($aResponse);
            return false;
        }

        $aPositions = $this->getBasketPositions($oBasketExists);
        $iAmount = 0;
        if(count($aPositions) > 0) {
            foreach($aPositions as $oPos) {
                $iAmount+=$oPos->amount;
            }
        }
        $aResponse = ['state'=>'success','message'=>'open basket found','basket'=>$oBasketExists,'items'=>$aPositions,'amount'=>$iAmount];
        echo json_encode($aResponse);

        return false;
    }

    private function getBasketPositions($oBasketExists) {
        # we have a basket - lets check for positions
        $aPositions = [];
        $oPosTbl = CoreEntityController::$oServiceManager->get(PositionTable::class);
        $oArticleTbl = CoreEntityController::$oServiceManager->get(ArticleTable::class);
        $oVariantTbl = CoreEntityController::$oServiceManager->get(VariantTable::class);

        try {
            $oEventTbl = CoreEntityController::$oServiceManager->get(\OnePlace\Event\Model\EventTable::class);
        } catch(\RuntimeException $e) {
            # event plugin not present
        }

        # attach positions
        $oBasketPositions = $oPosTbl->fetchAll(false,['basket_idfs' => $oBasketExists->getID()]);
        if(count($oBasketPositions) > 0) {
            foreach($oBasketPositions as $oPos) {
                switch($oPos->article_type) {
                    case 'variant':
                        $oPos->oVariant = $oVariantTbl->getSingle($oPos->article_idfs);
                        $oPos->oArticle = $oArticleTbl->getSingle($oPos->oVariant->article_idfs);
                        $oPos->oArticle->featured_image = '/data/article/'.$oPos->oArticle->getID().'/'.$oPos->oArticle->featured_image;
                        # check for custom price (used for free amount coupons)
                        if($oPos->price != 0) {
                            $oPos->oVariant->price = $oPos->price;
                        }
                        # event plugin
                        if($oPos->ref_idfs != 0) {
                            switch($oPos->ref_type) {
                                case 'event':
                                    if(isset($oEventTbl)) {
                                        $oPos->article_type = 'event';
                                        $oPos->oEvent = $oEventTbl->getSingle($oPos->ref_idfs);
                                        # Event Rerun Plugin Start
                                        if($oPos->oEvent->root_event_idfs != 0) {
                                            $oRoot = $oEventTbl->getSingle($oPos->oEvent->root_event_idfs);
                                            $oPos->oEvent->label = $oRoot->label;
                                            $oPos->oEvent->excerpt = $oRoot->excerpt;
                                            $oPos->oEvent->featured_image = $oRoot->featured_image;
                                            $oPos->oEvent->description = $oRoot->description;
                                            $oPos->oEvent->featured_image = '/data/event/'.$oRoot->getID().'/'.$oPos->oEvent->featured_image;
                                        } else {
                                            $oPos->oEvent->featured_image = '/data/event/'.$oPos->oEvent->getID().'/'.$oPos->oEvent->featured_image;
                                        }
                                    }
                                    break;
                                default:
                                    break;
                            }
                        }
                        break;
                    default:
                        break;
                }
                $aPositions[] = $oPos;
            }
        }

        return $aPositions;
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
                    $aDeliveryMethods[] = (object)[
                        'id' => $oDel->Entitytag_ID,
                        'label' => $oDel->tag_value,
                        'icon' => $oDel->tag_icon,
                        'gateway' => $oDel->tag_key,
                    ];
                }
            }
        }

        # check if we have contact salutations in database
        $oTagSalu = CoreEntityController::$aCoreTables['core-tag']->select(['tag_key' => 'salutation']);
        $aContactSalutations = [];
        if(count($oTagSalu) > 0) {
            $oTagSalu = $oTagSalu->current();

            $oContactSalutsDB = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'entity_form_idfs' => 'contact-single',
                'tag_idfs' => $oTagSalu->Tag_ID,
            ]);
            if(count($oContactSalutsDB) > 0) {
                foreach($oContactSalutsDB as $oSal) {
                    $aContactSalutations[] = (object)['id' => $oSal->Entitytag_ID,'label' => $oSal->tag_value];
                }
            }
        }

        # get open basket
        try {
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
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
                $this->addBasketStep($oBasketExists,'checkout_init','Checkout stared','(contact not found) - init again');

                $aResponse = [
                    'state' => 'success',
                    'message' => 'checkout started',
                    'basket' => $oBasketExists,
                    'deliverymethods' => $aDeliveryMethods,
                    'salutations' => $aContactSalutations,
                ];
                echo json_encode($aResponse);

                return false;
            }

            # get contact address
            $oAddress = $oAddressTbl->getSingle($oContactExists->getID(),'contact_idfs');
            $oContactExists->address = $oAddress;

            $this->addBasketStep($oBasketExists,'checkout_repeat','Checkout repeat','');

            $aResponse = [
                'state'=>'success',
                'message'=>'checkout started again',
                'basket'=>$oBasketExists,
                'contact'=>$oContactExists,
                'deliverymethods' => $aDeliveryMethods,
                'salutations' => $aContactSalutations
            ];
            echo json_encode($aResponse);

            return false;
        } else {
            $this->addBasketStep($oBasketExists,'checkout_init','Checkout started','');

            $aResponse = [
                'state' => 'success',
                'message' => 'checkout started',
                'basket' => $oBasketExists,
                'deliverymethods' => $aDeliveryMethods,
                'salutations' => $aContactSalutations
            ];
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
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
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
            $this->addBasketStep($oBasketExists,'payselect_init','Payment Selection','');

            $aContactData['email_private'] = $_REQUEST['email'];
            $aContactData['firstname'] = $_REQUEST['firstname'];
            $aContactData['lastname'] = $_REQUEST['lastname'];
            $aContactData['phone_private'] = $_REQUEST['phone'];
            $aContactData['salutation_idfs'] = $_REQUEST['salutation'];

            try {
                $oContactExists = $oContactTbl->getSingle($aContactData['email_private'],'email_private');
                $iContactID = $oContactExists->getID();
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
            }
            $this->oTableGateway->updateAttribute('contact_idfs',$iContactID,'Basket_ID',$oBasketExists->getID());
        } else {
            $oContactExists = $oContactTbl->getSingle($oBasketExists->contact_idfs);
        }

        if(isset($_REQUEST['comment'])) {
            $this->oTableGateway->updateAttribute('comment', $_REQUEST['comment'], 'Basket_ID', $oBasketExists->getID());
        }

        /**
         * Load Delivery Method
         */
        $aDelivery = ['id' => 0,'label' => '-','icon' => ''];
        if(isset($_REQUEST['deliverymethod'])) {
            $iDeliveryMethodID = $_REQUEST['deliverymethod'];
            $this->oTableGateway->updateAttribute('deliverymethod_idfs', $iDeliveryMethodID, 'Basket_ID', $oBasketExists->getID());
            $oDeliveryMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'Entitytag_ID' => $iDeliveryMethodID,
            ]);
            if(count($oDeliveryMethod) > 0) {
                $oDeliveryMethod = $oDeliveryMethod->current();
                $aDelivery = [
                    'id' => $oDeliveryMethod->Entitytag_ID,
                    'label' => $oDeliveryMethod->tag_value,
                    'gateway' => $oDeliveryMethod->tag_key,
                    'icon' => $oDeliveryMethod->tag_icon
                ];
            }
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
                        'gateway' => $oDel->tag_key,
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
                $this->addBasketStep($oBasketExists,'payselect_repeat','Payment Selection','Current Method '.$oPaymentMethod->tag_value);
            }
        }

        # generate JSON response for api
        $aResponse = [
            'state' => 'success',
            'message' => 'contact saved',
            'basket' => $oBasketExists,
            'contact' => $oContactExists,
            'deliverymethod' => $aDelivery,
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
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
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
        $aPay = ['id' => 0,'label' => '-','icon' => '','gateway' => 'none'];
        if($iPaymentMethodID != 0) {
            $oPaymentMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'Entitytag_ID' => $iPaymentMethodID,
            ]);
            if (count($oPaymentMethod) > 0) {
                $oPaymentMethod = $oPaymentMethod->current();
                $aPay = [
                    'id' => $oPaymentMethod->Entitytag_ID,
                    'label' => $oPaymentMethod->tag_value,
                    'gateway' => $oPaymentMethod->tag_key,
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
                'gateway' => $oDeliveryMethod->tag_key,
                'icon' => $oDeliveryMethod->tag_icon
            ];
        }

        $aPositions = $this->getBasketPositions($oBasketExists);

        $this->addBasketStep($oBasketExists,'confirm_order','Confirm Order','Payment Method '.$aPay['label'].', Delivery Method: '.$aDelivery['label'].', Positions: '.count($aPositions));

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

    public function initpaymentAction() {
        $this->layout('layout/json');

        $sShopSessionID = $_REQUEST['shop_session_id'];

        /**
         * Load Basket
         */
        try {
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
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
         * Load Payment Method
         */
        $aPay = ['id' => 0,'label' => '-','icon' => '','gateway' => 'invalid'];
        $oPaymentMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
            'Entitytag_ID' => $oBasketExists->paymentmethod_idfs,
        ]);
        if (count($oPaymentMethod) > 0) {
            $oPaymentMethod = $oPaymentMethod->current();
            $aPay = [
                'id' => $oPaymentMethod->Entitytag_ID,
                'label' => $oPaymentMethod->tag_value,
                'gateway' => $oPaymentMethod->tag_key,
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

        $aPositions = $this->getBasketPositions($oBasketExists);

        $this->addBasketStep($oBasketExists,'payment_start','Payment Start','Payment Method '.$aPay['label'].',Positions: '.count($aPositions));

        switch($aPay['gateway']) {
            case 'prepay':
            case 'instore':
                $this->addBasketStep($oBasketExists,'basket_close','Create Order - Close Basket','Done '.$aPay['label'].',Positions: '.count($aPositions));
                $this->closeBasketAndCreateOrder($oBasketExists);
                /**
                # Get State Tag
                $oStateTag = CoreEntityController::$aCoreTables['core-tag']->select(['tag_key' => 'state']);
                if(count($oStateTag) > 0) {
                    $oStateTag = $oStateTag->current();

                    # Get Basket "done" Entity State Tag
                    $oDoneState = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                        'entity_form_idfs' => 'basket-single',
                        'tag_idfs' => $oStateTag->Tag_ID,
                        'tag_key' => 'done',
                    ]);

                    # only proceed of we have state tag present
                    if (count($oDoneState) > 0) {
                        $oDoneState = $oDoneState->current();
                        $this->oTableGateway->updateAttribute('state_idfs', $oDoneState->Entitytag_ID, 'Basket_ID', $oBasketExists->getID());
                    }
                }
                # archive basket
                $this->oTableGateway->updateAttribute('is_archived_idfs', 1, 'Basket_ID', $oBasketExists->getID());
                // Directly create order and close basket**/
                break;
            default:
                break;
        }

        $aResponse = [
            'state' => 'success',
            'message' => 'payment started',
            'basket' => $oBasketExists,
            'paymentmethod' => $aPay,
            'deliverymethod' => $aDelivery,
            'contact' => $oContactExists,
            'positions' => $aPositions,
        ];
        echo json_encode($aResponse);

        return false;
    }

    public function removeAction() {
        $this->layout('layout/json');

        $iPosID = $_REQUEST['position_id'];
        $sShopSessionID = $_REQUEST['shop_session_id'];

        /**
         * Load Basket
         */
        try {
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
        } catch(\RuntimeException $e) {
            $aResponse = ['state' => 'error','message' => 'No matching open basket found'];
            echo json_encode($aResponse);
            return false;
        }

        $this->aPluginTables['basket-position']->delete([
            'Position_ID' => $iPosID,
        ]);

        $this->addBasketStep($oBasketExists,'item_remove','Remove Position','Remove Item '.$iPosID);

        $aPositions = $this->getBasketPositions($oBasketExists);
        $aResponse = [
            'state' => 'success',
            'message' => 'position removed',
            'basket' => $oBasketExists,
            'items' => $aPositions,
        ];
        echo json_encode($aResponse);

        return false;
    }

    public function updateAction() {
        $this->layout('layout/json');

        $iPosID = $_REQUEST['position_id'];
        $fAmount = (float)$_REQUEST['position_amount'];
        $sShopSessionID = $_REQUEST['shop_session_id'];

        /**
         * Load Basket
         */
        try {
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
        } catch(\RuntimeException $e) {
            $aResponse = ['state' => 'error','message' => 'No matching open basket found'];
            echo json_encode($aResponse);
            return false;
        }

        $this->aPluginTables['basket-position']->update([
            'amount' => $fAmount,
        ],[
            'Position_ID' => $iPosID,
        ]);

        $this->addBasketStep($oBasketExists,'item_update','Update Position','New Amount '.$fAmount.' for Item '.$iPosID);

        $aPositions = $this->getBasketPositions($oBasketExists);
        $aResponse = [
            'state' => 'success',
            'message' => 'position updated',
            'basket' => $oBasketExists,
            'items' => $aPositions,
        ];
        echo json_encode($aResponse);

        return false;
    }

    public function stripeAction() {
        $this->layout('layout/json');

        $sShopSessionID = $_REQUEST['shop_session_id'];
        $sSessionID = $_REQUEST['session_id'];
        $sPaymentID = '';
        if(isset($_REQUEST['payment_id'])) {
            $sPaymentID = $_REQUEST['payment_id'];
        }
        $sPayState = 'init';
        if(isset($_REQUEST['payment_state'])) {
            if($_REQUEST['payment_state'] == 'done') {
                $sPayState = 'done';
            }
        }

        /**
         * Load Basket
         */
        try {
            $oBasketExists = $this->tryToGetBasket($sShopSessionID);
        } catch(\RuntimeException $e) {
            $aResponse = ['state' => 'error','message' => 'No matching open basket found'];
            echo json_encode($aResponse);
            return false;
        }

        $aPositions = $this->getBasketPositions($oBasketExists);

        switch($sPayState) {
            case 'done':
                if($sSessionID == $oBasketExists->payment_session_id) {
                    $this->oTableGateway->updateAttribute('payment_received', date('Y-m-d H:i:s', time()), 'Basket_ID', $oBasketExists->getID());

                    $this->addBasketStep($oBasketExists,'basket_close','Create Order - Close Basket','Done Stripe,Positions: ' . count($aPositions));

                    /// here
                    $this->closeBasketAndCreateOrder($oBasketExists,date('Y-m-d H:i:s', time()));
                    ///
                    $aResponse = [
                        'state' => 'success',
                        'message' => 'payment finished',
                        'basket' => $oBasketExists,
                    ];
                } else {
                    $aResponse = [
                        'state' => 'error',
                        'message' => 'invalid payment id',
                        'basket' => $oBasketExists,
                    ];
                }
                break;
            case 'init':
                $this->oTableGateway->updateAttribute('payment_gateway','stripe','Basket_ID',$oBasketExists->getID());
                $this->oTableGateway->updateAttribute('payment_id',$sPaymentID,'Basket_ID',$oBasketExists->getID());
                $this->oTableGateway->updateAttribute('payment_session_id',$sSessionID,'Basket_ID',$oBasketExists->getID());
                $this->oTableGateway->updateAttribute('payment_started',date('Y-m-d H:i:s',time()),'Basket_ID',$oBasketExists->getID());

                $aResponse = [
                    'state' => 'success',
                    'message' => 'payment started',
                    'basket' => $oBasketExists,
                ];
                break;
            default:
                break;
        }

        echo json_encode($aResponse);

        return false;
    }

    private function addBasketStep($oBasket,$sStepKey,$sMessage,$sComment) {
        $this->aPluginTables['basket-step']->insert([
            'basket_idfs' => $oBasket->getID(),
            'label' => $sMessage,
            'step_key' => $sStepKey,
            'comment' => $sComment,
            'date_created' => date('Y-m-d H:i:s',time()),
        ]);
        $this->oTableGateway->updateAttribute('modified_date', date('Y-m-d H:i:s',time()), 'Basket_ID', $oBasket->getID());
    }

    private function closeBasketAndCreateOrder($oBasket,$sPaymentReceived = '0000-00-00 00:00:00') {
        # Get State Tag
        $oStateTag = CoreEntityController::$aCoreTables['core-tag']->select(['tag_key' => 'state']);
        if (count($oStateTag) > 0) {
            $oStateTag = $oStateTag->current();

            # Get Basket "done" Entity State Tag
            $oDoneState = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'entity_form_idfs' => 'basket-single',
                'tag_idfs' => $oStateTag->Tag_ID,
                'tag_key' => 'done',
            ]);

            # only proceed of we have state tag present
            if (count($oDoneState) > 0) {
                $oDoneState = $oDoneState->current();
                $this->oTableGateway->updateAttribute('state_idfs', $oDoneState->Entitytag_ID, 'Basket_ID', $oBasket->getID());
            }
        }
        # archive basket
        $this->oTableGateway->updateAttribute('is_archived_idfs', 1, 'Basket_ID', $oBasket->getID());

        $oNewJob = $this->aPluginTables['job']->generateNew();

        # Get Job "new" Entity State Tag
        $oNewState = CoreEntityController::$aCoreTables['core-entity-tag']->select([
            'entity_form_idfs' => 'job-single',
            'tag_idfs' => $oStateTag->Tag_ID,
            'tag_key' => 'new',
        ]);
        if(count($oNewState)) {
            $oNewState = $oNewState->current();
            $aDelivery = false;
            $oDeliveryMethod = CoreEntityController::$aCoreTables['core-entity-tag']->select([
                'Entitytag_ID' => $oBasket->deliverymethod_idfs,
            ]);
            if(count($oDeliveryMethod) > 0) {
                $oDeliveryMethod = $oDeliveryMethod->current();
                $aDelivery = [
                    'id' => $oDeliveryMethod->Entitytag_ID,
                    'label' => $oDeliveryMethod->tag_value,
                    'gateway' => $oDeliveryMethod->tag_key,
                    'icon' => $oDeliveryMethod->tag_icon
                ];
            }

            $aJobData = [
                'contact_idfs' => $oBasket->contact_idfs,
                'state_idfs' => $oNewState->Entitytag_ID,
                'paymentmethod_idfs' => $oBasket->paymentmethod_idfs,
                'payment_session_id' => $oBasket->payment_session_id,
                'payment_started' => $oBasket->payment_started,
                'payment_received' => $sPaymentReceived,
                'payment_id' => $oBasket->payment_id,
                'deliverymethod_idfs' => $oBasket->deliverymethod_idfs,
                'label' => 'Shop Bestellung vom '.date('d.m.Y H:i',time()),
                'date' => date('Y-m-d H:i:s',time()),
                'discount' => 0,
                'description' => 'Bestellung aus dem Shop. Kommentar des Kunden: '.$oBasket->comment,
                'created_by' => 1,
                'created_date' => date('Y-m-d H:i:s',time()),
                'modified_by' => 1,
                'modified_date' => date('Y-m-d H:i:s',time())
            ];
            $oNewJob->exchangeArray($aJobData);
            $iNewJobID = $this->aPluginTables['job']->saveSingle($oNewJob);
            $this->oTableGateway->updateAttribute('job_idfs',$iNewJobID,'Basket_ID',$oBasket->getID());

            $aPositions = $this->getBasketPositions($oBasket);
            $fTotal = 0;
            if(count($aPositions) > 0) {
                $iSortID = 0;
                foreach($aPositions as $oPos) {
                    $this->aPluginTables['job-position']->insert([
                        'job_idfs' => $iNewJobID,
                        'article_idfs' => $oPos->article_idfs,
                        'ref_idfs' => $oPos->ref_idfs,
                        'ref_type' => $oPos->ref_type,
                        'type' => $oPos->article_type,
                        'sort_id' => $iSortID,
                        'amount' => $oPos->amount,
                        'price' => $oPos->price,
                        'discount' => 0,
                        'discount_type' => 'percent',
                        'description' => $oPos->comment
                    ]);
                    $fTotal+=($oPos->amount*$oPos->price);
                    $iSortID++;
                }
            }
            if($fTotal <= 100 && $aDelivery['gateway'] == 'mail') {
                $this->aPluginTables['job-position']->insert([
                    'job_idfs' => $iNewJobID,
                    'article_idfs' => 0,
                    'ref_idfs' => 0,
                    'ref_type' => 'none',
                    'type' => 'custom',
                    'sort_id' => $iSortID,
                    'amount' => 1,
                    'price' => 2.5,
                    'discount' => 0,
                    'discount_type' => 'percent',
                    'description' => 'Lieferkosten Postversand unter 100 €'
                ]);
            }
        } else {
            // could not find state "new" tag for job
        }
    }
}

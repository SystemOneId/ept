<?php

class Api_ShipmentsController extends Zend_Controller_Action {
    public function init() {
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
            ->initContext();
        $this->_helper->layout()->setLayout('api');
    }

    public function indexAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else {
            $this->getResponse()->setHeader("Content-Type", "application/json");
            $shipmentService = new Application_Service_Shipments();
            $shipmentService->getShipmentCurrent(array_merge(
                array(
                    "currentType" => "active",
                    "forMobileApp" => true
                ), $this->getRequest()->getParams()));
        }
    }

    public function shipmentAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else {
            $this->getResponse()->setHeader("Content-Type", "application/json");
            $shipmentService = new Application_Service_Shipments();
            $shipmentService->getShipmentCurrent(array_merge(
                array(
                    "currentType" => "active",
                    "forMobileApp" => true,
                ), $this->getRequest()->getParams()));
        }
    }

    public function receiveAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else {
            if ($this->getRequest()->isPut()) {
                $sID = intval($this->getRequest()->getParam('sid'));
                $pID = intval($this->getRequest()->getParam('pid'));
                $params = Zend_Json::decode($this->getRequest()->getRawBody());
                $params['shipment_id'] = $sID;
                $params['participant_id'] = $pID;
                $params['shipment_receipt_date'] = Application_Service_Common::ParseDate($params['dateReceived']);
                $shipmentService = new Application_Service_Shipments();
                $shipmentService->receiveShipment($params);
                $this->getResponse()->setBody('OK');
                $this->getResponse()->setHttpResponseCode(200);
            }
        }
    }
}




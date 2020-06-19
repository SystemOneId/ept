<?php

class Admin_ResponseController extends Zend_Controller_Action
{
    public function init() {
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
                    ->addActionContext('get-shipments', 'html')
                    ->addActionContext('update-shipment-status', 'html')
                    ->addActionContext('delete-response', 'html')
                    ->initContext();
        $this->_helper->layout()->pageName = 'analyze';
    }

    public function indexAction() {
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $responseService = new Application_Service_Response();
            $responseService->echoAllDistributions($params);
        }
		if ($this->_hasParam('scheme') && $this->_hasParam('showcalc')) {
            $this->view->showcalc = ($this->_getParam('showcalc'));
            $this->view->scheme = $this->_getParam('scheme');
		}
    }

    public function getShipmentsAction() {
        if ($this->_hasParam('did')) {
            $id = (int)($this->_getParam('did'));
            $responseService = new Application_Service_Response();
            $this->view->shipments = $responseService->getShipments($id);
        } else {
            $this->view->shipments = false;
        }
    }

    public function shipmentAction() {
        if ($this->_hasParam('sid')) {
            $id = (int)base64_decode($this->_getParam('sid'));
            $responseService = new Application_Service_Response();
            $shipment = $this->view->shipment = $responseService->getShipmentToEdit($id);
            $this->view->shipmentsUnderDistro = $responseService->getShipments($shipment[0]['distribution_id']);
        } else {
            $this->_redirect("/admin/response/");
        }
    }

    public function editAction() {
        if ($this->getRequest()->isPost()) {
            $params = $this->getRequest()->getPost();
            $responseService = new Application_Service_Response();
            $shipmentId = base64_encode($params['shipmentId']);
            $rawSubmissionService = new Application_Service_RawSubmission();
            $rawSubmissionService->addRawSubmission(array(
                "function" => "modules/admin/controllers/ResponseController/editAction POST",
                "body" => $params
            ));
            if ($responseService->updateShipmentResults($params)) {
                $shipmentService = new Application_Service_Shipments();
                $shipmentService->sendShipmentSavedEmailToParticipantsAndPTCC($params['participantId'], $params['shipmentId']);
            }
            $alertMsg = new Zend_Session_Namespace('alertSpace');
            $alertMsg->message = "Shipment Results updated successfully";
            if (isset($params['whereToGo']) && $params['whereToGo'] != "") {
               $this->_redirect($params['whereToGo']);
            } else {
                $this->_redirect("/admin/response/shipment/sid/$shipmentId");
            }
        } else {
            if ($this->_hasParam('sid') && $this->_hasParam('pid')  && $this->_hasParam('scheme')) {
                $this->view->currentUrl = "/admin/response/edit/sid/".$this->_getParam('sid')."/pid/".$this->_getParam('pid')."/scheme/".$this->_getParam('scheme');
                $sid = (int)base64_decode($this->_getParam('sid'));
                $pid = (int)base64_decode($this->_getParam('pid'));
                $this->view->scheme = $scheme = base64_decode($this->_getParam('scheme'));
                $schemeService = new Application_Service_Schemes();
                if ($scheme == 'tb') {
                    $this->view->assays = $schemeService->getTbAssayReferenceMap();
                    $instrumentDb = new Application_Model_DbTable_Instruments();
                    $this->view->instruments = $instrumentDb->getInstruments($pid, true);
                }
                $responseService = new Application_Service_Response();
                $this->view->responseData = $responseService->editResponse($sid,$pid,$scheme);
                $globalConfigDb = new Application_Model_DbTable_GlobalConfig();
                $this->view->customField1 = $globalConfigDb->getValue('custom_field_1');
                $this->view->customField2 = $globalConfigDb->getValue('custom_field_2');
                $this->view->haveCustom = $globalConfigDb->getValue('custom_field_needed');
                $commonService = new Application_Service_Common();
                $this->view->globalQcAccess = $commonService->getConfig('qc_access');
                $this->view->allNotTestedReason = $schemeService->getNotTestedReasons($scheme);
                if ($scheme == 'tb') {
                    $attributes = json_decode($this->view->responseData['shipment']['attributes'], true);
                    $transferToParticipantId = null;
                    if (isset($attributes)) {
                        if (isset($attributes['transferToParticipantId'])) {
                            $transferToParticipantId = $attributes['transferToParticipantId'];
                        }
                    }
                    $this->view->otherUnenrolledParticipants =
                        $responseService->getOtherUnenrolledParticipants(
                            $sid, $pid, $transferToParticipantId);
                }
            } else {
                $this->_redirect("/admin/response/");
            }
        }
    }
}


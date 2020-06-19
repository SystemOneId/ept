<?php

class Reports_FinalizeController extends Zend_Controller_Action
{

    public function init()
    {
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
                 ->addActionContext('get-shipments', 'html')
                 ->addActionContext('shipments', 'html')
                 ->addActionContext('get-finalized-shipments', 'html')
                  ->initContext();
        $this->_helper->layout()->pageName = 'analyze';
    }

    public function indexAction()
    {
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $distributionService = new Application_Service_Distribution();
            $distributionService->getAllDistributionReports($params);
        }
    }

    public function getShipmentsAction()
    {
        if($this->_hasParam('did')){
            $id = (int)($this->_getParam('did'));
            $shipmentService = new Application_Service_Shipments();
            $this->view->shipments = $shipmentService->getShipmentInReports($id);
        }else{
            $this->view->shipments = false;
        }
    }

    public function shipmentsAction()
    {
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $distributionService = new Application_Service_Shipments();
            $distributionService->getAllFinalizedShipments($params);
        }
    }

    public function getFinalizedShipmentsAction()
    {
        if($this->_hasParam('did')){
            $id = (int)($this->_getParam('did'));
            $shipmentService = new Application_Service_Shipments();
            $this->view->shipments = $shipmentService->getFinalizedShipmentInReports($id);
        }else{
            $this->view->shipments = false;
        }
    }

    public function viewFinalizedShipmentAction(){
        $shipmentService = new Application_Service_Shipments();
         if($this->_hasParam('sid')){
            $id = (int)base64_decode($this->_getParam('sid'));
            $reEvaluate = false;
            $evalService = new Application_Service_Evaluation();
            $shipment = $this->view->shipment = $evalService->getShipmentToEvaluateReports($id,$reEvaluate);
            $this->view->responseCount = $evalService->getResponseCount($id,$shipment[0]['distribution_id']);
            $this->view->shipmentsUnderDistro = $shipmentService->getShipmentInReports($shipment[0]['distribution_id']);
        }else{
            $this->_redirect("/reports/finalize/");
        }
    }

    public function downloadAction() {
        $this->_helper->layout()->disableLayout();
        if ($this->_hasParam('d92nl9d8d')) {
            $id = (int) base64_decode($this->_getParam('d92nl9d8d'));
            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $this->view->result = $db->fetchRow($db->select()
                ->from(array('spm' => 'shipment_participant_map'), array('spm.map_id'))
                ->join(array('s' => 'shipment'), 's.shipment_id=spm.shipment_id', array('s.shipment_code'))
                ->join(array('p' => 'participant'), 'p.participant_id=spm.participant_id', array('p.lab_name'))
                ->where("spm.map_id = ?", $id));
        }
    }
}






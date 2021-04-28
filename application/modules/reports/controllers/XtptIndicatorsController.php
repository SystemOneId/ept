<?php

class Reports_XtptIndicatorsController extends Zend_Controller_Action {
    public function init(){
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
            ->addActionContext('report', 'html')
            ->addActionContext('participant-attention', 'html')
            ->initContext();
        $this->_helper->layout()->pageName = 'report';
    }

    public function indexAction() {
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $reportService = new Application_Service_Reports();
            $response = $reportService->getXtptIndicatorsReport($params);
            $this->view->response = $response;
        }
    }
    public function participantAttentionAction() {
        $participantService = new Application_Service_Participants();
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $output=$participantService->getUAttentionRequiredParticipants($params);
            echo json_encode($output);          
        }
    }
}
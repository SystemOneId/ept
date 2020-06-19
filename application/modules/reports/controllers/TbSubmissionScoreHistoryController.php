<?php

class Reports_TbSubmissionScoreHistoryController extends Zend_Controller_Action {
    public function init(){
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
            ->addActionContext('report', 'html')
            ->addActionContext('submissions-count', 'html')
            ->initContext();
        $this->_helper->layout()->pageName = 'report';
    }

    public function indexAction() {
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $reportService = new Application_Service_Reports();
            $response = $reportService->getSubmissionScoreHistoryReport($params);
            $this->view->response = $response;
        }
    }

    public function submissionsCountAction() {
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $reportService = new Application_Service_Reports();
            $this->view->participantsCount = $reportService->getSubmissionScoreHistoryCount($params);
        }
    }
}


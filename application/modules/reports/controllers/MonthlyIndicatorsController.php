<?php

class Reports_MonthlyIndicatorsController extends Zend_Controller_Action {
    public function init(){
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
            ->addActionContext('report', 'html')
            ->initContext();
        $this->_helper->layout()->pageName = 'report';
    }

    public function indexAction() {
        $reportService = new Application_Service_Reports();
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();
            $response = $reportService->getMonthlyIndicatorsReport($params);
            $this->view->response = $response;
        }
        $commonService = new Application_Service_Common();
        $this->view->countriesList = $commonService->getCountriesList();
        $this->view->monthsList = $reportService->getMonthlyIndicatorsMonths();
    }
}


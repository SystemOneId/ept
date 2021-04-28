<?php

class Admin_PtccProfilesController extends Zend_Controller_Action {
    public function init() {
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
                ->initContext();
        $this->_helper->layout()->pageName = 'configMenu';
    }

    public function indexAction() {
        if ($this->getRequest()->isPost()) {
            $params = $this->_getAllParams();            
            $clientsServices = new Application_Service_PtccProfile();
            $clientsServices->getAllPtccProfiles($params);
        }
    }

    public function saveAction() {
        $ptccProfileService = new Application_Service_PtccProfile();
        if ($this->getRequest()->isPost()) {
            $params = $this->getRequest()->getPost();
            $ptccProfileService->savePtccProfile($params);
            $this->_redirect("/admin/ptcc-profiles");
        }
    }

    public function editAction() {
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $ptccProfileService = new Application_Service_PtccProfile();
        if ($this->getRequest()->isPost()) {
            $params = $this->getRequest()->getPost();
            $ptccProfileService->savePtccProfile($params);
            if ($authNameSpace->is_ptcc_coordinator == 0) {
                $this->_redirect("/admin/ptcc-profiles");
            } else {
                $this->_redirect("/admin");
            }
        } elseif ($this->_hasParam('id')) {
            $idParamValue = $this->_getParam('id');
            $this->view->idParam = $idParamValue;
            if ($idParamValue == "me") {
                $this->view->ptccProfile = $ptccProfileService->getSystemPtccProfileDetails($authNameSpace->admin_id);
            } else {
                $adminId = (int)$this->_getParam('id');
                $this->view->ptccProfile = $ptccProfileService->getSystemPtccProfileDetails($adminId);
            }
        } else {
            $this->view->ptccProfile = $ptccProfileService->getSystemPtccProfileDetails();
        }
    }
}






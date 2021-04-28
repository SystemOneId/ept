<?php

class Admin_CountriesController extends Zend_Controller_Action {
    public function init() {
        $this->_helper->layout()->pageName = 'configMenu';
    }

    public function participantMonthlyIndicatorsAction() {
        $commonService = new Application_Service_Common();
        if ($this->_hasParam('enable')) {
            $enableCountryId = (int)base64_decode($this->_getParam('enable'));
            $commonService->enableMonthlyIndicatorSubmission($enableCountryId);
            $this->_redirect("/admin/countries/participant-monthly-indicators");
        }
        else if ($this->_hasParam('disable')) {
            $disableCountryId = (int)base64_decode($this->_getParam('disable'));
            $commonService->disableMonthlyIndicatorSubmission($disableCountryId);
            $this->_redirect("/admin/countries/participant-monthly-indicators");
        }
        $this->view->countriesList = $commonService->getCountriesList();
    }
}





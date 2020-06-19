<?php

class Api_GxAlertController extends Zend_Controller_Action {
    public function init() {
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
            ->initContext();
        $this->_helper->layout()->setLayout('api');
    }

    public function indexAction() {
        $request = $this->getRequest();
        $response = $this->getResponse();
        if ($request->isPost()) {
            $authResolver = Application_Service_ApiAuthResolver::create($request, $response);
            $authResult = $authResolver->authenticate();
            if ($authResult->isValid() == 1) {
                $params = Zend_Json::decode($this->getRequest()->getRawBody());

                $gxAlertResultService = new Application_Service_GxAlertResult();
                $gxAlertResultId = $gxAlertResultService->submitResultFromGxAlert($params);
                if (isset($gxAlertResultId)) { // && $gxAlertResultId > 0) {
                    $response->setBody($gxAlertResultId);
                    $response->setHttpResponseCode(200);
                } else {
                    $response->setBody("Not Found");
                    $response->setHttpResponseCode(404);
                }
            } else {
                $response->setBody('Invalid credentials');
                $response->setHttpResponseCode(401);
            }
        } else {
            $response->setBody('Post a result');
            $response->setHttpResponseCode(400);
        }
    }
}





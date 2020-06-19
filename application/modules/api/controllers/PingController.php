<?php

class Api_PingController extends Zend_Controller_Action {
    public function init() {
        $this->_helper->layout()->setLayout('api');
    }

    public function indexAction() {
        $this->getResponse()->setBody('Pong');
        $this->getResponse()->setHttpResponseCode(200);
        $this->getResponse()->setHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}




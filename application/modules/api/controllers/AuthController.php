<?php

class Api_AuthController extends Zend_Controller_Action {
    public function init() {
        $this->_helper->layout()->setLayout('api');
    }

    public function indexAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (isset($authNameSpace->dm_id) && intval($authNameSpace->dm_id) > 0) {
            $this->getResponse()->setHeader("Content-Type", "application/json");
            $this->getResponse()->setHttpResponseCode(200);
            echo json_encode(array(
                'id' => $authNameSpace->dm_id,
                'username' => $authNameSpace->UserID,
                'firstName' => $authNameSpace->first_name,
                'lastName' => $authNameSpace->last_name,
                'qcAccess' => $authNameSpace->qc_access,
                'message' => 'Signed In As ' . $authNameSpace->first_name . ' ' . $authNameSpace->last_name
            ));
        } else {
            $this->getResponse()->setHeader("Content-Type", "application/json");
            $this->getResponse()->setHttpResponseCode(200);
            echo json_encode(array(
                'message' => 'Not Signed In'
            ));
        }
    }

    public function signInAction() {
        if ($this->getRequest()->isPost()) {
            $params = Zend_Json::decode($this->getRequest()->getRawBody());
            $params['username'] = trim($params['username']);
            $params['password'] = trim($params['password']);
            $rememberMe = isset($params['rememberMe']) ? boolval(trim($params['rememberMe'])) : false;
            $platform = isset($params['platform']) ? $params['platform'] : null;
            $pushNotificationToken = isset($params['pushNotificationToken']) ? $params['pushNotificationToken'] : null;
            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $adapter = new Zend_Auth_Adapter_DbTable($db, "data_manager", "primary_email", "password");
            $adapter->setIdentity($params['username']);
            $adapter->setCredential($params['password']);
            $select = $adapter->getDbSelect();
            $select->where('status = "active"');

            // STEP 2 : Let's Authenticate
            $auth = Zend_Auth::getInstance();
            $res = $auth->authenticate($adapter); // -- METHOD 2 to authenticate , seems to work fine for me

            if ($res->isValid()){
                Zend_Session::rememberMe($rememberMe ? (60 * 60) : (60 * 60 * 24 * 30)); // asking the session to be active for 1 hour or 30 days
                $rs = $adapter->getResultRowObject();
                $authNameSpace = new Zend_Session_Namespace('datamanagers');
                $authNameSpace->UserID = $params['username'];
                $authNameSpace->dm_id = $rs->dm_id;
                $authNameSpace->first_name = $rs->first_name;
                $authNameSpace->last_name = $rs->last_name;
                $authNameSpace->phone = $rs->phone;
                $authNameSpace->email = $rs->primary_email;
                $authNameSpace->qc_access = $rs->qc_access;
                $authNameSpace->view_only_access = $rs->view_only_access;
                $authNameSpace->enable_adding_test_response_date = $rs->enable_adding_test_response_date;
                $authNameSpace->enable_choosing_mode_of_receipt = $rs->enable_choosing_mode_of_receipt;
                $authNameSpace->force_password_reset = $rs->force_password_reset;
                $userService = new Application_Service_DataManagers();
                $userService->updateLastLogin($rs->dm_id);

                if(isset($pushNotificationToken)) {
                    $pushNotificationService = new Application_Service_PushNotifications();
                    $pushNotificationService->registerToken($rs->dm_id, $platform, $pushNotificationToken);
                }

                $this->getResponse()->setHeader("Content-Type", "application/json");
                $this->getResponse()->setHttpResponseCode(200);
                echo json_encode(array(
                    'id' => $authNameSpace->dm_id,
                    'username' => $authNameSpace->UserID,
                    'firstName' => $authNameSpace->first_name,
                    'lastName' => $authNameSpace->last_name,
                    'qcAccess' => $authNameSpace->qc_access,
                    'message' => 'Signed In As ' . $authNameSpace->first_name . ' ' . $authNameSpace->last_name
                ));
            } else {
                Zend_Auth::getInstance()->clearIdentity();
                $this->getResponse()->setHeader("Content-Type", "application/json");
                $this->getResponse()->setHttpResponseCode(401);
                echo json_encode(array(
                    'message' => 'Not Signed In'
                ));
                Zend_Session::namespaceUnset('datamanagers');
            }
        }
    }

    public function signOutAction() {
        Zend_Auth::getInstance()->clearIdentity();
        $this->getResponse()->setHeader("Content-Type", "application/json");
        $this->getResponse()->setHttpResponseCode(200);
        echo json_encode(array(
            'message' => 'Signed Out'
        ));
        Zend_Session::namespaceUnset('datamanagers');
    }
}




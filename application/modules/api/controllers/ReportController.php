<?php

class Api_ReportController extends Zend_Controller_Action {
    public function init() {
        $this->_helper->layout()->setLayout('api');
    }

    public function indexAction() {
        // http://ept/api/report?sid=4&pid=2
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else {
            $sID = intval($this->getRequest()->getParam('sid'));
            $pID = intval($this->getRequest()->getParam('pid'));
            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $db->insert('report_download_log', array(
                'shipment_id' => $sID,
                'participant_id' => $pID,
                'request_data' => json_encode(
                    array_diff_key(array_merge($this->getRequest()->getParams(), $_SERVER),
                    array_flip(['REDIRECT_APPLICATION_ENV', 'REDIRECT_STATUS', 'APPLICATION_ENV',
                            'HTTP_CONNECTION', 'PATH', 'SystemRoot', 'COMSPEC', 'PATHEXT', 'WINDIR',
                            'SERVER_SIGNATURE', 'SERVER_SOFTWARE', 'SERVER_NAME', 'SERVER_ADDR',
                            'SERVER_PORT', 'REMOTE_ADDR', 'DOCUMENT_ROOT', 'CONTEXT_DOCUMENT_ROOT',
                            'SERVER_ADMIN']))),
                'timestamp' => new Zend_Db_Expr('now()')
            ));
            $this->view->result = $db->fetchRow($db->select()
                ->from(array('spm' => 'shipment_participant_map'), array('spm.map_id'))
                ->join(array('s' => 'shipment'), 's.shipment_id=spm.shipment_id', array('s.shipment_code'))
                ->join(array('p' => 'participant'), 'p.participant_id=spm.participant_id', array('p.lab_name'))
                ->where("spm.shipment_id = ?", $sID)
                ->where("spm.participant_id = ?", $pID));
        }
    }

    public function viewAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else {
            $sID = intval($this->getRequest()->getParam('sid'));
            $pID = intval($this->getRequest()->getParam('pid'));
            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $db->insert('report_download_log', array(
                'shipment_id' => $sID,
                'participant_id' => $pID,
                'request_data' => json_encode(
                    array_diff_key(array_merge($this->getRequest()->getParams(), $_SERVER),
                        array_flip(['REDIRECT_APPLICATION_ENV', 'REDIRECT_STATUS', 'APPLICATION_ENV',
                            'HTTP_CONNECTION', 'PATH', 'SystemRoot', 'COMSPEC', 'PATHEXT', 'WINDIR',
                            'SERVER_SIGNATURE', 'SERVER_SOFTWARE', 'SERVER_NAME', 'SERVER_ADDR',
                            'SERVER_PORT', 'REMOTE_ADDR', 'DOCUMENT_ROOT', 'CONTEXT_DOCUMENT_ROOT',
                            'SERVER_ADMIN']))),
                'timestamp' => new Zend_Db_Expr('now()')
            ));
            $result = $db->fetchRow($db->select()
                ->from(array('spm' => 'shipment_participant_map'), array('spm.map_id'))
                ->join(array('s' => 'shipment'), 's.shipment_id=spm.shipment_id', array('s.shipment_code'))
                ->join(array('p' => 'participant'), 'p.participant_id=spm.participant_id', array('p.lab_name'))
                ->where("spm.shipment_id = ?", $sID)
                ->where("spm.participant_id = ?", $pID));

            $fileName=$result['lab_name']."-".$result['map_id'].".pdf";
            $fileName = preg_replace('/[^A-Za-z0-9.]/', '-', $fileName);
            $fileName = str_replace(" ", "-", $fileName);
            $this->view->url = '../../uploads/reports/'.$result['shipment_code'].'/'.$fileName;
        }
    }
}




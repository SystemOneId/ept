<?php

class Application_Model_DbTable_ApiCredentials extends Zend_Db_Table_Abstract {
    protected $_name = 'api_credentials';
    protected $_primary = 'id';

    public function getCredentials($username) {
        $sQuery = $this->getAdapter()
            ->select()
            ->from(array('ac' => $this->_name))
            ->where('username = ?', $username);

        return $this->getAdapter()->fetchRow($sQuery);
    }
}
<?php

class Application_Model_DbTable_Countries extends Zend_Db_Table_Abstract {
    protected $_name = 'countries';

    public function getAllCountries() {
		$sql = $this->select();
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if ($authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->where("id IN (".implode(",",$authNameSpace->countries).")");
        }
		return $this->fetchAll($sql);
	}
    public function getCountryIds($isonames=array()) {
        return $this->fetchAll($this->select()->from(array('countries'),
               array('id'))->where('iso_name  IN (?) ',$isonames) );
    }
    public function updateCountry($countryId, $countryData) {
        return $this->update($countryData,"id=".$countryId);
    }
}


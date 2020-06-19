<?php

class Application_Service_PtccProfile {
	public function getAllPtccProfiles($params) {
		$ptccProfileDb = new Application_Model_DbTable_SystemAdmin();
		return $ptccProfileDb->getAllPtccProfiles($params);
	}

	public function savePtccProfile($params) {
        $ptccProfileDb = new Application_Model_DbTable_SystemAdmin();
		return $ptccProfileDb->upsertPtccProfile($params);
	}

	public function getSystemPtccProfileDetails($adminId = null) {
        $ptccProfileDb = new Application_Model_DbTable_SystemAdmin();
		return $ptccProfileDb->getPtccProfileDetails($adminId);
	}
}


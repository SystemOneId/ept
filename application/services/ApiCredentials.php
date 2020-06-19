<?php

class Application_Service_ApiCredentials {
	public function getApiPassword($username) {
		$apiCredentialsDb = new Application_Model_DbTable_ApiCredentials();
		$credentials = $apiCredentialsDb->getCredentials($username);
		if ($credentials["active"] == 1) {
		    return $credentials["password"];
        }
        return null;
	}
}


<?php

class Application_Service_ApiAuthResolver implements Zend_Auth_Adapter_Http_Resolver_Interface
{
    public static function create($request, $response) {
        $authConfig = array(
            'accept_schemes' => 'basic',
            'realm'          => 'ePT Web Platform',
            'digest_domains' => '/api/GxAlert',
            'nonce_timeout'  => 3600,
        );
        $authAdapter = new Zend_Auth_Adapter_Http($authConfig);
        $authAdapter->setRequest($request);
        $authAdapter->setResponse($response);
        $basicAuthResolver = new Application_Service_ApiAuthResolver();
        $authAdapter->setBasicResolver($basicAuthResolver);
        return $authAdapter;
    }

    public function resolve($username, $realm)
    {
        $apiCredentialsService = new Application_Service_ApiCredentials();
        $password = $apiCredentialsService->getApiPassword($username);
        if($password != null) {
            return $password;
        }
        return false;
    }
}
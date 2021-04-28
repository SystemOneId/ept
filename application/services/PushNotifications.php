<?php

class Application_Service_PushNotifications {
    public function registerToken($dataManagerId, $platform, $pushNotificationToken){
        $pushNotificationTokenDb = new Application_Model_DbTable_PushNotificationTokens();
        return $pushNotificationTokenDb->upsert($dataManagerId, $platform, $pushNotificationToken);
    }
}


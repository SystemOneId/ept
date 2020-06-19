<?php
class Application_Model_DbTable_TempPushNotification extends Zend_Db_Table_Abstract {
    protected $_name = 'temp_push_notification';
    protected $_primary = 'temp_id';
    
    public function insertTempPushNotificationDetails($to, $sound, $title, $body, $data) {
        $result = $this->insert(array(
            'to' => $to,
            'sound' => $sound,
            'title' => $title,
            'body' => $body,
            'data' => $data
        ));
        return $result;
    }
}


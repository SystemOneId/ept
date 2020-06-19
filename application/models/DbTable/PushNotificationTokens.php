<?php

class Application_Model_DbTable_PushNotificationTokens extends Zend_Db_Table_Abstract {

    protected $_name = 'push_notification_token';
    protected $_primary = array('push_notification_token_id');

    public function upsert($dataManagerId, $platform, $pushNotificationToken) {
        $db = $this->getAdapter();
        $sql = $db->select()
            ->from(array('pnt' => $this->_name))
            ->where('dm_id = '.$dataManagerId)
            ->where('push_notification_token = \''.$pushNotificationToken.'\'');
        $tokens = $db->fetchAll($sql);
        if (count($tokens) > 0) {
            $this->update(array(
                'platform' => $platform,
                'last_seen' => new Zend_Db_Expr('now()'),
                'updated_on' => new Zend_Db_Expr('now()')
            ), "push_notification_token_id = " . $tokens[0]['push_notification_token_id']);
        } else {
            $this->insert(array(
                'dm_id' => $dataManagerId,
                'platform' => $platform,
                'push_notification_token' => $pushNotificationToken,
                'last_seen' => new Zend_Db_Expr('now()'),
                'created_on' => new Zend_Db_Expr('now()')
            ));
        }
    }

    public function getTokensForDataManager($dataManagerId) {
        $db = $this->getAdapter();
        $sql = $db->select()
            ->from(array('pnt' => $this->_name))
            ->where('dm_id = '.$dataManagerId);
        return $db->fetchAll($sql);
    }

    public function getTokensForDevice($pushNotificationToken) {
        $db = $this->getAdapter();
        $sql = $db->select()
            ->from(array('pnt' => $this->_name))
            ->where('push_notification_token = \''.$pushNotificationToken.'\'');
        return $db->fetchAll($sql);
    }
}


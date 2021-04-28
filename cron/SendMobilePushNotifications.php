<?php
include_once 'CronInit.php';

$conf = new Zend_Config_Ini(APPLICATION_PATH . '/configs/application.ini', APPLICATION_ENV);

try {
    $db = Zend_Db::factory($conf->resources->db);
    Zend_Db_Table::setDefaultAdapter($db);

    $limit = '10';
    $sQuery = $db->select()
        ->from(array('tpn' => 'temp_push_notification'))
        ->where("tpn.status = ?",'pending')
        ->limit($limit);
    $pushNotificationResults = $db->fetchAll($sQuery);

    if (count($pushNotificationResults) > 0) {
        foreach ($pushNotificationResults as $pushNotificationResult) {
           $db->update('temp_push_notification', array('status' => 'not-sent'),
                'temp_id = ' . $pushNotificationResult['temp_id']);

            $curl = curl_init();

            $postBody = json_encode(array(array(
                'to' => $pushNotificationResult['to'],
                'sound' => $pushNotificationResult['sound'],
                'title' => $pushNotificationResult['title'],
                'body' => $pushNotificationResult['body'],
                'data' => json_decode($pushNotificationResult['data'])
            )), 0);

            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://exp.host/--/api/v2/push/send',
                CURLOPT_HTTPHEADER => array(
                    'host: exp.host',
                    'content-type: application/json',
                    'accept-encoding: gzip, deflate',
                    'accept: application/json'
                ),
                CURLOPT_POST => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_POSTFIELDS => $postBody,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADER => 1,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ));

            $result = curl_exec($curl);
            $err = curl_errno($curl);
            if ($err > 0) {
                error_log($result, 0);
                error_log($err, 0);
            } else {
                $responseHeaderLength = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
                $responseBody = substr($result, $responseHeaderLength);

                $response = json_decode($responseBody, true);

                if ($response['data'][0]['status'] == "ok") {
                    $db->delete('temp_push_notification', 'temp_id = ' . $pushNotificationResult['temp_id']);
                } else {
                    $db->update('temp_push_notification', array('status' => $response['data'][0]['status']),
                        'temp_id = ' . $pushNotificationResult['temp_id']);
                    error_log($result, 0);
                    error_log($err, 0);

                    $errMsg = curl_error($curl);
                    $header = curl_getinfo($curl);
                    error_log($errMsg, 0);
                    error_log(json_encode($header, true), 0);
                }
            }

            curl_close($curl);
        }
    }
} catch (Exception $e) {
    error_log($e->getMessage(), 0);
    error_log($e->getTraceAsString(), 0);
    error_log('whoops! Something went wrong in cron/SendMobilePushNotifications.php', 0);
}

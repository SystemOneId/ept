<?php

class Application_Model_DbTable_ShipmentParticipantMap extends Zend_Db_Table_Abstract {

    protected $_name = 'shipment_participant_map';
    protected $_primary = 'map_id';

    public function shipItNow($params) {
        $db = $this->getAdapter();
        try {
            $db->beginTransaction();
            $authNameSpace = new Zend_Session_Namespace("administrators");
            $existingEnrollments = $db->fetchAll(
                $db->select()
                    ->from(array("spm" => $this->_name), array("spm.participant_id"))
                    ->where("spm.shipment_id = " . $params["shipmentId"]));
            $existingEnrollmentParticipantIds = array_column($existingEnrollments, 'participant_id');
            foreach ($existingEnrollmentParticipantIds as $existingEnrollmentParticipantId) {
                if (!in_array($existingEnrollmentParticipantId, $params["participants"])) {
                    $db->delete($this->_name,
                        "shipment_id = " . $params["shipmentId"] .
                        " AND participant_id = " . $existingEnrollmentParticipantId);
                }
            }
            foreach ($params['participants'] as $participant) {
                if (!in_array($participant, $existingEnrollmentParticipantIds)) {
                    $data = array('shipment_id' => $params['shipmentId'],
                        'participant_id' => $participant,
                        'evaluation_status' => '19901190',
                        'created_by_admin' => $authNameSpace->admin_id,
                        "created_on_admin" => new Zend_Db_Expr('now()'));
                    $db->insert($this->_name, $data);
                }
            }

            $shipmentDb = new Application_Model_DbTable_Shipments();
            $shipmentRow = $shipmentDb->fetchRow('shipment_id=' . $params['shipmentId']);
            if ($shipmentRow["status"] == "pending") {
                $shipmentDb->updateShipmentStatus($params['shipmentId'], 'ready');
            }

            $shipmentsPending = $shipmentDb->fetchAll($shipmentDb->select()
                ->where("status = 'pending' AND distribution_id = " . $shipmentRow['distribution_id']));

            if (count($shipmentsPending) == 0) {
                $distroService = new Application_Service_Distribution();
                $distribution = $distroService->getDistribution($shipmentRow['distribution_id']);
                if ($distribution["status"] == "created") {
                    $distroService->updateDistributionStatus($shipmentRow['distribution_id'], 'configured');
                }
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            $db->rollBack();
            error_log($e, 0);
            return false;
        }
    }

    public function getByShipmentCodeAndParticipantUniqueId($shipmentCode, $participantUniqueIdentfier) {
        $result = $this->getAdapter()->fetchRow(
            $this->getAdapter()
                ->select()
                ->from(array('spm' => $this->_name))
                ->join(array('p' => 'participant'), 'p.participant_id = spm.participant_id', array())
                ->join(array('s' => 'shipment'), 's.shipment_id = spm.shipment_id', array())
                ->where("s.shipment_code = ?", $shipmentCode)
                ->where("p.unique_identifier = ?", $participantUniqueIdentfier));
        return $result;
    }

    public function updateShipment($params, $shipmentMapId, $lastDate, $submitAction) {
        // Log incorrect late submission
        error_log(
            "Application_Model_DbTable_ShipmentParticipantMap->updateShipment params: ".
            json_encode($params)." lastDate: ".$lastDate." submitAction: ".$submitAction,
            0
        );

        $row = $this->fetchRow("map_id = " . $shipmentMapId);
        if ($row != "") {
            if (trim($row['created_on_user']) == "" || $row['created_on_user'] == NULL) {
                $this->update(array('created_on_user' => new Zend_Db_Expr('now()')), "map_id = " . $shipmentMapId);
            }
        }

        if (isset($submitAction) && $submitAction == 'submit') {
            $params['date_submitted'] = new Zend_Db_Expr('now()');

            $params['evaluation_status'] = $row['evaluation_status'];

            // changing evaluation status 3rd character to 1 = responded
            $params['evaluation_status'][2] = 1;

            // changing evaluation status 5th character to 1 = via web user
            $params['evaluation_status'][4] = 1;

            // changing evaluation status 4th character to 1 = timely response or 2 = delayed response
            $date = new Zend_Date();
            /*
            ACCESS LOG
            52.44.9.255:80 197.255.173.42 - - [06/Oct/2018:15:45:46 +0000] "PUT /api/results/result-footer?sid=23&pid=789&submitResponse=yes HTTP/1.1" 200 363 "-" "okhttp/3.4.1"

            ERROR LOG
            [Sat Oct 06 15:45:46.918171 2018] [:error] [pid 485] [client 197.255.173.42:4754] Application_Model_DbTable_ShipmentParticipantMap->updateShipment params: {"supervisor_approval":"y
es","participant_supervisor":"Thomas S. Ayodeji","user_comment":"only two modules are funcrional for now. ","updated_by_user":"924","updated_on_user":{},"shipment_test_report_date"
:"2018-10-02"} lastDate: 07-Dec-2018 submitAction: submit
            07-Dec-2018 doesn't seem to be parsed correctly
             */
            $lastDate = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($lastDate);
            // only if current date is LATER than last date we make status = 2
            if ($date->compare($lastDate) == 1) {
                $params['evaluation_status'][3] = 2;
            } else {
                $params['evaluation_status'][3] = 1;
            }
        }
        return $this->update($params, "map_id = " . $shipmentMapId);
    }

    public function updateShipmentValues($params, $shipmentMapId) {
        $row = $this->fetchRow("map_id = " . $shipmentMapId);
        if ($row != "") {
            if (trim($row['created_on_user']) == "" || $row['created_on_user'] == NULL) {
                $this->update(array('created_on_user' => new Zend_Db_Expr('now()')), "map_id = " . $shipmentMapId);
            }
        }
        return $this->update($params, "map_id = " . $shipmentMapId);
    }

    public function removeShipmentMapDetails($params, $mapId) {
        $row = $this->fetchRow("map_id = " . $mapId);
        if ($row != "") {
            if (trim($row['created_on_user']) == "" || $row['created_on_user'] == NULL) {
                $this->update(array('created_on_user' => new Zend_Db_Expr('now()')), "map_id = " . $mapId);
            }
        }
        $params['evaluation_status'] = $row['evaluation_status'];
        // changing evaluation status 3rd character to 9 = not responded
        $params['evaluation_status'][2] = 9;

        // changing evaluation status 5th character to 1 = via web user
        $params['evaluation_status'][4] = 1;

        // changing evaluation status 4th character to 0 = no response
        $params['evaluation_status'][3] = 0;

        return $this->update($params, "map_id = " . $mapId);
    }

    public function isShipmentEditable($shipmentId, $participantId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $shipment = $db->fetchRow($db->select()->from(array('s' => 'shipment'))
                        ->where("s.shipment_id = ?", $shipmentId));

        if($shipment["status"] == 'finalized' || $shipment["response_switch"] == 'off'){
            return false;
        }else{
            return true;
        }
    }

    public function addEnrollementDetails($params) {
        try {
            $this->getAdapter()->beginTransaction();
            $authNameSpace = new Zend_Session_Namespace('administrators');
            $size = count($params['participants']);
            for ($i = 0; $i < $size; $i++) {
                $data = array('shipment_id' => base64_decode($params['shipmentId']),
                    'participant_id' => base64_decode($params['participants'][$i]),
                    'evaluation_status' => '19901190',
                    'created_by_admin' => $authNameSpace->admin_id,
                    "created_on_admin" => new Zend_Db_Expr('now()'));
                $this->insert($data);
            }
            $this->getAdapter()->commit();
            $alertMsg = new Zend_Session_Namespace('alertSpace');
            $alertMsg->message = "Participants added successfully";
        } catch (Exception $e) {
            $this->getAdapter()->rollBack();
            die($e->getMessage());
            error_log($e->getTraceAsString());
            return false;
        }
    }

    public function enrollShipmentParticipant($shipmentId, $participantId) {
        $insertCount = 0;
        try {
            $this->getAdapter()->beginTransaction();
            $authNameSpace = new Zend_Session_Namespace('administrators');
            $participantId = explode(',', $participantId);
            $count = count($participantId);
            for ($i = 0; $i < $count; $i++) {
                $data = array('shipment_id' => $shipmentId,
                    'participant_id' => base64_decode($participantId[$i]),
                    'evaluation_status' => '19901190',
                    'created_by_admin' => $authNameSpace->admin_id,
                    "created_on_admin" => new Zend_Db_Expr('now()'));
                   $insertCount = $this->insert($data);
            }
            $this->getAdapter()->commit();
            return $insertCount;
        } catch (Exception $e) {
            $this->getAdapter()->rollBack();
            die($e->getMessage());
            error_log($e->getTraceAsString());
            return 0;
        }
    }

    public function addQcInfo($params){
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if(isset($params['mapId']) && trim($params['mapId'])!=""){
            $participantMapId = explode(',', $params['mapId']);
            $count = count($participantMapId);
            $qcDate=Application_Service_Common::ParseDate($params['qcDate']);
            for ($i = 0; $i < $count; $i++) {
                if(trim($participantMapId[$i])!=""){
                    $data = array(
                        'qc_date' => $qcDate,
                        'qc_done_by' => $authNameSpace->dm_id,
                        "qc_created_on" => new Zend_Db_Expr('now()')
                    );
                    $result=$this->update($data, "map_id = " . $participantMapId[$i]);
                }
            }
            return $result;
        }


    }
}

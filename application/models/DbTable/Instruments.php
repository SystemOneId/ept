<?php

class Application_Model_DbTable_Instruments extends Zend_Db_Table_Abstract {
    protected $_name = 'instrument';
    protected $_primary = 'instrument_id';

    public function getInstruments($pid, $insertBlankRowIfEmpty) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = null;
        if ($pid == null) {
            $authNameSpace = new Zend_Session_Namespace('datamanagers');
            $userSystemId = $authNameSpace->dm_id;
            $sql = $db->select()->from(array('i'=>'instrument'))
                ->join(array('pmm'=>'participant_manager_map'),'i.participant_id=pmm.participant_id', array())
                ->where("pmm.dm_id=".$userSystemId);
        } else {
            $sql = $db->select()->from(array('i'=>'instrument'))
                ->where("i.participant_id=".$pid);
        }
        $res = $db->fetchAll($sql);
        $response = array();
        foreach ($res as $row) {
            $response[$row['instrument_id']] = array(
                'participant_id' => $row['participant_id'],
                'instrument_serial' => $row['instrument_serial'],
                'instrument_installed_on' => $row['instrument_installed_on'],
                'instrument_last_calibrated_on' => $row['instrument_last_calibrated_on']
            );
        }
        if(count($response) == 0 && $insertBlankRowIfEmpty) {
            $response["-1"] = array(
                'participant_id' => $pid,
                'instrument_serial' => "",
                'instrument_installed_on' => "",
                'instrument_last_calibrated_on' => ""
            );
        }
        return $response;
    }

    public function getInstrumentsReferenceMap($pid, $insertBlankRowIfEmpty) {
        $instruments = $this->getInstruments($pid, $insertBlankRowIfEmpty);
        $response = array();
        foreach ($instruments as $instrumentId => $instrumentDetails) {
            $response[$instrumentId] = array(
                'participantId' => $instrumentDetails['participant_id'],
                'instrumentSerial' => $instrumentDetails['instrument_serial'],
                'instrumentInstalledOn' => Pt_Commons_General::dbDateToString($instrumentDetails['instrument_installed_on']),
                'instrumentLastCalibratedOn' => Pt_Commons_General::dbDateToString($instrumentDetails['instrument_last_calibrated_on'])
            );
        }
        return $response;
    }

    public function upsertInstrument($pid, $params) {
        if (!isset($params['instrument_id']) && (!isset($params['instrument_serial']) || $params['instrument_serial'] == "")) {
            return;
        }
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        $dataManagerId = $authNameSpace->dm_id;
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = null;
        if (isset($params['instrument_id'])) {
            $sql = $db->select()->from(array('i'=>'instrument'))
                ->where("i.instrument_id = ?", $params['instrument_id']);
        } else {
            $sql = $db->select()->from(array('i'=>'instrument'))
                ->where("i.participant_id = ?", $pid)
                ->where("i.instrument_serial = ?", $params['instrument_serial']);
        }
        $instruments = $db->fetchAll($sql);
        $noOfRows = 0;
        $data = array(
            'instrument_serial' => $params['instrument_serial'],
            'participant_id' => $pid
        );
        if (isset($params['instrument_installed_on'])) {
            $data['instrument_installed_on'] = $params['instrument_installed_on'];
        }
        if ($params['instrument_last_calibrated_on']) {
            $data['instrument_last_calibrated_on'] = $params['instrument_last_calibrated_on'];
        }
        if (count($instruments) == 0) {
            $data['created_by'] = $dataManagerId;
            $data['created_on'] = new Zend_Db_Expr('now()');
            $db->insert('instrument', $data);
            $noOfRows = 1;
        } else if (isset($data['instrument_installed_on']) || isset($data['instrument_last_calibrated_on'])) {
            $data['updated_by'] = $dataManagerId;
            $data['updated_on'] = new Zend_Db_Expr('now()');
            $noOfRows = $this->update($data, "instrument_id = " . $instruments[0]['instrument_id']);
        }
        $this->updateUnfinalizedResponsesWithNewDates($pid, $params['instrument_serial']);
        return $noOfRows;
    }

    public function deleteInstrument($instrumentId) {
        $noOfRows = 0;
        if (isset($instrumentId) && $instrumentId != null && intval($instrumentId) > 0) {
            $where = $this->getAdapter()->quoteInto("instrument_id = ?", $instrumentId);
            $noOfRows = $this->delete($where);
        }
        return $noOfRows;
    }

    public function updateUnfinalizedResponsesWithNewDates($participantId, $instrumentSerial) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('res'=>'response_result_tb'), array(
            "shipment_map_id" => "res.shipment_map_id",
            "sample_id" => "res.sample_id"
        ))
            ->join(array('spm' => 'shipment_participant_map'), 'res.shipment_map_id = spm.map_id', array())
            ->join(array('s' => 'shipment'), 'spm.shipment_id = s.shipment_id', array())
            ->join(array('i' => 'instrument'), 'res.instrument_serial = i.instrument_serial AND spm.participant_id = i.participant_id', array(
                "instrument_installed_on" => "i.instrument_installed_on",
                "instrument_last_calibrated_on" => "i.instrument_last_calibrated_on"
            ))
            ->where("res.instrument_serial = ?", $instrumentSerial)
            ->where("spm.participant_id = ?", $participantId)
            ->where("s.status <> 'finalized'")
            ->where("substr(spm.evaluation_status, 3, 1) = '9'")
            ->where("res.instrument_installed_on <> i.instrument_installed_on OR res.instrument_last_calibrated_on <> i.instrument_last_calibrated_on");
        $unsubmittedResponses = $db->fetchAll($sql);
        foreach ($unsubmittedResponses as $unsubmittedResponse) {

            $instrumentInstalledOn = Application_Service_Common::ParseDate($unsubmittedResponse['instrument_installed_on']);
            $instrumentLastCalibratedOn = Application_Service_Common::ParseDate($unsubmittedResponse['instrument_last_calibrated_on']);
            $data = array();
            if (isset($instrumentInstalledOn)) {
                $data['instrument_installed_on'] = $instrumentInstalledOn;
            }
            if (isset($instrumentLastCalibratedOn)) {
                $data['instrument_last_calibrated_on'] = $instrumentLastCalibratedOn;
            }
            if (count($data) > 0) {
                $db->update('response_result_tb', $data,
                    "shipment_map_id = " . $unsubmittedResponse['shipment_map_id'] . " AND sample_id = " . $unsubmittedResponse['sample_id']
                );
            }
        }
    }
}


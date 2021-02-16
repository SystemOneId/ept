<?php

class Application_Service_Schemes {
    public function getAllSchemes() {
        $schemeListDb = new Application_Model_DbTable_SchemeList();
        return $schemeListDb->getAllSchemes();
    }

    public function getTbAssayReferenceMap() {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $res = $db->fetchAll($db->select()->from('r_tb_assay'));
        $response = array();
        $userAgent = new Zend_Http_UserAgent();
        foreach ($res as $row) {
            if ($userAgent->getUserAgent() == "okhttp/3.4.1") {
                if ($row['name'] == "Xpert MTB/RIF") {
                    $response[$row['id']] = $row['name'];
                }
            } else {
                $response[$row['id']] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'analyte1Label' => $row['analyte1Label'],
                    'analyte2Label' => $row['analyte2Label'],
                    'analyte3Label' => $row['analyte3Label'],
                    'analyte4Label' => $row['analyte4Label'],
                    'analyte5Label' => $row['analyte5Label'],
                    'analyte6Label' => $row['analyte6Label'],
                    'includeTraceForMtbDetected' => $row['includeTraceForMtbDetected']
                );
            }
        }
        return $response;
    }

    public function getTbReferenceData($shipmentId){
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('reference_result_tb'))
            ->where('shipment_id = ? ', $shipmentId);
        return $db->fetchAll($sql);
    }

    public function getTbSampleIds($sId, $pId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('ref' => 'reference_result_tb'), array('sample_id'))
            ->join(array('sp' => 'shipment_participant_map'), 'ref.shipment_id=sp.shipment_id')
            ->where('sp.shipment_id = ? ', $sId)
            ->where('sp.participant_id = ? ', $pId);
        $res = $db->fetchAll($sql);
        $response = array();
        foreach ($res as $row) {
            array_push($response, $row['sample_id']);
        }
        return $response;
    }

    public function getTbSamples($sId, $pId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()
            ->from(array('sp' => 'shipment_participant_map'))
            ->join(array('s' => 'shipment'), 's.shipment_id=sp.shipment_id')
            ->join(array('ref' => 'reference_result_tb'), 'sp.shipment_id=ref.shipment_id', array(
            'sample_label', 'mandatory', 'sample_id', 'control',
            'ref_is_exempt' => 'ref.is_exempt',
            'ref_is_excluded' => 'ref.is_excluded',
            'ref_mtb_rif_mtb_detected' => 'ref.mtb_rif_mtb_detected',
            'ref_mtb_rif_rif_resistance' => 'ref.mtb_rif_rif_resistance',
            'ref_mtb_rif_probe_d' => 'ref.mtb_rif_probe_d',
            'ref_mtb_rif_probe_c' => 'ref.mtb_rif_probe_c',
            'ref_mtb_rif_probe_e' => 'ref.mtb_rif_probe_e',
            'ref_mtb_rif_probe_b' => 'ref.mtb_rif_probe_b',
            'ref_mtb_rif_probe_spc' => 'ref.mtb_rif_probe_spc',
            'ref_mtb_rif_probe_a' => 'ref.mtb_rif_probe_a',
            'ref_ultra_mtb_detected' => 'ref.ultra_mtb_detected',
            'ref_ultra_rif_resistance' => 'ref.ultra_rif_resistance',
            'ref_ultra_probe_spc' => 'ref.ultra_probe_spc',
            'ref_ultra_probe_is1081_is6110' => 'ref.ultra_probe_is1081_is6110',
            'ref_ultra_probe_rpo_b1' => 'ref.ultra_probe_rpo_b1',
            'ref_ultra_probe_rpo_b2' => 'ref.ultra_probe_rpo_b2',
            'ref_ultra_probe_rpo_b3' => 'ref.ultra_probe_rpo_b3',
            'ref_ultra_probe_rpo_b4' => 'ref.ultra_probe_rpo_b4',
            'ref_sample_score' => 'ref.sample_score',
        ))
            ->joinLeft(array('res' => 'response_result_tb'),
                'res.shipment_map_id = sp.map_id and res.sample_id = ref.sample_id',
                array(
                    'res_date_tested' => 'res.date_tested',
                    'res_mtb_detected' => 'res.mtb_detected',
                    'res_rif_resistance' => 'res.rif_resistance',
                    'res_error_code' => 'res.error_code',
                    'res_instrument_serial' => 'res.instrument_serial',
                    'res_module_name' => 'res.module_name',
                    'res_instrument_user' => 'res.instrument_user',
                    'res_cartridge_expiration_date' => 'res.cartridge_expiration_date',
                    'res_reagent_lot_id' => 'res.reagent_lot_id',
                    'res_probe_1' => 'res.probe_1',
                    'res_probe_2' => 'res.probe_2',
                    'res_probe_3' => 'res.probe_3',
                    'res_probe_4' => 'res.probe_4',
                    'res_probe_5' => 'res.probe_5',
                    'res_probe_6' => 'res.probe_6',
                    'responseDate' => 'res.created_on'
                ))
            ->joinLeft('instrument',
                'instrument.participant_id = sp.participant_id and instrument.instrument_serial = res.instrument_serial', array(
                    'res_instrument_installed_on' =>
                        new Zend_Db_Expr("COALESCE(res.instrument_installed_on, instrument.instrument_installed_on)"),
                    'res_instrument_last_calibrated_on' =>
                        new Zend_Db_Expr("COALESCE(res.instrument_last_calibrated_on, instrument.instrument_last_calibrated_on)")))
            ->where('sp.shipment_id = ? ', $sId)
            ->where('sp.participant_id = ? ', $pId)
            ->order('sample_id ASC');
        return $db->fetchAll($sql);
    }

    public function getTbSample($shipmentId, $participantId, $sampleId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('ref' => 'reference_result_tb'),
            array(
                'sample_label', 'mandatory', 'sample_id', 'control',
                'ref_mtb_rif_mtb_detected' => 'ref.mtb_rif_mtb_detected',
                'ref_mtb_rif_rif_resistance' => 'ref.mtb_rif_rif_resistance',
                'ref_mtb_rif_probe_d' => 'ref.mtb_rif_probe_d',
                'ref_mtb_rif_probe_c' => 'ref.mtb_rif_probe_c',
                'ref_mtb_rif_probe_e' => 'ref.mtb_rif_probe_e',
                'ref_mtb_rif_probe_b' => 'ref.mtb_rif_probe_b',
                'ref_mtb_rif_probe_spc' => 'ref.mtb_rif_probe_spc',
                'ref_mtb_rif_probe_a' => 'ref.mtb_rif_probe_a',
                'ref_ultra_mtb_detected' => 'ref.ultra_mtb_detected',
                'ref_ultra_rif_resistance' => 'ref.ultra_rif_resistance',
                'ref_ultra_probe_spc' => 'ref.ultra_probe_spc',
                'ref_ultra_probe_is1081_is6110' => 'ref.ultra_probe_is1081_is6110',
                'ref_ultra_probe_rpo_b1' => 'ref.ultra_probe_rpo_b1',
                'ref_ultra_probe_rpo_b2' => 'ref.ultra_probe_rpo_b2',
                'ref_ultra_probe_rpo_b3' => 'ref.ultra_probe_rpo_b3',
                'ref_ultra_probe_rpo_b4' => 'ref.ultra_probe_rpo_b4',
                'ref_sample_score' => 'ref.sample_score'
            ))
            ->join(array('s' => 'shipment'), 's.shipment_id=ref.shipment_id')
            ->join(array('sp' => 'shipment_participant_map'), 's.shipment_id=sp.shipment_id')
            ->joinLeft(array('res' => 'response_result_tb'),
                'res.shipment_map_id = sp.map_id and res.sample_id = ref.sample_id',
                array(
                    'res_date_tested' => 'res.date_tested',
                    'res_mtb_detected' => 'res.mtb_detected',
                    'res_rif_resistance' => 'res.rif_resistance',
                    'res_error_code' => 'res.error_code',
                    'res_instrument_serial' => 'res.instrument_serial',
                    'res_module_name' => 'res.module_name',
                    'res_instrument_user' => 'res.instrument_user',
                    'res_cartridge_expiration_date' => 'res.cartridge_expiration_date',
                    'res_reagent_lot_id' => 'res.reagent_lot_id',
                    'res_probe_1' => 'res.probe_1',
                    'res_probe_2' => 'res.probe_2',
                    'res_probe_3' => 'res.probe_3',
                    'res_probe_4' => 'res.probe_4',
                    'res_probe_5' => 'res.probe_5',
                    'res_probe_6' => 'res.probe_6',
                    'responseDate' => 'res.created_on'
                ))
            ->joinLeft('instrument',
                'instrument.participant_id = sp.participant_id and instrument.instrument_serial = res.instrument_serial', array(
                    'res_instrument_installed_on' =>
                        new Zend_Db_Expr("COALESCE(res.instrument_installed_on, instrument.instrument_installed_on)"),
                    'res_instrument_last_calibrated_on' =>
                        new Zend_Db_Expr("COALESCE(res.instrument_last_calibrated_on, instrument.instrument_last_calibrated_on)")))
            ->where('sp.shipment_id = ? ', $shipmentId)
            ->where('sp.participant_id = ? ', $participantId)
            ->where('ref.sample_id = ? ', $sampleId);
        return $db->fetchRow($sql);
    }

    public function getQuartile($inputArray, $quartile) {
        $pos = (count($inputArray) - 1) * $quartile;

        $base = floor($pos);
        $rest = $pos - $base;

        if (isset($inputArray[$base + 1])) {
            return $inputArray[$base] + $rest * ($inputArray[$base + 1] - $inputArray[$base]);
        } else {
            return $inputArray[$base];
        }
    }

    public function getAverage($inputArray) {
        return array_sum($inputArray) / count($inputArray);
    }

    public function getStdDeviation($inputArray) {
        if (count($inputArray) < 2) {
            return;
        }

        $avg = $this->getAverage($inputArray);

        $sum = 0;
        foreach ($inputArray as $value) {
            $sum += pow($value - $avg, 2);
        }

        return sqrt((1 / (count($inputArray) - 1)) * $sum);
    }

    public function getShipmentData($sId, $pId) {
        $db = new Application_Model_DbTable_Shipments();
        return $db->getShipmentData($sId, $pId);
    }

    public function getSchemeControls($schemeId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        return $db->fetchAll($db->select()->from('r_control')->where("for_scheme='$schemeId'"));
    }

    public function getSchemeEvaluationComments($schemeId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        return $db->fetchAll($db->select()->from('r_evaluation_comments')->where("scheme='$schemeId'"));
    }

    public function getPossibleResults($schemeId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        return $db->fetchAll($db->select()->from('r_possibleresult')->where("scheme_id='$schemeId'"));
    }

    public function countEnrollmentSchemes() {
        $schemeListDb = new Application_Model_DbTable_SchemeList();
        return $schemeListDb->countEnrollmentSchemes();
    }

    public function getScheme($sid) {
        if($sid != null){
            $schemeListDb = new Application_Model_DbTable_SchemeList();
            return $schemeListDb->fetchRow($schemeListDb->select()->where("scheme_id = ?", $sid));
        }else{
            return null;
        }
    }

    public function addTestkit($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $db->beginTransaction();
        try {
            $testkitsDb = new Application_Model_DbTable_TestkitnameDts();
            $testkitsDb->addTestkitDetails($params);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            error_log($e->getMessage());
        }
    }

    public function updateTestkit($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $db->beginTransaction();
        try {
            $testkitsDb = new Application_Model_DbTable_TestkitnameDts();
            $testkitsDb->updateTestkitDetails($params);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            error_log($e->getMessage());
        }
    }

    public function updateTestkitStage($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $db->beginTransaction();
        try {
            $testkitsDb = new Application_Model_DbTable_TestkitnameDts();
            $testkitsDb->updateTestkitStageDetails($params);
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            error_log($e->getMessage());
        }
    }

    public function getNotTestedReasons($schemeType){
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()
            ->from(array('response_not_tested_reason'))
            ->where('status = ? ', 'active')
            ->where('scheme_type = ?', $schemeType);
        return $db->fetchAll($sql);
    }

    public function getNotTestedReasonsReferenceMap($schemeType) {
        $reasons = $this->getNotTestedReasons($schemeType);
        $response = array();
        foreach ($reasons as $reason) {
            $response[$reason['not_tested_reason_id']] = $reason['not_tested_reason'];
        }
        $response["other"] = "Other";
        return $response;
    }
}

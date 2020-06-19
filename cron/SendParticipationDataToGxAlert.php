<?php

include_once 'CronInit.php';

$conf = new Zend_Config_Ini(APPLICATION_PATH . '/configs/application.ini', APPLICATION_ENV);

try {

    $db = Zend_Db::factory($conf->resources->db);
    Zend_Db_Table::setDefaultAdapter($db);

    $countryQuery = $db->select()
        ->from(array('c' => 'countries'), array('c.id', 'c.gxalert_url', 'c.gxalert_api_credentials'))
        ->where("c.gxalert_url IS NOT NULL")
        ->where("c.gxalert_api_credentials IS NOT NULL");
    $countriesToUpdate = $db->fetchAll($countryQuery);
    if (count($countriesToUpdate) > 0) {
        foreach ($countriesToUpdate as $gxAlertCountry) {
            $participantsQuery = $db->select()
                ->from(array('p' => 'participant'), array('p.unique_identifier'))
                ->join(array('spm' => 'shipment_participant_map'), 'spm.participant_id = p.participant_id',
                    array('spm.map_id', 'spm.shipment_id', 'spm.shipment_score', 'spm.documentation_score', 'spm.documentation_score',
                        'submitted' => new Zend_Db_Expr("CASE WHEN substr(spm.evaluation_status, 3, 1) = '1' THEN CAST(1 AS BIT) ELSE CAST(0 AS BIT) END")))
                ->join(array('s' => 'shipment'), 's.shipment_id = spm.shipment_id', array('s.shipment_id', 's.max_score'))
                ->join(array('r' => 'r_results'), 'r.result_id = spm.final_result', array('r.result_name'))
                ->where("p.country=?", $gxAlertCountry["id"])
                ->where("c.gxalert_api_credentials IS NOT NULL");
            $submissionsToSend = $db->fetchAll($participantsQuery);

            if (count($submissionsToSend) > 0) {
                $resultSet = array(
                    "Participants" => array()
                );

                foreach ($submissionsToSend as $submission) {
                    $participant = array(
                        "ePTParticipantId" => $submission["unique_identifier"],
                        "ePTShipmentId" => $submission["shipment_id"],
                        "Submitted" => $submission["submitted"],
                        "Score" => $submission["shipment_score"] + $submission["documentation_score"],
                        "TotalScore" => $submission["max_score"],
                        "SubmissionStatus" => $submission["result_name"],
                        "Samples" => array()
                    );

                    $samplesQuery = $db->select()
                        ->from(array('res' => 'response_result_tb'), array('res.sample_id', 'res.instrument_serial', 'res.reagent_lot_id',
                            'res.cartridge_expiration_date', 'res.module_name', 'res.instrument_user', 'res.error_code', 'res.date_tested',
                            'res.mtb_detected', 'res.rif_resistance', 'res.probe_1', 'res.probe_2', 'res.probe_3', 'res.probe_4', 'res.probe_5',
                            'res.probe_6', 'res.calculated_score'))
                        ->join(array('spm' => 'shipment_participant_map'),
                            'spm.map_id = res.shipment_map_id', array())
                        ->joinLeft('instrument',
                            'instrument.participant_id = spm.participant_id and instrument.instrument_serial = res.instrument_serial', array(
                                'instrument_installed_on' =>
                                    new Zend_Db_Expr("COALESCE(res.instrument_installed_on, instrument.instrument_installed_on)"),
                                'instrument_last_calibrated_on' =>
                                    new Zend_Db_Expr("COALESCE(res.instrument_last_calibrated_on, instrument.instrument_last_calibrated_on)")))
                        ->where("res.shipment_map_id = ?", $submission["map_id"]);
                    $samplesToSend = $db->fetchAll($samplesQuery);
                    foreach ($samplesToSend as $sample) {
                        $participant["Samples"][] = array(
                            "SampleId" => $sample["sample_id"],
                            "InstrumentSerial" => $sample["instrument_serial"],
                            "InstrumentInstalledOn" => $sample["instrument_installed_on"],
                            "InstrumentLastCalibratedOn" => $sample["instrument_last_calibrated_on"],
                            "ReagentLotId" => $sample["reagent_lot_id"],
                            "CartridgeExpirationDate" => $sample["cartridge_expiration_date"],
                            "ModuleName" => $sample["module_name"],
                            "InstrumentUser" => $sample["instrument_user"],
                            "ErrorCode" => $sample["error_code"],
                            "DateTested" => $sample["date_tested"],
                            "MtbDetected" => $sample["mtb_detected"],
                            "RifResistance" => $sample["rif_resistance"],
                            "Probe1" => $sample["probe_1"],
                            "Probe2" => $sample["probe_2"],
                            "Probe3" => $sample["probe_3"],
                            "Probe4" => $sample["probe_4"],
                            "Probe5" => $sample["probe_5"],
                            "Probe6" => $sample["probe_6"],
                            "SampleStatus" => $sample["calculated_score"]
                        );
                    }
                    $resultSet["Participants"][] = $participant;
                }

                // Post to GxAlert
                $curl = curl_init();

                $postBody = json_encode($resultSet, 0);

                curl_setopt_array($curl, array(
                    CURLOPT_URL => $gxAlertCountry['gxalert_url'] . '/api/v1/ept/surveyresults',
                    CURLOPT_HTTPHEADER => array(
                        'content-type: application/json',
                        'accept-encoding: gzip, deflate',
                        'accept: application/json',
                        'Authorization: Basic ' . $gxAlertCountry['gxalert_api_credentials']
                    ),
                    CURLOPT_POST => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_POSTFIELDS => $postBody,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 60,
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

                    if ($response['data'][0]['status'] != "ok") {
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
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());
    error_log('whoops! Something went wrong in cron/SendParticipationDataToGxAlert.php');
}

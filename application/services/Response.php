<?php

class Application_Service_Response {
    public function echoAllDistributions($parameters) {
        $aColumns = array("DATE_FORMAT(distribution_date,'%d-%b-%Y')", 'distribution_code', 's.shipment_code', 'd.status');
        $orderColumns = array('distribution_date', 'distribution_code', 's.shipment_code', 'd.status');

        /*
         * Paging
         */
        $sLimit = "";
        if (isset($parameters['iDisplayStart']) && $parameters['iDisplayLength'] != '-1') {
            $sOffset = $parameters['iDisplayStart'];
            $sLimit = $parameters['iDisplayLength'];
        }

        /*
         * Ordering
         */
        $sOrder = "";
        if (isset($parameters['iSortCol_0'])) {
            $sOrder = "";
            for ($i = 0; $i < intval($parameters['iSortingCols']); $i++) {
                if ($parameters['bSortable_' . intval($parameters['iSortCol_' . $i])] == "true") {
                    $sOrder .= $orderColumns[intval($parameters['iSortCol_' . $i])] . "
				 	" . ($parameters['sSortDir_' . $i]) . ", ";
                }
            }

            $sOrder = substr_replace($sOrder, "", -2);
        }

        /*
         * Filtering
         * NOTE this does not match the built-in DataTables filtering which does it
         * word by word on any field. It's possible to do here, but concerned about efficiency
         * on very large tables, and MySQL's regex functionality is very limited
         */
        $sWhere = "";
        if (isset($parameters['sSearch']) && $parameters['sSearch'] != "") {
            $searchArray = explode(" ", $parameters['sSearch']);
            $sWhereSub = "";
            foreach ($searchArray as $search) {
                if ($sWhereSub == "") {
                    $sWhereSub .= "(";
                } else {
                    $sWhereSub .= " AND (";
                }
                $colSize = count($aColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($aColumns[$i] == "" || $aColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $aColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $aColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($aColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $aColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $aColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }

        /*
         * SQL queries
         * Get data to display
         */
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('d' => 'distributions'))
                ->joinLeft(array('s' => 'shipment'), 's.distribution_id=d.distribution_id', array('shipments' => new Zend_Db_Expr("GROUP_CONCAT(DISTINCT s.shipment_code SEPARATOR ', ')"),'not_finalized_count' => new Zend_Db_Expr("SUM(IF(s.status!='finalized',1,0))")))
                ->where("d.status='shipped'")
                ->group('d.distribution_id');

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }
        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }
        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }
		$sQuery = $dbAdapter->select()->from(array('temp' => $sQuery))->where("not_finalized_count > 0");
        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $aResultTotal = $dbAdapter->fetchAll($sQuery);
        $iTotal = count($aResultTotal);

        /*
         * Output
         */
        $output = array(
            "sEcho" => isset($parameters['sEcho']) ? intval($parameters['sEcho']) : 0,
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        $shipmentDb = new Application_Model_DbTable_Shipments();

        foreach ($rResult as $aRow) {
            $shipmentResults = $shipmentDb->getPendingShipmentsByDistribution($aRow['distribution_id']);
            $row = array();
            $row['DT_RowId'] = "dist" . $aRow['distribution_id'];
            $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['distribution_date']);
            $row[] = $aRow['distribution_code'];
            $row[] = $aRow['shipments'];
            $row[] = ucwords($aRow['status']);
            $row[] = '<a class="btn btn-primary btn-xs" href="javascript:void(0);" onclick="getShipments(\'' . ($aRow['distribution_id']) . '\')"><span><i class="icon-search"></i> View</span></a>';
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getShipments($distributionId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment'))
                ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id')
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array(
                    'map_id',
                    'responseDate' => 'shipment_test_report_date',
                    'participant_count' => new Zend_Db_Expr('count("participant_id")'),
                    'reported_count' => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
                    'number_passed' => new Zend_Db_Expr("SUM(final_result = 1)"),
                    'last_not_participated_mailed_on',
                    'last_not_participated_mail_count',
                    'shipment_status' => 's.status'))
                ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
                ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type')
                ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id')
                ->where("s.distribution_id = ?", $distributionId);
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if(isset($authNameSpace) && $authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->where("p.country IN (".implode(",",$authNameSpace->countries).")");
        }
        $sql = $sql->group('s.shipment_id')
                   ->order(new Zend_Db_Expr("CASE WHEN p.unique_identifier REGEXP '\d*' THEN CAST(CAST(p.unique_identifier AS DECIMAL) AS CHAR) ELSE TRIM(LEADING '0' FROM p.unique_identifier) END"));
        return $db->fetchAll($sql);
    }

    public function getResponseCount($shipmentId,$distributionId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment'),array(''))
                ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id',array(''))
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array(
                    'reported_count' => new Zend_Db_Expr("COUNT(CASE substr(sp.evaluation_status,4,1) WHEN '1' THEN 1 WHEN '2' THEN 1 END)")))
                ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
                ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type',array(''))
                ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id',array(''))
		        ->where("s.shipment_id = ?", $shipmentId)
                ->where("s.distribution_id = ?", $distributionId);
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if(isset($authNameSpace) && $authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->where("p.country IN (".implode(",",$authNameSpace->countries).")");
        }
        $sql = $sql->group('s.shipment_id');
        return $db->fetchRow($sql);
    }

    public function getShipmentToEdit($shipmentId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment'), array('s.shipment_id', 's.shipment_code', 's.scheme_type', 's.shipment_date', 's.lastdate_response', 's.distribution_id', 's.number_of_samples', 's.max_score', 's.shipment_comment', 's.created_by_admin', 's.created_on_admin', 's.updated_by_admin', 's.updated_on_admin', 'shipment_status' => 's.status'))
                ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id')
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id')
                ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type')
                ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id', array(
                    'sorting_unique_identifier' => new Zend_Db_Expr("LPAD(p.unique_identifier, 10, '0')"),
                    'p.*'
                ))
                ->where("s.shipment_id = ?", $shipmentId);
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if(isset($authNameSpace) && $authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->where("p.country IN (".implode(",",$authNameSpace->countries).")");
        }
        $sql = $sql->order(new Zend_Db_Expr("sorting_unique_identifier"));
        $shipmentResult = $db->fetchAll($sql);
        return $shipmentResult;
    }

    public function editResponse($shipmentId, $participantId, $scheme) {
        $participantService = new Application_Service_Participants();
        $schemeService = new Application_Service_Schemes();
        $participantData = $participantService->getParticipantDetails($participantId);
        $shipmentData = $schemeService->getShipmentData($shipmentId, $participantId);
        $possibleResults = $schemeService->getPossibleResults($scheme);
        if ($scheme == 'tb') {
            $results = $schemeService->getTbSamples($shipmentId, $participantId);
        }

        return array(
            'participant' => $participantData,
            'shipment' => $shipmentData,
            'possibleResults' => $possibleResults,
            'results' => $results
        );
    }

    public function updateShipmentResults($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $admin = $authNameSpace->primary_email;
        $size = count($params['sampleId']);

        if ($params['scheme'] == 'tb') {
            $attributes = array(
                "cartridge_lot_no" => isset($params['cartridgeLotNo']) ? $params['cartridgeLotNo'] : $params['mtbRifKitLotNo'],
                "expiry_date" =>  Application_Service_Common::ParseDate($params['expiryDate']),
                "assay" => $params['assay'],
                "count_tests_conducted_over_month" => $params['countTestsConductedOverMonth'],
                "count_errors_encountered_over_month" => $params['countErrorsEncounteredOverMonth'],
                "error_codes_encountered_over_month" => $params['errorCodesEncounteredOverMonth']
            );
            if (isset($params['transferToParticipant']) && $params['transferToParticipant'] != "") {
                $attributes["transferToParticipantId"] = $params['transferToParticipant'];
            }
            $mapData = array(
                "shipment_receipt_date" => Application_Service_Common::ParseDate($params['receiptDate']),
                "attributes" => json_encode($attributes),
                "supervisor_approval" => $params['supervisorApproval'],
                "participant_supervisor" => $params['participantSupervisor'],
                "user_comment" => $params['userComments'],
                "updated_by_admin" => $admin,
                "updated_on_admin" => new Zend_Db_Expr('now()')
            );
            if (isset($params['testDate'])) {
                $mapData['shipment_test_date'] = Application_Service_Common::ParseDate($params['testDate']);
            }
            if (isset($params['modeOfReceipt'])) {
                $mapData['mode_id'] = $params['modeOfReceipt'];
            }
            if ($params['ableToEnterResults'] == "no") {
                $mapData['is_pt_test_not_performed'] = "yes";
                if ($params["notTestedReason"] == "other") {
                    $mapData['not_tested_reason'] = null;
                    $mapData['pt_test_not_performed_comments'] = $params["notTestedOtherReason"];
                } else if (isset($params["notTestedReason"]) && trim($params["notTestedReason"]) != "") {
                    $mapData['not_tested_reason'] = $params["notTestedReason"];
                    $mapData['pt_test_not_performed_comments'] = null;
                }

                if (isset($params['submitAction']) && $params['submitAction'] == 'submit') {
                    $transferToParticipantId = "";
                    if (isset($attributes)) {
                        if (isset($attributes['transferToParticipantId'])) {
                            $transferToParticipantId = $attributes['transferToParticipantId'];
                        }
                    }
                    if ($transferToParticipantId != "") {
                        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
                        $sql = $db->select()
                            ->from(array('spm' => 'shipment_participant_map'))
                            ->where("spm.shipment_id = ?", $params["shipmentId"])
                            ->where("spm.participant_id = ?", $transferToParticipantId);
                        $enrolledParticipant = $db->fetchRow($sql);
                        if (!isset($enrolledParticipant) || !$enrolledParticipant) {
                            $enrollmentData = array(
                                'shipment_id' => $params['shipmentId'],
                                'participant_id' => $transferToParticipantId,
                                'evaluation_status' => '19901190',
                                'created_by_admin' => $authNameSpace->admin_id,
                                "created_on_admin" => new Zend_Db_Expr('now()'));
                            $db->insert('shipment_participant_map', $enrollmentData);

                            $emailParticipantDetailsQuery = $db->select()->from(array('sp' => 'shipment_participant_map'),
                                array(
                                    'sp.participant_id',
                                    'sp.shipment_id',
                                    'sp.map_id',
                                    'sp.new_shipment_mail_count'
                                ))
                                ->join(array('s' => 'shipment'), 's.shipment_id=sp.shipment_id', array('s.shipment_code', 's.shipment_code'))
                                ->join(array('d' => 'distributions'), 'd.distribution_id = s.distribution_id',
                                    array('distribution_code', 'distribution_date'))
                                ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id',
                                    array(
                                        'p.email',
                                        'participantName' => new Zend_Db_Expr(
                                            "GROUP_CONCAT(DISTINCT p.lab_name ORDER BY p.lab_name SEPARATOR ', ')"
                                        )
                                    ))
                                ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type', array('SCHEME' => 'sl.scheme_name'))
                                ->joinLeft(array('pmm' => 'participant_manager_map'), 'pmm.participant_id = sp.participant_id', array())
                                ->joinLeft(array('pnt' => 'push_notification_token'), 'pnt.dm_id = pmm.dm_id',
                                    array('push_notification_token'))
                                ->where("sp.shipment_id = ?", $params['shipmentId'])
                                ->where("sp.participant_id = ?", $transferToParticipantId)
                                ->group("p.participant_id");
                            $participantEmailDetailsList = $db->fetchAll($emailParticipantDetailsQuery);

                            if (isset($participantEmailDetailsList) && count($participantEmailDetailsList) > 0) {
                                foreach ($participantEmailDetailsList as $participantEmailDetails) {
                                    if (isset($participantEmailDetails['email']) && $participantEmailDetails['email'] != '') {
                                        $commonServices = new Application_Service_Common();
                                        $general = new Pt_Commons_General();
                                        $newShipmentMailContent = $commonServices->getEmailTemplate('new_shipment');

                                        $surveyDate = $general->humanDateFormat($participantEmailDetails['distribution_date']);
                                        $search = array('##NAME##', '##SHIPCODE##', '##SHIPTYPE##', '##SURVEYCODE##', '##SURVEYDATE##',);
                                        $replace = array(
                                            $participantEmailDetails['participantName'],
                                            $participantEmailDetails['shipment_code'],
                                            $participantEmailDetails['SCHEME'],
                                            $participantEmailDetails['distribution_code'],
                                            $surveyDate
                                        );
                                        $content = $newShipmentMailContent['mail_content'];
                                        $message = str_replace($search, $replace, $content);
                                        $subject = $newShipmentMailContent['mail_subject'];
                                        $fromEmail = $newShipmentMailContent['mail_from'];
                                        $fromFullName = $newShipmentMailContent['from_name'];
                                        $toEmail = $participantEmailDetails['email'];
                                        $cc = $newShipmentMailContent['mail_cc'];
                                        $bcc = $newShipmentMailContent['mail_bcc'];
                                        $commonServices->insertTempMail($toEmail, $cc, $bcc, $subject, $message, $fromEmail, $fromFullName);
                                        $count = $participantEmailDetails['new_shipment_mail_count'] + 1;
                                        $db->update('shipment_participant_map', array(
                                            'last_new_shipment_mailed_on' => new Zend_Db_Expr('now()'),
                                            'new_shipment_mail_count' => $count
                                        ), 'map_id = ' . $participantEmailDetails['map_id']);
                                    }
                                    if (isset($participantEmailDetails['push_notification_token']) && $participantEmailDetails['push_notification_token'] != '') {
                                        $tempPushNotificationsDb = new Application_Model_DbTable_TempPushNotification();
                                        $tempPushNotificationsDb->insertTempPushNotificationDetails(
                                            $participantEmailDetails['push_notification_token'],
                                            'default', 'ePT ' . $participantEmailDetails['shipment_code'] . ' Transferred',
                                            'ePT panel ' . $participantEmailDetails['shipment_code'] . ' has been transferred to ' . $participantEmailDetails['participantName'] . '. Did you receive it?',
                                            '{"title": "ePT ' . $participantEmailDetails['shipment_code'] . ' Transferred", "body": "ePT panel ' . $participantEmailDetails['shipment_code'] . ' has been transferred to ' . $participantEmailDetails['participantName'] . '. Did you receive it?", "dismissText": "Close", "actionText": "Confirm", "shipmentId": ' . $participantEmailDetails['shipment_id'] . ', "participantId": ' . $participantEmailDetails['participant_id'] . ', "action": "receive_shipment"}');
                                    }
                                }
                            }

                        }
                    }
                }
            } else {
                $mapData['is_pt_test_not_performed'] = "no";
                $mapData['not_tested_reason'] = null;
                $mapData['pt_test_not_performed_comments'] = null;
            }
            if (isset($params['testReceiptDate']) && trim($params['testReceiptDate'])!= '') {
                $mapData['shipment_test_report_date'] = Application_Service_Common::ParseDate($params['testReceiptDate']);
            } else {
                $mapData['shipment_test_report_date'] = new Zend_Db_Expr('now()');
            }

            $mapData['qc_done'] = isset($params['qcDone']) ? $params['qcDone'] : 'no';
            if (isset($mapData['qc_done']) && trim($mapData['qc_done']) == "yes") {
                $mapData['qc_date'] =  Application_Service_Common::ParseDate($params['qcDate']);
                $mapData['qc_done_by'] = trim($params['qcDoneBy']);
                $mapData['qc_created_on'] = new Zend_Db_Expr('now()');
            } else {
                $mapData['qc_date'] = null;
                $mapData['qc_done_by'] = null;
                $mapData['qc_created_on'] = null;
            }

            if (isset($params['customField1']) && trim($params['customField1']) != "") {
                $mapData['custom_field_1'] = $params['customField1'];
            }

            if (isset($params['customField2']) && trim($params['customField2']) != "") {
                $mapData['custom_field_2'] = $params['customField2'];
            }

            $shipmentParticipantDb = new Application_Model_DbTable_ShipmentParticipantMap();
            $shipmentParticipantDb->updateShipment($mapData, $params['smid'], $params['hdLastDate'], $params['submitAction']);

            $instrumentsDb = new Application_Model_DbTable_Instruments();
            $headerInstrumentSerials = $params['headerInstrumentSerial'];
            $instrumentDetails = array();
            foreach ($headerInstrumentSerials as $key => $headerInstrumentSerial) {
                if (isset($headerInstrumentSerial) &&
                    $headerInstrumentSerial != "") {
                    $headerInstrumentDetails = array(
                        'instrument_serial' => $headerInstrumentSerial,
                        'instrument_installed_on' => Application_Service_Common::ParseDate($params['headerInstrumentInstalledOn'][$key]),
                        'instrument_last_calibrated_on' => Application_Service_Common::ParseDate($params['headerInstrumentLastCalibratedOn'][$key])
                    );
                    $instrumentsDb->upsertInstrument($params['participantId'], $headerInstrumentDetails);
                    $instrumentDetails[$headerInstrumentSerial] = array(
                        'instrument_installed_on' => Application_Service_Common::ParseDate($params['headerInstrumentInstalledOn'][$key]),
                        'instrument_last_calibrated_on' => Application_Service_Common::ParseDate($params['headerInstrumentLastCalibratedOn'][$key])
                    );
                }
            }

            $shipmentMap = $db->fetchRow($db->select()->from(array('spm' => 'shipment_participant_map'))
                ->where("spm.map_id = ?", $params['smid']));
            $evaluationStatus = $shipmentMap['evaluation_status'];
            $submitted = $evaluationStatus[2] == '1' ||
                (isset($params['submitAction']) && $params['submitAction'] == 'submit');

            if ($params['ableToEnterResults'] == "no" && $submitted) {
                $db->delete('response_result_tb', "shipment_map_id = " . $params['smid']);
            } else {
                for ($i = 0; $i < $size; $i++) {
                    $sql = $db->select()
                        ->from("response_result_tb")
                        ->where("shipment_map_id = " . $params['smid'] . " and sample_id = " . $params['sampleId'][$i]);
                    $res = $db->fetchRow($sql);

                    $mtbDetected = $params['mtbDetected'][$i];
                    $rifResistance = isset($params['rifResistance'][$i]) ? $params['rifResistance'][$i] : null;
                    $errorCode = isset($params['errorCode'][$i]) ? $params['errorCode'][$i] : null;
                    if ($mtbDetected != "error") {
                        $errorCode = null;
                        if(!in_array($mtbDetected, array("detected", "high", "medium", "low", "veryLow")) && ($rifResistance == null || $rifResistance == "")) {
                            if($mtbDetected=="trace") {
                                $rifResistance = "indeterminate";
                            }else{
                                $rifResistance = "na";
                            }
                        }
                    } else {
                        $rifResistance = "na";
                    }
                    $params['rifResistance'][$i] = $rifResistance;
                    $params['errorCode'][$i] = $errorCode;

                    $dateTested = Application_Service_Common::ParseDate($params['dateTested'][$i]);
                    $instrumentInstalledOn = null;
                    $instrumentLastCalibratedOn = null;
                    if (isset($params['instrumentSerial'][$i]) &&
                        isset($instrumentDetails[$params['instrumentSerial'][$i]])) {
                        if (isset($instrumentDetails[$params['instrumentSerial'][$i]]['instrument_installed_on'])) {
                            $instrumentInstalledOn = $instrumentDetails[$params['instrumentSerial'][$i]]['instrument_installed_on'];
                        }
                        if (isset($instrumentDetails[$params['instrumentSerial'][$i]]['instrument_last_calibrated_on'])) {
                            $instrumentLastCalibratedOn = $instrumentDetails[$params['instrumentSerial'][$i]]['instrument_last_calibrated_on'];
                        }
                    }
                    $cartridgeExpirationDate = Application_Service_Common::ParseDate($params['expiryDate']);
                    if ($res == null || count($res) == 0) {
                        $db->insert('response_result_tb', array(
                            'shipment_map_id' => $params['smid'],
                            'sample_id' => $params['sampleId'][$i],
                            'date_tested' => $dateTested,
                            'mtb_detected' => $params['mtbDetected'][$i],
                            'error_code' => $params['errorCode'][$i],
                            'rif_resistance' => $params['rifResistance'][$i],
                            'probe_1' => $params['probe1'][$i],
                            'probe_2' => $params['probe2'][$i],
                            'probe_3' => $params['probe3'][$i],
                            'probe_4' => $params['probe4'][$i],
                            'probe_5' => $params['probe5'][$i],
                            'probe_6' => $params['probe6'][$i],
                            'instrument_serial' => $params['instrumentSerial'][$i],
                            'instrument_installed_on' => $instrumentInstalledOn,
                            'instrument_last_calibrated_on' => $instrumentLastCalibratedOn,
                            'module_name' => $params['moduleName'][$i],
                            'instrument_user' => $params['instrumentUser'][$i],
                            'cartridge_expiration_date' => $cartridgeExpirationDate,
                            'reagent_lot_id' => isset($params['cartridgeLotNo']) ? $params['cartridgeLotNo'] : $params['mtbRifKitLotNo'],
                            'created_by' => $admin,
                            'created_on' => new Zend_Db_Expr('now()')
                        ));
                    } else {
                        $db->update('response_result_tb', array(
                            'date_tested' => $dateTested,
                            'mtb_detected' => $params['mtbDetected'][$i],
                            'error_code' => $params['errorCode'][$i],
                            'rif_resistance' => $params['rifResistance'][$i],
                            'probe_1' => $params['probe1'][$i],
                            'probe_2' => $params['probe2'][$i],
                            'probe_3' => $params['probe3'][$i],
                            'probe_4' => $params['probe4'][$i],
                            'probe_5' => $params['probe5'][$i],
                            'probe_6' => $params['probe6'][$i],
                            'instrument_serial' => $params['instrumentSerial'][$i],
                            'instrument_installed_on' => $instrumentInstalledOn,
                            'instrument_last_calibrated_on' => $instrumentLastCalibratedOn,
                            'module_name' => $params['moduleName'][$i],
                            'instrument_user' => $params['instrumentUser'][$i],
                            'cartridge_expiration_date' => $cartridgeExpirationDate,
                            'reagent_lot_id' => isset($params['cartridgeLotNo']) ? $params['cartridgeLotNo'] : $params['mtbRifKitLotNo'],
                            'updated_by' => $admin,
                            'updated_on' => new Zend_Db_Expr('now()')
                        ), "shipment_map_id = " . $params['smid'] . " and sample_id = " . $params['sampleId'][$i]);
                    }
                }
            }
            return true;
        }
        return false;
    }

    public function getOtherUnenrolledParticipants($shipmentId, $currentParticipantId, $transferredToParticipantId) {
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()
            ->from(array('p' => 'participant'), array(
                'participant_id' => 'p.participant_id',
                'participant_name' => new Zend_Db_Expr("CONCAT(p.unique_identifier, ': ', p.lab_name, COALESCE(CONCAT(' - ', CASE WHEN p.state = '' THEN NULL ELSE p.state END), CONCAT(' - ', CASE WHEN p.city = '' THEN NULL ELSE p.city END), ''))")
            ))
            ->joinLeft(array('spm' => 'shipment_participant_map'), 'spm.participant_id = p.participant_id AND spm.shipment_id = '.$shipmentId, array())
            ->where("p.participant_id <> ?", $currentParticipantId);
        if (isset($transferredToParticipantId) && $transferredToParticipantId != "") {
            $sql = $sql->where('spm.map_id IS NULL OR spm.participant_id = ?', $transferredToParticipantId);
        } else {
            $sql = $sql->where('spm.map_id IS NULL');
        }
        if (isset($authNameSpace) && $authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->where("p.country IN (".implode(",",$authNameSpace->countries).")");
        }
        $otherUnenrolledParticipants = $db->fetchAll($sql);
        return $otherUnenrolledParticipants;
    }
}

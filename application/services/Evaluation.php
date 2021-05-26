<?php

class Application_Service_Evaluation {
    public function echoAllDistributions($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

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
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if($authNameSpace->is_ptcc_coordinator) {
            if ($sWhere == "") {
                $sWhere .= "(";
            } else {
                $sWhere .= " AND (";
            }
            $sWhere .= "p.country IS NULL OR p.country IN (".implode(",",$authNameSpace->countries)."))";
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
                ->joinLeft(array('s' => 'shipment'), 's.distribution_id=d.distribution_id',
                    array(
                        'shipments' => new Zend_Db_Expr("GROUP_CONCAT(DISTINCT s.shipment_code SEPARATOR ', ')"),
                        'not_finalized_count' => new Zend_Db_Expr("SUM(IF(s.status!='finalized',1,0))")
                    )
                )
                ->joinLeft(array('spm'=>'shipment_participant_map'),'s.shipment_id=spm.shipment_id',array())
                ->joinLeft(array('p'=>'participant'),'spm.participant_id=p.participant_id',array())
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
                'reported_count' => new Zend_Db_Expr("COUNT(CASE substr(sp.evaluation_status,4,1) WHEN '1' THEN 1 WHEN '2' THEN 1 END)"),
                'number_passed' => new Zend_Db_Expr("SUM(final_result = 1)"),
                'last_not_participated_mailed_on',
                'last_not_participated_mail_count',
                'shipment_status' => 's.status'))
            ->joinLeft(array('p'=>'participant'),'sp.participant_id=p.participant_id',array())
            ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type')
            ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id')
            ->where("s.distribution_id = ?", $distributionId)
            ->where("IFNULL(sp.is_pt_test_not_performed, 'no') = 'no'");
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if($authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->where("p.country IS NULL OR p.country IN (".implode(",",$authNameSpace->countries).")");
        }
        $sql = $sql->group('s.shipment_id');
        return $db->fetchAll($sql);
    }

    public function getResponseCount($shipmentId,$distributionId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment'),array(''))
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id',array(''))
            ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array(
                'reported_count' => new Zend_Db_Expr("COUNT(CASE substr(sp.evaluation_status,4,1) WHEN '1' THEN 1 WHEN '2' THEN 1 END)")
            ))
            ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type',array(''))
            ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id',array(''))
            ->where("s.shipment_id = ?", $shipmentId)
            ->where("s.distribution_id = ?", $distributionId)
            ->group('s.shipment_id');
        return $db->fetchRow($sql);
    }

    public function getShipmentToEvaluate($shipmentId, $reEvaluate = false) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment'), array('s.shipment_id', 's.shipment_code', 's.scheme_type', 's.shipment_date', 's.lastdate_response', 's.distribution_id', 's.number_of_samples', 's.max_score', 's.shipment_comment', 's.created_by_admin', 's.created_on_admin', 's.updated_by_admin', 's.updated_on_admin', 'shipment_status' => 's.status'))
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id')
            ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id')
            ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type')
            ->join(array('p' => 'participant'), 'p.participant_id = sp.participant_id', array(
                'sorting_unique_identifier' => new Zend_Db_Expr("LPAD(p.unique_identifier, 10, '0')"),
                'p.*'
            ))
            ->where("s.shipment_id = ?", $shipmentId)
            ->where("substring(sp.evaluation_status,4,1) != '0'")
            ->order('sorting_unique_identifier');
        $shipmentResult = $db->fetchAll($sql);

        return $this->evaluateTb($shipmentResult,$shipmentId);
    }

    public function editEvaluation($shipmentId, $participantId, $scheme) {
        $participantService = new Application_Service_Participants();
        $schemeService = new Application_Service_Schemes();
        $participantData = $participantService->getParticipantDetails($participantId);
        $shipmentData = $schemeService->getShipmentData($shipmentId, $participantId);
        $possibleResults = $schemeService->getPossibleResults($scheme);
        $evalComments = $schemeService->getSchemeEvaluationComments($scheme);
        $results = $schemeService->getTbSamples($shipmentId, $participantId);
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()
            ->from(array('s' => 'shipment'))
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id')
            ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id',
                array('fullscore' => new Zend_Db_Expr("SUM(if(s.max_score = sp.shipment_score, 1, 0))")))
            ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
            ->where("sp.shipment_id = ?", $shipmentId)
            ->where("substring(sp.evaluation_status,4,1) != '0'")->group('sp.map_id');
        $shipmentOverall = $db->fetchAll($sql);
        $noOfParticipants = count($shipmentOverall);
        $numScoredFull = $shipmentOverall[0]['fullscore'];
        $maxScore = $shipmentOverall[0]['max_score'];
        $controlRes = array();
        $sampleRes = array();
        if (isset($results) && count($results) > 0) {
            foreach ($results as $res) {
                if ($res['control'] == 1) {
                    $controlRes[] = $res;
                } else {
                    $sampleRes[] = $res;
                }
            }
        }
        $submissionShipmentScore = 0;
        $scoringService = new Application_Service_EvaluationScoring();
        $samplePassStatuses = array();
        $maxShipmentScore = 0;
        $attributes = json_decode($shipmentData['attributes'],true);
        $assayRecords = $db->fetchAll($db->select()->from('r_tb_assay'));
        foreach ($assayRecords as $assayRecord) {
            $assays[$assayRecord['id']] = $assayRecord['short_name'];
        }
        $assayName = "Unspecified";
        if (isset($attributes['assay']) && $attributes['assay'] != '' && array_key_exists($attributes['assay'], $assays)) {
            $assayName = $assays[$attributes['assay']];
        }
        for ($i = 0; $i < count($sampleRes); $i++) {
            $sampleRes[$i]['calculated_score'] = $scoringService->calculateTbSamplePassStatus($sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_mtb_detected' : 'ref_mtb_rif_mtb_detected'],
                $sampleRes[$i]['res_mtb_detected'], $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_rif_resistance' : 'ref_mtb_rif_rif_resistance'], $sampleRes[$i]['res_rif_resistance'],
                $sampleRes[$i]['res_probe_1'], $sampleRes[$i]['res_probe_2'], $sampleRes[$i]['res_probe_3'],
                $sampleRes[$i]['res_probe_4'], $sampleRes[$i]['res_probe_5'], $sampleRes[$i]['res_probe_6'],
                $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'],
                $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt']);
            $submissionShipmentScore += $scoringService->calculateTbSampleScore(
                $sampleRes[$i]['calculated_score'],
                $sampleRes[$i]['ref_sample_score']);
            if ($sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'] == 'no' || $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt'] == 'yes') {
                $maxShipmentScore += $sampleRes[$i]['ref_sample_score'];
            }
            array_push($samplePassStatuses, $sampleRes[$i]['calculated_score']);
        }
        $shipmentData['shipment_score'] = $submissionShipmentScore;
        $shipmentData['documentation_score'] = $scoringService->calculateTbDocumentationScore($shipmentData['shipment_date'],
            $attributes['expiry_date'], $shipmentData['shipment_receipt_date'], $shipmentData['supervisor_approval'],
            $shipmentData['participant_supervisor'], $shipmentData['lastdate_response']);
        $shipmentData['calculated_score'] = $scoringService->calculateSubmissionPassStatus(
            $submissionShipmentScore, $shipmentData['documentation_score'], $maxShipmentScore,
            $samplePassStatuses);
        $shipmentData['max_documentation_score'] = Application_Service_EvaluationScoring::MAX_DOCUMENTATION_SCORE;
        $shipmentData['max_shipment_score'] = $maxShipmentScore;
        return array(
            'participant' => $participantData,
            'shipment' => $shipmentData,
            'possibleResults' => $possibleResults,
            'totalParticipants' => $noOfParticipants,
            'fullScorers' => $numScoredFull,
            'maxScore' => $maxScore,
            'evalComments' => $evalComments,
            'controlResults' => $controlRes,
            'results' => $sampleRes
        );
    }

    public function viewEvaluation($shipmentId, $participantId, $scheme) {
        $participantService = new Application_Service_Participants();
        $schemeService = new Application_Service_Schemes();
        $participantData = $participantService->getParticipantDetails($participantId);
        $shipmentData = $schemeService->getShipmentData($shipmentId, $participantId);
        $possibleResults = $schemeService->getPossibleResults($scheme);
        $evalComments = $schemeService->getSchemeEvaluationComments($scheme);
        $results = $schemeService->getTbSamples($shipmentId, $participantId);
        $controlRes = array();
        $sampleRes = array();

        if (isset($results) && count($results) > 0) {
            foreach ($results as $res) {
                if ($res['control'] == 1) {
                    $controlRes[] = $res;
                } else {
                    $sampleRes[] = $res;
                }
            }
        }

        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $config = new Zend_Config_Ini(APPLICATION_PATH . DIRECTORY_SEPARATOR . "configs" . DIRECTORY_SEPARATOR . "config.ini", APPLICATION_ENV);
        $sql = $db->select()->from(array('s' => 'shipment'))
                ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id')
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array('fullscore' => new Zend_Db_Expr("(if((sp.shipment_score+sp.documentation_score) >= " . $config->evaluation->dts->passPercentage . ", 1, 0))")))
                ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
                ->where("sp.shipment_id = ?", $shipmentId)
                ->where("substring(sp.evaluation_status,4,1) != '0'")
                ->group('sp.map_id');

        $shipmentOverall = $db->fetchAll($sql);

        $noOfParticipants = count($shipmentOverall);
        $numScoredFull = 0;
        foreach ($shipmentOverall as $shipment) {
            $numScoredFull += $shipment['fullscore'];
        }

        $submissionShipmentScore = 0;
        $scoringService = new Application_Service_EvaluationScoring();
        $samplePassStatuses = array();
        $maxShipmentScore = 0;
        $attributes = json_decode($shipmentData['attributes'],true);
        $assayRecords = $db->fetchAll($db->select()->from('r_tb_assay'));
        foreach ($assayRecords as $assayRecord) {
            $assays[$assayRecord['id']] = $assayRecord['short_name'];
        }
        $assayName = "Unspecified";
        if (isset($attributes['assay']) && $attributes['assay'] != '' && array_key_exists($attributes['assay'], $assays)) {
            $assayName = $assays[$attributes['assay']];
        }
        for ($i=0; $i < count($sampleRes); $i++) {
            $sampleRes[$i]['calculated_score'] = $scoringService->calculateTbSamplePassStatus($sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_mtb_detected' : 'ref_mtb_rif_mtb_detected'],
                $sampleRes[$i]['res_mtb_detected'], $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_rif_resistance' : 'ref_mtb_rif_rif_resistance'], $sampleRes[$i]['res_rif_resistance'],
                $sampleRes[$i]['res_probe_1'], $sampleRes[$i]['res_probe_2'], $sampleRes[$i]['res_probe_3'],
                $sampleRes[$i]['res_probe_4'], $sampleRes[$i]['res_probe_5'], $sampleRes[$i]['res_probe_6'],
                $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'],
                $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt']);
            $submissionShipmentScore += $scoringService->calculateTbSampleScore(
                $sampleRes[$i]['calculated_score'],
                $sampleRes[$i]['ref_sample_score']);
            if ($sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'] == 'no' || $sampleRes[$i][$assayName == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt'] == 'yes') {
                $maxShipmentScore += $sampleRes[$i]['ref_sample_score'];
            }
            array_push($samplePassStatuses, $sampleRes[$i]['calculated_score']);
        }
        $shipmentData['shipment_score'] = $submissionShipmentScore;
        $shipmentData['documentation_score'] = $scoringService->calculateTbDocumentationScore($shipmentData['shipment_date'],
            $attributes['expiry_date'], $shipmentData['shipment_receipt_date'], $shipmentData['supervisor_approval'],
            $shipmentData['participant_supervisor'], $shipmentData['lastdate_response']);
        $shipmentData['calculated_score'] = $scoringService->calculateSubmissionPassStatus(
            $submissionShipmentScore, $shipmentData['documentation_score'], $maxShipmentScore,
            $samplePassStatuses);
        $shipmentData['max_documentation_score'] = Application_Service_EvaluationScoring::MAX_DOCUMENTATION_SCORE;
        $shipmentData['max_shipment_score'] = $maxShipmentScore;

        return array(
            'participant' => $participantData,
            'shipment' => $shipmentData,
            'possibleResults' => $possibleResults,
            'totalParticipants' => $noOfParticipants,
            'fullScorers' => $numScoredFull,
            'evalComments' => $evalComments,
            'controlResults' => $controlRes,
            'results' => $sampleRes
        );
    }

    public function updateShipmentResults($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $admin = $authNameSpace->primary_email;
        if ($params['unableToSubmit'] == "yes") {
            $correctiveActions = array();
            if (isset($params['correctiveActions']) && $params['correctiveActions'] != "") {
                if (is_array($params['correctiveActions'])) {
                    foreach ($params['correctiveActions'] as $correctiveAction) {
                        if($correctiveAction != null && trim($correctiveAction) != "") {
                            array_push($correctiveActions, $correctiveAction);
                        }
                    }
                } else {
                    array_push($correctiveActions, $params['correctiveActions']);
                }
            }

            $schemeService = new Application_Service_Schemes();
            $shipmentData = $schemeService->getShipmentData($params['shipmentId'], $params['participantId']);
            $attributes = json_decode($shipmentData['attributes'], true);

            if (isset($shipmentData['follows_up_from']) && $shipmentData['follows_up_from'] > 0) {
                $previousShipmentData = $schemeService->getShipmentData($shipmentData['follows_up_from'], $params['participantId']);
                $previousShipmentAttributes = json_decode($previousShipmentData['attributes'], true);

                $correctiveActionsFromPreviousRound = array();
                $correctiveActionsCheckedOff = array();
                if (isset($params['correctiveActionsFromPreviousRound'])) {
                    if ($params['correctiveActionsFromPreviousRound'] == "") {
                        array_push($correctiveActionsCheckedOff, $params['correctiveActionsFromPreviousRound']);
                    } else {
                        $correctiveActionsCheckedOff = $params['correctiveActionsFromPreviousRound'];
                    }
                }

                if (isset($previousShipmentAttributes['corrective_actions'])) {
                    foreach ($previousShipmentAttributes['corrective_actions'] as $correctiveActionFromPreviousRound) {
                        array_push($correctiveActionsFromPreviousRound, array(
                            'checked_off' => in_array($correctiveActionFromPreviousRound,
                                $correctiveActionsCheckedOff),
                            'corrective_action' => $correctiveActionFromPreviousRound
                        ));
                    }
                }
                $attributes['corrective_actions_from_previous_round'] = $correctiveActionsFromPreviousRound;
            }

            $attributes = array_merge($attributes, array(
                "corrective_actions" => $correctiveActions));

            $attributes = json_encode($attributes);
            $mapData = array(
                "attributes" => $attributes,
                "updated_by_admin" => $admin,
                "updated_on_admin" => new Zend_Db_Expr('now()')
            );
            if (isset($params["userComments"])) {
                $mapData["user_comment"] = $params['userComments'];
            }

            if (isset($params['customField1']) && trim($params['customField1']) != "") {
                $mapData['custom_field_1'] = $params['customField1'];
            }

            if (isset($params['customField2']) && trim($params['customField2']) != "") {
                $mapData['custom_field_2'] = $params['customField2'];
            }

            $mapData['shipment_score'] = 0;
            $mapData['documentation_score'] = 0;
            $db->update('shipment_participant_map', $mapData, "map_id = " . $params['smid']);
        } else {
            $size = count($params['sampleId']);
            $correctiveActions = array();
            if (isset($params['correctiveActions']) && $params['correctiveActions'] != "") {
                if (is_array($params['correctiveActions'])) {
                    foreach ($params['correctiveActions'] as $correctiveAction) {
                        if($correctiveAction != null && trim($correctiveAction) != "") {
                            array_push($correctiveActions, $correctiveAction);
                        }
                    }
                } else {
                    array_push($correctiveActions, $params['correctiveActions']);
                }
            }

            $schemeService = new Application_Service_Schemes();
            $shipmentData = $schemeService->getShipmentData($params['shipmentId'], $params['participantId']);
            $attributes = json_decode($shipmentData['attributes'], true);

            if (isset($shipmentData['follows_up_from']) && $shipmentData['follows_up_from'] > 0) {
                $previousShipmentData = $schemeService->getShipmentData($shipmentData['follows_up_from'], $params['participantId']);
                $previousShipmentAttributes = json_decode($previousShipmentData['attributes'], true);

                $correctiveActionsFromPreviousRound = array();
                $correctiveActionsCheckedOff = array();
                if (isset($params['correctiveActionsFromPreviousRound'])) {
                    if ($params['correctiveActionsFromPreviousRound'] == "") {
                        array_push($correctiveActionsCheckedOff, $params['correctiveActionsFromPreviousRound']);
                    } else {
                        $correctiveActionsCheckedOff = $params['correctiveActionsFromPreviousRound'];
                    }
                }

                if (isset($previousShipmentAttributes['corrective_actions'])) {
                    foreach ($previousShipmentAttributes['corrective_actions'] as $correctiveActionFromPreviousRound) {
                        array_push($correctiveActionsFromPreviousRound, array(
                            'checked_off' => in_array($correctiveActionFromPreviousRound,
                                $correctiveActionsCheckedOff),
                            'corrective_action' => $correctiveActionFromPreviousRound
                        ));
                    }
                }
                $attributes['corrective_actions_from_previous_round'] = $correctiveActionsFromPreviousRound;
            }

            $attributes = array_merge($attributes, array(
                "cartridge_lot_no" => isset($params['cartridgeLotNo']) ? $params['cartridgeLotNo'] : $params['mtbRifKitLotNo'],
                "expiry_date" => Application_Service_Common::ParseDate($params['expiryDate']),
                "assay" => $params['assay'],
                "count_tests_conducted_over_month" => $params['countTestsConductedOverMonth'],
                "count_errors_encountered_over_month" => $params['countErrorsEncounteredOverMonth'],
                "error_codes_encountered_over_month" => $params['errorCodesEncounteredOverMonth'],
                "corrective_actions" => $correctiveActions));
            $attributes = json_encode($attributes);
            $assayRecords = $db->fetchAll($db->select()->from('r_tb_assay'));
            foreach ($assayRecords as $assayRecord) {
                $assays[$assayRecord['id']] = $assayRecord['short_name'];
            }
            $assayName = "Unspecified";
            if (isset($attributes['assay']) && $attributes['assay'] != '') {
                $assayName = $assays[$attributes['assay']];
            }
            $mapData = array(
                "shipment_receipt_date" => Application_Service_Common::ParseDate($params['receiptDate']),
                "shipment_test_report_date" => Application_Service_Common::ParseDate($params['testReceiptDate']),
                "attributes" => $attributes,
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

            $mapData['qc_done'] = $params['qcDone'];
            if (isset($mapData['qc_done']) && trim($mapData['qc_done']) == "yes") {
                $mapData['qc_date'] = Application_Service_Common::ParseDate($params['qcDate']);
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
            $scoringService = new Application_Service_EvaluationScoring();
            $shipmentScore = 0;
            $shipmentTestDate = null;
            for ($i = 0; $i < $size; $i++) {
                $dateTested = Application_Service_Common::ParseDate($params['dateTested'][$i]);
                if ($dateTested > $shipmentTestDate) {
                    $shipmentTestDate = $dateTested;
                }
                if (!isset($params['dateTested'][$i]) ||
                    $params['dateTested'][$i] == "") {
                    $dateTested = null;
                }
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

                $db = Zend_Db_Table_Abstract::getDefaultAdapter();
                $sql = $db->select()->from(array('reference_result_tb'))
                    ->where('shipment_id = ? ', $params['shipmentId'])
                    ->where('sample_id = ?', $params['sampleId'][$i]);
                $referenceSample = $db->fetchRow($sql);
                $calculatedScorePassStatus = $scoringService->calculateTbSamplePassStatus($referenceSample[$assayName == 'MTB Ultra' ? 'ultra_mtb_detected' : 'mtb_rif_mtb_detected'],
                    $params['mtbDetected'][$i], $referenceSample[$assayName == 'MTB Ultra' ? 'ultra_rif_resistance' : 'mtb_rif_rif_resistance'], $params['rifResistance'][$i],
                    $params['probe1'][$i], $params['probe2'][$i], $params['probe3'][$i], $params['probe4'][$i],
                    $params['probe5'][$i], $params['probe6'][$i], $referenceSample[$assayName == 'MTB Ultra' ? 'ultra_is_excluded' : 'mtb_rif_is_excluded'],
                    $referenceSample[$assayName == 'MTB Ultra' ? 'ultra_is_exempt' : 'mtb_rif_is_exempt']);
                $shipmentScore += $scoringService->calculateTbSampleScore(
                    $calculatedScorePassStatus,
                    $referenceSample['sample_score']);

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

                $db->update('response_result_tb', array(
                    'date_tested' => $dateTested,
                    'mtb_detected' => $params['mtbDetected'][$i],
                    'rif_resistance' => $params['rifResistance'][$i],
                    'probe_1' => $params['probe1'][$i],
                    'probe_2' => $params['probe2'][$i],
                    'probe_3' => $params['probe3'][$i],
                    'probe_4' => $params['probe4'][$i],
                    'probe_5' => $params['probe5'][$i],
                    'probe_6' => $params['probe6'][$i],
                    'calculated_score' => $calculatedScorePassStatus,
                    'instrument_serial' => $params['instrumentSerial'][$i],
                    'instrument_installed_on' => $instrumentInstalledOn,
                    'instrument_last_calibrated_on' => $instrumentLastCalibratedOn,
                    'module_name' => $params['moduleName'][$i],
                    'instrument_user' => $params['instrumentUser'][$i],
                    'cartridge_expiration_date' => $cartridgeExpirationDate,
                    'reagent_lot_id' => isset($params['cartridgeLotNo']) ? $params['cartridgeLotNo'] : $params['mtbRifKitLotNo'],
                    'error_code' => $params['errorCode'][$i],
                    'updated_by' => $admin,
                    'updated_on' => new Zend_Db_Expr('now()')
                ), "shipment_map_id = " . $params['smid'] . " and sample_id = " . $params['sampleId'][$i]);
            }
            if (isset($shipmentTestDate)) {
                $mapData['shipment_test_date'] = $shipmentTestDate;
            }
            $mapData['shipment_score'] = $shipmentScore;
            $mapData['documentation_score'] = $scoringService->calculateTbDocumentationScore(
                Application_Service_Common::ParseDate($params['shipmentDate']),
                Application_Service_Common::ParseDate($params['expiryDate']),
                Application_Service_Common::ParseDate($params['receiptDate']),
                $params['supervisorApproval'],
                $params['participantSupervisor'],
                Application_Service_Common::ParseDate($params['responseDeadlineDate']));
            $db->update('shipment_participant_map', $mapData, "map_id = " . $params['smid']);
        }

        $params['isFollowUp'] = (isset($params['isFollowUp']) && $params['isFollowUp'] != "" ) ?
            $params['isFollowUp'] : "no";

		$updateArray = array(
            'optional_eval_comment' => $params['optionalComments'],
            'is_followup' => $params['isFollowUp'],
            'is_excluded' => $params['isExcluded'],
            'updated_by_admin' => $admin,
            'updated_on_admin' => new Zend_Db_Expr('now()')
        );
        if(isset($params['comment']) && $params['comment'] != ""){
            $updateArray['evaluation_comment'] = $params['comment'];
        }
		if($params['isExcluded'] == 'yes'){
			$updateArray['final_result'] = 3;
		}

        $db->update('shipment_participant_map', $updateArray, "map_id = " . $params['smid']);
    }

    public function updateShipmentComment($shipmentId, $comment) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $admin = $authNameSpace->primary_email;
        $noOfRows = $db->update('shipment', array('shipment_comment' => $comment, 'updated_by_admin' => $admin, 'updated_on_admin' => new Zend_Db_Expr('now()')), "shipment_id = " . $shipmentId);
        if ($noOfRows > 0) {
            return "Comment updated";
        } else {
            return "Unable to update shipment comment. Please try again later.";
        }
    }

    public function updateShipmentStatus($shipmentId, $status) {
        $shipmentDb = new Application_Model_DbTable_Shipments();
        $schemeType = '';
        if ($status == 'finalized') {
            $shipmentData = $shipmentDb->getShipmentRowInfo($shipmentId);
            $schemeType = $shipmentData['scheme_type'];
        }
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $admin = $authNameSpace->primary_email;
        $db->beginTransaction();
        try {
            $noOfRows = $db->update('shipment', array(
                'status' => $status,
                'updated_by_admin' => $admin,
                'updated_on_admin' => new Zend_Db_Expr('now()')),
                "shipment_id = " . $shipmentId);

            if ($noOfRows > 0) {
                if ($schemeType == 'tb') {
                    $tempPushNotificationsDb = new Application_Model_DbTable_TempPushNotification();
                    $pushNotifications = $shipmentDb->getShipmentFinalisedPushNotifications($shipmentId);
                    foreach ($pushNotifications as $pushNotificationData) {
                        $tempPushNotificationsDb->insertTempPushNotificationDetails(
                            $pushNotificationData['push_notification_token'],
                            'default', 'ePT ' . $pushNotificationData['shipment_code'] . ' Report Released',
                            'Report for ePT panel ' . $pushNotificationData['shipment_code'] . ' has been released for ' . $pushNotificationData['lab_name'] . '. Tap to view it.',
                            '{"title": "ePT ' . $pushNotificationData['shipment_code'] . ' Report Released", "body": "Report for ePT panel ' . $pushNotificationData['shipment_code'] . ' has been released for ' . $pushNotificationData['lab_name'] . '. Would you like to view it?", "dismissText": "Close", "actionText": "View Report", "shipmentId": ' . $pushNotificationData['shipment_id'] . ', "participantId": ' . $pushNotificationData['participant_id'] . ', "action": "view_report"}');
                    }
                }
                $db->commit();
                return "Status updated";
            } else {
                return "Unable to update shipment status. Please try again later.";
            }
        } catch (Exception $e) {
            $db->rollBack();
            error_log($e->getMessage());
            error_log($e->getTraceAsString());
            return "Unable to update shipment status. Please try again later.";
        }
    }

    public function getShipmentToEvaluateReports($shipmentId, $reEvaluate = false) {
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment', array(
            'shipment_id',
            'shipment_code',
            'status',
            'number_of_samples'
        )))
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array(
                'distribution_code',
                'distribution_date'
            ))
            ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id')
            ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type', array('scheme_name'))
            ->join(array('p' => 'participant'), 'p.participant_id = sp.participant_id', array(
                'lab_name',
                'unique_identifier',
                'sorting_unique_identifier' => new Zend_Db_Expr("LPAD(p.unique_identifier, 10, '0')")
            ))
            ->joinLeft(array('res' => 'r_results'), 'res.result_id=sp.final_result')
            ->where("s.shipment_id = ?", $shipmentId)
            ->where("substring(sp.evaluation_status,4,1) != '0'");
        if($authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->where("p.country IS NULL OR p.country IN (".implode(",",$authNameSpace->countries).")");
        }
        $sql = $sql->order('sorting_unique_identifier');
        $shipmentResult = $db->fetchAll($sql);
        return $shipmentResult;
    }

    private function getExpectedResults($shipmentId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $tbResultsExpected = array();
        $expectedResultsQuery = $db->select()->from(array('ref' => 'reference_result_tb'),
            array(
                'sample_id',
                'sample_label',
                'mtb_rif_mtb_detected',
                'mtb_rif_rif_resistance',
                'ultra_mtb_detected',
                'ultra_rif_resistance',
                'ref_mtb_rif_is_excluded' => 'ref.mtb_rif_is_excluded',
                'ref_mtb_rif_is_exempt' => 'ref.mtb_rif_is_exempt',
                'ref_ultra_is_excluded' => 'ref.ultra_is_excluded',
                'ref_ultra_is_exempt' => 'ref.ultra_is_exempt'
            ))
            ->where("ref.shipment_id = ?", $shipmentId);
        $tbResultsExpectedResults = $db->fetchAll($expectedResultsQuery);
        foreach ($tbResultsExpectedResults as $tbResultsExpectedResult) {
            $tbResultsExpected[$tbResultsExpectedResult['sample_id']] = array(
                'mtb_rif_mtb_detected' => $tbResultsExpectedResult['mtb_rif_mtb_detected'],
                'mtb_rif_rif_resistance' => $tbResultsExpectedResult['mtb_rif_rif_resistance'],
                'ultra_mtb_detected' => $tbResultsExpectedResult['ultra_mtb_detected'],
                'ultra_rif_resistance' => $tbResultsExpectedResult['ultra_rif_resistance'],
                'ref_mtb_rif_is_excluded' => $tbResultsExpectedResult['ref_mtb_rif_is_excluded'],
                'ref_mtb_rif_is_exempt' => $tbResultsExpectedResult['ref_mtb_rif_is_exempt'],
                'ref_ultra_is_excluded' => $tbResultsExpectedResult['ref_ultra_is_excluded'],
                'ref_ultra_is_exempt' => $tbResultsExpectedResult['ref_ultra_is_exempt']
            );
        }
        return $tbResultsExpected;
    }

    private function getConsensusResults($shipmentId, $assayShortName) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $tbResultsConsensus = array();
        $consensusResultsQueryMtbDetected = $db->select()->from(array('spm' => 'shipment_participant_map'), array())
            ->joinLeft(array('a' => 'r_tb_assay'),
                'a.id = CASE WHEN JSON_VALID(spm.attributes) = 1 THEN JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, "$.assay")) ELSE 0 END')
            ->join(array('ref' => 'reference_result_tb'),
                'ref.shipment_id = spm.shipment_id', array('sample_id'))
            ->joinLeft(array('res' => 'response_result_tb'), 'res.shipment_map_id = spm.map_id AND res.sample_id = ref.sample_id', array(
                'mtb_detected',
                'occurrences' => 'COUNT(*)',
                'matches_reference_result' => 'SUM(CASE WHEN `res`.`mtb_detected` = `ref`.`'.($assayShortName == 'MTB Ultra' ? 'ultra' : 'mtb_rif').'_mtb_detected` THEN 1 ELSE 0 END)'))
            ->where("spm.shipment_id = ?", $shipmentId)
            ->where("substring(spm.evaluation_status,4,1) != '0'")
            ->where("spm.is_excluded = 'no'")
            ->where("a.short_name = ?", $assayShortName)
            ->group('ref.sample_id')
            ->group('res.mtb_detected')
            ->order('ref.sample_id ASC')
            ->order('occurrences DESC')
            ->order('matches_reference_result DESC');
        $tbResultsConsensusMtbDetected = $db->fetchAll($consensusResultsQueryMtbDetected);
        foreach ($tbResultsConsensusMtbDetected as $tbResultsConsensusMtbDetectedItem) {
            if (isset($tbResultsConsensusMtbDetectedItem['mtb_detected']) &&
                !trim($tbResultsConsensusMtbDetectedItem['mtb_detected']) != "" &&
                isset($tbResultsConsensusMtbDetectedItem['occurrences']) &&
                $tbResultsConsensusMtbDetectedItem['occurrences'] > 0) {
                if (!isset($tbResultsConsensus[$tbResultsConsensusMtbDetectedItem['sample_id']])) {
                    $tbResultsConsensus[$tbResultsConsensusMtbDetectedItem['sample_id']] = array(
                        'mtb_detected' => $tbResultsConsensusMtbDetectedItem['mtb_detected'],
                        'mtb_occurrences' => $tbResultsConsensusMtbDetectedItem['occurrences'],
                        'mtb_matches_reference_result' => $tbResultsConsensusMtbDetectedItem['matches_reference_result'],
                        'rif_resistance' => '',
                        'rif_occurrences' => 0,
                        'rif_matches_reference_result' => 0
                    );
                } else if ($tbResultsConsensusMtbDetectedItem['occurrences'] >
                    $tbResultsConsensus[$tbResultsConsensusMtbDetectedItem['sample_id']]['mtb_occurrences'] ||
                    ($tbResultsConsensusMtbDetectedItem['occurrences'] ==
                        $tbResultsConsensus[$tbResultsConsensusMtbDetectedItem['sample_id']]['mtb_occurrences'] &&
                        $tbResultsConsensusMtbDetectedItem['matches_reference_result'] == 1)) {
                    $tbResultsConsensus[$tbResultsConsensusMtbDetectedItem['sample_id']]['mtb_detected'] = $tbResultsConsensusMtbDetectedItem['mtb_detected'];
                    $tbResultsConsensus[$tbResultsConsensusMtbDetectedItem['sample_id']]['mtb_occurrences'] = $tbResultsConsensusMtbDetectedItem['occurrences'];
                    $tbResultsConsensus[$tbResultsConsensusMtbDetectedItem['sample_id']]['mtb_matches_reference_result'] = $tbResultsConsensusMtbDetectedItem['matches_reference_result'];
                }
            }
        }

        $consensusResultsQueryRifDetected = $db->select()->from(array('spm' => 'shipment_participant_map'), array())
            ->joinLeft(array('a' => 'r_tb_assay'),
                'a.id = CASE WHEN JSON_VALID(spm.attributes) = 1 THEN JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, "$.assay")) ELSE 0 END')
            ->join(array('ref' => 'reference_result_tb'),
                'ref.shipment_id = spm.shipment_id', array('sample_id'))
            ->joinLeft(array('res' => 'response_result_tb'), 'res.shipment_map_id = spm.map_id AND res.sample_id = ref.sample_id', array(
                'rif_resistance',
                'occurrences' => 'COUNT(*)',
                'matches_reference_result' => 'SUM(CASE WHEN `res`.`rif_resistance` = `ref`.`'.($assayShortName == 'MTB Ultra' ? 'ultra' : 'mtb_rif').'_rif_resistance` THEN 1 ELSE 0 END)'))
            ->where("spm.shipment_id = ?", $shipmentId)
            ->where("substring(spm.evaluation_status,4,1) != '0'")
            ->where("spm.is_excluded = 'no'")
            ->where("a.short_name = ?", $assayShortName)
            ->group('ref.sample_id')
            ->group('res.rif_resistance')
            ->order('ref.sample_id ASC')
            ->order('occurrences DESC')
            ->order('matches_reference_result DESC');
        $tbResultsConsensusRifDetected = $db->fetchAll($consensusResultsQueryRifDetected);

        foreach ($tbResultsConsensusRifDetected as $tbResultsConsensusRifDetectedItem) {
            if (isset($tbResultsConsensusRifDetectedItem['rif_resistance']) &&
                !trim($tbResultsConsensusRifDetectedItem['rif_resistance']) != "" &&
                isset($tbResultsConsensusRifDetectedItem['occurrences']) &&
                $tbResultsConsensusRifDetectedItem['occurrences'] > 0) {
                if (!isset($tbResultsConsensus[$tbResultsConsensusRifDetectedItem['sample_id']])) {
                    $tbResultsConsensus[$tbResultsConsensusRifDetectedItem['sample_id']] = array(
                        'mtb_detected' => '',
                        'mtb_occurrences' => 0,
                        'mtb_matches_reference_result' => 0,
                        'rif_resistance' => $tbResultsConsensusRifDetectedItem['rif_resistance'],
                        'rif_occurrences' => $tbResultsConsensusRifDetectedItem['occurrences'],
                        'rif_matches_reference_result' => $tbResultsConsensusRifDetectedItem['matches_reference_result']
                    );
                } else if ($tbResultsConsensusRifDetectedItem['occurrences'] >
                    $tbResultsConsensus[$tbResultsConsensusRifDetectedItem['sample_id']]['rif_occurrences'] ||
                    ($tbResultsConsensusRifDetectedItem['occurrences'] ==
                        $tbResultsConsensus[$tbResultsConsensusRifDetectedItem['sample_id']]['rif_occurrences'] &&
                        $tbResultsConsensusRifDetectedItem['matches_reference_result'] == 1)) {
                    $tbResultsConsensus[$tbResultsConsensusRifDetectedItem['sample_id']]['rif_resistance'] = $tbResultsConsensusRifDetectedItem['rif_resistance'];
                    $tbResultsConsensus[$tbResultsConsensusRifDetectedItem['sample_id']]['rif_occurrences'] = $tbResultsConsensusRifDetectedItem['occurrences'];
                    $tbResultsConsensus[$tbResultsConsensusRifDetectedItem['sample_id']]['rif_matches_reference_result'] = $tbResultsConsensusRifDetectedItem['matches_reference_result'];
                }
            }
        }
        return $tbResultsConsensus;
    }

    public function getEvaluateReportsInPdf ($shipmentId, $sLimit, $sOffset) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment'), array(
            's.shipment_id',
            's.shipment_code',
            's.scheme_type',
            's.shipment_date',
            's.lastdate_response',
            's.max_score'))
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array(
                'd.distribution_id',
                'd.distribution_code',
                'd.distribution_date'
            ))
            ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array(
                'sp.map_id',
                'sp.participant_id',
                'sp.shipment_test_date',
                'sp.shipment_receipt_date',
                'sp.shipment_test_report_date',
                'result_submission_date' => new Zend_Db_Expr('IFNULL(sp.date_submitted, sp.shipment_test_report_date)'),
                'sp.final_result',
                'sp.failure_reason',
                'sp.shipment_score',
                'sp.final_result',
                'sp.attributes',
                'sp.is_followup',
                'sp.is_excluded',
                'sp.optional_eval_comment',
                'sp.evaluation_comment',
                'sp.documentation_score',
                'sp.supervisor_approval',
                'sp.participant_supervisor',
                'sp.qc_done',
                'sp.qc_date',
                'sp.is_pt_test_not_performed',
                'sp.pt_test_not_performed_comments'))
            ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type', array('sl.scheme_id', 'sl.scheme_name'))
            ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id', array(
                'p.unique_identifier',
                'p.lab_name',
                'p.status',
                'sorting_unique_identifier' => new Zend_Db_Expr("LPAD(p.unique_identifier, 10, '0')")
            ))
            ->join(array('c' => 'countries'), 'c.id=p.country', array('country_name' => 'c.iso_name', 'country_id' => 'c.id'))
            ->joinLeft(array('res' => 'r_results'), 'res.result_id=sp.final_result', array('result_name'))
            ->joinLeft(array('ec' => 'r_evaluation_comments'), 'ec.comment_id=sp.evaluation_comment', array(
                'evaluationComments' => 'comment'))
            ->joinLeft(array('rntr' => 'response_not_tested_reason'), 'rntr.not_tested_reason_id = sp.not_tested_reason', array('rntr.not_tested_reason'))
            ->where("s.shipment_id = ?", $shipmentId)
            ->where("substring(sp.evaluation_status,4,1) != '0'")
            ->order("sorting_unique_identifier");
        if (isset($sLimit) && isset($sOffset)) {
            $sql = $sql->limit($sLimit, $sOffset);
		}
        $shipmentResult = $db->fetchAll($sql);
        $previousSixShipmentsSql = $db->select()
            ->from(array('s' => 'shipment'), array(
                's.shipment_id',
                's.shipment_code',
                's.shipment_date'))
            ->join(array('spm' => 'shipment_participant_map'), 's.shipment_id=spm.shipment_id', array('mean_shipment_score' => new Zend_Db_Expr("AVG(IFNULL(spm.shipment_score, 0) + IFNULL(spm.documentation_score, 0))")))
            ->where("s.is_official = 1")
            ->where(new Zend_Db_Expr("IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'"))
            ->where(new Zend_Db_Expr("IFNULL(spm.is_excluded, 'no') = 'no'"))
            ->where(new Zend_Db_Expr("SUBSTR(spm.evaluation_status, 3, 1) = '1'")) // Submitted
            ->where("s.shipment_id <= ".$shipmentId)
            ->group('s.shipment_id')
            ->order("s.shipment_date DESC")
            ->limit(6);
        $previousSixShipments = $db->fetchAll($previousSixShipmentsSql);
        $countryPtccs = array();
        $tbResultsExpected = array();
        $tbResultsConsensusMtbRif = array();
        $tbResultsConsensusUltra = array();
        if (count($shipmentResult) > 0) {
            $tbResultsExpected = $this->getExpectedResults($shipmentId);
            $tbResultsConsensusMtbRif = $this->getConsensusResults($shipmentId, 'MTB/RIF');
            $tbResultsConsensusUltra = $this->getConsensusResults($shipmentId, 'MTB Ultra');
        }

        $assays = array();
        $assayRecords = $db->fetchAll($db->select()->from('r_tb_assay'));
        foreach ($assayRecords as $assayRecord) {
            $assays[$assayRecord['id']] = $assayRecord['short_name'];
        }
        $i = 0;
        $mapRes = array();
        $scoringService = new Application_Service_EvaluationScoring();
        foreach ($shipmentResult as $res) {
            $dmSql = $db->select()
                ->from(array('pmm' => 'participant_manager_map'))
                ->join(array('dm' => 'data_manager'), 'dm.dm_id = pmm.dm_id',
                    array("institute" => "IFNULL(dm.institute, '')"))
                ->where("pmm.participant_id = " . $res['participant_id']);

            $dmResult = $db->fetchAll($dmSql);
            if (!isset($countryPtccs[$res['country_id']])) {
                $ptccSql = $db->select()
                    ->from(array('pcm' => 'ptcc_country_map'))
                    ->join(array('sa' => 'system_admin'), 'sa.admin_id = pcm.admin_id')
                    ->where("pcm.country_id = " . $res['country_id'])
                    ->where("pcm.show_details_on_report = 1")
                    ->limit(2);

                $countryPtccs[$res['country_id']] = $db->fetchAll($ptccSql);
            }
            $shipmentResult[$i]['ptcc_profiles'] = $countryPtccs[$res['country_id']];
            $participantPreviousSixShipments = array();
            if (count($previousSixShipments) > 0) {
                $participantPreviousSixShipmentsSql = $db->select()
                    ->from(array('spm' => 'shipment_participant_map'), array('shipment_id' => 'spm.shipment_id', 'shipment_score' => new Zend_Db_Expr("IFNULL(spm.shipment_score, 0) + IFNULL(spm.documentation_score, 0)")))
                    ->where("spm.participant_id = " . $res['participant_id'])
                    ->where("spm.shipment_id IN (" . implode(",", array_column($previousSixShipments, "shipment_id")) . ")");

                $participantPreviousSixShipmentRecords = $db->fetchAll($participantPreviousSixShipmentsSql);
                foreach ($participantPreviousSixShipmentRecords as $participantPreviousSixShipmentRecord) {
                    $participantPreviousSixShipments[$participantPreviousSixShipmentRecord['shipment_id']] = $participantPreviousSixShipmentRecord;
                }
            }
            $shipmentResult[$i]['previous_six_shipments'] = array();
            for($participantPreviousSixShipmentIndex = 5; $participantPreviousSixShipmentIndex >= 0; $participantPreviousSixShipmentIndex--) {
                $previousShipmentData = array(
                    'shipment_code' => 'XXXX',
                    'mean_shipment_score' => null,
                    'shipment_score' => null,
                );
                if (count($previousSixShipments) > $participantPreviousSixShipmentIndex) {
                    $previousShipmentData['shipment_code'] = $previousSixShipments[$participantPreviousSixShipmentIndex]['shipment_code'];
                    $previousShipmentData['mean_shipment_score'] = $previousSixShipments[$participantPreviousSixShipmentIndex]['mean_shipment_score'];
                    if (isset($participantPreviousSixShipments[$previousSixShipments[$participantPreviousSixShipmentIndex]['shipment_id']])) {
                        $previousShipmentData['shipment_score'] = $participantPreviousSixShipments[$previousSixShipments[$participantPreviousSixShipmentIndex]['shipment_id']]['shipment_score'];
                    }
                }
                $shipmentResult[$i]['previous_six_shipments'][5 - $participantPreviousSixShipmentIndex] = $previousShipmentData;
            }
            foreach ($dmResult as $dmRes) {
                $participantFileName = preg_replace('/[^A-Za-z0-9.]/', '-', $res['lab_name'] . "-" . $res['map_id']);
				$participantFileName = str_replace(" ", "-", $participantFileName);
                if (count($mapRes) == 0) {
                    $mapRes[$dmRes['dm_id']] = $dmRes['institute'] . "#" . $dmRes['participant_id'] . "#" . $participantFileName;
                } else if (array_key_exists($dmRes['dm_id'], $mapRes)) {
                    $mapRes[$dmRes['dm_id']] .= "$" . $dmRes['institute'] . "#" . $dmRes['participant_id'] . "#" . $participantFileName;
                } else {
                    $mapRes[$dmRes['dm_id']] = $dmRes['institute'] . "#" . $dmRes['participant_id'] . "#" . $participantFileName;
                }
            }
            $attributes = json_decode($res['attributes'], true);
            $sql = $db->select()->from(array('ref' => 'reference_result_tb'),
                array(
                    'sample_id', 'sample_label', 'sample_score',
                    'ref_mtb_rif_is_excluded' => 'ref.mtb_rif_is_excluded',
                    'ref_mtb_rif_is_exempt' => 'ref.mtb_rif_is_exempt',
                    'ref_ultra_is_excluded' => 'ref.ultra_is_excluded',
                    'ref_ultra_is_exempt' => 'ref.ultra_is_exempt',
                    'ref_excluded_reason' => 'ref.excluded_reason'))
                ->join(array('spm' => 'shipment_participant_map'),
                    'spm.shipment_id = ref.shipment_id', array())
                ->joinLeft(array('a' => 'r_tb_assay'),
                    'a.id = CASE WHEN JSON_VALID(spm.attributes) = 1 THEN JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, "$.assay")) ELSE 0 END', array('assay_short_name' => 'a.short_name'))
                ->joinLeft(array('res' => 'response_result_tb'),
                    'res.shipment_map_id = spm.map_id and res.sample_id = ref.sample_id', array(
                        'res.mtb_detected',
                        'res.rif_resistance',
                        'res.error_code',
                        'res.date_tested',
                        'cartridge_expiration_date' => new Zend_Db_Expr("COALESCE(
      CASE WHEN res.cartridge_expiration_date = '0000-00-00' THEN NULL
      ELSE COALESCE(STR_TO_DATE(res.cartridge_expiration_date, '%d-%b-%Y'),
        STR_TO_DATE(res.cartridge_expiration_date, '%Y-%b-%d'),
        STR_TO_DATE(res.cartridge_expiration_date, '%d-%m-%Y'),
        STR_TO_DATE(res.cartridge_expiration_date, '%Y-%m-%d'))
      END,
      CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")) = '0000-00-00' THEN NULL
      ELSE COALESCE(STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%d-%b-%Y'),
        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%Y-%b-%d'),
        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%d-%m-%Y'),
        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%Y-%m-%d'))
      END)"),
                        'res.probe_1',
                        'res.probe_2',
                        'res.probe_3',
                        'res.probe_4',
                        'res.probe_5',
                        'res.probe_6'))
                ->joinLeft('instrument',
                    'instrument.participant_id = spm.participant_id and instrument.instrument_serial = res.instrument_serial', array(
                        'years_since_last_calibrated' =>
                            new Zend_Db_Expr("DATEDIFF(COALESCE(res.date_tested, spm.shipment_test_date, spm.shipment_test_report_date), COALESCE(res.instrument_last_calibrated_on, instrument.instrument_last_calibrated_on, res.instrument_installed_on, instrument.instrument_installed_on, CAST('1990-01-01' AS DATE))) / 365"),
                        'instrument_serial' => new Zend_Db_Expr("COALESCE(CASE WHEN res.instrument_serial = '' THEN NULL ELSE res.instrument_serial END, CASE WHEN instrument.instrument_serial = '' THEN NULL ELSE instrument.instrument_serial END, 'NO SERIAL ENTERED')"),
                        'instrument_installed_on' =>
                            new Zend_Db_Expr("COALESCE(res.instrument_installed_on, instrument.instrument_installed_on)"),
                        'instrument_last_calibrated_on' =>
                            new Zend_Db_Expr("COALESCE(res.instrument_last_calibrated_on, instrument.instrument_last_calibrated_on)")))
                ->where('ref.shipment_id = ? ', $shipmentId)
                ->where('spm.participant_id = ?', $res['participant_id'])
                ->order('ref.sample_id ASC');
            $tbResults = $db->fetchAll($sql);

            $counter = 0;
            $toReturn = array();
            $shipmentScore = 0;
            $maxShipmentScore = 0;
            $sampleStatuses = array();
            $instrumentsUsed = array();
            $cartridgeExpiredOn = null;
            $instrumentRequiresCalibration = false;
            $testsDoneAfterCalibrationDue = array();
            $testsDoneAfterCartridgeExpired = array();
            $qcDoneOnTime = $shipmentResult[$i]['qc_done'] == 'yes' && isset($shipmentResult[$i]['qc_date']);
            $lastTestDate = null;
            foreach ($tbResults as $tbResult) {
                if (in_array($tbResult['mtb_detected'], array("notDetected", "noResult", "invalid", "error")) &&
                    $tbResult['rif_resistance'] == null) {
                    $tbResult['rif_resistance'] = "na";
                }
                $sampleScoreStatus = $scoringService->calculateTbSamplePassStatus(
                    $tbResultsExpected[$tbResult['sample_id']][$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ultra_mtb_detected' : 'mtb_rif_mtb_detected'],
                    $tbResult['mtb_detected'],
                    $tbResultsExpected[$tbResult['sample_id']][$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ultra_rif_resistance' : 'mtb_rif_rif_resistance'],
                    $tbResult['rif_resistance'],
                    $tbResult['probe_1'], $tbResult['probe_2'], $tbResult['probe_3'], $tbResult['probe_4'],
                    $tbResult['probe_5'], $tbResult['probe_6'], $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'],
                    $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt']);
                array_push($sampleStatuses, $sampleScoreStatus);
                $sampleScore = $scoringService->calculateTbSampleScore(
                    $sampleScoreStatus,
                    $tbResult['sample_score']);
                $shipmentScore += $sampleScore;
                if ($tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'] == 'no' || $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt'] == 'yes') {
                    $maxShipmentScore += $tbResult['sample_score'];
                }
                $consensusTbMtbDetectedMtbRif = $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_mtb_detected'];
                $consensusTbRifResistanceMtbRif = $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_rif_resistance'];
                if (isset($tbResultsConsensusMtbRif[$tbResult['sample_id']])) {
                    if (isset($tbResultsConsensusMtbRif[$tbResult['sample_id']]['mtb_detected']) &&
                        trim($tbResultsConsensusMtbRif[$tbResult['sample_id']]['mtb_detected']) != "") {
                        $consensusTbMtbDetectedMtbRif = $tbResultsConsensusMtbRif[$tbResult['sample_id']]['mtb_detected'];
                    }
                    if (isset($tbResultsConsensusMtbRif[$tbResult['sample_id']]['rif_resistance']) &&
                        trim($tbResultsConsensusMtbRif[$tbResult['sample_id']]['rif_resistance']) != "") {
                        $consensusTbRifResistanceMtbRif = $tbResultsConsensusMtbRif[$tbResult['sample_id']]['rif_resistance'];
                    }
                }
                $consensusTbMtbDetectedUltra = $tbResultsExpected[$tbResult['sample_id']]['ultra_mtb_detected'];
                $consensusTbRifResistanceUltra = $tbResultsExpected[$tbResult['sample_id']]['ultra_rif_resistance'];
                if (isset($tbResultsConsensusUltra[$tbResult['sample_id']])) {
                    if (isset($tbResultsConsensusUltra[$tbResult['sample_id']]['mtb_detected']) &&
                        trim($tbResultsConsensusUltra[$tbResult['sample_id']]['mtb_detected']) != "") {
                        $consensusTbMtbDetectedUltra = $tbResultsConsensusUltra[$tbResult['sample_id']]['mtb_detected'];
                    }
                    if (isset($tbResultsConsensusUltra[$tbResult['sample_id']]['rif_resistance']) &&
                        trim($tbResultsConsensusUltra[$tbResult['sample_id']]['rif_resistance']) != "") {
                        $consensusTbRifResistanceUltra = $tbResultsConsensusUltra[$tbResult['sample_id']]['rif_resistance'];
                    }
                }

                if (isset($tbResult['cartridge_expiration_date']) && $tbResult['cartridge_expiration_date'] != '0000-00-00' && $tbResult['cartridge_expiration_date'] < $tbResult['date_tested']) {
                    $cartridgeExpiredOn = $tbResult['cartridge_expiration_date'];
                    array_push($testsDoneAfterCartridgeExpired, array(
                        "sample_label" => $tbResult['sample_label'],
                        "date_tested" => $tbResult['date_tested']
                    ));
                }
                $instrumentInArray = false;
                foreach ($instrumentsUsed as $instrumentUsed) {
                    if ($instrumentUsed["instrument_serial"] == $tbResult['instrument_serial']) {
                        $instrumentInArray = true;
                    }
                }
                if (!$instrumentInArray) {
                    array_push(
                        $instrumentsUsed,
                        array (
                            "instrument_last_calibrated_on" => $tbResult['instrument_last_calibrated_on'],
                            "instrument_serial" => $tbResult['instrument_serial']
                        )
                    );
                }
                if ((!isset($tbResult['instrument_last_calibrated_on']) &&
                        !isset($tbResult['instrument_installed_on'])) ||
                    (isset($tbResult['years_since_last_calibrated']) &&
                        $tbResult['years_since_last_calibrated'] >= 1)) {
                    $instrumentRequiresCalibration = true;
                    array_push($testsDoneAfterCalibrationDue, array(
                        "sample_label" => $tbResult['sample_label'],
                        "date_tested" => $tbResult['date_tested'],
                        "instrument_serial" => $tbResult['instrument_serial'],
                        "instrument_last_calibrated_on" => $tbResult['instrument_last_calibrated_on']
                    ));
                }
                if ($lastTestDate == null || $lastTestDate < $tbResult['date_tested']) {
                    $lastTestDate = $tbResult['date_tested'];
                }
                $toReturn[$counter] = array(
                    'sample_id' => $tbResult['sample_id'],
                    'sample_label' => $tbResult['sample_label'],
                    'mtb_detected' => $tbResult['mtb_detected'],
                    'discrepant_result' => $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt'] != 'yes' &&
                        $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'] != 'yes' &&
                        $sampleScore == 0,
                    'rif_resistance' => $tbResult['rif_resistance'],
                    'error_code' => $tbResult['error_code'],
                    'probe_1' => $tbResult['probe_1'],
                    'probe_2' => $tbResult['probe_2'],
                    'probe_3' => $tbResult['probe_3'],
                    'probe_4' => $tbResult['probe_4'],
                    'probe_5' => $tbResult['probe_5'],
                    'probe_6' => $tbResult['probe_6'],
                    'expected_mtb_rif_mtb_detected' => $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_mtb_detected'],
                    'expected_mtb_rif_rif_resistance' => $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_rif_resistance'],
                    'expected_ultra_mtb_detected' => $tbResultsExpected[$tbResult['sample_id']]['ultra_mtb_detected'],
                    'expected_ultra_rif_resistance' => $tbResultsExpected[$tbResult['sample_id']]['ultra_rif_resistance'],
                    'consensus_mtb_rif_mtb_detected' => $consensusTbMtbDetectedMtbRif,
                    'consensus_mtb_rif_rif_resistance' => $consensusTbRifResistanceMtbRif,
                    'consensus_ultra_mtb_detected' => $consensusTbMtbDetectedUltra,
                    'consensus_ultra_rif_resistance' => $consensusTbRifResistanceUltra,
                    'ref_mtb_rif_is_excluded' => $tbResult['ref_mtb_rif_is_excluded'],
                    'ref_mtb_rif_is_exempt' => $tbResult['ref_mtb_rif_is_exempt'],
                    'ref_ultra_is_excluded' => $tbResult['ref_ultra_is_excluded'],
                    'ref_ultra_is_exempt' => $tbResult['ref_ultra_is_exempt'],
                    'ref_excluded_reason' => $tbResult['ref_excluded_reason'],
                    'max_score' => $tbResult['sample_score'],
                    'score' => $sampleScore,
                    'score_status' => $sampleScoreStatus);
                $counter++;
            }
            if ($qcDoneOnTime) {
                $secondsSinceQcDoneToLastTest = strtotime(Pt_Commons_General::dbDateToString($lastTestDate)) - strtotime(Pt_Commons_General::dbDateToString($shipmentResult[$i]['qc_date']));
                $qcDoneOnTime = round($secondsSinceQcDoneToLastTest / (60 * 60 * 24)) < 62;
            }
            $shipmentResult[$i]['qc_done_on_time'] = $qcDoneOnTime;
            $shipmentResult[$i]['shipment_score'] = $shipmentScore;
            $shipmentResult[$i]['max_shipment_score'] = $maxShipmentScore;
            if(!isset($attributes['shipment_date'])) {
                $attributes['shipment_date'] = '';
            }
            if(!isset($attributes['expiry_date'])) {
                $attributes['expiry_date'] = '';
            }
            $shipmentResult[$i]['documentation_score'] = $scoringService->calculateTbDocumentationScore(
                $res['shipment_date'], $attributes['expiry_date'], $res['shipment_receipt_date'],
                $res['supervisor_approval'], $res['participant_supervisor'], $res['lastdate_response']);
            $shipmentResult[$i]['submission_score_status'] = $scoringService->calculateSubmissionPassStatus(
                $shipmentScore, $shipmentResult[$i]['documentation_score'], $maxShipmentScore,
                $sampleStatuses);
            $shipmentResult[$i]['eval_comment'] = $res['evaluationComments'];
            $shipmentResult[$i]['optional_eval_comment'] = $res['optional_eval_comment'];
            $shipmentResult[$i]['corrective_actions'] = isset($attributes['corrective_actions']) ? $attributes['corrective_actions'] : array();
            $shipmentResult[$i]['responseResult'] = $toReturn;
            $shipmentResult[$i]['instrumentsUsed'] = $instrumentsUsed;
            $shipmentResult[$i]['cartridge_expired_on'] = $cartridgeExpiredOn;
            $shipmentResult[$i]['tests_done_on_expired_cartridges'] = "";
            if ($cartridgeExpiredOn) {
                if (count($testsDoneAfterCartridgeExpired) < $counter) {
                    $shipmentResult[$i]['tests_done_on_expired_cartridges'] = " The following samples were tested using expired cartridges:";
                    foreach ($testsDoneAfterCartridgeExpired as $testDoneAfterCartridgeExpired) {
                        $shipmentResult[$i]['tests_done_on_expired_cartridges'] .= "<br/>".$testDoneAfterCartridgeExpired["sample_label"] . " was tested on " . Pt_Commons_General::dbDateToString($testDoneAfterCartridgeExpired['date_tested']);
                    }
                } else if (count($testsDoneAfterCartridgeExpired) > 0) {
                    $shipmentResult[$i]['tests_done_on_expired_cartridges'] = " This panel was tested on ".Pt_Commons_General::dbDateToString($testsDoneAfterCartridgeExpired[0]['date_tested']);
                }
            }
            $shipmentResult[$i]['instrument_requires_calibration'] = $instrumentRequiresCalibration;
            $shipmentResult[$i]['tests_done_after_calibration_due'] = "";
            if ($instrumentRequiresCalibration) {
                if (count($testsDoneAfterCalibrationDue) < $counter) {
                    $shipmentResult[$i]['tests_done_after_calibration_due'] = " The following samples were tested on instruments that were due for calibration or had no calibration information:";
                    foreach ($testsDoneAfterCalibrationDue as $testDoneAfterCalibrationDue) {
                        if (!isset($testDoneAfterCalibrationDue['instrument_last_calibrated_on'])) {
                            $shipmentResult[$i]['tests_done_after_calibration_due'] .= "<br/>".$testDoneAfterCalibrationDue["sample_label"] .
                                " was tested on " . Pt_Commons_General::dbDateToString($testDoneAfterCalibrationDue['date_tested']) .
                                " using an instrument that has no calibration date specified.";
                        } else {
                            $shipmentResult[$i]['tests_done_after_calibration_due'] .= "<br/>".$testDoneAfterCalibrationDue["sample_label"] .
                                " was tested on " . Pt_Commons_General::dbDateToString($testDoneAfterCalibrationDue['date_tested']) .
                                " using ".$testDoneAfterCalibrationDue['instrument_serial'] .
                                " which was last calibrated on " .
                                Pt_Commons_General::dbDateToString($testDoneAfterCalibrationDue['instrument_last_calibrated_on']) . ".";
                        }
                    }
                } else {
                    $shipmentResult[$i]['tests_done_after_calibration_due'] = " All samples in this submission were tested on instruments that were due for calibration or had no calibration information.";
                }
                $shipmentResult[$i]['tests_done_after_calibration_due'] .= "<br/>";
            }
            if (isset($res['is_pt_test_not_performed']) && $res['is_pt_test_not_performed'] == 'yes') {
                $ptNotTestedComment = null;
                if (isset($res['not_tested_reason']) && $res['not_tested_reason'] != '') {
                    $ptNotTestedComment = $res['not_tested_reason'];
                } else if (isset($res['pt_test_not_performed_comments']) && $res['pt_test_not_performed_comments'] != '') {
                    $ptNotTestedComment = $res['pt_test_not_performed_comments'];
                }
                $shipmentResult[$i]['ptNotTestedComment'] = 'Xpert testing site was unable to participate in '.$shipmentResult[0]['shipment_code'];
                if (isset($ptNotTestedComment)) {
                    $shipmentResult[$i]['ptNotTestedComment'] .= ' due to the following reason(s): '.$ptNotTestedComment.'.';
                }
            }
            $shipmentResult[$i]['assay_name'] = "Unspecified";
            if (isset($attributes['assay']) && $attributes['assay'] != '') {
                $shipmentResult[$i]['assay_name'] = $assays[$attributes['assay']];
	        }
            $i++;
            $db->update('shipment_participant_map', array('report_generated' => 'yes'), "map_id=" . $res['map_id']);
            $db->update('shipment', array('status' => 'evaluated'), "shipment_id=" . $shipmentId);
        }

        $result = array(
            'shipment' => $shipmentResult,
            'dmResult' => $mapRes,
            'previousSixShipments' => $previousSixShipments);
        return $result;
    }

    public function getSummaryReportsInPdf($shipmentId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()
            ->from(array('s' => 'shipment'), array(
                's.shipment_id',
                's.shipment_code',
                's.scheme_type',
                's.shipment_date',
                's.lastdate_response',
                's.max_score',
                's.shipment_comment'))
            ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type', array('sl.scheme_name'))
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array('d.distribution_code'))
            ->where("s.shipment_id = ?", $shipmentId);
        $shipmentResult = $db->fetchRow($sql);

        $aggregates = array();
        $mtbRifReportSummary = array();
        $mtbRifUltraReportSummary = array();
        $referenceResults = array();
        if ($shipmentResult != "") {
            $db->update('shipment', array('status' => 'evaluated'), "shipment_id = " . $shipmentId);
            $aggregatesQuery = $db->select()->from(array('spm' => 'shipment_participant_map'), array(
                'enrolled' => 'COUNT(DISTINCT map_id)',
                'participated' => "SUM(CASE WHEN SUBSTR(spm.evaluation_status, 3, 1) = '1' AND IFNULL(is_pt_test_not_performed, 'no') <> 'yes' AND IFNULL(is_excluded, 'no') = 'no' THEN 1 ELSE 0 END)",
                'scored_100_percent' => 'SUM(CASE WHEN IFNULL(spm.shipment_score, 0) + IFNULL(spm.documentation_score, 0) = 100 THEN 1 ELSE 0 END)'
            ))
                ->joinLeft(array('a' => 'r_tb_assay'),
                    'a.id = CASE WHEN JSON_VALID(spm.attributes) = 1 THEN JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, "$.assay")) ELSE 0 END', array(
                        'mtb_rif' => "SUM(CASE WHEN SUBSTR(spm.evaluation_status, 3, 1) = '1' AND IFNULL(is_pt_test_not_performed, 'no') <> 'yes' AND IFNULL(is_excluded, 'no') = 'no' AND a.short_name = 'MTB/RIF' THEN 1 ELSE 0 END)",
                        'mtb_rif_ultra' => "SUM(CASE WHEN SUBSTR(spm.evaluation_status, 3, 1) = '1' AND IFNULL(is_pt_test_not_performed, 'no') <> 'yes' AND IFNULL(is_excluded, 'no') = 'no' AND a.short_name = 'MTB Ultra' THEN 1 ELSE 0 END)"
                    ))
                ->where("spm.shipment_id = ?", $shipmentId);
            $aggregates = $db->fetchRow($aggregatesQuery);

            $mtbRifSummaryQuery = $db->select()->from(array('spm' => 'shipment_participant_map'), array())
                ->join(array('ref' => 'reference_result_tb'),
                    'ref.shipment_id = spm.shipment_id', array(
                        'sample_label' => 'ref.sample_label',
                        'ref_mtb_rif_is_excluded' => 'ref.mtb_rif_is_excluded',
                        'ref_mtb_rif_is_exempt' => 'ref.mtb_rif_is_exempt',
                        'ref_ultra_is_excluded' => 'ref.ultra_is_excluded',
                        'ref_ultra_is_exempt' => 'ref.ultra_is_exempt',
                        'ref_expected_ct' => new Zend_Db_Expr("CASE WHEN ref.mtb_rif_mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow') THEN ref.mtb_rif_probe_a ELSE 0 END")
                    ))
                ->joinLeft(array('res' => 'response_result_tb'), 'res.shipment_map_id = spm.map_id AND res.sample_id = ref.sample_id',
                    array('mtb_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace') THEN 1 ELSE 0 END)"),
                        'mtb_not_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` = 'notDetected' THEN 1 ELSE 0 END)"),
                        'mtb_uninterpretable' => new Zend_Db_Expr("SUM(CASE WHEN IFNULL(`res`.`mtb_detected`, '') IN ('noResult', 'invalid', 'error') THEN 1 ELSE 0 END)"),
                        'rif_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` IN ('detected', 'high', 'medium', 'low', 'veryLow') AND `res`.`rif_resistance` = 'detected' THEN 1 ELSE 0 END)"),
                        'rif_not_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` IN ('notDetected', 'detected', 'high', 'medium', 'low', 'veryLow') AND IFNULL(`res`.`rif_resistance`, '') IN ('notDetected', 'na', '') THEN 1 ELSE 0 END)"),
                        'rif_indeterminate' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`rif_resistance` = 'indeterminate' THEN 1 ELSE 0 END)"),
                        'rif_uninterpretable' => new Zend_Db_Expr("SUM(CASE WHEN IFNULL(`res`.`mtb_detected`, '') IN ('noResult', 'invalid', 'error', '') THEN 1 ELSE 0 END)"),
                        'no_of_responses' => new Zend_Db_Expr("SUM(CASE WHEN IFNULL(`res`.`mtb_detected`, '') = '' THEN 0 ELSE 1 END)"),
                        'average_ct' => new Zend_Db_Expr('SUM(CASE WHEN IFNULL(`res`.`calculated_score`, \'pass\') NOT IN (\'fail\', \'noresult\') THEN IFNULL(CASE WHEN `res`.`probe_6` = \'\' THEN 0 ELSE `res`.`probe_6` END, 0) ELSE 0 END) / SUM(CASE WHEN IFNULL(CASE WHEN `res`.`probe_6` = \'\' THEN 0 ELSE `res`.`probe_6` END, 0) = 0 OR IFNULL(`res`.`calculated_score`, \'pass\') IN (\'fail\', \'noresult\') THEN 0 ELSE 1 END)')))
                ->joinLeft(array('a' => 'r_tb_assay'),
                    'a.id = CASE WHEN JSON_VALID(spm.attributes) = 1 THEN JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, "$.assay")) ELSE 0 END')
                ->where("spm.shipment_id = ?", $shipmentId)
                ->where("substring(spm.evaluation_status,4,1) != '0'")
                ->where("spm.is_excluded = 'no'")
                ->where("IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'")
                ->where("a.short_name = 'MTB/RIF'")
                ->group("ref.sample_id")
                ->order("ref.sample_id");
            $mtbRifReportSummary = $db->fetchAll($mtbRifSummaryQuery);
            $mtbRifUltraSummaryQuery = $db->select()->from(array('spm' => 'shipment_participant_map'), array())
                ->join(array('ref' => 'reference_result_tb'),
                    'ref.shipment_id = spm.shipment_id', array(
                        'sample_label' => 'ref.sample_label',
                        'ref_mtb_rif_is_excluded' => 'ref.mtb_rif_is_excluded',
                        'ref_mtb_rif_is_exempt' => 'ref.mtb_rif_is_exempt',
                        'ref_ultra_is_excluded' => 'ref.ultra_is_excluded',
                        'ref_ultra_is_exempt' => 'ref.ultra_is_exempt',
                        'ref_expected_ct' => new Zend_Db_Expr("CASE WHEN ref.ultra_mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace') THEN LEAST(ref.ultra_probe_rpo_b1, ref.ultra_probe_rpo_b2, ref.ultra_probe_rpo_b3, ref.ultra_probe_rpo_b4) ELSE 0 END")
                    ))
                ->joinLeft(array('res' => 'response_result_tb'), 'res.shipment_map_id = spm.map_id AND res.sample_id = ref.sample_id',
                    array('mtb_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace') THEN 1 ELSE 0 END)"),
                        'mtb_not_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` = 'notDetected' THEN 1 ELSE 0 END)"),
                        'mtb_uninterpretable' => new Zend_Db_Expr("SUM(CASE WHEN IFNULL(`res`.`mtb_detected`, '') IN ('noResult', 'invalid', 'error') THEN 1 ELSE 0 END)"),
                        'rif_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` IN ('detected', 'high', 'medium', 'low', 'veryLow') AND `res`.`rif_resistance` = 'detected' THEN 1 ELSE 0 END)"),
                        'rif_not_detected' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`mtb_detected` IN ('notDetected', 'detected', 'high', 'medium', 'low', 'veryLow') AND IFNULL(`res`.`rif_resistance`, '') IN ('notDetected', 'na', '') THEN 1 ELSE 0 END)"),
                        'rif_indeterminate' => new Zend_Db_Expr("SUM(CASE WHEN `res`.`rif_resistance` = 'indeterminate' OR (`res`.`mtb_detected` = 'trace' AND `res`.`rif_resistance` = 'na') THEN 1 ELSE 0 END)"),
                        'rif_uninterpretable' => new Zend_Db_Expr("SUM(CASE WHEN IFNULL(`res`.`mtb_detected`, '') IN ('noResult', 'invalid', 'error', '') THEN 1 ELSE 0 END)"),
                        'no_of_responses' => new Zend_Db_Expr("SUM(CASE WHEN IFNULL(`res`.`mtb_detected`, '') = '' THEN 0 ELSE 1 END)"),
                        'average_ct' => new Zend_Db_Expr('SUM(CASE WHEN IFNULL(`res`.`calculated_score`, \'pass\') NOT IN (\'fail\', \'noresult\') THEN  LEAST(IFNULL(`res`.`probe_3`, 0), IFNULL(`res`.`probe_4`, 0), IFNULL(`res`.`probe_5`, 0), IFNULL(`res`.`probe_6`, 0)) ELSE 0 END) / SUM(CASE WHEN LEAST(IFNULL(CASE WHEN `res`.`probe_3` = \'\' THEN 0 ELSE `res`.`probe_3` END, 0), IFNULL(CASE WHEN `res`.`probe_4` = \'\' THEN 0 ELSE `res`.`probe_4` END, 0), IFNULL(CASE WHEN `res`.`probe_5` = \'\' THEN 0 ELSE `res`.`probe_5` END, 0), IFNULL(CASE WHEN `res`.`probe_6` = \'\' THEN 0 ELSE `res`.`probe_6` END, 0)) = 0 OR IFNULL(`res`.`calculated_score`, \'pass\') IN (\'fail\', \'noresult\') THEN 0 ELSE 1 END)')))
                ->joinLeft(array('a' => 'r_tb_assay'),
                    'a.id = CASE WHEN JSON_VALID(spm.attributes) = 1 THEN JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, "$.assay")) ELSE 0 END')
                ->where("spm.shipment_id = ?", $shipmentId)
                ->where("substring(spm.evaluation_status,4,1) != '0'")
                ->where("spm.is_excluded = 'no'")
                ->where("IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'")
                ->where("a.short_name = 'MTB Ultra'")
                ->group("ref.sample_id")
                ->order("ref.sample_id");
            $mtbRifUltraReportSummary = $db->fetchAll($mtbRifUltraSummaryQuery);
            $referenceResultsSql = $db->select()->from(array('ref' => 'reference_result_tb'), array(
                'ref.sample_label',
                'ref.sample_content',
                'expected_tb_detection_result' => new Zend_Db_Expr("CASE WHEN ref.mtb_rif_mtb_detected IN ('high', 'medium', 'low', 'veryLow', 'trace') THEN 'Detected at any semi-quantitative level' ELSE 'Not Detected' END"),
                'expected_rif_detection_result' => new Zend_Db_Expr("CASE WHEN ref.mtb_rif_rif_resistance = 'detected' THEN 'Detected' WHEN ref.mtb_rif_rif_resistance <> 'detected' AND ref.mtb_rif_mtb_detected IN ('high', 'medium', 'low', 'veryLow', 'trace') THEN 'Not Detected' ELSE 'N/A' END")
            ))
                ->where("ref.shipment_id = ?", $shipmentId)
                ->order("ref.sample_id");
            $referenceResults = $db->fetchAll($referenceResultsSql);
        }

		$result = array(
		    'shipment' => $shipmentResult,
            'mtbRifReportSummary' => $mtbRifReportSummary,
            'mtbRifUltraReportSummary' => $mtbRifUltraReportSummary,
            'referenceResults' => $referenceResults,
            'aggregates' => $aggregates
        );
        return $result;
    }

    public function getResponseReports($shipmentId) {
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('p' => 'participant'), array())
            ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
            ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('shipment_code'))
            ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.participant_id=p.participant_id', array(
                'others' => new Zend_Db_Expr("SUM(sp.shipment_test_date IS NULL)"),
                'excluded' => new Zend_Db_Expr("SUM(if(sp.is_excluded = 'yes', 1, 0))"),
                'number_failed' => new Zend_Db_Expr("SUM(sp.final_result = 2 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"),
                'number_passed' => new Zend_Db_Expr("SUM(sp.final_result = 1 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"),
                'number_late' => new Zend_Db_Expr("SUM(sp.shipment_test_date > s.lastdate_response AND sp.is_excluded != 'yes')"), 'map_id'))
            ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array())
            ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array())
            ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id', array())
            ->where("s.shipment_id = ?", $shipmentId)
            ->group('s.shipment_id');

        return $dbAdapter->fetchRow($sQuery);
    }

	public function evaluateDtsViralLoad($shipmentResult,$shipmentId,$reEvaluate) {
		$counter = 0;
		$maxScore = 0;
		$finalResult = null;
		$schemeService = new Application_Service_Schemes();
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$file = APPLICATION_PATH . DIRECTORY_SEPARATOR . "configs" . DIRECTORY_SEPARATOR . "config.ini";
		$config = new Zend_Config_Ini($file, APPLICATION_ENV);
		$passPercentage = $config->evaluation->vl->passPercentage;
		$vlRange = $schemeService->getVlRange($shipmentId);
		if ($reEvaluate || $vlRange == null || $vlRange == "" || count($vlRange) == 0) {
			$schemeService->setVlRange($shipmentId);
			$vlRange = $schemeService->getVlRange($shipmentId);
		}
		foreach ($shipmentResult as $shipment) {
			$createdOnUser = explode(" ", $shipment['shipment_test_report_date']);
            $createdOn = Application_Service_Common::ParseDateISO8601OrYYYYMMDDOrMin($createdOnUser[0]);
            $lastDate = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($shipment['lastdate_response']);
			if ($createdOn->compare($lastDate,Zend_date::DATES) <= 0) {
				$results = $schemeService->getVlSamples($shipmentId, $shipment['participant_id']);
				$totalScore = 0;
				$maxScore = 0;
				$mandatoryResult = "";
				$failureReason = array();
				foreach ($results as $result) {
					if($result['control'] == 1) continue;
					$calcResult = "";
					$responseAssay = json_decode($result['attributes'], true);
					$responseAssay = isset($responseAssay['vl_assay']) ? $responseAssay['vl_assay'] : "";
					if (isset($vlRange[$responseAssay])) {
						// matching reported and low/high limits
						if (isset($result['reported_viral_load']) && $result['reported_viral_load'] != null) {
							if (isset($vlRange[$responseAssay][$result['sample_id']]) &&
                                $vlRange[$responseAssay][$result['sample_id']]['low'] <= $result['reported_viral_load'] &&
                                $vlRange[$responseAssay][$result['sample_id']]['high'] >= $result['reported_viral_load']) {
							    $totalScore += $result['sample_score'];
								$calcResult = "pass";
							} else {
								if ($result['sample_score'] > 0) {
									$failureReason[]['warning'] = "Sample <strong>" . $result['sample_label'] . "</strong> was reported wrongly";
								}
								$calcResult = "fail";
							}
						}
					} else {
						$totalScore = "N/A";
						$calcResult = "excluded";
					}
					$maxScore += $result['sample_score'];
					$db->update('response_result_vl', array('calculated_score' => $calcResult),
                        "shipment_map_id = " . $result['map_id'] . " and sample_id = " . $result['sample_id']);
				}
                // if we are excluding this result, then let us not give pass/fail
                if ($shipment['is_excluded'] == 'yes') {
                    $totalScore = 0;
                    $failureReason = array();
                    $shipmentResult[$counter]['shipment_score'] = $responseScore = 0;
                    $shipmentResult[$counter]['documentation_score'] = 0;
                    $shipmentResult[$counter]['display_result'] = 'Excluded';
                    $shipmentResult[$counter]['is_followup'] = 'yes';
                    $failureReason[] = array('warning' => 'Excluded from Evaluation');
                    $finalResult = 3;
                    $shipmentResult[$counter]['failure_reason'] = $failureReason = json_encode($failureReason);
                } else {
                    $shipment['is_excluded'] = 'no';
                    // checking if total score and maximum scores are the same
                    if ($totalScore == 'N/A') {
                        $failureReason[]['warning'] = "Could not determine score. Not enough responses found in the chosen VL Assay.";
                        $scoreResult = 'Not Evaluated';
                    } else if ($totalScore != $maxScore) {
                        $scoreResult = 'Fail';
                        if ($maxScore != 0) {
                            $totalScore = ($totalScore/$maxScore)*100;
                        }
                        $failureReason[]['warning'] = "Participant did not meet the score criteria (Participant Score - <strong>$totalScore</strong> and Required Score - <strong>$passPercentage</strong>)";
                    } else {
                        if($maxScore != 0) {
                            $totalScore = ($totalScore/$maxScore)*100;
                        }
                        $scoreResult = 'Pass';
                    }
                    if ($scoreResult == 'Not Evaluated') {
                        $finalResult = 4;
                    }
                    else if ($scoreResult == 'Fail' || $mandatoryResult == 'Fail') {
                        $finalResult = 2;
                    } else {
                        $finalResult = 1;
                    }
                    $shipmentResult[$counter]['shipment_score'] = $totalScore;
                    $shipmentResult[$counter]['max_score'] = $passPercentage;
                    $fRes = $db->fetchCol($db->select()->from('r_results', array('result_name'))
                        ->where('result_id = ' . $finalResult));
                    $shipmentResult[$counter]['display_result'] = $fRes[0];
                    $shipmentResult[$counter]['failure_reason'] = $failureReason = json_encode($failureReason);
                    // let us update the total score in DB
                    if ($totalScore == 'N/A') {
                        $totalScore = 0;
                    }
                }
				$db->update('shipment_participant_map', array(
				    'shipment_score' => $totalScore,
                    'final_result' => $finalResult,
                    'failure_reason' => $failureReason),
                    "map_id = " . $shipment['map_id']);
			} else {
				$failureReason = array('warning' => "Response was submitted after the last response date.");
				$db->update('shipment_participant_map',
                    array('failure_reason' => json_encode($failureReason)),
                    "map_id = " . $shipment['map_id']);
			}
			$counter++;
		}
		$db->update('shipment', array('max_score' => $maxScore), "shipment_id = " . $shipmentId);
		return $shipmentResult;
	}

    public function evaluateTb($shipmentResult,$shipmentId) {
        $counter = 0;
        $finalResult = null;
        $schemeService = new Application_Service_Schemes();
        $scoringService = new Application_Service_EvaluationScoring();
        $maxTotalScore = 0;
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $assays = array();
        $assayRecords = $db->fetchAll($db->select()->from('r_tb_assay'));
        foreach ($assayRecords as $assayRecord) {
            $assays[$assayRecord['id']] = $assayRecord['short_name'];
        }
        foreach ($shipmentResult as $shipment) {
            $createdOnUser = explode(" ", $shipment['shipment_test_report_date']);
            $results = $schemeService->getTbSamples($shipmentId, $shipment['participant_id']);
            $failureReason = array();
            $shipmentScore = 0;
            $samplePassStatuses = array();
            $maxShipmentScore = 0;
            $hasBlankResult = false;
            $createdOn = Application_Service_Common::ParseDateISO8601OrYYYYMMDDOrMin($createdOnUser[0]);
            $lastDate = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($shipment['lastdate_response']);
            if ($createdOn->compare($lastDate,Zend_date::DATES) <= 0) {
                $failureReason['warning'] = "Response was submitted after the last response date.";
            }
            $attributes = json_decode($shipment['attributes'], true);
            $assayName = "Unspecified";
            if (isset($attributes['assay']) && $attributes['assay'] != '' && array_key_exists($attributes['assay'], $assays)) {
                $assayName = $assays[$attributes['assay']];
            }
            foreach ($results as $result) {
                $calculatedScorePassStatus = $scoringService->calculateTbSamplePassStatus($result[$assayName == 'MTB Ultra' ? 'ref_ultra_mtb_detected' : 'ref_mtb_rif_mtb_detected'],
                    $result['res_mtb_detected'], $result[$assayName == 'MTB Ultra' ? 'ref_ultra_rif_resistance' : 'ref_mtb_rif_rif_resistance'], $result['res_rif_resistance'],
                    $result['res_probe_1'], $result['res_probe_2'], $result['res_probe_3'], $result['res_probe_4'],
                    $result['res_probe_5'], $result['res_probe_6'], $result[$assayName == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'],
                    $result[$assayName == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt']);

                $shipmentScore += $scoringService->calculateTbSampleScore(
                    $calculatedScorePassStatus,
                    $result['ref_sample_score']);
                $db->update('response_result_tb', array('calculated_score' => $calculatedScorePassStatus),
                    "shipment_map_id = " . $result['map_id'] . " and sample_id = " . $result['sample_id']);
                if ($result[$assayName == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'] == 'no' || $result[$assayName == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt'] == 'yes') {
                    $maxShipmentScore += $result['ref_sample_score'];
                }
                array_push($samplePassStatuses, $calculatedScorePassStatus);
                $hasBlankResult = $hasBlankResult || !isset($result['res_mtb_detected']);
            }
            $maxTotalScore = $maxShipmentScore + Application_Service_EvaluationScoring::MAX_DOCUMENTATION_SCORE;
            // if we are excluding this result, then let us not give pass/fail
            $documentationScore = 0;
            if ($shipment['is_excluded'] == 'yes') {
                $shipmentScore = 0;
                $shipmentResult[$counter]['shipment_score'] = $shipmentScore;
                $shipmentResult[$counter]['documentation_score'] = 0;
                $shipmentResult[$counter]['display_result'] = 'Excluded';
                $failureReason = array('warning' => 'Excluded from Evaluation');
                $finalResult = 3;
                $shipmentResult[$counter]['failure_reason'] = $failureReason = json_encode($failureReason);
            } else {
                $shipment['is_excluded'] = 'no';
                // checking if total score and maximum scores are the same
                if ($hasBlankResult) {
                    $failureReason['warning'] = "Could not determine score. Not enough responses found in the submission.";
                    $scoreResult = 'Not Evaluated';
                } else {
                    $attributes = json_decode($shipment['attributes'],true);
                    $shipmentData['shipment_score'] = $shipmentScore;
                    if(!isset($attributes['shipment_date'])) {
                        $attributes['shipment_date'] = '';
                    }
                    if(!isset($attributes['expiry_date'])) {
                        $attributes['expiry_date'] = '';
                    }
                    $documentationScore = $scoringService->calculateTbDocumentationScore($shipment['shipment_date'],
                        $attributes['expiry_date'], $shipment['shipment_receipt_date'], $shipment['supervisor_approval'],
                        $shipment['participant_supervisor'], $shipment['lastdate_response']);
                    $scoreResult = ucfirst($scoringService->calculateSubmissionPassStatus(
                        $shipmentScore, $documentationScore, $maxShipmentScore,
                        $samplePassStatuses));
                    if ($scoreResult == 'Fail') {
                        $totalScore = $shipmentScore + $documentationScore;
                        $failureReason['warning'] = "Participant did not meet the score criteria (Participant Score - <strong>$totalScore</strong> out of <strong>$maxTotalScore</strong>)";
                    }
                }
                if ($scoreResult == 'Not Evaluated') {
                    $finalResult = 4;
                } else if ($scoreResult == 'Fail') {
                    $finalResult = 2;
                } else {
                    $finalResult = 1;
                }
                $shipmentResult[$counter]['shipment_score'] = $shipmentScore;
                $shipmentResult[$counter]['max_score'] = $maxTotalScore;
                $fRes = $db->fetchCol($db->select()->from('r_results', array('result_name'))->where('result_id = ' . $finalResult));
                $shipmentResult[$counter]['display_result'] = $fRes[0];
                $shipmentResult[$counter]['failure_reason'] = $failureReason = json_encode($failureReason);
            }
            $db->update('shipment_participant_map', array(
                'shipment_score' => $shipmentScore,
                'documentation_score' => $documentationScore,
                'final_result' => $finalResult,
                'failure_reason' => $failureReason
            ), "map_id = " . $shipment['map_id']);
            $counter++;
        }
        $db->update('shipment', array('max_score' => $maxTotalScore), "shipment_id = " . $shipmentId);
        return $shipmentResult;
    }

    public function evaluateEid($shipmentResult,$shipmentId) {
		$counter = 0;
		$maxScore = 0;
		$finalResult = null;
		$schemeService = new Application_Service_Schemes();
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();
		foreach ($shipmentResult as $shipment) {
		    $createdOnUser = explode(" ", $shipment['shipment_test_report_date']);
            $createdOn = Application_Service_Common::ParseDateISO8601OrYYYYMMDDOrMin($createdOnUser[0]);
            $lastDate = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($shipment['lastdate_response']);
            if ($createdOn->compare($lastDate) <= 0) {
                $results = $schemeService->getEidSamples($shipmentId, $shipment['participant_id']);
                $totalScore = 0;
                $maxScore = 0;
                $mandatoryResult = "";
                $failureReason = array();
                foreach ($results as $result) {
                    // matching reported and reference results
                    if (isset($result['reported_result']) && $result['reported_result'] != null) {
                        if ($result['reference_result'] == $result['reported_result']) {
						    if(0 == $result['control']) {
							    $totalScore += $result['sample_score'];
							}
                        } else {
                            if ($result['sample_score'] > 0) {
                                $failureReason[]['warning'] = "Control/Sample <strong>" . $result['sample_label'] . "</strong> was reported wrongly";
                            }
                        }
                    }
					if(0 == $result['control']) {
					    $maxScore += $result['sample_score'];
					}
                }
                $totalScore = ($totalScore/$maxScore)*100;
			    $maxScore = 100;

			    // if we are excluding this result, then let us not give pass/fail
				if ($shipment['is_excluded'] == 'yes') {
				    $totalScore = 0;
					$shipmentResult[$counter]['shipment_score'] = $responseScore = 0;
					$shipmentResult[$counter]['documentation_score'] = 0;
					$shipmentResult[$counter]['display_result'] = '';
					$shipmentResult[$counter]['is_followup'] = 'yes';
					$failureReason[] = array('warning' => 'Excluded from Evaluation');
					$finalResult = 3;
					$shipmentResult[$counter]['failure_reason'] = $failureReason = json_encode($failureReason);
				} else {
				    $shipment['is_excluded'] = 'no';
					// checking if total score and maximum scores are the same
					if ($totalScore != $maxScore) {
					    $scoreResult = 'Fail';
						$failureReason[]['warning'] = "Participant did not meet the score criteria (Participant Score - <strong>$totalScore</strong> and Required Score - <strong>$maxScore</strong>)";
					} else {
					    $scoreResult = 'Pass';
					}

					// if any of the results have failed, then the final result is fail
					if ($scoreResult == 'Fail' || $mandatoryResult == 'Fail') {
					    $finalResult = 2;
					} else {
					    $finalResult = 1;
					}
					$shipmentResult[$counter]['shipment_score'] = $totalScore = round($totalScore,2);
					$shipmentResult[$counter]['max_score'] = 100; //$maxScore;
					$shipmentResult[$counter]['final_result'] = $finalResult;

					$fRes = $db->fetchCol($db->select()->from('r_results', array('result_name'))->where('result_id = ' . $finalResult));

					$shipmentResult[$counter]['display_result'] = $fRes[0];
					$shipmentResult[$counter]['failure_reason'] = $failureReason = json_encode($failureReason);
				}
                // let us update the total score in DB
                $db->update('shipment_participant_map', array(
                    'shipment_score' => $totalScore,
                    'final_result' => $finalResult,
                    'failure_reason' => $failureReason),
                    "map_id = " . $shipment['map_id']);
            } else {
                $failureReason = array('warning' => "Response was submitted after the last response date.");
                $db->update('shipment_participant_map',
                    array('failure_reason' => json_encode($failureReason)),
                    "map_id = " . $shipment['map_id']);
            }
            $counter++;
		}
        $db->update('shipment', array('max_score' => $maxScore), "shipment_id = " . $shipmentId);
        return $shipmentResult;
    }

	public function evaluateDtsHivSerology($shipmentResult,$shipmentId) {
		$counter = 0;
		$maxScore = 0;
		$scoreHolder = array();
		$schemeService = new Application_Service_Schemes();
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();

		$file = APPLICATION_PATH . DIRECTORY_SEPARATOR . "configs" . DIRECTORY_SEPARATOR . "config.ini";
		$config = new Zend_Config_Ini($file, APPLICATION_ENV);
		$correctiveActions = $schemeService->getDtsCorrectiveActions();
		$recommendedTestkits = $schemeService->getRecommededDtsTestkit();

		foreach ($shipmentResult as $shipment) {
			$createdOnUser = explode(" ", $shipment['created_on_user']);
            $createdOn = Application_Service_Common::ParseDateISO8601OrYYYYMMDDOrMin($createdOnUser[0]);
			$results = $schemeService->getDtsSamples($shipmentId, $shipment['participant_id']);
			$totalScore = 0;
			$maxScore = 0;
			$mandatoryResult = "";
			$testKitExpiryResult = "";
			$lotResult = "";
			$failureReason = array();
			$correctiveActionList = array();
			$algoResult = "";
			$lastDateResult = "";
			$controlTesKitFail = "";
			$attributes = json_decode($shipment['attributes'], true);
			//Response was submitted after the last response date.
            $lastDate = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($shipment['lastdate_response']);
			if ($createdOn->compare($lastDate,Zend_date::DATES) > 0) {
				$failureReason[] = array('warning' => "Response was submitted after the last response date.",
					'correctiveAction' => $correctiveActions[1]);
				$correctiveActionList[] = 1;
			}

            $testedOn = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($results[0]['shipment_test_date']);

			// Getting the Test Date string to show in Corrective Actions and other sentences
			$testDate = $testedOn->toString('dd-MMM-YYYY');

			// Getting test kit expiry dates as reported
			$expDate1 = "";
            $expDate1 = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($results[0]['exp_date_1']);
            $expDate2 = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($results[0]['exp_date_2']);
            $expDate3 = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($results[0]['exp_date_3']);

			// Getting Test Kit Names
			$testKitDb = new Application_Model_DbTable_TestkitnameDts();
			$testKit1 = "";

			$testKitName = $testKitDb->getTestKitNameById($results[0]['test_kit_name_1']);
			if (isset($testKitName[0])) {
				$testKit1 = $testKitName[0];
			}

			$testKit2 = "";
			if (trim($results[0]['test_kit_name_2']) != "") {
				$testKitName = $testKitDb->getTestKitNameById($results[0]['test_kit_name_2']);
				if (isset($testKitName[0])) {
					$testKit2 = $testKitName[0];
				}
			}
			$testKit3 = "";
			if (trim($results[0]['test_kit_name_3']) != "") {
				$testKitName = $testKitDb->getTestKitNameById($results[0]['test_kit_name_3']);
				if (isset($testKitName[0])) {
					$testKit3 = $testKitName[0];
				}
			}

			// T.7 Checking for Expired Test Kits
			if ($testKit1 != "") {
				if ($expDate1 != "") {
					if ($testedOn->isLater($expDate1)) {
						$difference = $testedOn->sub($expDate1);
						$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
						$measure->convertTo(Zend_Measure_Time::DAY);
						$failureReason[] = array(
						    'warning' => "Test Kit 1 (<strong>" . $testKit1 . "</strong>) expired " . round($measure->getValue()) . " days before the test date " . $testDate,
							'correctiveAction' => $correctiveActions[5]);
						$correctiveActionList[] = 5;
						$tk1Expired = true;
					} else {
						$tk1Expired = false;
					}
				} else {
					$failureReason[] = array(
					    'warning' => "Result not evaluated – Test kit 1 expiry date is not reported with PT response.",
						'correctiveAction' => $correctiveActions[6]);
					$correctiveActionList[] = 6;
					$shipment['is_excluded'] = 'yes';
				}

				if (isset($recommendedTestkits[1]) && count($recommendedTestkits[1]) > 0) {
					if (!in_array($results[0]['test_kit_name_1'],$recommendedTestkits[1])) {
						$tk1RecommendedUsed = false;
						$failureReason[] = array(
						    'warning' => "For Test 1, testing is not performed with country approved test kit.",
							'correctiveAction' => $correctiveActions[17]);
					} else {
						$tk1RecommendedUsed = true;
					}
				}
			}

			if ($testKit2 != "") {
				if ($expDate2 != "") {
					if ($testedOn->isLater($expDate2)) {
						$difference = $testedOn->sub($expDate2);
						$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
						$measure->convertTo(Zend_Measure_Time::DAY);
						$failureReason[] = array(
						    'warning' => "Test Kit 2 (<strong>" . $testKit2 . "</strong>) expired " . round($measure->getValue()) . " days before the test date " . $testDate,
							'correctiveAction' => $correctiveActions[5]);
						$correctiveActionList[] = 5;
						$tk2Expired = true;
					} else {
						$tk2Expired = false;
					}
				} else {
					$failureReason[] = array(
					    'warning' => "Result not evaluated – Test kit 2 expiry date is not reported with PT response.",
						'correctiveAction' => $correctiveActions[6]);
					$correctiveActionList[] = 6;
					$shipment['is_excluded'] = 'yes';
				}

				if (isset($recommendedTestkits[2]) && count($recommendedTestkits[2]) > 0) {
					if (!in_array($results[0]['test_kit_name_2'], $recommendedTestkits[2])) {
						$tk2RecommendedUsed = false;
						$failureReason[] = array(
						    'warning' => "For Test 2, testing is not performed with country approved test kit.",
							'correctiveAction' => $correctiveActions[17]);
					} else {
						$tk2RecommendedUsed = true;
					}
				}
			}

			if ($testKit3 != "") {
				if ($expDate3 != "") {
					if ($testedOn->isLater($expDate3)) {
						$difference = $testedOn->sub($expDate3);
						$measure = new Zend_Measure_Time($difference->toValue(), Zend_Measure_Time::SECOND);
						$measure->convertTo(Zend_Measure_Time::DAY);
						$failureReason[] = array(
						    'warning' => "Test Kit 3 (<strong>" . $testKit3 . "</strong>) expired " . round($measure->getValue()) . " days before the test date " . $testDate,
							'correctiveAction' => $correctiveActions[5]);
						$correctiveActionList[] = 5;
						$tk3Expired = true;
					} else {
						$tk3Expired = false;
					}
				} else {
					$failureReason[] = array(
					    'warning' => "Result not evaluated – Test kit 3 expiry date is not reported with PT response.",
						'correctiveAction' => $correctiveActions[6]);
					$correctiveActionList[] = 6;
					$shipment['is_excluded'] = 'yes';
				}

				if (isset($recommendedTestkits[3]) && count($recommendedTestkits[3]) > 0) {
					if (!in_array($results[0]['test_kit_name_3'],$recommendedTestkits[3])) {
						$tk3RecommendedUsed = false;
						$failureReason[] = array(
						    'warning' => "For Test 3, testing is not performed with country approved test kit.",
							'correctiveAction' => $correctiveActions[17]);
					} else {
						$tk3RecommendedUsed = true;
					}
				}
			}
			//checking if testkits were repeated
			// T.9 Test kit repeated for confirmatory or tiebreaker test (T1/T2/T3).
			if (($testKit1 == "") && ($testKit2 == "") && ($testKit3 == "")) {
				$failureReason[] = array(
				    'warning' => "No Test Kit reported. Result not evaluated",
					'correctiveAction' => $correctiveActions[7]);
				$correctiveActionList[] = 7;
				$shipment['is_excluded'] = 'yes';
			} else if (($testKit1 != "") && ($testKit2 != "") && ($testKit3 != "") && ($testKit1 == $testKit2) &&
                ($testKit2 == $testKit3)) {
				//$testKitRepeatResult = 'Fail';
				$failureReason[] = array(
				    'warning' => "<strong>$testKit1</strong> repeated for all three Test Kits",
					'correctiveAction' => $correctiveActions[8]);
				$correctiveActionList[] = 8;
			} else {
				if (($testKit1 != "") && ($testKit2 != "") && ($testKit1 == $testKit2) && $testKit1 != "" && $testKit2 != "") {
					$failureReason[] = array(
					    'warning' => "<strong>$testKit1</strong> repeated as Test Kit 1 and Test Kit 2",
						'correctiveAction' => $correctiveActions[9]);
					$correctiveActionList[] = 9;
				}
				if (($testKit2 != "") && ($testKit3 != "") && ($testKit2 == $testKit3) && $testKit2 != "" && $testKit3 != "") {
					$failureReason[] = array(
					    'warning' => "<strong>$testKit2</strong> repeated as Test Kit 2 and Test Kit 3",
						'correctiveAction' => $correctiveActions[9]);
					$correctiveActionList[] = 9;
				}
				if (($testKit1 != "") && ($testKit3 != "") && ($testKit1 == $testKit3) && $testKit1 != "" && $testKit3 != "") {
					$failureReason[] = array(
					    'warning' => "<strong>$testKit1</strong> repeated as Test Kit 1 and Test Kit 3",
						'correctiveAction' => $correctiveActions[9]);
					$correctiveActionList[] = 9;
				}
			}

			// checking if all LOT details were entered
			// T.3 Ensure test kit lot number is reported for all performed tests.
			if ($testKit1 != "" &&
                (!isset($results[0]['lot_no_1']) || $results[0]['lot_no_1'] == "" || $results[0]['lot_no_1'] == null)) {
				if (isset($result['test_result_1']) && $result['test_result_1'] != "" && $result['test_result_1'] != null) {
					$lotResult = 'Fail';
					$failureReason[] = array(
					    'warning' => "Result not evaluated – Test Kit lot number 1 is not reported.",
						'correctiveAction' => $correctiveActions[10]);
					$correctiveActionList[] = 10;
					$shipment['is_excluded'] = 'yes';
				}
			}
			if ($testKit2 != "" &&
                (!isset($results[0]['lot_no_2']) || $results[0]['lot_no_2'] == "" || $results[0]['lot_no_2'] == null)) {
				if (isset($result['test_result_2']) && $result['test_result_2'] != "" && $result['test_result_2'] != null) {
					$lotResult = 'Fail';
					$failureReason[] = array(
					    'warning' => "Result not evaluated – Test Kit lot number 2 is not reported.",
						'correctiveAction' => $correctiveActions[10]);
					$correctiveActionList[] = 10;
					$shipment['is_excluded'] = 'yes';
				}
			}
			if ($testKit3 != "" &&
                (!isset($results[0]['lot_no_3']) || $results[0]['lot_no_3'] == "" || $results[0]['lot_no_3'] == null)) {
				if (isset($result['test_result_3']) && $result['test_result_3'] != "" && $result['test_result_3'] != null) {
					$lotResult = 'Fail';
					$failureReason[] = array(
					    'warning' => "Result not evaluated – Test Kit lot number 3 is not reported.",
						'correctiveAction' => $correctiveActions[10]);
					$correctiveActionList[] = 10;
					$shipment['is_excluded'] = 'yes';
				}
			}
			foreach ($results as $result) {
				//if Sample is not mandatory, we will skip the evaluation
				if (0 == $result['mandatory']) {
					$db->update('response_result_dts', array('calculated_score' => "N.A."),
                        "shipment_map_id = " . $result['map_id'] . " and sample_id = " . $result['sample_id']);
					continue;
				}

				// Checking algorithm Pass/Fail only if it is NOT a control.
				if (0 == $result['control']) {
					if ($result['test_result_1'] == 1) {
						$r1 = 'R';
					} else if ($result['test_result_1'] == 2) {
						$r1 = 'NR';
					} else if ($result['test_result_1'] == 3) {
						$r1 = 'I';
					} else {
						$r1 = '-';
					}
					if ($result['test_result_2'] == 1) {
						$r2 = 'R';
					} else if ($result['test_result_2'] == 2) {
						$r2 = 'NR';
					} else if ($result['test_result_2'] == 3) {
						$r2 = 'I';
					} else {
						$r2 = '-';
					}
					if (isset($config->evaluation->dts->dtsOptionalTest3) &&
                        $config->evaluation->dts->dtsOptionalTest3 == 'yes') {
						$r3 = 'X';
					} else {
						if ($result['test_result_3'] == 1) {
							$r3 = 'R';
						} else if ($result['test_result_3'] == 2) {
							$r3 = 'NR';
						} else if ($result['test_result_3'] == 3) {
							$r3 = 'I';
						} else {
							$r3 = '-';
						}
					}

					if ($attributes['algorithm'] == 'serial') {
						if ($r1 == 'NR') {
							if (($r2 == '-') && ($r3 == '-' || $r3 == 'X')) {
								$algoResult = 'Pass';
							} else {
								$algoResult = 'Fail';
								$failureReason[] = array(
								    'warning' => "For <strong>" . $result['sample_label'] . "</strong> National HIV Testing algorithm was not followed.",
									'correctiveAction' => $correctiveActions[2]);
								$correctiveActionList[] = 2;
							}
						}
						else if ($r1 == 'R' && $r2 == 'R') {
							if (($r3 == '-' || $r3 == 'X')) {
								$algoResult = 'Pass';
							} else {
								$algoResult = 'Fail';
								$failureReason[] = array(
								    'warning' => "For <strong>" . $result['sample_label'] . "</strong> National HIV Testing algorithm was not followed.",
									'correctiveAction' => $correctiveActions[2]);
								$correctiveActionList[] = 2;
							}
						} else if ($r1 == 'R' && $r2 == 'NR' && ($r3 == 'R' || $r3 == 'X')) {
							$algoResult = 'Pass';
						} else {
							$algoResult = 'Fail';
							$failureReason[] = array(
							    'warning' => "For <strong>" . $result['sample_label'] . "</strong> National HIV Testing algorithm was not followed.",
								'correctiveAction' => $correctiveActions[2]);
							$correctiveActionList[] = 2;
						}
					} else if ($attributes['algorithm'] == 'parallel') {
						if ($r1 == 'R' && $r2 == 'R') {
							if (($r3 == '-' || $r3 == 'X')) {
								$algoResult = 'Pass';
							} else {
								$algoResult = 'Fail';
								$failureReason[] = array(
								    'warning' => "For <strong>" . $result['sample_label'] . "</strong> National HIV Testing algorithm was not followed.",
									'correctiveAction' => $correctiveActions[2]);
								$correctiveActionList[] = 2;
							}
						} else if ($r1 == 'R' && $r2 == 'NR' && ($r3 == 'R' || $r3 == 'X')) {
							$algoResult = 'Pass';
						} else if ($r1 == 'R' && $r2 == 'NR' && ($r3 == 'NR' || $r3 == 'X')) {
							$algoResult = 'Pass';
						} else if ($r1 == 'NR' && $r2 == 'NR') {
							if (($r3 == '-' || $r3 == 'X')) {
								$algoResult = 'Pass';
							} else {
								$algoResult = 'Fail';
								$failureReason[] = array(
								    'warning' => "For <strong>" . $result['sample_label'] . "</strong> National HIV Testing algorithm was not followed.",
									'correctiveAction' => $correctiveActions[2]);
								$correctiveActionList[] = 2;
							}
						} else if ($r1 == 'NR' && $r2 == 'R' && ($r3 == 'NR' || $r3 == 'X')) {
							$algoResult = 'Pass';
						} else if ($r1 == 'NR' && $r2 == 'R' && ($r3 == 'R' || $r3 == 'X')) {
							$algoResult = 'Pass';
						} else {
							$algoResult = 'Fail';
							$failureReason[] = array(
							    'warning' => "For <strong>" . $result['sample_label'] . "</strong> National HIV Testing algorithm was not followed.",
								'correctiveAction' => $correctiveActions[2]);
							$correctiveActionList[] = 2;
						}
					}
				} else {
					// If there are two kit used for the participants then the control
					// needs to be tested with at least both kit.
					// If three then all three kits required and one then atleast one.
					if ($testKit1 != "") {
						if (!isset($result['test_result_1']) || $result['test_result_1'] == "") {
							$controlTesKitFail = 'Fail';
							$failureReason[] = array(
							    'warning' => "For the Control Sample <strong>" . $result['sample_label'] . "</strong>, Test Kit 1 (<strong>$testKit1</strong>) was not used",
								'correctiveAction' => $correctiveActions[2]);
							$correctiveActionList[] = 2;
						}
					}

					if ($testKit2 != "") {
						if (!isset($result['test_result_2']) || $result['test_result_2'] == "") {
							$controlTesKitFail = 'Fail';
							$failureReason[] = array(
							    'warning' => "For the Control Sample <strong>" . $result['sample_label'] . "</strong>, Test Kit 2 (<strong>$testKit2</strong>) was not used",
								'correctiveAction' => $correctiveActions[2]);
							$correctiveActionList[] = 2;
						}
					}

					if ($testKit3 != "") {
						if (!isset($result['test_result_3']) || $result['test_result_3'] == "") {
							$controlTesKitFail = 'Fail';
							$failureReason[] = array(
							    'warning' => "For the Control Sample <strong>" . $result['sample_label'] . "</strong>, Test Kit 3 (<strong>$testKit3</strong>) was not used",
								'correctiveAction' => $correctiveActions[2]);
							$correctiveActionList[] = 2;
						}
					}
				}

				if (!isset($result['reported_result']) || $result['reported_result'] == "" || $result['reported_result'] == null) {
					$mandatoryResult = 'Fail';
					$shipment['is_excluded'] = 'yes';
					$failureReason[] = array(
					    'warning' => "Sample <strong>" . $result['sample_label'] . "</strong> was not reported. Result not evaluated.",
						'correctiveAction' => $correctiveActions[4]);
					$correctiveActionList[] = 4;
				}

				// matching reported and reference results
				$correctResponse = false;
				if (isset($result['reported_result']) && $result['reported_result'] != null) {
					if($controlTesKitFail != 'Fail'){
						if ($result['reference_result'] == $result['reported_result']) {
							if($algoResult != 'Fail' && $mandatoryResult != 'Fail'){
								$totalScore += $result['sample_score'];
								$correctResponse = true;
							} else {
								$correctResponse = false;
								// $totalScore remains the same
							}
						} else {
							if ($result['sample_score'] > 0) {
								$failureReason[] = array(
								    'warning' => "<strong>" . $result['sample_label'] . "</strong> - Reported result does not match the expected result",
									'correctiveAction' => $correctiveActions[3]);
								$correctiveActionList[] = 3;
							}
							$correctResponse = false;
						}
					} else {
						$correctResponse = false;
					}
				}

				$maxScore += $result['sample_score'];
				if (isset($result['test_result_1']) && $result['test_result_1'] != "" && $result['test_result_1'] != null) {
					//T.1 Ensure test kit name is reported for all performed tests.
					if (($testKit1 == "")) {
						$failureReason[] = array(
						    'warning' => "Result not evaluated – name of Test kit 1 not reported.",
							'correctiveAction' => $correctiveActions[7]);
						$correctiveActionList[] = 7;
						$shipment['is_excluded'] = 'yes';
					}
					//T.5 Ensure expiry date information is submitted for all performed tests.
					//T.15 Testing performed with a test kit that is not recommended by MOH
					if ((isset($tk1Expired) && $tk1Expired) || (isset($tk1RecommendedUsed) && !$tk1RecommendedUsed)) {
						$testKitExpiryResult = 'Fail';
						if($correctResponse){
							$totalScore -= $result['sample_score'];
						}
						$correctResponse = false;
					}
				}
				if (isset($result['test_result_2']) && $result['test_result_2'] != "" && $result['test_result_2'] != null) {
					//T.1 Ensure test kit name is reported for all performed tests.
					if (($testKit2 == "")) {
						$failureReason[] = array(
						    'warning' => "Result not evaluated – name of Test kit 2 not reported.",
							'correctiveAction' => $correctiveActions[7]);
						$correctiveActionList[] = 7;
						$shipment['is_excluded'] = 'yes';
					}
					//T.5 Ensure expiry date information is submitted for all performed tests.
					//T.15 Testing performed with a test kit that is not recommended by MOH
					if ((isset($tk2Expired) && $tk2Expired) || (isset($tk2RecommendedUsed) && !$tk2RecommendedUsed)) {
						$testKitExpiryResult = 'Fail';
						if($correctResponse){
							$totalScore -= $result['sample_score'];
						}
						$correctResponse = false;
					}
				}
				if (isset($result['test_result_3']) && $result['test_result_3'] != "" && $result['test_result_3'] != null) {
					//T.1 Ensure test kit name is reported for all performed tests.
					if (($testKit3 == "")) {
						$failureReason[] = array(
						    'warning' => "Result not evaluated – name of Test kit 3 not reported.",
							'correctiveAction' => $correctiveActions[7]);
						$correctiveActionList[] = 7;
						$shipment['is_excluded'] = 'yes';
					}
					//T.5 Ensure expiry date information is submitted for all performed tests.
					//T.15 Testing performed with a test kit that is not recommended by MOH
					if ((isset($tk3Expired) && $tk3Expired) || (isset($tk3RecommendedUsed) && !$tk3RecommendedUsed)) {
						$testKitExpiryResult = 'Fail';
						if($correctResponse){
							$totalScore -= $result['sample_score'];
						}
						$correctResponse = false;
					}
				}

				if (!$correctResponse || $algoResult == 'Fail' || $mandatoryResult == 'Fail' ||
                    $result['reference_result'] != $result['reported_result']) {
					$db->update('response_result_dts', array('calculated_score' => "Fail"),
                        "shipment_map_id = " . $result['map_id'] . " and sample_id = " . $result['sample_id']);
				} else {
					$db->update('response_result_dts', array('calculated_score' => "Pass"),
                        "shipment_map_id = " . $result['map_id'] . " and sample_id = " . $result['sample_id']);
				}
			}

			$configuredDocScore = ((isset($config->evaluation->dts->documentationScore) &&
                $config->evaluation->dts->documentationScore != "" &&
                $config->evaluation->dts->documentationScore != null) ? $config->evaluation->dts->documentationScore : 0);

			// Response Score
			if ($maxScore == 0 || $totalScore == 0) {
				$responseScore = 0;
			} else {
				$responseScore = round(($totalScore / $maxScore) * 100 * (100 - $configuredDocScore) / 100, 2);
			}

			//Let us now calculate documentation score
			$documentationScore = 0;
			$documentationScorePerItem = ($config->evaluation->dts->documentationScore / 5);

			// D.1
			if (isset($results[0]['shipment_receipt_date']) && strtolower($results[0]['shipment_receipt_date']) != '') {
				$documentationScore += $documentationScorePerItem;
			} else {
				$failureReason[] = array('warning' => "Shipment Receipt Date not provided",
					'correctiveAction' => $correctiveActions[16]);
				$correctiveActionList[] = 16;
			}

			//D.3
			if (isset($attributes['sample_rehydration_date']) && trim($attributes['sample_rehydration_date']) != "") {
				$documentationScore += $documentationScorePerItem;
			} else {
				$failureReason[] = array('warning' => "Missing reporting rehydration date for DTS Panel",
					'correctiveAction' => $correctiveActions[12]);
				$correctiveActionList[] = 12;
			}

			//D.5
			if (isset($results[0]['shipment_test_date']) && trim($results[0]['shipment_test_date']) != "") {
				$documentationScore += $documentationScorePerItem;
			} else {
				$failureReason[] = array('warning' => "Shipment received test date not provided",
					'correctiveAction' => $correctiveActions[13]);
				$correctiveActionList[] = 13;
			}

			//D.7
			// Testing should be done within 24*($config->evaluation->dts->sampleRehydrateDays) hours of rehydration.
			$sampleRehydrationDate = new DateTime($attributes['sample_rehydration_date']);
			$testedOnDate = new DateTime($results[0]['shipment_test_date']);
			$interval = $sampleRehydrationDate->diff($testedOnDate);

			$sampleRehydrateDays = $config->evaluation->dts->sampleRehydrateDays;
			$rehydrateHours = $sampleRehydrateDays*24;

			if ($interval->days > $sampleRehydrateDays) {
				$failureReason[] = array(
				    'warning' => "Testing should be done within $rehydrateHours hours of rehydration.",
					'correctiveAction' => $correctiveActions[14]);
				$correctiveActionList[] = 14;
			} else {
				$documentationScore += $documentationScorePerItem;
			}

			//D.8
			if (isset($result['supervisor_approval']) && strtolower($result['supervisor_approval']) == 'yes' &&
                trim($result['participant_supervisor']) != "") {
				$documentationScore += $documentationScorePerItem;
			} else {
				$failureReason[] = array(
				    'warning' => "Supervisor approval absent",
					'correctiveAction' => $correctiveActions[11]);
				$correctiveActionList[] = 11;
			}

			$grandTotal = ($responseScore + $documentationScore);
			if ($grandTotal < $config->evaluation->dts->passPercentage) {
				$scoreResult = 'Fail';
				$failureReason[] = array(
				    'warning' => "Participant did not meet the score criteria (Participant Score is <strong>" . $grandTotal . "</strong> and Required Score is <strong>" . $config->evaluation->dts->passPercentage . "</strong>)",
					'correctiveAction' => $correctiveActions[15]);
				$correctiveActionList[] = 15;
			} else {
				$scoreResult = 'Pass';
			}

			// if we are excluding this result, then let us not give pass/fail
			if ($shipment['is_excluded'] == 'yes') {
				$shipmentResult[$counter]['shipment_score'] = $responseScore = 0;
				$shipmentResult[$counter]['documentation_score'] = 0;
				$shipmentResult[$counter]['display_result'] = '';
				$shipmentResult[$counter]['is_followup'] = 'yes';
				$failureReason[] = array('warning' => 'Excluded from Evaluation');
				$finalResult = 3;
				$shipmentResult[$counter]['failure_reason'] = $failureReason = json_encode($failureReason);
			} else {
				$shipment['is_excluded'] = 'no';
				// if any of the results have failed, then the final result is fail
				if ($algoResult == 'Fail' || $scoreResult == 'Fail' || $lastDateResult == 'Fail' ||
                    $mandatoryResult == 'Fail' || $lotResult == 'Fail' || $testKitExpiryResult == 'Fail') {
					$finalResult = 2;
					$shipmentResult[$counter]['is_followup'] = 'yes';
				} else {
					$finalResult = 1;
				}
				$shipmentResult[$counter]['shipment_score'] = $responseScore;
				$shipmentResult[$counter]['documentation_score'] = $documentationScore;
				$scoreHolder[$shipment['map_id']] = $responseScore + $documentationScore;

				$fRes = $db->fetchCol($db->select()->from('r_results', array('result_name'))->where('result_id = ' . $finalResult));

				$shipmentResult[$counter]['display_result'] = $fRes[0];
				$shipmentResult[$counter]['failure_reason'] = $failureReason = (isset($failureReason) &&
                    count($failureReason) > 0) ? json_encode($failureReason) : "";
            }
			$shipmentResult[$counter]['max_score'] = $maxScore;
			$shipmentResult[$counter]['final_result'] = $finalResult;

			// let us update the total score in DB
			$db->update('shipment_participant_map', array(
			    'shipment_score' => $responseScore,
                'documentation_score' => $documentationScore,
                'final_result' => $finalResult,
                "is_followup" => $shipmentResult[$counter]['is_followup'],
                'is_excluded' => $shipment['is_excluded'],
                'failure_reason' => $failureReason),
                "map_id = " . $shipment['map_id']);
			$db->delete('dts_shipment_corrective_action_map', "shipment_map_id = " . $shipment['map_id']);
			$correctiveActionList = array_unique($correctiveActionList);
			foreach ($correctiveActionList as $ca) {
				$db->insert('dts_shipment_corrective_action_map', array(
				    'shipment_map_id' => $shipment['map_id'],
                    'corrective_action_id' => $ca), "map_id = " . $shipment['map_id']);
			}
			$counter++;
		}

		if (count($scoreHolder) > 0) {
			$averageScore = round(array_sum($scoreHolder)/count($scoreHolder),2);
		} else {
			$averageScore = 0 ;
		}

		$db->update('shipment', array('max_score' => $maxScore,'average_score' => $averageScore),
            "shipment_id = " . $shipmentId);
		return $shipmentResult;
	}

    public function getEvaluateReports ($shipmentId) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sql = $db->select()->from(array('s' => 'shipment'), array(
            's.shipment_id',
            's.shipment_code',
            's.scheme_type',
            's.shipment_date',
            's.lastdate_response',
            's.max_score'))
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array(
                'd.distribution_id',
                'd.distribution_code',
                'd.distribution_date'
            ))
            ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array(
                'sp.map_id',
                'sp.participant_id',
                'sp.shipment_test_date',
                'sp.shipment_receipt_date',
                'sp.shipment_test_report_date',
                'result_submission_date' => new Zend_Db_Expr('IFNULL(sp.date_submitted, sp.shipment_test_report_date)'),
                'sp.final_result',
                'sp.failure_reason',
                'sp.shipment_score',
                'sp.final_result',
                'sp.attributes',
                'sp.is_followup',
                'sp.is_excluded',
                'sp.optional_eval_comment',
                'sp.evaluation_comment',
                'sp.documentation_score',
                'sp.supervisor_approval',
                'sp.participant_supervisor',
                'sp.qc_done',
                'sp.qc_date',
                'sp.is_pt_test_not_performed',
                'sp.pt_test_not_performed_comments'))
            ->join(array('sl' => 'scheme_list'), 'sl.scheme_id=s.scheme_type', array('sl.scheme_id', 'sl.scheme_name'))
            ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id', array(
                'p.unique_identifier',
                'p.lab_name',
                'p.status',
                'sorting_unique_identifier' => new Zend_Db_Expr("LPAD(p.unique_identifier, 10, '0')")
            ))
            ->join(array('c' => 'countries'), 'c.id=p.country', array('country_name' => 'c.iso_name', 'country_id' => 'c.id'))
            ->joinLeft(array('res' => 'r_results'), 'res.result_id=sp.final_result', array('result_name'))
            ->joinLeft(array('ec' => 'r_evaluation_comments'), 'ec.comment_id=sp.evaluation_comment', array(
                'evaluationComments' => 'comment'))
            ->joinLeft(array('rntr' => 'response_not_tested_reason'), 'rntr.not_tested_reason_id = sp.not_tested_reason', array('rntr.not_tested_reason'))
            ->where("s.shipment_id = ?", $shipmentId)
            ->where("substring(sp.evaluation_status,4,1) != '0'")
            ->order("sorting_unique_identifier");
        $shipmentResult = $db->fetchAll($sql);
        $previousSixShipmentsSql = $db->select()
            ->from(array('s' => 'shipment'), array(
                's.shipment_id',
                's.shipment_code',
                's.shipment_date'))
            ->join(array('spm' => 'shipment_participant_map'), 's.shipment_id=spm.shipment_id', array('mean_shipment_score' => new Zend_Db_Expr("AVG(IFNULL(spm.shipment_score, 0) + IFNULL(spm.documentation_score, 0))")))
            ->where("s.is_official = 1")
            ->where(new Zend_Db_Expr("IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'"))
            ->where(new Zend_Db_Expr("IFNULL(spm.is_excluded, 'no') = 'no'"))
            ->where(new Zend_Db_Expr("SUBSTR(spm.evaluation_status, 3, 1) = '1'")) // Submitted
            ->where("s.shipment_id <= ".$shipmentId)
            ->group('s.shipment_id')
            ->order("s.shipment_date DESC")
            ->limit(6);
        $previousSixShipments = $db->fetchAll($previousSixShipmentsSql);
        $countryPtccs = array();
        $tbResultsExpected = array();
        $tbResultsConsensusMtbRif = array();
        $tbResultsConsensusUltra = array();
        if (count($shipmentResult) > 0) {
            $tbResultsExpected = $this->getExpectedResults($shipmentId);
            $tbResultsConsensusMtbRif = $this->getConsensusResults($shipmentId, 'MTB/RIF');
            $tbResultsConsensusUltra = $this->getConsensusResults($shipmentId, 'MTB Ultra');
        }

        $assays = array();
        $assayRecords = $db->fetchAll($db->select()->from('r_tb_assay'));
        foreach ($assayRecords as $assayRecord) {
            $assays[$assayRecord['id']] = $assayRecord['short_name'];
        }
        $i = 0;
        $mapRes = array();
        $scoringService = new Application_Service_EvaluationScoring();
        foreach ($shipmentResult as $res) {
            $dmSql = $db->select()
                ->from(array('pmm' => 'participant_manager_map'))
                ->join(array('dm' => 'data_manager'), 'dm.dm_id = pmm.dm_id',
                    array("institute" => "IFNULL(dm.institute, '')"))
                ->where("pmm.participant_id = " . $res['participant_id']);

            $dmResult = $db->fetchAll($dmSql);
            if (!isset($countryPtccs[$res['country_id']])) {
                $ptccSql = $db->select()
                    ->from(array('pcm' => 'ptcc_country_map'))
                    ->join(array('sa' => 'system_admin'), 'sa.admin_id = pcm.admin_id')
                    ->where("pcm.country_id = " . $res['country_id'])
                    ->where("pcm.show_details_on_report = 1")
                    ->limit(2);

                $countryPtccs[$res['country_id']] = $db->fetchAll($ptccSql);
            }
            $shipmentResult[$i]['ptcc_profiles'] = $countryPtccs[$res['country_id']];
            $participantPreviousSixShipments = array();
            if (count($previousSixShipments) > 0) {
                $participantPreviousSixShipmentsSql = $db->select()
                    ->from(array('spm' => 'shipment_participant_map'), array('shipment_id' => 'spm.shipment_id', 'shipment_score' => new Zend_Db_Expr("IFNULL(spm.shipment_score, 0) + IFNULL(spm.documentation_score, 0)")))
                    ->where("spm.participant_id = " . $res['participant_id'])
                    ->where("spm.shipment_id IN (" . implode(",", array_column($previousSixShipments, "shipment_id")) . ")");

                $participantPreviousSixShipmentRecords = $db->fetchAll($participantPreviousSixShipmentsSql);
                foreach ($participantPreviousSixShipmentRecords as $participantPreviousSixShipmentRecord) {
                    $participantPreviousSixShipments[$participantPreviousSixShipmentRecord['shipment_id']] = $participantPreviousSixShipmentRecord;
                }
            }
            $shipmentResult[$i]['previous_six_shipments'] = array();
            for($participantPreviousSixShipmentIndex = 5; $participantPreviousSixShipmentIndex >= 0; $participantPreviousSixShipmentIndex--) {
                $previousShipmentData = array(
                    'shipment_code' => 'XXXX',
                    'mean_shipment_score' => null,
                    'shipment_score' => null,
                );
                if (count($previousSixShipments) > $participantPreviousSixShipmentIndex) {
                    $previousShipmentData['shipment_code'] = $previousSixShipments[$participantPreviousSixShipmentIndex]['shipment_code'];
                    $previousShipmentData['mean_shipment_score'] = $previousSixShipments[$participantPreviousSixShipmentIndex]['mean_shipment_score'];
                    if (isset($participantPreviousSixShipments[$previousSixShipments[$participantPreviousSixShipmentIndex]['shipment_id']])) {
                        $previousShipmentData['shipment_score'] = $participantPreviousSixShipments[$previousSixShipments[$participantPreviousSixShipmentIndex]['shipment_id']]['shipment_score'];
                    }
                }
                $shipmentResult[$i]['previous_six_shipments'][5 - $participantPreviousSixShipmentIndex] = $previousShipmentData;
            }
            foreach ($dmResult as $dmRes) {
                $participantFileName = preg_replace('/[^A-Za-z0-9.]/', '-', $res['lab_name'] . "-" . $res['map_id']);
				$participantFileName = str_replace(" ", "-", $participantFileName);
                if (count($mapRes) == 0) {
                    $mapRes[$dmRes['dm_id']] = $dmRes['institute'] . "#" . $dmRes['participant_id'] . "#" . $participantFileName;
                } else if (array_key_exists($dmRes['dm_id'], $mapRes)) {
                    $mapRes[$dmRes['dm_id']] .= "$" . $dmRes['institute'] . "#" . $dmRes['participant_id'] . "#" . $participantFileName;
                } else {
                    $mapRes[$dmRes['dm_id']] = $dmRes['institute'] . "#" . $dmRes['participant_id'] . "#" . $participantFileName;
                }
            }
            $attributes = json_decode($res['attributes'], true);
            $sql = $db->select()->from(array('ref' => 'reference_result_tb'),
                array(
                    'sample_id', 'sample_label', 'sample_score',
                    'ref_mtb_rif_is_excluded' => 'ref.mtb_rif_is_excluded',
                    'ref_mtb_rif_is_exempt' => 'ref.mtb_rif_is_exempt',
                    'ref_ultra_is_excluded' => 'ref.ultra_is_excluded',
                    'ref_ultra_is_exempt' => 'ref.ultra_is_exempt',
                    'ref_excluded_reason' => 'ref.excluded_reason'))
                ->join(array('spm' => 'shipment_participant_map'),
                    'spm.shipment_id = ref.shipment_id', array())
                ->joinLeft(array('a' => 'r_tb_assay'),
                    'a.id = CASE WHEN JSON_VALID(spm.attributes) = 1 THEN JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, "$.assay")) ELSE 0 END', array('assay_short_name' => 'a.short_name'))
                ->joinLeft(array('res' => 'response_result_tb'),
                    'res.shipment_map_id = spm.map_id and res.sample_id = ref.sample_id', array(
                        'res.mtb_detected',
                        'res.rif_resistance',
                        'res.error_code',
                        'res.date_tested',
                        'cartridge_expiration_date' => new Zend_Db_Expr("COALESCE(
                CASE WHEN res.cartridge_expiration_date = '0000-00-00' THEN NULL
                ELSE COALESCE(STR_TO_DATE(res.cartridge_expiration_date, '%d-%b-%Y'),
                    STR_TO_DATE(res.cartridge_expiration_date, '%Y-%b-%d'),
                    STR_TO_DATE(res.cartridge_expiration_date, '%d-%m-%Y'),
                    STR_TO_DATE(res.cartridge_expiration_date, '%Y-%m-%d'))
                END,
                CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")) = '0000-00-00' THEN NULL
                ELSE COALESCE(STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%d-%b-%Y'),
                    STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%Y-%b-%d'),
                    STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%d-%m-%Y'),
                    STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(CAST(spm.attributes AS JSON), \"$.expiry_date\")), '%Y-%m-%d'))
                END)"),
                        'res.probe_1',
                        'res.probe_2',
                        'res.probe_3',
                        'res.probe_4',
                        'res.probe_5',
                        'res.probe_6'))
                ->joinLeft('instrument',
                    'instrument.participant_id = spm.participant_id and instrument.instrument_serial = res.instrument_serial', array(
                        'years_since_last_calibrated' =>
                            new Zend_Db_Expr("DATEDIFF(COALESCE(res.date_tested, spm.shipment_test_date, spm.shipment_test_report_date), COALESCE(res.instrument_last_calibrated_on, instrument.instrument_last_calibrated_on, res.instrument_installed_on, instrument.instrument_installed_on, CAST('1990-01-01' AS DATE))) / 365"),
                        'instrument_serial' => new Zend_Db_Expr("COALESCE(CASE WHEN res.instrument_serial = '' THEN NULL ELSE res.instrument_serial END, CASE WHEN instrument.instrument_serial = '' THEN NULL ELSE instrument.instrument_serial END, 'NO SERIAL ENTERED')"),
                        'instrument_installed_on' =>
                            new Zend_Db_Expr("COALESCE(res.instrument_installed_on, instrument.instrument_installed_on)"),
                        'instrument_last_calibrated_on' =>
                            new Zend_Db_Expr("COALESCE(res.instrument_last_calibrated_on, instrument.instrument_last_calibrated_on)")))
                ->where('ref.shipment_id = ? ', $shipmentId)
                ->where('spm.participant_id = ?', $res['participant_id'])
                ->order('ref.sample_id ASC');
            $tbResults = $db->fetchAll($sql);

            $counter = 0;
            $toReturn = array();
            $shipmentScore = 0;
            $maxShipmentScore = 0;
            $sampleStatuses = array();
            $instrumentsUsed = array();
            $cartridgeExpiredOn = null;
            $instrumentRequiresCalibration = false;
            $testsDoneAfterCalibrationDue = array();
            $testsDoneAfterCartridgeExpired = array();
            $qcDoneOnTime = $shipmentResult[$i]['qc_done'] == 'yes' && isset($shipmentResult[$i]['qc_date']);
            $lastTestDate = null;
            foreach ($tbResults as $tbResult) {
                if (in_array($tbResult['mtb_detected'], array("notDetected", "noResult", "invalid", "error")) &&
                    $tbResult['rif_resistance'] == null) {
                    $tbResult['rif_resistance'] = "na";
                }
                $sampleScoreStatus = $scoringService->calculateTbSamplePassStatus(
                    $tbResultsExpected[$tbResult['sample_id']][$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ultra_mtb_detected' : 'mtb_rif_mtb_detected'],
                    $tbResult['mtb_detected'],
                    $tbResultsExpected[$tbResult['sample_id']][$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ultra_rif_resistance' : 'mtb_rif_rif_resistance'],
                    $tbResult['rif_resistance'],
                    $tbResult['probe_1'], $tbResult['probe_2'], $tbResult['probe_3'], $tbResult['probe_4'],
                    $tbResult['probe_5'], $tbResult['probe_6'], $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'],
                    $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt']);
                array_push($sampleStatuses, $sampleScoreStatus);
                $sampleScore = $scoringService->calculateTbSampleScore(
                    $sampleScoreStatus,
                    $tbResult['sample_score']);
                $shipmentScore += $sampleScore;
                if ($tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'] == 'no' || $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt'] == 'yes') {
                    $maxShipmentScore += $tbResult['sample_score'];
                }
                $consensusTbMtbDetectedMtbRif = $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_mtb_detected'];
                $consensusTbRifResistanceMtbRif = $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_rif_resistance'];
                if (isset($tbResultsConsensusMtbRif[$tbResult['sample_id']])) {
                    if (isset($tbResultsConsensusMtbRif[$tbResult['sample_id']]['mtb_detected']) &&
                        trim($tbResultsConsensusMtbRif[$tbResult['sample_id']]['mtb_detected']) != "") {
                        $consensusTbMtbDetectedMtbRif = $tbResultsConsensusMtbRif[$tbResult['sample_id']]['mtb_detected'];
                    }
                    if (isset($tbResultsConsensusMtbRif[$tbResult['sample_id']]['rif_resistance']) &&
                        trim($tbResultsConsensusMtbRif[$tbResult['sample_id']]['rif_resistance']) != "") {
                        $consensusTbRifResistanceMtbRif = $tbResultsConsensusMtbRif[$tbResult['sample_id']]['rif_resistance'];
                    }
                }
                $consensusTbMtbDetectedUltra = $tbResultsExpected[$tbResult['sample_id']]['ultra_mtb_detected'];
                $consensusTbRifResistanceUltra = $tbResultsExpected[$tbResult['sample_id']]['ultra_rif_resistance'];
                if (isset($tbResultsConsensusUltra[$tbResult['sample_id']])) {
                    if (isset($tbResultsConsensusUltra[$tbResult['sample_id']]['mtb_detected']) &&
                        trim($tbResultsConsensusUltra[$tbResult['sample_id']]['mtb_detected']) != "") {
                        $consensusTbMtbDetectedUltra = $tbResultsConsensusUltra[$tbResult['sample_id']]['mtb_detected'];
                    }
                    if (isset($tbResultsConsensusUltra[$tbResult['sample_id']]['rif_resistance']) &&
                        trim($tbResultsConsensusUltra[$tbResult['sample_id']]['rif_resistance']) != "") {
                        $consensusTbRifResistanceUltra = $tbResultsConsensusUltra[$tbResult['sample_id']]['rif_resistance'];
                    }
                }

                if (isset($tbResult['cartridge_expiration_date']) && $tbResult['cartridge_expiration_date'] != '0000-00-00' && $tbResult['cartridge_expiration_date'] < $tbResult['date_tested']) {
                    $cartridgeExpiredOn = $tbResult['cartridge_expiration_date'];
                    array_push($testsDoneAfterCartridgeExpired, array(
                        "sample_label" => $tbResult['sample_label'],
                        "date_tested" => $tbResult['date_tested']
                    ));
                }
                $instrumentInArray = false;
                foreach ($instrumentsUsed as $instrumentUsed) {
                    if ($instrumentUsed["instrument_serial"] == $tbResult['instrument_serial']) {
                        $instrumentInArray = true;
                    }
                }
                if (!$instrumentInArray) {
                    array_push(
                        $instrumentsUsed,
                        array (
                            "instrument_last_calibrated_on" => $tbResult['instrument_last_calibrated_on'],
                            "instrument_serial" => $tbResult['instrument_serial']
                        )
                    );
                }
                if ((!isset($tbResult['instrument_last_calibrated_on']) &&
                        !isset($tbResult['instrument_installed_on'])) ||
                    (isset($tbResult['years_since_last_calibrated']) &&
                        $tbResult['years_since_last_calibrated'] >= 1)) {
                    $instrumentRequiresCalibration = true;
                    array_push($testsDoneAfterCalibrationDue, array(
                        "sample_label" => $tbResult['sample_label'],
                        "date_tested" => $tbResult['date_tested'],
                        "instrument_serial" => $tbResult['instrument_serial'],
                        "instrument_last_calibrated_on" => $tbResult['instrument_last_calibrated_on']
                    ));
                }
                if ($lastTestDate == null || $lastTestDate < $tbResult['date_tested']) {
                    $lastTestDate = $tbResult['date_tested'];
                }
                $toReturn[$counter] = array(
                    'sample_id' => $tbResult['sample_id'],
                    'sample_label' => $tbResult['sample_label'],
                    'mtb_detected' => $tbResult['mtb_detected'],
                    'discrepant_result' => $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_exempt' : 'ref_mtb_rif_is_exempt'] != 'yes' &&
                        $tbResult[$tbResult['assay_short_name'] == 'MTB Ultra' ? 'ref_ultra_is_excluded' : 'ref_mtb_rif_is_excluded'] != 'yes' &&
                        $sampleScore == 0,
                    'rif_resistance' => $tbResult['rif_resistance'],
                    'error_code' => $tbResult['error_code'],
                    'probe_1' => $tbResult['probe_1'],
                    'probe_2' => $tbResult['probe_2'],
                    'probe_3' => $tbResult['probe_3'],
                    'probe_4' => $tbResult['probe_4'],
                    'probe_5' => $tbResult['probe_5'],
                    'probe_6' => $tbResult['probe_6'],
                    'expected_mtb_rif_mtb_detected' => $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_mtb_detected'],
                    'expected_mtb_rif_rif_resistance' => $tbResultsExpected[$tbResult['sample_id']]['mtb_rif_rif_resistance'],
                    'expected_ultra_mtb_detected' => $tbResultsExpected[$tbResult['sample_id']]['ultra_mtb_detected'],
                    'expected_ultra_rif_resistance' => $tbResultsExpected[$tbResult['sample_id']]['ultra_rif_resistance'],
                    'consensus_mtb_rif_mtb_detected' => $consensusTbMtbDetectedMtbRif,
                    'consensus_mtb_rif_rif_resistance' => $consensusTbRifResistanceMtbRif,
                    'consensus_ultra_mtb_detected' => $consensusTbMtbDetectedUltra,
                    'consensus_ultra_rif_resistance' => $consensusTbRifResistanceUltra,
                    'ref_mtb_rif_is_excluded' => $tbResult['ref_mtb_rif_is_excluded'],
                    'ref_mtb_rif_is_exempt' => $tbResult['ref_mtb_rif_is_exempt'],
                    'ref_ultra_is_excluded' => $tbResult['ref_ultra_is_excluded'],
                    'ref_ultra_is_exempt' => $tbResult['ref_ultra_is_exempt'],
                    'ref_excluded_reason' => $tbResult['ref_excluded_reason'],
                    'max_score' => $tbResult['sample_score'],
                    'score' => $sampleScore,
                    'score_status' => $sampleScoreStatus);
                $counter++;
            }
            if ($qcDoneOnTime) {
                $secondsSinceQcDoneToLastTest = strtotime(Pt_Commons_General::dbDateToString($lastTestDate)) - strtotime(Pt_Commons_General::dbDateToString($shipmentResult[$i]['qc_date']));
                $qcDoneOnTime = round($secondsSinceQcDoneToLastTest / (60 * 60 * 24)) < 62;
            }
            $shipmentResult[$i]['qc_done_on_time'] = $qcDoneOnTime;
            $shipmentResult[$i]['shipment_score'] = $shipmentScore;
            $shipmentResult[$i]['max_shipment_score'] = $maxShipmentScore;
            if(!isset($attributes['shipment_date'])) {
                $attributes['shipment_date'] = '';
            }
            if(!isset($attributes['expiry_date'])) {
                $attributes['expiry_date'] = '';
            }
            $shipmentResult[$i]['documentation_score'] = $scoringService->calculateTbDocumentationScore(
                $res['shipment_date'], $attributes['expiry_date'], $res['shipment_receipt_date'],
                $res['supervisor_approval'], $res['participant_supervisor'], $res['lastdate_response']);
            $shipmentResult[$i]['submission_score_status'] = $scoringService->calculateSubmissionPassStatus(
                $shipmentScore, $shipmentResult[$i]['documentation_score'], $maxShipmentScore,
                $sampleStatuses);
            $shipmentResult[$i]['eval_comment'] = $res['evaluationComments'];
            $shipmentResult[$i]['optional_eval_comment'] = $res['optional_eval_comment'];
            $shipmentResult[$i]['corrective_actions'] = isset($attributes['corrective_actions']) ? $attributes['corrective_actions'] : array();
            $shipmentResult[$i]['responseResult'] = $toReturn;
            $shipmentResult[$i]['instrumentsUsed'] = $instrumentsUsed;
            $shipmentResult[$i]['cartridge_expired_on'] = $cartridgeExpiredOn;
            $shipmentResult[$i]['tests_done_on_expired_cartridges'] = "";
            if ($cartridgeExpiredOn) {
                if (count($testsDoneAfterCartridgeExpired) < $counter) {
                    $shipmentResult[$i]['tests_done_on_expired_cartridges'] = " The following samples were tested using expired cartridges:";
                    foreach ($testsDoneAfterCartridgeExpired as $testDoneAfterCartridgeExpired) {
                        $shipmentResult[$i]['tests_done_on_expired_cartridges'] .= "<br/>".$testDoneAfterCartridgeExpired["sample_label"] . " was tested on " . Pt_Commons_General::dbDateToString($testDoneAfterCartridgeExpired['date_tested']);
                    }
                } else if (count($testsDoneAfterCartridgeExpired) > 0) {
                    $shipmentResult[$i]['tests_done_on_expired_cartridges'] = " This panel was tested on ".Pt_Commons_General::dbDateToString($testsDoneAfterCartridgeExpired[0]['date_tested']);
                }
            }
            $shipmentResult[$i]['instrument_requires_calibration'] = $instrumentRequiresCalibration;
            $shipmentResult[$i]['tests_done_after_calibration_due'] = "";
            if ($instrumentRequiresCalibration) {
                if (count($testsDoneAfterCalibrationDue) < $counter) {
                    $shipmentResult[$i]['tests_done_after_calibration_due'] = " The following samples were tested on instruments that were due for calibration or had no calibration information:";
                    foreach ($testsDoneAfterCalibrationDue as $testDoneAfterCalibrationDue) {
                        if (!isset($testDoneAfterCalibrationDue['instrument_last_calibrated_on'])) {
                            $shipmentResult[$i]['tests_done_after_calibration_due'] .= "<br/>".$testDoneAfterCalibrationDue["sample_label"] .
                                " was tested on " . Pt_Commons_General::dbDateToString($testDoneAfterCalibrationDue['date_tested']) .
                                " using an instrument that has no calibration date specified.";
                        } else {
                            $shipmentResult[$i]['tests_done_after_calibration_due'] .= "<br/>".$testDoneAfterCalibrationDue["sample_label"] .
                                " was tested on " . Pt_Commons_General::dbDateToString($testDoneAfterCalibrationDue['date_tested']) .
                                " using ".$testDoneAfterCalibrationDue['instrument_serial'] .
                                " which was last calibrated on " .
                                Pt_Commons_General::dbDateToString($testDoneAfterCalibrationDue['instrument_last_calibrated_on']) . ".";
                        }
                    }
                } else {
                    $shipmentResult[$i]['tests_done_after_calibration_due'] = " All samples in this submission were tested on instruments that were due for calibration or had no calibration information.";
                }
                $shipmentResult[$i]['tests_done_after_calibration_due'] .= "<br/>";
            }
            if (isset($res['is_pt_test_not_performed']) && $res['is_pt_test_not_performed'] == 'yes') {
                $ptNotTestedComment = null;
                if (isset($res['not_tested_reason']) && $res['not_tested_reason'] != '') {
                    $ptNotTestedComment = $res['not_tested_reason'];
                } else if (isset($res['pt_test_not_performed_comments']) && $res['pt_test_not_performed_comments'] != '') {
                    $ptNotTestedComment = $res['pt_test_not_performed_comments'];
                }
                $shipmentResult[$i]['ptNotTestedComment'] = 'Xpert testing site was unable to participate in '.$shipmentResult[0]['shipment_code'];
                if (isset($ptNotTestedComment)) {
                    $shipmentResult[$i]['ptNotTestedComment'] .= ' due to the following reason(s): '.$ptNotTestedComment.'.';
                }
            }
            $shipmentResult[$i]['assay_name'] = "Unspecified";
            if (isset($attributes['assay']) && $attributes['assay'] != '') {
                $shipmentResult[$i]['assay_name'] = $assays[$attributes['assay']];
	        }
            $i++;
            $db->update('shipment_participant_map', array('report_generated' => 'yes'), "map_id=" . $res['map_id']);
            $db->update('shipment', array('status' => 'evaluated'), "shipment_id=" . $shipmentId);
        }

        
        $reportService = new Application_Service_Reports();
        $reflexive_comments = $reportService->getReflexiveComment($shipmentId);
        $result = array(
            'reflexive_comments' => $reflexive_comments,
            'shipment' => $shipmentResult,
            'dmResult' => $mapRes,
            'previousSixShipments' => $previousSixShipments);
        return $result;
    }
}

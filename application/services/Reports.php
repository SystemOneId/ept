<?php

include_once "PHPExcel.php";

class Application_Service_Reports {
    public function getAllShipments($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array(
            'distribution_code',
            "DATE_FORMAT(distribution_date,'%d-%b-%Y')",
            's.shipment_code',
            "DATE_FORMAT(s.lastdate_response,'%d-%b-%Y')",
            'sl.scheme_name',
            's.number_of_samples',
            new Zend_Db_Expr('count("participant_id")'),
            new Zend_Db_Expr("SUM(substr(evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("(SUM(shipment_test_date <> '0000-00-00')/count('participant_id'))*100"),
            new Zend_Db_Expr("SUM(final_result = 1)"),
            's.status'
        );
        $searchColumns = array('distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')", 's.shipment_code', "DATE_FORMAT(s.lastdate_response,'%d-%b-%Y')", 'sl.scheme_name', 's.number_of_samples', 'participant_count', 'reported_count', 'reported_percentage', 'number_passed', 's.status');
        $havingColumns = array('participant_count', 'reported_count');
        $orderColumns = array(
            'distribution_code',
            'distribution_date',
            's.shipment_code',
            's.lastdate_response',
            'sl.scheme_name',
            's.number_of_samples',
            new Zend_Db_Expr('count("participant_id")'),
            new Zend_Db_Expr("SUM(substr(evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("(SUM(substr(evaluation_status,3,1) = 1)/count('participant_id'))*100"),
            new Zend_Db_Expr("SUM(final_result = 1)"),
            's.status'
        );

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = 'shipment_id';
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
                $colSize = count($searchColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($searchColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }

        /*
         * SQL queries
         * Get data to display
         */

        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'))
            ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
            ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id')
            ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array(
                'report_generated',
                'participant_count' => new Zend_Db_Expr('count("participant_id")'),
                'reported_count' => new Zend_Db_Expr("COUNT(CASE substr(sp.evaluation_status,4,1) WHEN '1' THEN 1 WHEN '2' THEN 1 END)"),
                'reported_percentage' => new Zend_Db_Expr("ROUND((COUNT(CASE substr(sp.evaluation_status,4,1) WHEN '1' THEN 1 WHEN '2' THEN 1 END)/count('participant_id'))*100,2)"),
                'number_passed' => new Zend_Db_Expr("SUM(final_result = 1)")))
            ->joinLeft(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
            //->joinLeft(array('pmm'=>'participant_manager_map'),'pmm.participant_id=p.participant_id')
            ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id')
            ->group(array('s.shipment_id'));

        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("s.shipment_date >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("s.shipment_date <= ?", $parameters['endDate']);
        }

        if (isset($parameters['dataManager']) && $parameters['dataManager'] != "") {
            $sQuery = $sQuery->joinLeft(array('pmm' => 'participant_manager_map'), 'pmm.participant_id=p.participant_id');
            $sQuery = $sQuery->where("pmm.dm_id = ?", $parameters['dataManager']);
        }

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }

        //if (isset($sHaving) && $sHaving != "") {
        // $sQuery = $sQuery->having($sHaving);
        // }


        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }


        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));
        $aResultTotal = $dbAdapter->fetchCol($sQuery);
        $iTotal = $aResultTotal[0];

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );


        $shipmentDb = new Application_Model_DbTable_Shipments();
        foreach ($rResult as $aRow) {
            $download = ' No Download Available ';
            $fileSafeShipmentCode = str_replace( ' ', '-', str_replace(array_merge(
                array_map('chr', range(0, 31)),
                array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
            ), '', $aRow['shipment_code']));
            if (isset($aRow['report_generated']) && $aRow['report_generated'] == 'yes') {
                if (file_exists(UPLOAD_PATH . DIRECTORY_SEPARATOR . "reports" . DIRECTORY_SEPARATOR . $fileSafeShipmentCode . DIRECTORY_SEPARATOR . $fileSafeShipmentCode."-summary.pdf")) {
                    $download = '<a href="/uploads/reports/' . $fileSafeShipmentCode . '/'.$fileSafeShipmentCode.'-summary.pdf" class=\'btn btn-info btn-xs\'><i class=\'icon-download\'></i> Summary</a>';
                }
            }
            $shipmentResults = $shipmentDb->getPendingShipmentsByDistribution($aRow['distribution_id']);
            $responsePercentage = ($aRow['reported_percentage'] != "") ? $aRow['reported_percentage'] : "0";
            $row = array();
            $row[] = $aRow['distribution_code'];
            $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['distribution_date']);
            $row[] = "<a href='javascript:void(0);' onclick='generateShipmentParticipantList(\"" . base64_encode($aRow['shipment_id']) . "\",\"".$aRow['scheme_type']."\")'>" . $aRow['shipment_code'] . "</a>";
            $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['lastdate_response']);
            $row[] = $aRow['scheme_name'];
            $row[] = $aRow['number_of_samples'];
            $row[] = $aRow['participant_count'];
            $row[] = ($aRow['reported_count'] != "") ? $aRow['reported_count'] : 0;
            $row[] = '<a href="/reports/shipments/response-chart/id/' . base64_encode($aRow['shipment_id']) . '/shipmentDate/' . base64_encode($aRow['distribution_date']) . '/shipmentCode/' . base64_encode($aRow['distribution_code']) . '" target="_blank" style="text-decoration:underline">' . $responsePercentage . ' %</a>';
            $row[] = $aRow['number_passed'];
            $row[] = ucwords($aRow['status']);
            $row[] = $download;
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function updateReportConfigs($params) {
        $filterRules = array('*' => 'StripTags', '*' => 'StringTrim');
        $filter = new Zend_Filter_Input($filterRules, null, $params);
        if ($filter->isValid()) {
            $db = new Application_Model_DbTable_ReportConfig();
            $db->getAdapter()->beginTransaction();
            try {
                $result = $db->updateReportDetails($params);
                $db->getAdapter()->commit();
                return $result;
            } catch (Exception $exc) {
                $db->getAdapter()->rollBack();
                error_log($exc->getMessage());
                error_log($exc->getTraceAsString());
            }
        }
    }

    public function getReportConfigValue($name) {
        $db = new Application_Model_DbTable_ReportConfig();
        return $db->getValue($name);
    }

    public function getParticipantDetailedReport($params) {
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        if (isset($params['reportType']) && $params['reportType'] == "network") {
            $sQuery = $dbAdapter->select()->from(array('n' => 'r_network_tiers'))
                    ->joinLeft(array('p' => 'participant'), 'p.network_tier=n.network_id', array())
                    ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                    ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('lastdate_response'))
                    ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.participant_id=p.participant_id', array('others' => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"), 'excluded' => new Zend_Db_Expr("SUM(if(sp.is_excluded = 'yes', 1, 0))"), 'number_failed' => new Zend_Db_Expr("SUM(sp.final_result = 2 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_passed' => new Zend_Db_Expr("SUM(sp.final_result = 1 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_late' => new Zend_Db_Expr("SUM(sp.shipment_test_date > s.lastdate_response AND sp.is_excluded != 'yes')"), 'map_id'))
                    ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array())
                    ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array())
                    ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id', array())
                    ->group('n.network_id')/* ->where("p.status = 'active'") */;
        }

        if (isset($params['reportType']) && $params['reportType'] == "affiliation") {
            $sQuery = $dbAdapter->select()->from(array('pa' => 'r_participant_affiliates'))
                    ->joinLeft(array('p' => 'participant'), 'p.affiliation=pa.affiliate', array())
                    ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                    ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('lastdate_response'))
                    ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.participant_id=p.participant_id', array('others' => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"), 'excluded' => new Zend_Db_Expr("SUM(if(sp.is_excluded = 'yes', 1, 0))"), 'number_failed' => new Zend_Db_Expr("SUM(sp.final_result = 2 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_passed' => new Zend_Db_Expr("SUM(sp.final_result = 1 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_late' => new Zend_Db_Expr("SUM(sp.shipment_test_date > s.lastdate_response AND sp.is_excluded != 'yes')")))
                    ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array())
                    ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array())
                    ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id', array())
                    ->group('pa.aff_id')/* ->where("p.status = 'active'") */;
        }

        if (isset($params['reportType']) && $params['reportType'] == "region") {
            $sQuery = $dbAdapter->select()->from(array('p' => 'participant'), array('p.region'))
                            ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                            ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('lastdate_response'))
                            ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.participant_id=p.participant_id', array('others' => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"), 'excluded' => new Zend_Db_Expr("SUM(if(sp.is_excluded = 'yes', 1, 0))"), 'number_failed' => new Zend_Db_Expr("SUM(sp.final_result = 2 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_passed' => new Zend_Db_Expr("SUM(sp.final_result = 1 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_late' => new Zend_Db_Expr("SUM(sp.shipment_test_date > s.lastdate_response AND sp.is_excluded != 'yes')")))
                            ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array())
                            ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array())
                            ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id', array())
                            ->group('p.region')->where("p.region IS NOT NULL")->where("p.region != ''")/* ->where("p.status = 'active'") */;
        }
        if (isset($params['reportType']) && $params['reportType'] == "enrolled-programs") {
            $sQuery = $dbAdapter->select()->from(array('p' => 'participant'), array())
                            ->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
                            ->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'))
                            ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                            ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('lastdate_response'))
                            ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.participant_id=p.participant_id', array('others' => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"), 'excluded' => new Zend_Db_Expr("SUM(if(sp.is_excluded = 'yes', 1, 0))"), 'number_failed' => new Zend_Db_Expr("SUM(sp.final_result = 2 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_passed' => new Zend_Db_Expr("SUM(sp.final_result = 1 AND sp.shipment_test_date <= s.lastdate_response AND sp.is_excluded != 'yes')"), 'number_late' => new Zend_Db_Expr("SUM(sp.shipment_test_date > s.lastdate_response AND sp.is_excluded != 'yes')")))
                            ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array())
                            ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array())
                            ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id', array())
                            ->group('rep.r_epid');
        }
        if (isset($params['scheme']) && $params['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $params['scheme']);
        }

		//die($sQuery);
        if (isset($params['startDate']) && $params['startDate'] != "" && isset($params['endDate']) && $params['endDate'] != "") {
            $sQuery = $sQuery->where("s.shipment_date >= ?", $params['startDate']);
            $sQuery = $sQuery->where("s.shipment_date <= ?", $params['endDate']);
        }
        //echo $sQuery;die;
        return $dbAdapter->fetchAll($sQuery);
    }

    public function getAllParticipantDetailedReport($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
            $aColumns = array('s.shipment_code', 'sl.scheme_name', 'network_name', 'distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')");
        } else if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
            $aColumns = array('s.shipment_code', 'sl.scheme_name', 'affiliate', 'distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')");
        } else if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
            $aColumns = array('s.shipment_code', 'sl.scheme_name', 'region', 'distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')");
        }else if (isset($parameters['reportType']) && $parameters['reportType'] == "enrolled-programs") {
            $aColumns = array('s.shipment_code', 'sl.scheme_name', 'enrolled_programs', 'distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')");
        }



        /*
         * Paging
         */
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
                    $sOrder .= $aColumns[intval($parameters['iSortCol_' . $i])] . "
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
        //////////////


        if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
            $sQuery = $dbAdapter->select()->from(array('n' => 'r_network_tiers'))
                            ->joinLeft(array('p' => 'participant'), 'p.network_tier=n.network_id', array())
                            ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                            ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('shipment_code', 'lastdate_response'))
                            ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array('scheme_name'))
                            ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array('distribution_code', 'distribution_date'))
                            ->group('n.network_id')->group('s.shipment_id')/* ->where("p.status = 'active'") */;
        } else if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
            $sQuery = $dbAdapter->select()->from(array('pa' => 'r_participant_affiliates'))
                            ->joinLeft(array('p' => 'participant'), 'p.affiliation=pa.affiliate', array())
                            ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                            ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('shipment_code', 'lastdate_response'))
                            ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array('scheme_name'))
                            ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array('distribution_code', 'distribution_date'))
                            ->group('pa.aff_id')->group('s.shipment_id')/* ->where("p.status = 'active'") */;
        } else if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
            $sQuery = $dbAdapter->select()->from(array('p' => 'participant'), array('p.region'))
                            ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                            ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('shipment_code', 'lastdate_response'))
                            ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array('scheme_name'))
                            ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array('distribution_code', 'distribution_date'))
                            ->group('p.region')->where("p.region IS NOT NULL")->where("p.region != ''")->group('s.shipment_id')/* ->where("p.status = 'active'") */;
        } else if (isset($parameters['reportType']) && $parameters['reportType'] == "enrolled-programs") {


			$sQuery = $dbAdapter->select()->from(array('p' => 'participant'), array())
			->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
                            ->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'))
                            ->joinLeft(array('shp' => 'shipment_participant_map'), 'shp.participant_id=p.participant_id', array())
                            ->joinLeft(array('s' => 'shipment'), 's.shipment_id=shp.shipment_id', array('shipment_code', 'lastdate_response'))
                            ->joinLeft(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id', array('scheme_name'))
                            ->joinLeft(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id', array('distribution_code', 'distribution_date'))
                             ->group('rep.r_epid')->group('s.shipment_id')/* ->where("p.status = 'active'") */;


        }
//        else{
//          $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'))
//                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
//                ->join(array('d' => 'distributions'), 'd.distribution_id=s.distribution_id')
//                ->group('s.shipment_id');
//        }
        ///////////


        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("s.shipment_date >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("s.shipment_date <= ?", $parameters['endDate']);
        }

        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */

        $aResultTotal = $dbAdapter->fetchAll($sQuery);
        $iTotal = sizeof($aResultTotal);

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row[] = $aRow['shipment_code'];
            $row[] = ucwords($aRow['scheme_name']);
            if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
                $row[] = $aRow['network_name'];
            } else if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
                $row[] = $aRow['affiliate'];
            } else if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
                $row[] = $aRow['region'];
            } else if (isset($parameters['reportType']) && $parameters['reportType'] == "enrolled-programs") {
				$row[] = (isset($aRow['enrolled_programs']) && $aRow['enrolled_programs'] != "" && $aRow['enrolled_programs'] != null) ? $aRow['enrolled_programs'] : "No Program";
            }

            $row[] = $aRow['distribution_code'];
            $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['distribution_date']);
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getParticipantPerformanceReport($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */
        $aColumns = array(
            'sl.scheme_name',
            "DATE_FORMAT(s.shipment_date,'%d-%b-%Y')",
            's.shipment_code',
            new Zend_Db_Expr('count("sp.map_id")'),
            new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
            new Zend_Db_Expr("SUM(final_result = 1)"),
            new Zend_Db_Expr("((SUM(final_result = 1))/(SUM(final_result = 1) + SUM(final_result = 2)))*100"),
            'average_score'
        );
        $searchColumns = array(
            'sl.scheme_name',
            "DATE_FORMAT(s.shipment_date,'%d-%b-%Y')",
            's.shipment_code', "total_shipped",
            'total_responses',
            'valid_responses',
            'total_passed',
            'pass_percentage',
            'average_score'
        );
        $orderColumns = array(
            'sl.scheme_name',
            "s.shipment_date",
            's.shipment_code',
            new Zend_Db_Expr('count("sp.map_id")'),
            new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
            new Zend_Db_Expr("SUM(final_result = 1)"),
            new Zend_Db_Expr("((SUM(final_result = 1))/(SUM(final_result = 1) + SUM(final_result = 2)))*100"),
            'average_score'
        );

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = 'shipment_id';

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
                $colSize = count($searchColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($searchColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }

        /*
         * SQL queries
         * Get data to display
         */
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id',
						   array("DATE_FORMAT(s.shipment_date,'%d-%b-%Y')",
								 "total_shipped" => new Zend_Db_Expr('count("sp.map_id")'),
								 "total_responses" => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
								 "valid_responses" => new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
								 "total_passed" => new Zend_Db_Expr("(SUM(final_result = 1))"),
								 "pass_percentage" => new Zend_Db_Expr("((SUM(final_result = 1))/(SUM(final_result = 1) + SUM(final_result = 2)))*100")
								 ))
                ->joinLeft(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
                ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id')
                ->group(array('s.shipment_id'));

        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        $sQuerySession = new Zend_Session_Namespace('participantPerformanceExcel');
        $sQuerySession->participantQuery = $sQuery;

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sWhere = "";
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));
        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        $aResultTotal = $dbAdapter->fetchCol($sQuery);
        $iTotal = $aResultTotal[0];

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row['DT_RowId'] = "shipment" . $aRow['shipment_id'];
            $row[] = $aRow['scheme_name'];
            $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['shipment_date']);
            $row[] = "<a href='javascript:void(0);' onclick='shipmetRegionReport(\"" . $aRow['shipment_id'] . "\"),regionDetails(\"" . $aRow['scheme_name'] . "\",\"" . Application_Service_Common::ParseDateHumanFormat($aRow['shipment_date']) . "\",\"" . $aRow['shipment_code'] . "\")'>" . $aRow['shipment_code'] . "</a>";
            $row[] = $aRow['total_shipped'];
            $row[] = $aRow['total_responses'];
            $row[] = $aRow['valid_responses'];
            $row[] = $aRow['total_passed'];
            $row[] = round($aRow['pass_percentage'], 2);
            $row[] = round($aRow['average_score'], 2);
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getParticipantPerformanceReportByShipmentId($shipmentId) {
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array("DATE_FORMAT(s.shipment_date,'%d-%b-%Y')", "total_shipped" => new Zend_Db_Expr('count("sp.map_id")'), "total_responses" => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
                    "valid_responses" => new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
                    ))
                ->where("s.shipment_id = ?", $shipmentId);
        //echo $sQuery;die;
        return $dbAdapter->fetchRow($sQuery);
    }

    public function getShipmentResponseReport($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array('sl.scheme_name',
            "ref.sample_label",
            'ref.reference_result',
            'positive_responses',
            'negative_responses',
            'invalid_responses',
            new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("SUM(sp.final_result=1)"),
            new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
        );

        $searchColumns = array('sl.scheme_name',
            "ref.sample_label",
            'ref.reference_result',
            'positive_responses',
            'negative_responses',
            'invalid_responses',
            'total_responses',
            "total_passed",
            'valid_responses'
        );
        $orderColumns = array('sl.scheme_name',
            "ref.sample_label",
            'ref.reference_result',
            'positive_responses',
            'negative_responses',
            'invalid_responses',
            new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("SUM(sp.final_result=1)"),
            new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
        );


        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = 'shipment_id';
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
                $colSize = count($searchColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
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
        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $refTable = "reference_result_" . $parameters['scheme'];
            $resTable = "response_result_" . $parameters['scheme'];

            // to count the total positive and negative, we need to know which r_possibleresults are positive and negative
            // so the following ...
	    $rInderminate = 0;
            if ($parameters['scheme'] == 'dts') {
                $rPositive = 4;
                $rNegative = 5;
                $rInderminate = 6;
            } else if ($parameters['scheme'] == 'dbs') {
                $rPositive = 7;
                $rNegative = 8;
            } else if ($parameters['scheme'] == 'eid') {
                $rPositive = 10;
                $rNegative = 11;
            }
        }

        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), array('shipment_code'))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array("total_responses" => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"), "total_passed" => new Zend_Db_Expr("SUM(sp.final_result=1)"), "valid_responses" => new Zend_Db_Expr("(SUM(sp.shipment_test_date <> '0000-00-00') - SUM(is_excluded = 'yes'))")))
                //->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
                ->join(array('ref' => $refTable), 's.shipment_id=ref.shipment_id')
                ->join(array('res' => $resTable), 'sp.map_id=res.shipment_map_id', array("positive_responses" => new Zend_Db_Expr('SUM(if(res.reported_result = ' . $rPositive . ', 1, 0))'), "negative_responses" => new Zend_Db_Expr('SUM(if(res.reported_result = ' . $rNegative . ', 1, 0))'), "invalid_responses" => new Zend_Db_Expr('SUM(if(res.reported_result = ' . $rInderminate . ', 1, 0))')))
                ->join(array('rr' => 'r_results'), 'sp.final_result=rr.result_id')
                ->join(array('rp' => 'r_possibleresult'), 'ref.reference_result=rp.id')
                ->where("res.sample_id = ref.sample_id")
                ->group(array('sp.shipment_id', 'ref.sample_label'));

        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }


        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }


        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        $sQuerySession = new Zend_Session_Namespace('shipmentExportExcel');
        $sQuerySession->shipmentExportQuery = $sQuery;

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        //die($sQuery);

        $rResult = $dbAdapter->fetchAll($sQuery);


        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sWhere = "";
        $sQuery = $dbAdapter->select()->from(array('ref' => $refTable), new Zend_Db_Expr("COUNT('ref.sample_label')"))
                ->join(array('s' => 'shipment'), 's.shipment_id=ref.shipment_id', array());


        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }


        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }

        $aResultTotal = $dbAdapter->fetchCol($sQuery);
        $iTotal = $aResultTotal[0];

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );


        foreach ($rResult as $aRow) {
            $row = array();
	    $exclamation = "";
	    if($aRow['mandatory'] == 0){
		$exclamation = "&nbsp;&nbsp;&nbsp;<i class='icon-exclamation' style='color:red;'></i>";
	    }
            $row[] = $aRow['scheme_name'];
            $row[] = $aRow['shipment_code'];
            $row[] = $aRow['sample_label'].$exclamation;
            $row[] = $aRow['response'];
            $row[] = $aRow['positive_responses'];
            $row[] = $aRow['negative_responses'];
            $row[] = $aRow['invalid_responses'];
            $row[] = $aRow['total_responses'];
            $row[] = $aRow['valid_responses'];
           // $row[] = $aRow['total_passed'];
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getTestKitReport($params) {
        //Zend_Debug::dump($params);die;
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('res' => 'response_result_dts'), array('totalTest' => new Zend_Db_Expr("CAST((COUNT('shipment_map_id')/s.number_of_samples) as UNSIGNED)")))
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.map_id=res.shipment_map_id', array())
                ->joinLeft(array('p' => 'participant'), 'sp.participant_id=p.participant_id', array())
                ->joinLeft(array('s' => 'shipment'), 's.shipment_id=sp.shipment_id', array());
        if (isset($params['kitType']) && $params['kitType'] == "testkit1") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_1', array('TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
        }
        else if (isset($params['kitType']) && $params['kitType'] == "testkit2") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_2', array('TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
        }
        else if (isset($params['kitType']) && $params['kitType'] == "testkit3") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_3', array('TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
        }else{
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_1 or tn.TestKitName_ID=res.test_kit_name_2 or tn.TestKitName_ID=res.test_kit_name_3', array('TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
		}
        if (isset($params['reportType']) && $params['reportType'] == "network") {
            if (isset($params['networkValue']) && $params['networkValue'] != "") {
                $sQuery = $sQuery->where("p.network_tier = ?", $params['networkValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('n' => 'r_network_tiers'), 'p.network_tier=n.network_id', array());
            }
        }

        if (isset($params['reportType']) && $params['reportType'] == "affiliation") {
            if (isset($params['affiliateValue']) && $params['affiliateValue'] != "") {
                $iQuery = $dbAdapter->select()->from(array('rpa' => 'r_participant_affiliates'))
                        ->where('rpa.aff_id=?', $params['affiliateValue']);
                $iResult = $dbAdapter->fetchRow($iQuery);
                $appliate = $iResult['affiliate'];
                $sQuery = $sQuery->where('p.affiliation="' . $appliate . '" OR p.affiliation=' . $params['affiliateValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('pa' => 'r_participant_affiliates'), 'p.affiliation=pa.affiliate', array());
            }
            //echo $sQuery;die;
        }
        if (isset($params['reportType']) && $params['reportType'] == "region") {
            if (isset($params['regionValue']) && $params['regionValue'] != "") {
                $sQuery = $sQuery->where("p.region= ?", $params['regionValue']);
            } else {
                $sQuery = $sQuery->where("p.region IS NOT NULL")->where("p.region != ''");
            }
        }
        if (isset($params['reportType']) && $params['reportType'] == "enrolled-programs") {
            if (isset($params['enrolledProgramsValue']) && $params['enrolledProgramsValue'] != "") {
                $sQuery = $sQuery->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
                            ->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'))
							->where("rep.r_epid= ?", $params['enrolledProgramsValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
                            ->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'));
            }
        }

        if (isset($params['startDate']) && $params['startDate'] != "" && isset($params['endDate']) && $params['endDate'] != "") {
            $sQuery = $sQuery->where("s.shipment_date >= ?", $params['startDate']);
            $sQuery = $sQuery->where("s.shipment_date <= ?", $params['endDate']);
        }
        $sQuery = $sQuery->where("tn.TestKit_Name IS NOT NULL");
        //echo $sQuery;die;
        return $dbAdapter->fetchAll($sQuery);
    }

    public function getTestKitDetailedReport($parameters) {
        //Zend_Debug::dump($parameters);die;
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        //    $aColumns = array('tn.TestKit_Name',new Zend_Db_Expr("CAST((COUNT('shipment_map_id')/s.number_of_samples) as UNSIGNED)"));

        $aColumns = array(
            'tn.TestKit_Name',
            new Zend_Db_Expr("CAST((COUNT('shipment_map_id')/s.number_of_samples) as UNSIGNED)")
        );
        $searchColumns = array(
            'tn.TestKit_Name',
            'totalTest'
        );
        $orderColumns = array(
            'tn.TestKit_Name',
            'totalTest'
        );

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
                $colSize = count($searchColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($searchColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }

        /*
         * SQL queries
         * Get data to display
         */



        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('res' => 'response_result_dts'), array('totalTest' => new Zend_Db_Expr("CAST((COUNT('shipment_map_id')/s.number_of_samples) as UNSIGNED)")))
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.map_id=res.shipment_map_id', array())
                ->joinLeft(array('p' => 'participant'), 'sp.participant_id=p.participant_id', array('p.lab_name', 'participantName' => new Zend_Db_Expr("GROUP_CONCAT(DISTINCT p.lab_name ORDER BY p.lab_name SEPARATOR ', ')")))
                ->joinLeft(array('s' => 'shipment'), 's.shipment_id=sp.shipment_id', array());
        //  ->group("p.participant_id");

        if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit1") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_1', array('tn.TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
        }
        else if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit2") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_2', array('tn.TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
        }
        else if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit3") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_3', array('tn.TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
        }else{
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_1 or tn.TestKitName_ID=res.test_kit_name_2 or tn.TestKitName_ID=res.test_kit_name_3', array('TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
		}
        if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
            if (isset($parameters['networkValue']) && $parameters['networkValue'] != "") {
                $sQuery = $sQuery->where("p.network_tier = ?", $parameters['networkValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('n' => 'r_network_tiers'), 'p.network_tier=n.network_id', array());
            }
        }
        if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
            if (isset($parameters['affiliateValue']) && $parameters['affiliateValue'] != "") {
                $iQuery = $dbAdapter->select()->from(array('rpa' => 'r_participant_affiliates'))
                        ->where('rpa.aff_id=?', $parameters['affiliateValue']);
                $iResult = $dbAdapter->fetchRow($iQuery);
                $appliate = $iResult['affiliate'];
                $sQuery = $sQuery->where('p.affiliation="' . $appliate . '" OR p.affiliation=' . $parameters['affiliateValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('pa' => 'r_participant_affiliates'), 'p.affiliation=pa.affiliate', array());
            }
        }
        if (isset($parameters['reportType']) && $parameters['reportType'] == "enrolled-programs") {
            if (isset($parameters['enrolledProgramsValue']) && $parameters['enrolledProgramsValue'] != "") {
                $sQuery = $sQuery->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
                            ->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'))
							->where("rep.r_epid= ?", $parameters['enrolledProgramsValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
                            ->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'));
            }
        }
        if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
            if (isset($parameters['regionValue']) && $parameters['regionValue'] != "") {
                $sQuery = $sQuery->where("p.region= ?", $parameters['regionValue']);
            } else {
                $sQuery = $sQuery->where("p.region IS NOT NULL")->where("p.region != ''");
            }
        }
        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("s.shipment_date >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("s.shipment_date <= ?", $parameters['endDate']);
        }
        $sQuery = $sQuery->where("tn.TestKit_Name IS NOT NULL");

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }
        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }
        $sQuerySession = new Zend_Session_Namespace('TestkitActionsExcel');
        $sQuerySession->testkitActionsQuery = $sQuery;


        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }
        $rResult = $dbAdapter->fetchAll($sQuery);


        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */

        $aResultTotal = $dbAdapter->fetchAll($sQuery);
        $iTotal = sizeof($aResultTotal);

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row['DT_RowId'] = "testkitId" . $aRow['TestKitName_ID'];
            //  $row[] = $aRow['participantName'];
            $row[] = "<a href='javascript:void(0);' onclick='participantReport(\"" . $aRow['TestKitName_ID'] . "\",\"" . $aRow['TestKit_Name'] . "\")'>" . stripslashes($aRow['TestKit_Name']) . "</a>";
            $row[] = $aRow['totalTest'];
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getShipmentResponseCount($shipmentId, $date, $step = 5, $maxDays = 60) {
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();

        $responseResult = array();
        $responseDate = array();
        $initialStartDate = $date;
        for ($i = $step; $i <= $maxDays; $i+=$step) {

            $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), array(''))
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array(
                    'reported_count' => new Zend_Db_Expr("COUNT(CASE substr(sp.evaluation_status,4,1) WHEN '1' THEN 1 WHEN '2' THEN 1 END)")))
                ->where("s.shipment_id = ?", $shipmentId)
                ->group('s.shipment_id');
            $endDate = strftime("%Y-%m-%d", strtotime("$date + $i day"));

            if (isset($date) && $date != "" && $endDate != '' && $i < $maxDays) {
                $sQuery = $sQuery->where("sp.shipment_test_date >= ?", $date);
                $sQuery = $sQuery->where("sp.shipment_test_date <= ?", $endDate);
                $result = $dbAdapter->fetchAll($sQuery);
                $count = (isset($result[0]['reported_count']) && $result[0]['reported_count'] != "") ? $result[0]['reported_count'] : 0;
                $responseResult[] = (int) $count;
                $responseDate[] = Application_Service_Common::ParseDateHumanFormat($date) . ' ' . Application_Service_Common::ParseDateHumanFormat($endDate);
                $date = strftime("%Y-%m-%d", strtotime("$endDate +1 day"));
            }

            if ($i == $maxDays) {
                $sQuery = $sQuery->where("sp.shipment_test_date >= ?", $date);
                $result = $dbAdapter->fetchAll($sQuery);
                $count = (isset($result[0]['reported_count']) && $result[0]['reported_count'] != "") ? $result[0]['reported_count'] : 0;
                $responseResult[] = (int) $count;
                $responseDate[] = Application_Service_Common::ParseDateHumanFormat($date) . '  and Above';
            }
        }
        return json_encode($responseResult) . '#' . json_encode($responseDate);
    }

    public function getShipmentParticipant($shipmentId,$schemeType=null) {

		if($schemeType == 'dts') {
			return $this->generateDtsRapidHivExcelReport($shipmentId);
		}else if($schemeType == 'vl') {
			return $this->generateDtsViralLoadExcelReport($shipmentId);
		}else if($schemeType == 'eid') {
			return $this->generateDbsEidExcelReport($shipmentId);
		}else{
			return false;
		}
    }

	public function generateDtsRapidHivExcelReport($shipmentId){
			$db = Zend_Db_Table_Abstract::getDefaultAdapter();

			$excel = new PHPExcel();
			//$sheet = $excel->getActiveSheet();


			$cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
			$cacheSettings = array('memoryCacheSize' => '80MB');

			$styleArray = array(
				'font' => array(
					'bold' => true,
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
					'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THICK,
					),
				)
			);

			$borderStyle = array(
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THICK,
					),
				)
			);

			$query = $db->select()->from('shipment', array('shipment_id', 'shipment_code', 'scheme_type', 'number_of_samples'))
					->where("shipment_id = ?", $shipmentId);
			$result = $db->fetchRow($query);

			if ($result['scheme_type'] == 'dts') {

				$refQuery = $db->select()->from(array('refRes' => 'reference_result_dts'), array('refRes.sample_label', 'sample_id', 'refRes.sample_score'))
						->joinLeft(array('r' => 'r_possibleresult'), 'r.id=refRes.reference_result', array('referenceResult' => 'r.response'))
						->where("refRes.shipment_id = ?", $shipmentId);
				$refResult = $db->fetchAll($refQuery);
				if (count($refResult) > 0) {
					foreach ($refResult as $key => $refRes) {
						$refDtsQuery = $db->select()->from(array('refDts' => 'reference_dts_rapid_hiv'), array('refDts.lot_no', 'refDts.expiry_date', 'refDts.result'))
								->joinLeft(array('r' => 'r_possibleresult'), 'r.id=refDts.result', array('referenceKitResult' => 'r.response'))
								->joinLeft(array('tk' => 'r_testkitname_dts'), 'tk.TestKitName_ID=refDts.testkit', array('testKitName' => 'tk.TestKit_Name'))
								->where("refDts.shipment_id = ?", $shipmentId)
								->where("refDts.sample_id = ?", $refRes['sample_id']);
						$refResult[$key]['kitReference'] = $db->fetchAll($refDtsQuery);
					}
				}
			}

			$firstSheet = new PHPExcel_Worksheet($excel, 'Instructions');
			$excel->addSheet($firstSheet, 0);
			$firstSheet->setTitle('Instructions');
			//$firstSheet->getDefaultColumnDimension()->setWidth(44);
			//$firstSheet->getDefaultRowDimension()->setRowHeight(45);
			$firstSheetHeading = array('Tab Name', 'Description');
			$firstSheetColNo = 0;
			$firstSheetRow = 1;

			$firstSheetStyle = array(
				'alignment' => array(
				//'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THICK,
					),
				)
			);

			foreach ($firstSheetHeading as $value) {
				$firstSheet->getCellByColumnAndRow($firstSheetColNo, $firstSheetRow)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getStyleByColumnAndRow($firstSheetColNo, $firstSheetRow)->getFont()->setBold(true);
				$cellName = $firstSheet->getCellByColumnAndRow($firstSheetColNo, $firstSheetRow)->getColumn();
				$firstSheet->getStyle($cellName . $firstSheetRow)->applyFromArray($firstSheetStyle);
				$firstSheetColNo++;
			}

			$firstSheet->getCellByColumnAndRow(0, 2)->setValueExplicit(html_entity_decode("Participant List", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 2)->setValueExplicit(html_entity_decode("Includes dropdown lists for the following: region, department, position, RT, ELISA, received logbook", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getDefaultRowDimension()->setRowHeight(10);
			$firstSheet->getColumnDimensionByColumn(0)->setWidth(20);
			$firstSheet->getDefaultRowDimension(1)->setRowHeight(70);
			$firstSheet->getColumnDimensionByColumn(1)->setWidth(100);

			$firstSheet->getCellByColumnAndRow(0, 3)->setValueExplicit(html_entity_decode("Results Reported", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 3)->setValueExplicit(html_entity_decode("This tab should include no commentary from NPHRL or GHSS staff.  All fields should only reflect results or comments reported on the results form.  If no report was submitted, highlight site data cells in red.  Explanation of missing results should only be comments that the site made, not PT staff.  All dates should be formatted as DD/MM/YY.  Dropdown menu legend is as followed: negative (NEG), positive (POS), invalid (INV), indeterminate (IND), not entered or reported (NE), not tested (NT) and should be used according to the way the site reported it.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 4)->setValueExplicit(html_entity_decode("Panel Score", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 4)->setValueExplicit(html_entity_decode("This tab is automatically populated.  Panel score calculated 6/6.  If a panel member must be omitted from the calculation (ie, loss of sample, etc) you must revise the equation manually by changing the number 6 to 5,4,etc. accordingly. Example seen for Akai House Clinic.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 5)->setValueExplicit(html_entity_decode("Documentation Score", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 5)->setValueExplicit(html_entity_decode("The points breakdown for this tab are listed in the row above the sites for each column.  Data should be entered in manually by PT staff.  A site scores 1.5/3 if they used the wrong test kits got a 100% panel score.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 6)->setValueExplicit(html_entity_decode("Total Score", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 6)->setValueExplicit(html_entity_decode("Columns C-F are populated automatically.  Columns G, H and I must be selected from the dropdown menu for each site based on the criteria listed in the 'Decision Tree' tab.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 7)->setValueExplicit(html_entity_decode("Follow-up Calls", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 7)->setValueExplicit(html_entity_decode("Final comments or outcomes should be updated continuously with receipt dates included.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 8)->setValueExplicit(html_entity_decode("Dropdown Lists", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 8)->setValueExplicit(html_entity_decode("This tab contains all of the dropdown lists included in the rest of the database, any modifications should be performed with caution.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 9)->setValueExplicit(html_entity_decode("Decision Tree", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 9)->setValueExplicit(html_entity_decode("Lists all of the appropriate corrective actions and scoring critieria.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 10)->setValueExplicit(html_entity_decode("Feedback Report", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 10)->setValueExplicit(html_entity_decode("This tab is populated automatically and used to export data into the Feedback Reports generated in MS Word.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getCellByColumnAndRow(0, 11)->setValueExplicit(html_entity_decode("Comments", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getCellByColumnAndRow(1, 11)->setValueExplicit(html_entity_decode("This tab lists all of the more detailed comments that will be given to the sites during site visits and phone calls.", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);


			for ($counter = 1; $counter <= 11; $counter++) {
				$firstSheet->getStyleByColumnAndRow(1, $counter)->getAlignment()->setWrapText(true);
				$firstSheet->getStyle("A$counter")->applyFromArray($firstSheetStyle);
				$firstSheet->getStyle("B$counter")->applyFromArray($firstSheetStyle);
			}
			//<------------ Participant List Details Start -----

			$headings = array('Facility Code', 'Facility Name', 'Region', 'Current Department', 'Site Type', 'Address', 'Facility Telephone', 'Email', 'Enroll Date');

			$sheet = new PHPExcel_Worksheet($excel, 'Participant List');
			$excel->addSheet($sheet, 1);
			$sheet->setTitle('Participant List');

			$sql = $db->select()->from(array('s' => 'shipment'), array('s.shipment_id', 's.shipment_code', 's.number_of_samples'))
					->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array('sp.map_id', 'sp.participant_id', 'sp.attributes', 'sp.shipment_test_date', 'sp.shipment_receipt_date', 'sp.shipment_test_report_date', 'sp.supervisor_approval','sp.participant_supervisor', 'sp.shipment_score', 'sp.documentation_score', 'sp.user_comment'))
					->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id', array('p.unique_identifier', 'p.institute_name', 'p.department_name', 'p.region', 'p.lab_name', 'p.address', 'p.city', 'p.mobile', 'p.email', 'p.status'))
					->joinLeft(array('pmp' => 'participant_manager_map'), 'pmp.participant_id=p.participant_id', array('pmp.dm_id'))
					->joinLeft(array('dm' => 'data_manager'), 'dm.dm_id=pmp.dm_id', array('dm.institute', 'dataManagerFirstName' => 'dm.first_name', 'dataManagerLastName' => 'dm.last_name'))
					->joinLeft(array('st' => 'r_site_type'), 'st.r_stid=p.site_type', array('st.site_type'))
					->joinLeft(array('en' => 'enrollments'), 'en.participant_id=p.participant_id', array('en.enrolled_on'))
					->where("s.shipment_id = ?", $shipmentId)
					->group(array('sp.map_id'));
			//echo $sql;die;
			$shipmentResult = $db->fetchAll($sql);
			//die;
			$colNo = 0;
			$currentRow = 1;
			$type = PHPExcel_Cell_DataType::TYPE_STRING;
			//$sheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("Participant List", ENT_QUOTES, 'UTF-8'), $type);
			//$sheet->getStyleByColumnAndRow(0,1)->getFont()->setBold(true);
			$sheet->getDefaultColumnDimension()->setWidth(24);
			$sheet->getDefaultRowDimension()->setRowHeight(18);

			foreach ($headings as $field => $value) {
				$sheet->getCellByColumnAndRow($colNo, $currentRow)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$sheet->getStyleByColumnAndRow($colNo, $currentRow)->getFont()->setBold(true);
				$cellName = $sheet->getCellByColumnAndRow($colNo, $currentRow)->getColumn();
				$sheet->getStyle($cellName . $currentRow)->applyFromArray($borderStyle);
				$colNo++;
			}

			if (isset($shipmentResult) && count($shipmentResult) > 0) {
				$currentRow+=1;
				foreach ($shipmentResult as $key => $aRow) {
					if ($result['scheme_type'] == 'dts') {
						$resQuery = $db->select()->from(array('rrdts' => 'response_result_dts'))
								->joinLeft(array('tk1' => 'r_testkitname_dts'), 'tk1.TestKitName_ID=rrdts.test_kit_name_1', array('testKitName1' => 'tk1.TestKit_Name'))
								->joinLeft(array('tk2' => 'r_testkitname_dts'), 'tk2.TestKitName_ID=rrdts.test_kit_name_2', array('testKitName2' => 'tk2.TestKit_Name'))
								->joinLeft(array('tk3' => 'r_testkitname_dts'), 'tk3.TestKitName_ID=rrdts.test_kit_name_3', array('testKitName3' => 'tk3.TestKit_Name'))
								->joinLeft(array('r' => 'r_possibleresult'), 'r.id=rrdts.test_result_1', array('testResult1' => 'r.response'))
								->joinLeft(array('rp' => 'r_possibleresult'), 'rp.id=rrdts.test_result_2', array('testResult2' => 'rp.response'))
								->joinLeft(array('rpr' => 'r_possibleresult'), 'rpr.id=rrdts.test_result_3', array('testResult3' => 'rpr.response'))
								->joinLeft(array('fr' => 'r_possibleresult'), 'fr.id=rrdts.reported_result', array('finalResult' => 'fr.response'))
								->where("rrdts.shipment_map_id = ?", $aRow['map_id']);
						$shipmentResult[$key]['response'] = $db->fetchAll($resQuery);
					}


					$sheet->getCellByColumnAndRow(0, $currentRow)->setValueExplicit(ucwords($aRow['unique_identifier']), PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(1, $currentRow)->setValueExplicit($aRow['lab_name'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(2, $currentRow)->setValueExplicit($aRow['region'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(3, $currentRow)->setValueExplicit($aRow['department_name'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(4, $currentRow)->setValueExplicit($aRow['site_type'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(5, $currentRow)->setValueExplicit($aRow['address'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(6, $currentRow)->setValueExplicit($aRow['mobile'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(7, $currentRow)->setValueExplicit($aRow['email'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow(8, $currentRow)->setValueExplicit($aRow['enrolled_on'], PHPExcel_Cell_DataType::TYPE_STRING);

					for ($i = 0; $i <= 8; $i++) {
						$cellName = $sheet->getCellByColumnAndRow($i, $currentRow)->getColumn();
						$sheet->getStyle($cellName . $currentRow)->applyFromArray($borderStyle);
					}

					$currentRow++;
                    $fileSafeShipmentCode = str_replace( ' ', '-', str_replace(array_merge(
                        array_map('chr', range(0, 31)),
                        array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
                    ), '', $aRow['shipment_code']));
				}
			}

			//------------- Participant List Details End ------>
			//<-------- Second sheet start
			$reportHeadings = array('Facility Code', 'Facility Name', 'Point of Contact', 'Region', 'Shipment Receipt Date', 'Sample Rehydration Date', 'Testing Date', 'Test#1 Name', 'Kit Lot #', 'Exp Date');

			if ($result['scheme_type'] == 'dts') {
				$reportHeadings = $this->addSampleNameInArray($shipmentId, $reportHeadings);
				array_push($reportHeadings, 'Test#2 Name', 'Kit Lot #', 'Exp Date');
				$reportHeadings = $this->addSampleNameInArray($shipmentId, $reportHeadings);
				array_push($reportHeadings, 'Test#3 Name', 'Kit Lot #', 'Exp Date');
				$reportHeadings = $this->addSampleNameInArray($shipmentId, $reportHeadings);
				$reportHeadings = $this->addSampleNameInArray($shipmentId, $reportHeadings);
				array_push($reportHeadings, 'Comments');
			}

			$sheet = new PHPExcel_Worksheet($excel, 'Results Reported');
			$excel->addSheet($sheet, 2);
			$sheet->setTitle('Results Reported');
			$sheet->getDefaultColumnDimension()->setWidth(24);
			$sheet->getDefaultRowDimension()->setRowHeight(18);


			$colNo = 0;
			$currentRow = 2;
			$n = count($reportHeadings);
			$finalResColoumn = $n - ($result['number_of_samples'] + 1);
			$c = 1;
			$endMergeCell = ($finalResColoumn + $result['number_of_samples']) - 1;

			$firstCellName = $sheet->getCellByColumnAndRow($finalResColoumn, 1)->getColumn();
			$secondCellName = $sheet->getCellByColumnAndRow($endMergeCell, 1)->getColumn();
			$sheet->mergeCells($firstCellName . "1:" . $secondCellName . "1");
			$sheet->getStyle($firstCellName . "1")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('#00FF00');
			$sheet->getStyle($firstCellName . "1")->applyFromArray($borderStyle);
			$sheet->getStyle($secondCellName . "1")->applyFromArray($borderStyle);

			foreach ($reportHeadings as $field => $value) {

				$sheet->getCellByColumnAndRow($colNo, $currentRow)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$sheet->getStyleByColumnAndRow($colNo, $currentRow)->getFont()->setBold(true);
				$cellName = $sheet->getCellByColumnAndRow($colNo, $currentRow)->getColumn();
				$sheet->getStyle($cellName . $currentRow)->applyFromArray($borderStyle);

				$cellName = $sheet->getCellByColumnAndRow($colNo, 3)->getColumn();
				$sheet->getStyle($cellName . "3")->applyFromArray($borderStyle);

				if ($colNo >= $finalResColoumn) {
					if ($c <= $result['number_of_samples']) {

						$sheet->getCellByColumnAndRow($colNo, 1)->setValueExplicit(html_entity_decode("Final Results", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
						$cellName = $sheet->getCellByColumnAndRow($colNo, $currentRow)->getColumn();
						$sheet->getStyle($cellName . $currentRow)->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('#00FF00');
						$l = $c - 1;
						$sheet->getCellByColumnAndRow($colNo, 3)->setValueExplicit(html_entity_decode($refResult[$l]['referenceResult'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					}
					$c++;
				}
				$sheet->getStyle($cellName . '3')->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('FFA0A0A0');
				$sheet->getStyle($cellName . '3')->getFont()->getColor()->setARGB('FFFFFF00');

				$colNo++;
			}

			$sheet->getStyle("A2")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
			$sheet->getStyle("B2")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
			$sheet->getStyle("C2")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');
			$sheet->getStyle("D2")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00');

			//$sheet->getStyle("D2")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('#A7A7A7');
			//$sheet->getStyle("E2")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('#A7A7A7');
			//$sheet->getStyle("F2")->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('#A7A7A7');

			$cellName = $sheet->getCellByColumnAndRow($n, 3)->getColumn();
			//$sheet->getStyle('A3:'.$cellName.'3')->getFill()->setFillType(\PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('#969696');
			//$sheet->getStyle('A3:'.$cellName.'3')->applyFromArray($borderStyle);
			//<-------- Sheet three heading -------
			$sheetThree = new PHPExcel_Worksheet($excel, 'Panel Score');
			$excel->addSheet($sheetThree, 3);
			$sheetThree->setTitle('Panel Score');
			$sheetThree->getDefaultColumnDimension()->setWidth(20);
			$sheetThree->getDefaultRowDimension()->setRowHeight(18);
			$panelScoreHeadings = array('Facility Code', 'Facility Name');
			$panelScoreHeadings = $this->addSampleNameInArray($shipmentId, $panelScoreHeadings);
			array_push($panelScoreHeadings, 'Test# Correct', '% Correct');
			$sheetThreeColNo = 0;
			$sheetThreeRow = 1;
			$panelScoreHeadingCount = count($panelScoreHeadings);
			$sheetThreeColor = 1 + $result['number_of_samples'];
			foreach ($panelScoreHeadings as $sheetThreeHK => $value) {
				$sheetThree->getCellByColumnAndRow($sheetThreeColNo, $sheetThreeRow)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$sheetThree->getStyleByColumnAndRow($sheetThreeColNo, $sheetThreeRow)->getFont()->setBold(true);
				$cellName = $sheetThree->getCellByColumnAndRow($sheetThreeColNo, $sheetThreeRow)->getColumn();
				$sheetThree->getStyle($cellName . $sheetThreeRow)->applyFromArray($borderStyle);

				if ($sheetThreeHK > 1 && $sheetThreeHK <= $sheetThreeColor) {
					$cellName = $sheetThree->getCellByColumnAndRow($sheetThreeColNo, $sheetThreeRow)->getColumn();
					$sheetThree->getStyle($cellName . $sheetThreeRow)->getFill()->setFillType(PHPExcel_Style_Fill::FILL_SOLID)->getStartColor()->setARGB('#00FF00');
				}

				$sheetThreeColNo++;
			}
			//---------- Sheet Three heading ------->
			//<-------- Document Score Sheet Heading (Sheet Four)-------

			if ($result['scheme_type'] == 'dts') {
				$file = APPLICATION_PATH . DIRECTORY_SEPARATOR . "configs" . DIRECTORY_SEPARATOR . "config.ini";
				$config = new Zend_Config_Ini($file, APPLICATION_ENV);
				$documentationScorePerItem = ($config->evaluation->dts->documentationScore / 5);
			}

			$docScoreSheet = new PHPExcel_Worksheet($excel, 'Documentation Score');
			$excel->addSheet($docScoreSheet, 4);
			$docScoreSheet->setTitle('Documentation Score');
			$docScoreSheet->getDefaultColumnDimension()->setWidth(20);
			//$docScoreSheet->getDefaultRowDimension()->setRowHeight(20);
			$docScoreSheet->getDefaultRowDimension('G')->setRowHeight(25);

			$docScoreHeadings = array('Facility Code', 'Facility Name', 'Supervisor signature', 'Panel Receipt Date' ,'Rehydration Date', 'Tested Date', 'Rehydration Test In Specified Time', 'Documentation Score %');

			$docScoreSheetCol = 0;
			$docScoreRow = 1;
			$docScoreHeadingsCount = count($docScoreHeadings);
			foreach ($docScoreHeadings as $sheetThreeHK => $value) {
				$docScoreSheet->getCellByColumnAndRow($docScoreSheetCol, $docScoreRow)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$docScoreSheet->getStyleByColumnAndRow($docScoreSheetCol, $docScoreRow)->getFont()->setBold(true);
				$cellName = $docScoreSheet->getCellByColumnAndRow($docScoreSheetCol, $docScoreRow)->getColumn();
				$docScoreSheet->getStyle($cellName . $docScoreRow)->applyFromArray($borderStyle);
				$docScoreSheet->getStyleByColumnAndRow($docScoreSheetCol, $docScoreRow)->getAlignment()->setWrapText(true);
				$docScoreSheetCol++;
			}
			$docScoreRow = 2;
			$secondRowcellName = $docScoreSheet->getCellByColumnAndRow(1, $docScoreRow);
			$secondRowcellName->setValueExplicit(html_entity_decode("Points Breakdown", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$docScoreSheet->getStyleByColumnAndRow(1, $docScoreRow)->getFont()->setBold(true);
			$cellName = $secondRowcellName->getColumn();
			$docScoreSheet->getStyle($cellName . $docScoreRow)->applyFromArray($borderStyle);

			for ($r = 2; $r <= 7; $r++) {

				$secondRowcellName = $docScoreSheet->getCellByColumnAndRow($r, $docScoreRow);
				if ($r != 7) {
					$secondRowcellName->setValueExplicit(html_entity_decode($documentationScorePerItem, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				}
				$docScoreSheet->getStyleByColumnAndRow($r, $docScoreRow)->getFont()->setBold(true);
				$cellName = $secondRowcellName->getColumn();
				$docScoreSheet->getStyle($cellName . $docScoreRow)->applyFromArray($borderStyle);
			}

			//---------- Document Score Sheet Heading (Sheet Four)------->
			//<-------- Total Score Sheet Heading (Sheet Four)-------


			$totalScoreSheet = new PHPExcel_Worksheet($excel, 'Total Score');
			$excel->addSheet($totalScoreSheet, 5);
			$totalScoreSheet->setTitle('Total Score');
			$totalScoreSheet->getDefaultColumnDimension()->setWidth(20);
			$totalScoreSheet->getDefaultRowDimension(1)->setRowHeight(30);
			$totalScoreHeadings = array('Facility Code', 'Facility Name', 'No.of Panels Correct(N=' . $result['number_of_samples'] . ')', 'Panel Score(100% Conv.)', 'Panel Score(90% Conv.)', 'Documentation Score(100% Conv.)', 'Documentation Score(10% Conv.)', 'Total Score', 'Overall Performance', 'Comments', 'Comments2', 'Comments3', 'Corrective Action');

			$totScoreSheetCol = 0;
			$totScoreRow = 1;
			$totScoreHeadingsCount = count($totalScoreHeadings);
			foreach ($totalScoreHeadings as $sheetThreeHK => $value) {
				$totalScoreSheet->getCellByColumnAndRow($totScoreSheetCol, $totScoreRow)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$totalScoreSheet->getStyleByColumnAndRow($totScoreSheetCol, $totScoreRow)->getFont()->setBold(true);
				$cellName = $totalScoreSheet->getCellByColumnAndRow($totScoreSheetCol, $totScoreRow)->getColumn();
				$totalScoreSheet->getStyle($cellName . $totScoreRow)->applyFromArray($borderStyle);
				$totalScoreSheet->getStyleByColumnAndRow($totScoreSheetCol, $totScoreRow)->getAlignment()->setWrapText(true);
				$totScoreSheetCol++;
			}

			//---------- Document Score Sheet Heading (Sheet Four)------->

			$ktr = 9;
			$kitId = 7; //Test Kit coloumn count
			if (isset($refResult) && count($refResult) > 0) {
				foreach ($refResult as $keyv => $row) {
					$keyv = $keyv + 1;
					$ktr = $ktr + $keyv;
					if (count($row['kitReference']) > 0) {

						if ($keyv == 1) {
							//In Excel Third row added the Test kit name1,kit lot,exp date
							if (trim($row['kitReference'][0]['expiry_date']) != "") {
								$row['kitReference'][0]['expiry_date'] = Application_Service_Common::ParseDateExcel($row['kitReference'][0]['expiry_date']);
							}
							$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][0]['testKitName'], PHPExcel_Cell_DataType::TYPE_STRING);
							$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][0]['lot_no'], PHPExcel_Cell_DataType::TYPE_STRING);
							$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][0]['expiry_date'], PHPExcel_Cell_DataType::TYPE_STRING);

							$kitId = $kitId + $aRow['number_of_samples'];
							if (isset($row['kitReference'][1]['referenceKitResult'])) {
								//In Excel Third row added the Test kit name2,kit lot,exp date
								if (trim($row['kitReference'][1]['expiry_date']) != "") {
									$row['kitReference'][1]['expiry_date'] = Application_Service_Common::ParseDateExcel($row['kitReference'][1]['expiry_date']);
								}
								$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][1]['testKitName'], PHPExcel_Cell_DataType::TYPE_STRING);
								$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][1]['lot_no'], PHPExcel_Cell_DataType::TYPE_STRING);
								$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][1]['expiry_date'], PHPExcel_Cell_DataType::TYPE_STRING);
							}
							$kitId = $kitId + $aRow['number_of_samples'];
							if (isset($row['kitReference'][2]['referenceKitResult'])) {
								//In Excel Third row added the Test kit name3,kit lot,exp date
								if (trim($row['kitReference'][2]['expiry_date']) != "") {
									$row['kitReference'][2]['expiry_date'] = Application_Service_Common::ParseDateExcel($row['kitReference'][2]['expiry_date']);
								}
								$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][2]['testKitName'], PHPExcel_Cell_DataType::TYPE_STRING);
								$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][2]['lot_no'], PHPExcel_Cell_DataType::TYPE_STRING);
								$sheet->getCellByColumnAndRow($kitId++, 3)->setValueExplicit($row['kitReference'][2]['expiry_date'], PHPExcel_Cell_DataType::TYPE_STRING);
							}
						}

						$sheet->getCellByColumnAndRow($ktr, 3)->setValueExplicit($row['kitReference'][0]['referenceKitResult'], PHPExcel_Cell_DataType::TYPE_STRING);
						$ktr = ($aRow['number_of_samples'] - $keyv) + $ktr + 3;

						if (isset($row['kitReference'][1]['referenceKitResult'])) {
							$ktr = $ktr + $keyv;
							$sheet->getCellByColumnAndRow($ktr, 3)->setValueExplicit($row['kitReference'][1]['referenceKitResult'], PHPExcel_Cell_DataType::TYPE_STRING);
							$ktr = ($aRow['number_of_samples'] - $keyv) + $ktr + 3;
						}
						if (isset($row['kitReference'][2]['referenceKitResult'])) {
							$ktr = $ktr + $keyv;
							$sheet->getCellByColumnAndRow($ktr, 3)->setValueExplicit($row['kitReference'][2]['referenceKitResult'], PHPExcel_Cell_DataType::TYPE_STRING);
						}
					}
					$ktr = 9;
				}
			}

			$currentRow = 4;
			$sheetThreeRow = 2;
			$docScoreRow = 3;
			$totScoreRow = 2;
			if (isset($shipmentResult) && count($shipmentResult) > 0) {

				foreach ($shipmentResult as $aRow) {
					$r = 0;
					$k = 0;
					$rehydrationDate = "";
					$shipmentTestDate = "";
					$sheetThreeCol = 0;
					$docScoreCol = 0;
					$totScoreCol = 0;
					$countCorrectResult = 0;

					$colCellObj = $sheet->getCellByColumnAndRow($r++, $currentRow);
					$colCellObj->setValueExplicit(ucwords($aRow['unique_identifier']), PHPExcel_Cell_DataType::TYPE_STRING);
					$cellName = $colCellObj->getColumn();
					$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['lab_name'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['dataManagerFirstName'] . $aRow['dataManagerLastName'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['region'], PHPExcel_Cell_DataType::TYPE_STRING);
					$shipmentReceiptDate = Application_Service_Common::ParseDateExcel($aRow['shipment_receipt_date']);
                    $shipmentTestDate = Application_Service_Common::ParseDateExcel($aRow['shipment_test_date']);

					if (trim($aRow['attributes']) != "") {
						$attributes = json_decode($aRow['attributes'], true);
						$sampleRehydrationDate = Application_Service_Common::ParseDateISO8601OrYYYYMMDD($attributes['sample_rehydration_date']);
                        $rehydrationDate = Application_Service_Common::ParseDateExcel($attributes["sample_rehydration_date"]);
					}

					$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['shipment_receipt_date'], PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($rehydrationDate, PHPExcel_Cell_DataType::TYPE_STRING);
					$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($shipmentTestDate, PHPExcel_Cell_DataType::TYPE_STRING);



					$sheetThree->getCellByColumnAndRow($sheetThreeCol++, $sheetThreeRow)->setValueExplicit(ucwords($aRow['unique_identifier']), PHPExcel_Cell_DataType::TYPE_STRING);
					$sheetThree->getCellByColumnAndRow($sheetThreeCol++, $sheetThreeRow)->setValueExplicit($aRow['lab_name'], PHPExcel_Cell_DataType::TYPE_STRING);

					//<-------------Document score sheet------------

					$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit(ucwords($aRow['unique_identifier']), PHPExcel_Cell_DataType::TYPE_STRING);
					$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit($aRow['lab_name'], PHPExcel_Cell_DataType::TYPE_STRING);

					if (isset($shipmentReceiptDate) && trim($shipmentReceiptDate) != "") {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit($documentationScorePerItem, PHPExcel_Cell_DataType::TYPE_STRING);
					} else {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit(0, PHPExcel_Cell_DataType::TYPE_STRING);
					}

					if (isset($aRow['supervisor_approval']) && strtolower($aRow['supervisor_approval']) == 'yes' && isset($aRow['participant_supervisor']) && trim($aRow['participant_supervisor']) != "") {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit($documentationScorePerItem, PHPExcel_Cell_DataType::TYPE_STRING);
					} else {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit(0, PHPExcel_Cell_DataType::TYPE_STRING);
					}

					if (isset($rehydrationDate) && trim($rehydrationDate) != "") {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit($documentationScorePerItem, PHPExcel_Cell_DataType::TYPE_STRING);
					} else {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit(0, PHPExcel_Cell_DataType::TYPE_STRING);
					}

					if ($shipmentTestDate != "") {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit($documentationScorePerItem, PHPExcel_Cell_DataType::TYPE_STRING);
					} else {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit(0, PHPExcel_Cell_DataType::TYPE_STRING);
					}

					if (isset($sampleRehydrationDate) && $shipmentTestDate != "") {
						$config = new Zend_Config_Ini(APPLICATION_PATH . DIRECTORY_SEPARATOR . "configs" . DIRECTORY_SEPARATOR . "config.ini", APPLICATION_ENV);
						$sampleRehydrationDate = new DateTime($attributes['sample_rehydration_date']);
						$testedOnDate = new DateTime($aRow['shipment_test_date']);
						$interval = $sampleRehydrationDate->diff($testedOnDate);

						// Testing should be done within 24*($config->evaluation->dts->sampleRehydrateDays) hours of rehydration.
						$sampleRehydrateDays = $config->evaluation->dts->sampleRehydrateDays;
						$rehydrateHours = $sampleRehydrateDays*24;

						if ($interval->days > $sampleRehydrateDays) {

							$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit(0, PHPExcel_Cell_DataType::TYPE_STRING);
						} else {
							$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit($documentationScorePerItem, PHPExcel_Cell_DataType::TYPE_STRING);
						}
					} else {
						$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit(0, PHPExcel_Cell_DataType::TYPE_STRING);
					}

					$documentScore = (($aRow['documentation_score'] / $config->evaluation->dts->documentationScore) * 100);
					$docScoreSheet->getCellByColumnAndRow($docScoreCol++, $docScoreRow)->setValueExplicit($documentScore, PHPExcel_Cell_DataType::TYPE_STRING);

					//-------------Document score sheet------------>
					//<------------ Total score sheet ------------

					$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit(ucwords($aRow['unique_identifier']), PHPExcel_Cell_DataType::TYPE_STRING);
					$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit($aRow['lab_name'], PHPExcel_Cell_DataType::TYPE_STRING);

					//------------ Total score sheet ------------>
					//Zend_Debug::dump($aRow['response']);
					if (count($aRow['response']) > 0) {

						if (isset($aRow['response'][0]['exp_date_1']) && trim($aRow['response'][0]['exp_date_1']) != "") {
							$aRow['response'][0]['exp_date_1'] = Application_Service_Common::ParseDateExcel($aRow['response'][0]['exp_date_1']);
						}
						if (isset($aRow['response'][0]['exp_date_2']) && trim($aRow['response'][0]['exp_date_2']) != "") {
							$aRow['response'][0]['exp_date_2'] = Application_Service_Common::ParseDateExcel($aRow['response'][0]['exp_date_2']);
						}
						if (isset($aRow['response'][0]['exp_date_3']) && trim($aRow['response'][0]['exp_date_3']) != "") {
							$aRow['response'][0]['exp_date_3'] = Application_Service_Common::ParseDateExcel($aRow['response'][0]['exp_date_3']);
						}

						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['testKitName1'], PHPExcel_Cell_DataType::TYPE_STRING);
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['lot_no_1'], PHPExcel_Cell_DataType::TYPE_STRING);
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['exp_date_1'], PHPExcel_Cell_DataType::TYPE_STRING);

						for ($k = 0; $k < $aRow['number_of_samples']; $k++) {
							//$row[] = $aRow[$k]['testResult1'];
							$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][$k]['testResult1'], PHPExcel_Cell_DataType::TYPE_STRING);
						}
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['testKitName2'], PHPExcel_Cell_DataType::TYPE_STRING);
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['lot_no_2'], PHPExcel_Cell_DataType::TYPE_STRING);
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['exp_date_2'], PHPExcel_Cell_DataType::TYPE_STRING);

						for ($k = 0; $k < $aRow['number_of_samples']; $k++) {
							//$row[] = $aRow[$k]['testResult2'];
							$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][$k]['testResult2'], PHPExcel_Cell_DataType::TYPE_STRING);
						}
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['testKitName3'], PHPExcel_Cell_DataType::TYPE_STRING);
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['lot_no_3'], PHPExcel_Cell_DataType::TYPE_STRING);
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][0]['exp_date_3'], PHPExcel_Cell_DataType::TYPE_STRING);

						for ($k = 0; $k < $aRow['number_of_samples']; $k++) {
							//$row[] = $aRow[$k]['testResult3'];
							$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][$k]['testResult3'], PHPExcel_Cell_DataType::TYPE_STRING);
						}

						for ($k = 0; $k < $aRow['number_of_samples']; $k++) {
							//$row[] = $aRow[$k]['finalResult'];
							$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['response'][$k]['finalResult'], PHPExcel_Cell_DataType::TYPE_STRING);

							$sheetThree->getCellByColumnAndRow($sheetThreeCol++, $sheetThreeRow)->setValueExplicit($aRow['response'][$k]['finalResult'], PHPExcel_Cell_DataType::TYPE_STRING);
							if (isset($aRow['response'][$k]['calculated_score']) && $aRow['response'][$k]['calculated_score'] == 'Pass' && $aRow['response'][$k]['sample_id'] == $refResult[$k]['sample_id']) {
								$countCorrectResult++;
							}
						}
						$sheet->getCellByColumnAndRow($r++, $currentRow)->setValueExplicit($aRow['user_comment'], PHPExcel_Cell_DataType::TYPE_STRING);

						$sheetThree->getCellByColumnAndRow($sheetThreeCol++, $sheetThreeRow)->setValueExplicit($countCorrectResult, PHPExcel_Cell_DataType::TYPE_STRING);

						$totPer = round((($countCorrectResult / $aRow['number_of_samples']) * 100), 2);
						$sheetThree->getCellByColumnAndRow($sheetThreeCol++, $sheetThreeRow)->setValueExplicit($totPer, PHPExcel_Cell_DataType::TYPE_STRING);

						$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit($countCorrectResult, PHPExcel_Cell_DataType::TYPE_STRING);
						$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit($totPer, PHPExcel_Cell_DataType::TYPE_STRING);

						$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit(($totPer * 0.9), PHPExcel_Cell_DataType::TYPE_STRING);
					}
					$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit($documentScore, PHPExcel_Cell_DataType::TYPE_STRING);
					$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit($aRow['documentation_score'], PHPExcel_Cell_DataType::TYPE_STRING);
					$totalScoreSheet->getCellByColumnAndRow($totScoreCol++, $totScoreRow)->setValueExplicit(($aRow['shipment_score'] + $aRow['documentation_score']), PHPExcel_Cell_DataType::TYPE_STRING);

					for ($i = 0; $i < $panelScoreHeadingCount; $i++) {
						$cellName = $sheetThree->getCellByColumnAndRow($i, $sheetThreeRow)->getColumn();
						$sheetThree->getStyle($cellName . $sheetThreeRow)->applyFromArray($borderStyle);
					}

					for ($i = 0; $i < $n; $i++) {
						$cellName = $sheet->getCellByColumnAndRow($i, $currentRow)->getColumn();
						$sheet->getStyle($cellName . $currentRow)->applyFromArray($borderStyle);
					}

					for ($i = 0; $i < $docScoreHeadingsCount; $i++) {
						$cellName = $docScoreSheet->getCellByColumnAndRow($i, $docScoreRow)->getColumn();
						$docScoreSheet->getStyle($cellName . $docScoreRow)->applyFromArray($borderStyle);
					}

					for ($i = 0; $i < $totScoreHeadingsCount; $i++) {
						$cellName = $totalScoreSheet->getCellByColumnAndRow($i, $totScoreRow)->getColumn();
						$totalScoreSheet->getStyle($cellName . $totScoreRow)->applyFromArray($borderStyle);
					}

					$currentRow++;

					$sheetThreeRow++;
					$docScoreRow++;
					$totScoreRow++;
				}
			}

			//----------- Second Sheet End----->
			$excel->setActiveSheetIndex(0);

			$writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
			$filename = $fileSafeShipmentCode . '-' . date('d-M-Y-H-i-s') . '.xls';
			$writer->save(UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename);
			return $filename;


	}

	public function generateDtsViralLoadExcelReport($shipmentId){

			$db = Zend_Db_Table_Abstract::getDefaultAdapter();

			$excel = new PHPExcel();

			$cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
			$cacheSettings = array('memoryCacheSize' => '180MB');

			$styleArray = array(
				'font' => array(
					'bold' => true,
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT,
					'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THIN,
					),
				)
			);

			$boldStyleArray = array(
				'font' => array(
					'bold' => true,
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_LEFT,
					'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
				)
			);

			$borderStyle = array(
				'font' => array(
					'bold' => true,
					'size'  => 12,
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THIN,
					),
				)
			);
			$vlBorderStyle = array(
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THIN,
					),
				)
			);


			$query = $db->select()->from('shipment')
					->where("shipment_id = ?", $shipmentId);
			$result = $db->fetchRow($query);


			$refQuery = $db->select()->from(array('refRes' => 'reference_result_vl'))->where("refRes.shipment_id = ?", $shipmentId)->where("refRes.control!=1");
			$refResult = $db->fetchAll($refQuery);

			$colNamesArray = array();
			$colNamesArray[] = "Lab ID";

			$firstSheet = new PHPExcel_Worksheet($excel, 'Overall Results');
			$excel->addSheet($firstSheet, 0);

			$firstSheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("Lab ID", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$firstSheet->getStyleByColumnAndRow(0, 1)->applyFromArray($borderStyle);

			$firstSheet->getDefaultRowDimension()->setRowHeight(15);

			$colNameCount = 1;
			foreach($refResult as $refRow){
				$colNamesArray[] = $refRow['sample_label'];
				$firstSheet->getCellByColumnAndRow($colNameCount, 1)->setValueExplicit(html_entity_decode($refRow['sample_label'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
				$colNameCount++;
			}
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Date Received", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$colNamesArray[] = "Date Received";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Date Tested", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$colNamesArray[] = "Date Tested";


			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Assay", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Assay";

			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Department Name", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Department Name";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Region", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Region";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Site Type", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Site Type";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Assay Expiration Date", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Assay Expiration Date";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Assay Lot Number", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Assay Lot Number";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Specimen Volume", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Specimen Volume";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Supervisor Name", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			$colNamesArray[] = "Supervisor Name";
			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Participant Comment", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$colNamesArray[] = "Participant Comments";

			$firstSheet->setTitle('OVERALL');

						$queryOverAll = $db->select()->from(array('s'=>'shipment'))
								->joinLeft(array('spm' => 'shipment_participant_map'),"spm.shipment_id = s.shipment_id")
								->joinLeft(array('p' => 'participant'),"p.participant_id = spm.participant_id")
								->joinLeft(array('st'=>'r_site_type'),"st.r_stid=p.site_type")
								->where("s.shipment_id = ?", $shipmentId);
			$resultOverAll = $db->fetchAll($queryOverAll);

			$row = 1; // $row 0 is already the column headings

			$schemeService = new Application_Service_Schemes();
			$assayList = $schemeService->getVlAssay();

			$assayWiseData = array();

			foreach($resultOverAll as $rowOverAll){
				$row++;

				$queryResponse = $db->select()->from(array('res' => 'response_result_vl'))
								->where("res.shipment_map_id = ?", $rowOverAll['map_id']);
				$resultResponse = $db->fetchAll($queryResponse);

				$attributes = json_decode($rowOverAll['attributes'], true);

				if(isset($attributes['other_assay']) && $attributes['other_assay'] != ""){
					$assayName = "Other - ".$attributes['other_assay'];
				}else{
					$assayName = (array_key_exists ($attributes['vl_assay'] , $assayList )) ? $assayList[$attributes['vl_assay']] : "";
				}

				$assayExpirationDate = "";
				if(isset($attributes['assay_expiration_date']) && $attributes['assay_expiration_date'] != ""){
					$assayExpirationDate = Application_Service_Common::ParseDateHumanFormat($attributes['assay_expiration_date']);
				}

				$assayLotNumber = "";
				if(isset($attributes['assay_lot_number']) && $attributes['assay_lot_number'] != ""){
					$assayLotNumber = ($attributes['assay_lot_number']);
				}

				$specimenVolume = "";
				if(isset($attributes['specimen_volume']) && $attributes['specimen_volume'] != ""){
					$specimenVolume = ($attributes['specimen_volume']);
				}
				// we are also building the data required for other Assay Sheets
				if($attributes['vl_assay'] > 0){
					$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $rowOverAll['unique_identifier'];
				}


				$firstSheet->getCellByColumnAndRow(0, $row)->setValueExplicit(html_entity_decode($rowOverAll['unique_identifier'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

				$col = 1;
				foreach($resultResponse as $responseRow){
					$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($responseRow['reported_viral_load'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					// we are also building the data required for other Assay Sheets
					if($attributes['vl_assay'] > 0){
						$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $responseRow['reported_viral_load'];
					}
				}

				$receiptDate = Application_Service_Common::ParseDateHumanFormat($rowOverAll['shipment_receipt_date']);
				$testDate = Application_Service_Common::ParseDateHumanFormat($rowOverAll['shipment_test_date']);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($receiptDate, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($testDate, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

				// we are also building the data required for other Assay Sheets
				if($attributes['vl_assay'] > 0){
					$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $receiptDate;
					$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $testDate;
				}


				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($assayName, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($rowOverAll['department_name'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($rowOverAll['region'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($rowOverAll['site_type'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($assayExpirationDate, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($assayLotNumber, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($specimenVolume, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($rowOverAll['participant_supervisor'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($rowOverAll['user_comment'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $assayName;
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $rowOverAll['department_name'];
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $rowOverAll['region'];
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $rowOverAll['site_type'];
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $assayExpirationDate;
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $assayLotNumber;
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $specimenVolume;
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $rowOverAll['participant_supervisor'];
				$assayWiseData[$attributes['vl_assay']][$rowOverAll['unique_identifier']][] = $rowOverAll['user_comment'];

			}


			foreach(range('A','Z') as $columnID) {
				$firstSheet->getColumnDimension($columnID)
					->setAutoSize(true);
			}
			//Zend_Debug::dump($assayWiseData);die;

			$db = Zend_Db_Table_Abstract::getDefaultAdapter();
			$assayRes = $db->fetchAll($db->select()->from('r_vl_assay'));

			$countOfVlAssaySheet = 1;
			foreach($assayRes as $assayRow){
				$newsheet = new PHPExcel_Worksheet($excel, '');
				$excel->addSheet($newsheet, $countOfVlAssaySheet);

				$newsheet->getDefaultRowDimension()->setRowHeight(15);


				foreach(range('A','Z') as $columnID) {
					$newsheet->getColumnDimension($columnID)->setAutoSize(true);
				}

				$i = 0;
				$startAt = 28;
				foreach($colNamesArray as $colName){
					$newsheet->getCellByColumnAndRow($i, $startAt)->setValueExplicit(html_entity_decode($colName, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getStyleByColumnAndRow($i, $startAt)->applyFromArray($borderStyle);
					$i++;
				}
				//get vl_assay wise low high limit
				$refVlCalci = $db->fetchAll($db->select()->from(array('rvc'=>'reference_vl_calculation'))
							    ->join(array('rrv'=>'reference_result_vl'),'rrv.sample_id=rvc.sample_id AND rrv.shipment_id='.$result['shipment_id'],array('sample_label'))
							    ->where('rvc.shipment_id='.$result['shipment_id'])->where('rvc.vl_assay='.$assayRow['id'])
								->where('rrv.control!=1'));
				if(count($refVlCalci)>0){
				    //write in excel low and high limit title
				    $newsheet->mergeCells('A1:F1');
				    $newsheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode('System Generated', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 2)->setValueExplicit(html_entity_decode('Sample', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 3)->setValueExplicit(html_entity_decode('Q1', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 4)->setValueExplicit(html_entity_decode('Q3', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 5)->setValueExplicit(html_entity_decode('IQR', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 6)->setValueExplicit(html_entity_decode('Quartile Low', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 7)->setValueExplicit(html_entity_decode('Quartile High', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 8)->setValueExplicit(html_entity_decode('Mean', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 9)->setValueExplicit(html_entity_decode('SD', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 10)->setValueExplicit(html_entity_decode('CV', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 11)->setValueExplicit(html_entity_decode('Low Limit', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				    $newsheet->getCellByColumnAndRow(0, 12)->setValueExplicit(html_entity_decode('High Limit', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

				    $newsheet->getStyleByColumnAndRow(0, 1)->applyFromArray($boldStyleArray);
				    $newsheet->getStyleByColumnAndRow(0, 2)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 3)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 4)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 5)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 6)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 7)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 8)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 9)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 10)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 11)->applyFromArray($styleArray);
				    $newsheet->getStyleByColumnAndRow(0, 12)->applyFromArray($styleArray);

				    $k = 1;
				    $manual = array();
				    foreach($refVlCalci as $calculation){
					$newsheet->getCellByColumnAndRow($k, 2)->setValueExplicit(html_entity_decode($calculation['sample_label'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 3)->setValueExplicit(html_entity_decode(round($calculation['q1'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 4)->setValueExplicit(html_entity_decode(round($calculation['q3'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 5)->setValueExplicit(html_entity_decode(round($calculation['iqr'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 6)->setValueExplicit(html_entity_decode(round($calculation['quartile_low'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 7)->setValueExplicit(html_entity_decode(round($calculation['quartile_high'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 8)->setValueExplicit(html_entity_decode(round($calculation['mean'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 9)->setValueExplicit(html_entity_decode(round($calculation['sd'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 10)->setValueExplicit(html_entity_decode(round($calculation['cv'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 11)->setValueExplicit(html_entity_decode(round($calculation['low_limit'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow($k, 12)->setValueExplicit(html_entity_decode(round($calculation['high_limit'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

					$newsheet->getStyleByColumnAndRow($k, 2)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 3)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 4)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 5)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 6)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 7)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 8)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 9)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 10)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 11)->applyFromArray($vlBorderStyle);
					$newsheet->getStyleByColumnAndRow($k, 12)->applyFromArray($vlBorderStyle);
					if($calculation['manual_mean']!=0){
					    $manual[] = 'yes';
					}elseif($calculation['manual_sd']!=0){
					    $manual[] = 'yes';
					}elseif($calculation['manual_low_limit']!=0){
					    $manual[] = 'yes';
					}elseif($calculation['manual_high_limit']!=0){
					    $manual[] = 'yes';
					}
					elseif($calculation['manual_cv']!=0){
					    $manual[] = 'yes';
					}
					elseif($calculation['manual_q1']!=0){
					    $manual[] = 'yes';
					}
					elseif($calculation['manual_q3']!=0){
					    $manual[] = 'yes';
					}
					elseif($calculation['manual_iqr']!=0){
					    $manual[] = 'yes';
					}
					elseif($calculation['manual_quartile_low']!=0){
					    $manual[] = 'yes';
					}
					elseif($calculation['manual_quartile_high']!=0){
					    $manual[] = 'yes';
					}
					$k++;
				    }
				    if(count($manual)>0){
					$newsheet->mergeCells('A15:F15');
					$newsheet->getCellByColumnAndRow(0, 15)->setValueExplicit(html_entity_decode('Manual Generated', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 16)->setValueExplicit(html_entity_decode('Sample', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 17)->setValueExplicit(html_entity_decode('Manual Q1', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 18)->setValueExplicit(html_entity_decode('Manual Q3', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 19)->setValueExplicit(html_entity_decode('Manual IQR', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 20)->setValueExplicit(html_entity_decode('Manual Quartile Low', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 21)->setValueExplicit(html_entity_decode('Manual Quartile High', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 22)->setValueExplicit(html_entity_decode('Manual Mean', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 23)->setValueExplicit(html_entity_decode('Manual SD', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 24)->setValueExplicit(html_entity_decode('Manual CV', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 25)->setValueExplicit(html_entity_decode('Manual Low Limit', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					$newsheet->getCellByColumnAndRow(0, 26)->setValueExplicit(html_entity_decode('Manual High Limit', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

					$newsheet->getStyleByColumnAndRow(0, 15)->applyFromArray($boldStyleArray);
					$newsheet->getStyleByColumnAndRow(0, 16)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 17)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 18)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 19)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 20)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 21)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 22)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 23)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 24)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 25)->applyFromArray($styleArray);
					$newsheet->getStyleByColumnAndRow(0, 26)->applyFromArray($styleArray);
					$k = 1;
					foreach($refVlCalci as $calculation){
					    $newsheet->getCellByColumnAndRow($k, 16)->setValueExplicit(html_entity_decode($calculation['sample_label'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 17)->setValueExplicit(html_entity_decode(round($calculation['manual_q1'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 18)->setValueExplicit(html_entity_decode(round($calculation['manual_q3'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 19)->setValueExplicit(html_entity_decode(round($calculation['manual_iqr'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 20)->setValueExplicit(html_entity_decode(round($calculation['manual_quartile_low'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 21)->setValueExplicit(html_entity_decode(round($calculation['manual_quartile_high'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 22)->setValueExplicit(html_entity_decode(round($calculation['manual_mean'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 23)->setValueExplicit(html_entity_decode(round($calculation['manual_sd'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 24)->setValueExplicit(html_entity_decode(round($calculation['manual_cv'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 25)->setValueExplicit(html_entity_decode(round($calculation['manual_low_limit'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
					    $newsheet->getCellByColumnAndRow($k, 26)->setValueExplicit(html_entity_decode(round($calculation['manual_high_limit'],4), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

					    $newsheet->getStyleByColumnAndRow($k, 16)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 17)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 18)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 19)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 20)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 21)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 22)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 23)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 24)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 25)->applyFromArray($vlBorderStyle);
					    $newsheet->getStyleByColumnAndRow($k, 26)->applyFromArray($vlBorderStyle);

					    $k++;
					}
				    }
				}
				//

				$assayData = isset($assayWiseData[$assayRow['id']]) ? $assayWiseData[$assayRow['id']] : array();
				//var_dump($assayData);die;
				$newsheet->setTitle(strtoupper($assayRow['short_name']));
				$row = $startAt; // $row 1-$startAt already occupied

				foreach($assayData as $assayKey => $assayRow){
					$row++;
					$noOfCols = count($assayRow);
					for($c=0;$c<$noOfCols;$c++){
						$newsheet->getCellByColumnAndRow($c, $row)->setValueExplicit(html_entity_decode($assayRow[$c], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
						$newsheet->getStyleByColumnAndRow($c,$row)->applyFromArray($vlBorderStyle);
					}
				}

				$countOfVlAssaySheet++;
			}

			//var_dump($assayList);die;

			$excel->setActiveSheetIndex(0);

			$writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
            $fileSafeShipmentCode = str_replace( ' ', '-', str_replace(array_merge(
                array_map('chr', range(0, 31)),
                array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
            ), '', $result['shipment_code']));
			$filename = $fileSafeShipmentCode . '-' . date('d-M-Y-H-i-s') .rand(). '.xls';
			$writer->save(UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename);
			return $filename;


	}

	public function generateDbsEidExcelReport($shipmentId){

			$db = Zend_Db_Table_Abstract::getDefaultAdapter();

			$excel = new PHPExcel();

			$cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
			$cacheSettings = array('memoryCacheSize' => '180MB');

			$styleArray = array(
				'font' => array(
					'bold' => true,
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
					'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THICK,
					),
				)
			);

			$borderStyle = array(
				'font' => array(
					'bold' => true,
					'size'  => 12,
				),
				'alignment' => array(
					'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
				),
				'borders' => array(
					'outline' => array(
						'style' => PHPExcel_Style_Border::BORDER_THIN,
					),
				)
			);

			$query = $db->select()->from('shipment')
					->where("shipment_id = ?", $shipmentId);
			$result = $db->fetchRow($query);


			$refQuery = $db->select()->from(array('refRes' => 'reference_result_eid'))->where("refRes.shipment_id = ?", $shipmentId);
			$refResult = $db->fetchAll($refQuery);


			$firstSheet = new PHPExcel_Worksheet($excel, 'DBS EID PE Results');
			$excel->addSheet($firstSheet, 0);

			$firstSheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("Lab ID", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow(0, 1)->applyFromArray($borderStyle);

			$firstSheet->getCellByColumnAndRow(1, 1)->setValueExplicit(html_entity_decode("Lab Name", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow(1, 1)->applyFromArray($borderStyle);

			$firstSheet->getCellByColumnAndRow(2, 1)->setValueExplicit(html_entity_decode("Department", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow(2, 1)->applyFromArray($borderStyle);

			$firstSheet->getCellByColumnAndRow(3, 1)->setValueExplicit(html_entity_decode("Region", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow(3, 1)->applyFromArray($borderStyle);

			$firstSheet->getCellByColumnAndRow(4, 1)->setValueExplicit(html_entity_decode("Site Type", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow(4, 1)->applyFromArray($borderStyle);

			$firstSheet->getCellByColumnAndRow(5, 1)->setValueExplicit(html_entity_decode("Sample Rehydration Date", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow(5, 1)->applyFromArray($borderStyle);

			$firstSheet->getDefaultRowDimension()->setRowHeight(15);

			$colNameCount = 6;
			foreach($refResult as $refRow){
				$firstSheet->getCellByColumnAndRow($colNameCount, 1)->setValueExplicit(html_entity_decode($refRow['sample_label'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
				$colNameCount++;
			}

			$firstSheet->getCellByColumnAndRow($colNameCount, 1)->setValueExplicit(html_entity_decode("Extraction", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow($colNameCount++, 1)->applyFromArray($borderStyle);

			$firstSheet->getCellByColumnAndRow($colNameCount, 1)->setValueExplicit(html_entity_decode("Detection", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow($colNameCount++, 1)->applyFromArray($borderStyle);

			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Date Received", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);


			$firstSheet->getStyleByColumnAndRow($colNameCount, 1)->applyFromArray($borderStyle);
			$firstSheet->getCellByColumnAndRow($colNameCount++, 1)->setValueExplicit(html_entity_decode("Date Tested", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);


			$firstSheet->setTitle('DBS EID PE Results');

			$queryOverAll = $db->select()->from(array('s'=>'shipment'))
								->joinLeft(array('spm' => 'shipment_participant_map'),"spm.shipment_id = s.shipment_id")
								->joinLeft(array('p' => 'participant'),"p.participant_id = spm.participant_id")
								->joinLeft(array('st'=>'r_site_type'),"st.r_stid=p.site_type")
								->where("s.shipment_id = ?", $shipmentId);
			$resultOverAll = $db->fetchAll($queryOverAll);

			$row = 1; // $row 0 is already the column headings

			$schemeService = new Application_Service_Schemes();
			$extractionAssayList = $schemeService->getEidExtractionAssay();
			$detectionAssayList = $schemeService->getEidDetectionAssay();

			//Zend_Debug::dump($extractionAssayList);die;

			foreach($resultOverAll as $rowOverAll){
				//Zend_Debug::dump($rowOverAll);
				$row++;

				$queryResponse = $db->select()->from(array('res' => 'response_result_eid'))
								->joinLeft(array('pr'=>'r_possibleresult'),"res.reported_result=pr.id")
								->where("res.shipment_map_id = ?", $rowOverAll['map_id']);
				$resultResponse = $db->fetchAll($queryResponse);

				$attributes = json_decode($rowOverAll['attributes'], true);
				$extraction = (array_key_exists ($attributes['extraction_assay'] , $extractionAssayList )) ? $extractionAssayList[$attributes['extraction_assay']] : "";
				$detection = (array_key_exists ($attributes['detection_assay'] , $detectionAssayList )) ? $detectionAssayList[$attributes['detection_assay']] : "";
				$sampleRehydrationDate = (isset($attributes['sample_rehydration_date'])) ? Application_Service_Common::ParseDateHumanFormat($attributes['sample_rehydration_date']) : "";


				$firstSheet->getCellByColumnAndRow(0, $row)->setValueExplicit(html_entity_decode($rowOverAll['unique_identifier'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow(1, $row)->setValueExplicit(html_entity_decode($rowOverAll['lab_name'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow(2, $row)->setValueExplicit(html_entity_decode($rowOverAll['department_name'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow(3, $row)->setValueExplicit(html_entity_decode($rowOverAll['region'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow(4, $row)->setValueExplicit(html_entity_decode($rowOverAll['site_type'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow(5, $row)->setValueExplicit(html_entity_decode($sampleRehydrationDate, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

				$col = 6;
				foreach($resultResponse as $responseRow){
					$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($responseRow['response'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				}

				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($extraction, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($detection, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

				$receiptDate = Application_Service_Common::ParseDateHumanFormat($rowOverAll['shipment_receipt_date']);
				$testDate = Application_Service_Common::ParseDateHumanFormat($rowOverAll['shipment_test_date']);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($receiptDate, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				$firstSheet->getCellByColumnAndRow($col++, $row)->setValueExplicit(html_entity_decode($testDate, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

			}

			foreach(range('A','Z') as $columnID) {
				$firstSheet->getColumnDimension($columnID)
					->setAutoSize(true);
			}

			$excel->setActiveSheetIndex(0);

			$writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
            $fileSafeShipmentCode = str_replace( ' ', '-', str_replace(array_merge(
                array_map('chr', range(0, 31)),
                array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
            ), '', $result['shipment_code']));
			$filename = $fileSafeShipmentCode . '-' . date('d-M-Y-H-i-s') .rand(). '.xls';
			$writer->save(UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename);
			return $filename;


	}

    public function addSampleNameInArray($shipmentId, $headings) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $query = $db->select()->from('reference_result_dts', array('sample_label'))
                        ->where("shipment_id = ?", $shipmentId)->order("sample_id");
        $result = $db->fetchAll($query);
        foreach ($result as $res) {
            array_push($headings, $res['sample_label']);
        }
        return $headings;
    }

    public function getShipmentsByScheme($schemeType, $startDate, $endDate) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $db->select()->from(array('s' => 'shipment'), array('s.shipment_id', 's.shipment_code', 's.scheme_type', 's.shipment_date',));
        if (isset($startDate) && $startDate != "") {
            $sQuery->where("DATE(s.shipment_date) >= ?", $startDate);
        }
        if (isset($endDate) && $endDate != "") {
            $sQuery->where("DATE(s.shipment_date) <= ?", $endDate);
        }
        if (isset($schemeType) && $schemeType != "") {
            $sQuery->where("s.scheme_type = ?", $schemeType);
        }
        $sQuery->order("s.shipment_id");
        $resultArray = $db->fetchAll($sQuery);
        return $resultArray;
    }

    public function getFinalisedShipmentsByScheme($schemeType, $startDate, $endDate) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();

        $sQuery = $db->select()->from(array('s' => 'shipment'), array('s.shipment_id', 's.shipment_code', 's.scheme_type', 's.shipment_date',));
        if (isset($startDate) && $startDate != "") {
            $sQuery->where("DATE(s.shipment_date) >= ?", $startDate);
        }
        if (isset($endDate) && $endDate != "") {
            $sQuery->where("DATE(s.shipment_date) <= ?", $endDate);
        }
        if (isset($schemeType) && $schemeType != "") {
            $sQuery->where("s.scheme_type = ?", $schemeType);
        }
        $sQuery->where("s.status = 'finalized'");
        $sQuery->order("s.shipment_id");
        $resultArray = $db->fetchAll($sQuery);
        return $resultArray;
    }

    public function getCorrectiveActionReport($parameters) {

        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array(new Zend_Db_Expr('count("cam.corrective_action_id")'), 'ca.corrective_action');
        $searchColumns = array('total_corrective', 'ca.corrective_action');
        $orderColumns = array(new Zend_Db_Expr('count("cam.corrective_action_id")'), 'ca.corrective_action');

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = 'shipment_id';
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
                $colSize = count($searchColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($searchColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }

        /*
         * SQL queries
         * Get data to display
         */


        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();



        $totalQuery = $dbAdapter->select()->from(array('s' => 'shipment'), array("average_score"))
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array("total_shipped" => new Zend_Db_Expr('count("sp.map_id")'),
            "total_responses" => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            "valid_responses" => new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
            ));

        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $totalQuery = $totalQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $totalQuery = $totalQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $totalQuery = $totalQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $totalQuery = $totalQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }
        //die($totalQuery);
        $totalResult = $dbAdapter->fetchRow($totalQuery);

        $totalShipped = ($totalResult['total_shipped']);
        $totalResp = ($totalResult['total_responses']);
        $validResp = ($totalResult['valid_responses']);
        $avgScore = ($totalResult['average_score']);

        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), array())
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array())
                ->join(array('cam' => 'dts_shipment_corrective_action_map'), 'sp.map_id=cam.shipment_map_id', array("total_corrective" => new Zend_Db_Expr("count('corrective_action_id')")))
                ->join(array('ca' => 'r_dts_corrective_actions'), 'cam.corrective_action_id=ca.action_id', array("action_id", "corrective_action"))
                ->where("sp.is_excluded = 'no'")
                ->group(array('ca.action_id'));

        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }


        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }


        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        $sQuerySession = new Zend_Session_Namespace('CorrectiveActionsExcel');
        $sQuerySession->correctiveActionsQuery = $sQuery;

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        //echo $sQuery;die;
        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sWhere = "";
        //$sQuery = $dbAdapter->select()->from(array('s'=>'shipment'), new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));


        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"))
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array())
                ->join(array('cam' => 'dts_shipment_corrective_action_map'), 'sp.map_id=cam.shipment_map_id', array())
                ->join(array('ca' => 'r_dts_corrective_actions'), 'cam.corrective_action_id=ca.action_id', array())
                ->where("sp.is_excluded = 'no'")
                ->group(array('ca.action_id'));

        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }

        $aResultTotal = $dbAdapter->fetchAll($sQuery);
        $iTotal = count($aResultTotal);

        /*
         * Output
         */

        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array(),
            "totalShipped" => (int) $totalShipped,
            "totalResponses" => (int) $totalResp,
            "validResponses" => (int) $validResp,
            "averageScore" => round((double) $avgScore, 2)
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row[] = $aRow['corrective_action'];
            $row[] = $aRow['total_corrective'];


            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getCorrectiveActionReportByShipmentId($shipmentId) {
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), array('s.shipment_code'))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array('map_id'))
                ->join(array('cam' => 'dts_shipment_corrective_action_map'), 'cam.shipment_map_id=sp.map_id', array("total_corrective" => new Zend_Db_Expr('count("cam.corrective_action_id")')))
                ->join(array('ca' => 'r_dts_corrective_actions'), 'ca.action_id=cam.corrective_action_id', array("action_id", "corrective_action"))
                ->where("s.shipment_id = ?", $shipmentId)
                ->group(array('cam.corrective_action_id'))
				->order(array('total_corrective DESC'));

        return $dbAdapter->fetchAll($sQuery);
    }

    public function exportParticipantPerformanceReport($params) {

        $headings = array('Scheme', 'Shipment Date', 'Shipment Code', 'No. of Shipments', 'No. of Responses', 'No. of Valid Responses', 'No. of Passed Responses', 'Pass %', 'Average Score');
        try {
            $excel = new PHPExcel();
            $cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
            $cacheSettings = array('memoryCacheSize' => '80MB');
            PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);
            $output = array();
            $sheet = $excel->getActiveSheet();
            $styleArray = array(
                'font' => array(
                    'bold' => true,
                ),
                'alignment' => array(
                    'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                    'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
                ),
                'borders' => array(
                    'outline' => array(
                        'style' => PHPExcel_Style_Border::BORDER_THICK,
                    ),
                )
            );

            $colNo = 0;
            $sheet->mergeCells('A1:I1');
            $sheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode('Participant Performance Overview Report', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            if (isset($params['shipmentName']) && trim($params['shipmentName']) != "") {
                $sheet->getCellByColumnAndRow(0, 2)->setValueExplicit(html_entity_decode('Shipment', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(1, 2)->setValueExplicit(html_entity_decode($params['shipmentName'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            }
            $sheet->getCellByColumnAndRow(0, 3)->setValueExplicit(html_entity_decode('Selected Date Range', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(1, 3)->setValueExplicit(html_entity_decode($params['dateRange'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

            $sheet->getStyleByColumnAndRow(0, 1)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(0, 2)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(0, 3)->getFont()->setBold(true);

            foreach ($headings as $field => $value) {
                $sheet->getCellByColumnAndRow($colNo, 5)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getStyleByColumnAndRow($colNo, 5)->getFont()->setBold(true);
                $colNo++;
            }

            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $sQuerySession = new Zend_Session_Namespace('participantPerformanceExcel');
            $rResult = $db->fetchAll($sQuerySession->participantQuery);
            foreach ($rResult as $aRow) {

                $row = array();
                $row[] = $aRow['scheme_name'];
                $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['shipment_date']);
                $row[] = $aRow['shipment_code'];
                $row[] = $aRow['total_shipped'];
                $row[] = $aRow['total_responses'];
                $row[] = $aRow['valid_responses'];
                $row[] = $aRow['total_passed'];
                $row[] = round($aRow['pass_percentage'], 2);
                $row[] = round($aRow['average_score'], 2);
                $output[] = $row;
            }

            foreach ($output as $rowNo => $rowData) {
                $colNo = 0;
                foreach ($rowData as $field => $value) {
                    if (!isset($value)) {
                        $value = "";
                    }
                    $sheet->getCellByColumnAndRow($colNo, $rowNo + 6)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    if ($colNo == (sizeof($headings) - 1)) {
                        $sheet->getColumnDimensionByColumn($colNo)->setWidth(150);
                        $sheet->getStyleByColumnAndRow($colNo, $rowNo + 6)->getAlignment()->setWrapText(true);
                    }
                    $colNo++;
                }
            }

            if (!file_exists(UPLOAD_PATH) && !is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH);
            }

            $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
            $filename = 'participant-performance-' . date('d-M-Y-H-i-s') . '.xls';
            $writer->save(UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename);
            return $filename;
        } catch (Exception $exc) {
            return "";
            $sQuerySession->participantQuery = '';
            error_log("GENERATE-PARTICIPANT-PERFORMANCE-REPORT-EXCEL--" . $exc->getMessage());
            error_log($exc->getTraceAsString());
        }
    }

    public function exportCorrectiveActionsReport($params) {

        $headings = array('Corrective Action', 'No. of Responses having this corrective action');
        try {
            $excel = new PHPExcel();
            $cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
            $cacheSettings = array('memoryCacheSize' => '80MB');
            PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);
            $output = array();
            $sheet = $excel->getActiveSheet();
            $styleArray = array(
                'font' => array(
                    'bold' => true,
                ),
                'alignment' => array(
                    'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                    'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
                ),
                'borders' => array(
                    'outline' => array(
                        'style' => PHPExcel_Style_Border::BORDER_THICK,
                    ),
                )
            );

            $colNo = 0;
            $sheet->mergeCells('A1:I1');
            $sheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode('Participant Corrective Action Overview', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            if (isset($params['shipmentName']) && trim($params['shipmentName']) != "") {
                $sheet->getCellByColumnAndRow(0, 2)->setValueExplicit(html_entity_decode('Shipment', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(1, 2)->setValueExplicit(html_entity_decode($params['shipmentName'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            }
            $sheet->getCellByColumnAndRow(0, 3)->setValueExplicit(html_entity_decode('Selected Date Range', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(1, 3)->setValueExplicit(html_entity_decode($params['dateRange'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);


            $sheet->getStyleByColumnAndRow(0, 1)->getFont()->setBold(true);

            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $totalQuery = $db->select()->from(array('s' => 'shipment'), array("average_score"))
                    ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array("total_shipped" => new Zend_Db_Expr('count("sp.map_id")'),
                "total_responses" => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
                "valid_responses" => new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
                ));

            if (isset($params['scheme']) && $params['scheme'] != "") {
                $totalQuery = $totalQuery->where("s.scheme_type = ?", $params['scheme']);
            }

            if (isset($params['dateStartDate']) && $params['dateStartDate'] != "" && isset($params['dateEndDate']) && $params['dateEndDate'] != "") {
                $totalQuery = $totalQuery->where("DATE(s.shipment_date) >= ?", $params['dateStartDate']);
                $totalQuery = $totalQuery->where("DATE(s.shipment_date) <= ?", $params['dateEndDate']);
            }

            if (isset($params['shipmentId']) && $params['shipmentId'] != "") {
                $totalQuery = $totalQuery->where("s.shipment_id = ?", $params['shipmentId']);
            }
            //die($totalQuery);
            $totalResult = $db->fetchRow($totalQuery);

            $totalShipped = ($totalResult['total_shipped']);
            $totalResp = ($totalResult['total_responses']);
            $validResp = ($totalResult['valid_responses']);
            $avgScore = round($totalResult['average_score'], 2) . '%';

            $sheet->mergeCells('A4:B4');
            $sheet->getCellByColumnAndRow(0, 4)->setValueExplicit(html_entity_decode('Total shipped :' . $totalShipped, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getStyleByColumnAndRow(0, 4)->getFont()->setBold(true);
            $sheet->mergeCells('A5:B5');
            $sheet->getCellByColumnAndRow(0, 5)->setValueExplicit(html_entity_decode('Total number of responses :' . $totalResp, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getStyleByColumnAndRow(0, 5)->getFont()->setBold(true);
            $sheet->mergeCells('A6:B6');
            $sheet->getCellByColumnAndRow(0, 6)->setValueExplicit(html_entity_decode('Total number of valid responses :' . $validResp, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getStyleByColumnAndRow(0, 6)->getFont()->setBold(true);
            $sheet->mergeCells('A7:B7');
            $sheet->getCellByColumnAndRow(0, 7)->setValueExplicit(html_entity_decode('Average score :' . $avgScore, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getStyleByColumnAndRow(0, 7)->getFont()->setBold(true);

            foreach ($headings as $field => $value) {
                $sheet->getCellByColumnAndRow($colNo, 9)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getStyleByColumnAndRow($colNo, 9)->getFont()->setBold(true);
                $colNo++;
            }


            $sQuerySession = new Zend_Session_Namespace('CorrectiveActionsExcel');
            $rResult = $db->fetchAll($sQuerySession->correctiveActionsQuery);

            if (count($rResult) > 0) {
                foreach ($rResult as $aRow) {
                    $row = array();
                    $row[] = $aRow['corrective_action'];
                    $row[] = $aRow['total_corrective'];
                    $output[] = $row;
                }
            } else {
                $row = array();
                $row[] = 'No result found';
                $output[] = $row;
            }

            foreach ($output as $rowNo => $rowData) {
                $colNo = 0;
                foreach ($rowData as $field => $value) {
                    if (!isset($value)) {
                        $value = "";
                    }
                    $sheet->getCellByColumnAndRow($colNo, $rowNo + 10)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    if ($colNo == (sizeof($headings) - 1)) {
                        $sheet->getColumnDimensionByColumn($colNo)->setWidth(100);
                        $sheet->getStyleByColumnAndRow($colNo, $rowNo + 10)->getAlignment()->setWrapText(true);
                    }
                    $colNo++;
                }
            }

            if (!file_exists(UPLOAD_PATH) && !is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH);
            }

            $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
            $filename = 'corrective-actions-' . date('d-M-Y-H-i-s') . '.xls';
            $writer->save(UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename);
            return $filename;
        } catch (Exception $exc) {
            return "";
            $sQuerySession->correctiveActionsQuery = '';
            error_log("GENERATE-PARTICIPANT-CORRECTIVE-ACTIONS--REPORT-EXCEL--" . $exc->getMessage());
            error_log($exc->getTraceAsString());
        }
    }

    public function exportShipmentsReport($params) {

        $headings = array('Scheme', 'Shipment Code', 'Sample Label', 'Reference Result', 'Total Positive Responses', 'Total Negative Responses', 'Total Indeterminate Responses', 'Total Responses', 'Total Valid Responses(Total - Excluded)', 'Total Passed');
        try {
            $excel = new PHPExcel();
            $cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
            $cacheSettings = array('memoryCacheSize' => '80MB');
            PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);
            $output = array();
            $sheet = $excel->getActiveSheet();
            $styleArray = array(
                'font' => array(
                    'bold' => true,
                ),
                'alignment' => array(
                    'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                    'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
                ),
                'borders' => array(
                    'outline' => array(
                        'style' => PHPExcel_Style_Border::BORDER_THICK,
                    ),
                )
            );

            $colNo = 0;
            $sheet->mergeCells('A1:I1');
            $sheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode('Shipment Response Overview', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            if (isset($params['shipmentName']) && trim($params['shipmentName']) != "") {
                $sheet->getCellByColumnAndRow(0, 2)->setValueExplicit(html_entity_decode('Shipment', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getCellByColumnAndRow(1, 2)->setValueExplicit(html_entity_decode($params['shipmentName'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            }
            $sheet->getCellByColumnAndRow(0, 3)->setValueExplicit(html_entity_decode('Selected Date Range', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(1, 3)->setValueExplicit(html_entity_decode($params['dateRange'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);


            $sheet->getStyleByColumnAndRow(0, 3)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(0, 2)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(0, 1)->getFont()->setBold(true);
            foreach ($headings as $field => $value) {
                $sheet->getCellByColumnAndRow($colNo, 5)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getStyleByColumnAndRow($colNo, 5)->getFont()->setBold(true);
                $colNo++;
            }

            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $sQuerySession = new Zend_Session_Namespace('shipmentExportExcel');
            $rResult = $db->fetchAll($sQuerySession->shipmentExportQuery);
            foreach ($rResult as $aRow) {

                $row = array();
                $row[] = $aRow['scheme_name'];
                $row[] = $aRow['shipment_code'];
                $row[] = $aRow['sample_label'];
                $row[] = $aRow['response'];
                $row[] = $aRow['positive_responses'];
                $row[] = $aRow['negative_responses'];
                $row[] = $aRow['invalid_responses'];
                $row[] = $aRow['total_responses'];
                $row[] = $aRow['valid_responses'];
                $row[] = $aRow['total_passed'];
                $output[] = $row;
            }

            foreach ($output as $rowNo => $rowData) {
                $colNo = 0;
                foreach ($rowData as $field => $value) {
                    if (!isset($value)) {
                        $value = "";
                    }
                    $sheet->getCellByColumnAndRow($colNo, $rowNo + 6)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    if ($colNo == (sizeof($headings) - 1)) {
                        $sheet->getColumnDimensionByColumn($colNo)->setWidth(150);
                        $sheet->getStyleByColumnAndRow($colNo, $rowNo + 6)->getAlignment()->setWrapText(true);
                    }
                    $colNo++;
                }
            }

            if (!file_exists(UPLOAD_PATH) && !is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH);
            }

            $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
            $filename = 'shipment-response-' . date('d-M-Y-H-i-s') . '.xls';
            $writer->save(UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename);
            return $filename;
        } catch (Exception $exc) {
            return "";
            $sQuerySession->shipmentExportQuery = '';
            error_log("GENERATE-SHIPMENT_RESPONSE-REPORT-EXCEL--" . $exc->getMessage());
            error_log($exc->getTraceAsString());
        }
    }

    public function exportParticipantPerformanceReportInPdf() {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuerySession = new Zend_Session_Namespace('participantPerformanceExcel');
        return $db->fetchAll($sQuerySession->participantQuery);
    }

    public function exportCorrectiveActionsReportInPdf($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $totalQuery = $db->select()->from(array('s' => 'shipment'), array())
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array("total_shipped" => new Zend_Db_Expr('count("sp.map_id")'),
            "total_responses" => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            "valid_responses" => new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
            "average_score" => new Zend_Db_Expr("((SUM(Case When sp.is_excluded='yes' Then 0 Else sp.shipment_score End)+SUM(Case When sp.is_excluded='yes' Then 0 Else sp.documentation_score End))/(SUM(final_result = 1) + SUM(final_result = 2)))")));

        if (isset($params['scheme']) && $params['scheme'] != "") {
            $totalQuery = $totalQuery->where("s.scheme_type = ?", $params['scheme']);
        }

        if (isset($params['dateStartDate']) && $params['dateStartDate'] != "" && isset($params['dateEndDate']) && $params['dateEndDate'] != "") {
            $totalQuery = $totalQuery->where("DATE(s.shipment_date) >= ?", $params['dateStartDate']);
            $totalQuery = $totalQuery->where("DATE(s.shipment_date) <= ?", $params['dateEndDate']);
        }

        if (isset($params['shipmentId']) && $params['shipmentId'] != "") {
            $totalQuery = $totalQuery->where("s.shipment_id = ?", $params['shipmentId']);
        }
        //die($totalQuery);
        $totalResult = $db->fetchRow($totalQuery);

        $sQuerySession = new Zend_Session_Namespace('CorrectiveActionsExcel');
        $rResult = $db->fetchAll($sQuerySession->correctiveActionsQuery);

        return $result = array('countCorrectiveAction' => $totalResult, 'correctiveAction' => $rResult);
    }

    public function exportShipmentsReportInPdf() {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuerySession = new Zend_Session_Namespace('shipmentExportExcel');
        return $db->fetchAll($sQuerySession->shipmentExportQuery);
    }

    // Results Per Site
    public function getResultsPerSiteReport($parameters) {
        $aColumns = array('map_id', 'unique_identifier', 'lab_name', 'iso_name', 'region',
            'final_result', 'shipment_score', 'documentation_score');

        $searchColumns = array('p.unique_identifier',
            'p.lab_name',
            'c.iso_name',
            'p.region'
        );

        $orderColumns = array(
            'p.unique_identifier',
            'p.lab_name',
            'c.iso_name',
            'p.region',
            'spm.shipment_score',
            'smp.final_result'
        );

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
                $colSize = count($searchColumns);
                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
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

        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()
            ->from(array('spm' => 'shipment_participant_map'),
                array('map_id', 'final_result', 'shipment_score', 'documentation_score'))
            ->join(array('p' => 'participant'), 'spm.participant_id=p.participant_id',
                array('unique_identifier', 'lab_name', 'region'))
            ->join(array('c' => 'countries'), 'p.country=c.id', array('iso_name'))
            ->where('spm.shipment_id = '.$parameters['shipmentId'])
            ->order(new Zend_Db_Expr("CASE WHEN p.unique_identifier REGEXP '\d*' THEN CAST(CAST(p.unique_identifier AS DECIMAL) AS CHAR) ELSE TRIM(LEADING '0' FROM p.unique_identifier) END"));

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $dbAdapter->select()
            ->from(array('spm' => 'shipment_participant_map'), new Zend_Db_Expr("COUNT('spm.map_id')"))
            ->where('spm.shipment_id = '.$parameters['shipmentId']);

        $aResultTotal = $dbAdapter->fetchCol($sQuery);
        $iTotal = $aResultTotal[0];

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row[] = $aRow['lab_name'].' ('.$aRow['unique_identifier'].')';
            $row[] = $aRow['iso_name'];
            $row[] = $aRow['region'];
            $row[] = $aRow['shipment_score'] + $aRow['documentation_score'];
            if ($aRow['final_result'] == 1) {
                $row[] = 'Satisfactory';
            } else if ($aRow['final_result'] == 2) {
                $row[] = 'Unsatisfactory';
            } else if ($aRow['final_result'] == 3) {
                $row[] = 'Excluded';
            } else {
                $row[] = 'Not Participated';
            }
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    //results count pie chart
    public function getResultsPerSiteCount($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $resultsQuery = $db->select()
            ->from(array('spm' => 'shipment_participant_map'),
                array(
                    'satisfactory' => 'sum(spm.final_result = 1)',
                    'unsatisfactory' => 'sum(spm.final_result = 2)',
                    'excluded' => 'sum(spm.final_result = 3)',
                    'not_participated' => 'sum(spm.shipment_test_report_date is null)',
                ))
            ->where('spm.shipment_id = '.$params['shipmentId'])
            ->group(array('spm.shipment_id'));;
        $resultsCountResult = $db->fetchRow($resultsQuery);
        return array(
            array('name' => 'Satisfactory', 'count' => $resultsCountResult['satisfactory']),
            array('name' => 'Unsatisfactory', 'count' => $resultsCountResult['unsatisfactory']),
            array('name' => 'Excluded', 'count' => $resultsCountResult['excluded']),
            array('name' => 'Not Participated', 'count' => $resultsCountResult['not_participated'])
        );
    }


    // Participants Per Country
    public function getParticipantsPerCountryReport($parameters) {
        $aColumns = array('id', 'country_name', 'participant_count');

        $searchColumns = array('c.iso_name');

        $orderColumns = array('country_name', 'participant_count');

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
                $colSize = count($searchColumns);
                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
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

        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()
            ->from(array('spm' => 'shipment_participant_map'), array())
            ->join(array('p' => 'participant'), 'spm.participant_id = p.participant_id',
                array('participant_count' => new Zend_Db_Expr('COUNT(p.participant_id)')))
            ->join(array('c' => 'countries'), 'p.country = c.id',
                array('id', 'country_name' => 'c.iso_name'))
            ->where('spm.shipment_id = '.$parameters['shipmentId']);

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        $sQuery = $sQuery->group(array('c.id'));

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $dbAdapter->select()
            ->from(array('spm' => 'shipment_participant_map'), array())
            ->join(array('p' => 'participant'), 'spm.participant_id = p.participant_id',
                new Zend_Db_Expr("COUNT(DISTINCT p.country)"))
            ->where('spm.shipment_id = '.$parameters['shipmentId']);

        $aResultTotal = $dbAdapter->fetchCol($sQuery);
        $iTotal = $aResultTotal[0];

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row[] = $aRow['country_name'];
            $row[] = $aRow['participant_count'];
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    //results count pie chart
    public function getParticipantsPerCountryCount($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $resultsQuery = $db->select()
            ->from(array('spm' => 'shipment_participant_map'), array())
            ->join(array('p' => 'participant'), 'spm.participant_id = p.participant_id',
                array('participant_count' => new Zend_Db_Expr('COUNT(p.participant_id)')))
            ->join(array('c' => 'countries'), 'p.country = c.id',
                array('id', 'country_name' => 'c.iso_name'))
            ->where('spm.shipment_id = '.$params['shipmentId'])
            ->group(array('c.id'))
            ->order('participant_count DESC');

        $resultsCountResult = $db->fetchAll($resultsQuery);

        $output = array();

        foreach ($resultsCountResult as $aRow) {
            $row = array();
            $row['name'] = $aRow['country_name'];
            $row['count'] = $aRow['participant_count'];
            $output[] = $row;
        }

        return $output;
    }

    public function getParticipantPerformanceRegionWiseReport($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array(
            'p.region',
            new Zend_Db_Expr('count("sp.map_id")'),
            new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
            new Zend_Db_Expr("SUM(final_result = 1)"),
            new Zend_Db_Expr("((SUM(final_result = 1))/(SUM(final_result = 1) + SUM(final_result = 2)))*100"),
            'average_score'
        );
        $searchColumns = array(
            'p.region',
            'total_responses',
            'valid_responses',
            'total_passed',
            'pass_percentage',
            'average_score'
        );
        $orderColumns = array(
            'p.region',
            new Zend_Db_Expr('count("sp.map_id")'),
            new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"),
            new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"),
            new Zend_Db_Expr("SUM(final_result = 1)"),
            new Zend_Db_Expr("((SUM(final_result = 1))/(SUM(final_result = 1) + SUM(final_result = 2)))*100"),
            'average_score'
        );

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = 'shipment_id';
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
                $colSize = count($searchColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($searchColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }

        /*
         * SQL queries
         * Get data to display
         */


        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array("DATE_FORMAT(s.shipment_date,'%d-%b-%Y')", "total_shipped" => new Zend_Db_Expr('count("sp.map_id")'), "total_responses" => new Zend_Db_Expr("SUM(substr(sp.evaluation_status,3,1) = 1)"), "valid_responses" => new Zend_Db_Expr("(SUM(substr(sp.evaluation_status,3,1) = 1) - SUM(is_excluded = 'yes'))"), "total_passed" => new Zend_Db_Expr("(SUM(final_result = 1))"), "pass_percentage" => new Zend_Db_Expr("((SUM(final_result = 1))/(SUM(final_result = 1) + SUM(final_result = 2)))*100"), "average_score" => new Zend_Db_Expr("((SUM(Case When sp.is_excluded='yes' Then 0 Else sp.shipment_score End)+SUM(Case When sp.is_excluded='yes' Then 0 Else sp.documentation_score End))/(SUM(final_result = 1) + SUM(final_result = 2)))")))
                ->joinLeft(array('p' => 'participant'), 'p.participant_id=sp.participant_id', array('region'))
                ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id')
                ->group(array('p.region'));



        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        $sQuerySession = new Zend_Session_Namespace('participantPerformanceExcel');
        $sQuerySession->participantRegionQuery = $sQuery;

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }


        $rResult = $dbAdapter->fetchAll($sQuery);


        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sWhere = "";
        //$sQuery = $dbAdapter->select()->from(array('s'=>'shipment'), new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));


        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'), new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id', array())
                ->joinLeft(array('p' => 'participant'), 'p.participant_id=sp.participant_id', array('region'))
                ->joinLeft(array('rr' => 'r_results'), 'sp.final_result=rr.result_id')
                ->group(array('p.region'));
        if (isset($parameters['scheme']) && $parameters['scheme'] != "") {
            $sQuery = $sQuery->where("s.scheme_type = ?", $parameters['scheme']);
        }

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }

        $aResultTotal = $dbAdapter->fetchAll($sQuery);
        $iTotal = count($aResultTotal);

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );


        foreach ($rResult as $aRow) {


            $row = array();

            $row[] = $aRow['region'];
            $row[] = $aRow['total_shipped'];
            $row[] = $aRow['total_responses'];
            $row[] = $aRow['valid_responses'];
            $row[] = $aRow['total_passed'];
            $row[] = round($aRow['pass_percentage'], 2);
            $row[] = round($aRow['average_score'], 2);


            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function exportParticipantPerformanceRegionReport($params) {
        $headings = array('Region', 'No. of Shipments', 'No. of Responses', 'No. of Valid Responses', 'No. of Passed Responses', 'Pass %', 'Average Score');
        try {
            $excel = new PHPExcel();
            $cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
            $cacheSettings = array('memoryCacheSize' => '80MB');
            PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);
            $output = array();
            $sheet = $excel->getActiveSheet();
            $styleArray = array(
                'font' => array(
                    'bold' => true,
                ),
                'alignment' => array(
                    'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
                    'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
                ),
                'borders' => array(
                    'outline' => array(
                        'style' => PHPExcel_Style_Border::BORDER_THICK,
                    ),
                )
            );

            $colNo = 0;
            $sheet->mergeCells('A1:I1');
            $sheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode('Region Wise Participant Performance Report ', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

            $sheet->getCellByColumnAndRow(0, 2)->setValueExplicit(html_entity_decode('Scheme', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(1, 2)->setValueExplicit(html_entity_decode($params['selectedScheme'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

            $sheet->getCellByColumnAndRow(0, 3)->setValueExplicit(html_entity_decode('Shipment Date', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(1, 3)->setValueExplicit(html_entity_decode($params['selectedDate'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

            $sheet->getCellByColumnAndRow(0, 4)->setValueExplicit(html_entity_decode('Shipment Code', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $sheet->getCellByColumnAndRow(1, 4)->setValueExplicit(html_entity_decode($params['selectedCode'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

            $sheet->getStyleByColumnAndRow(0, 1)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(0, 2)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(0, 3)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(0, 4)->getFont()->setBold(true);

            foreach ($headings as $field => $value) {
                $sheet->getCellByColumnAndRow($colNo, 6)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getStyleByColumnAndRow($colNo, 6)->getFont()->setBold(true);
                $colNo++;
            }

            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $sQuerySession = new Zend_Session_Namespace('participantPerformanceExcel');
            $rResult = $db->fetchAll($sQuerySession->participantRegionQuery);
            foreach ($rResult as $aRow) {
                $row = array();
                $row[] = $aRow['region'];
                $row[] = $aRow['total_shipped'];
                $row[] = $aRow['total_responses'];
                $row[] = $aRow['valid_responses'];
                $row[] = $aRow['total_passed'];
                $row[] = round($aRow['pass_percentage'], 2);
                $row[] = round($aRow['average_score'], 2);
                $output[] = $row;
            }

            foreach ($output as $rowNo => $rowData) {
                $colNo = 0;
                foreach ($rowData as $field => $value) {
                    if (!isset($value)) {
                        $value = "";
                    }
                    $sheet->getCellByColumnAndRow($colNo, $rowNo + 7)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    if ($colNo == (sizeof($headings) - 1)) {
                        $sheet->getColumnDimensionByColumn($colNo)->setWidth(150);
                        $sheet->getStyleByColumnAndRow($colNo, $rowNo + 7)->getAlignment()->setWrapText(true);
                    }
                    $colNo++;
                }
            }

            if (!file_exists(UPLOAD_PATH) && !is_dir(UPLOAD_PATH)) {
                mkdir(UPLOAD_PATH);
            }

            $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
            $filename = 'participant-performance-region-wise' . date('d-M-Y-H-i-s') . '.xls';
            $writer->save(UPLOAD_PATH . DIRECTORY_SEPARATOR . $filename);
            return $filename;
        } catch (Exception $exc) {
            return "";
            $sQuerySession->participantRegionQuery = '';
            error_log("GENERATE-PARTICIPANT-PERFORMANCE-REGION-WISE-REPORT-EXCEL--" . $exc->getMessage());
            error_log($exc->getTraceAsString());
        }
    }

    public function getTestKitParticipantReport($parameters) {
        //Zend_Debug::dump($parameters);die;
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
            $aColumns = array('p.lab_name', 'network_name');
        } else if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
            $aColumns = array('p.lab_name', 'affiliate');
        } else if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
            $aColumns = array('p.lab_name', 'region');
        } else {
            $aColumns = array('p.lab_name');
        }

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
                    $sOrder .= $aColumns[intval($parameters['iSortCol_' . $i])] . "
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
        $sQuery = $dbAdapter->select()->from(array('res' => 'response_result_dts'), array())
                ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.map_id=res.shipment_map_id', array())
                ->joinLeft(array('p' => 'participant'), 'sp.participant_id=p.participant_id', array('p.lab_name', 'p.region', 'p.affiliation'))
                ->joinLeft(array('s' => 'shipment'), 's.shipment_id=sp.shipment_id', array())
                ->group("p.participant_id");

        if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit1") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_1', array())->where("tn.TestKitName_ID = ?", $parameters['testkitId']);
        }
        else if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit2") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_2', array())->where("tn.TestKitName_ID = ?", $parameters['testkitId']);
        }
        else if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit3") {
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_3', array())->where("tn.TestKitName_ID = ?", $parameters['testkitId']);
        }else{
            $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_1 or tn.TestKitName_ID=res.test_kit_name_2 or tn.TestKitName_ID=res.test_kit_name_3', array('TestKit_Name', 'TestKitName_ID'))
                    ->group('tn.TestKitName_ID');
		}
        if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
            if (isset($parameters['networkValue']) && $parameters['networkValue'] != "") {
                $sQuery = $sQuery->joinLeft(array('n' => 'r_network_tiers'), 'p.network_tier=n.network_id', array('network_name'))->where("p.network_tier = ?", $parameters['networkValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('n' => 'r_network_tiers'), 'p.network_tier=n.network_id', array('network_name'));
            }
        }
        if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
            if (isset($parameters['affiliateValue']) && $parameters['affiliateValue'] != "") {
                $iQuery = $dbAdapter->select()->from(array('rpa' => 'r_participant_affiliates'), array('affiliation' => 'affiliate'))
                        ->where('rpa.aff_id=?', $parameters['affiliateValue']);
                $iResult = $dbAdapter->fetchRow($iQuery);
                $appliate = $iResult['affiliation'];
                $sQuery = $sQuery->where('p.affiliation="' . $appliate . '" OR p.affiliation=' . $parameters['affiliateValue']);
            } else {
                $sQuery = $sQuery->joinLeft(array('pa' => 'r_participant_affiliates'), 'p.affiliation=pa.affiliate', array('affiliation' => 'affiliate'));
            }
        }
        if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
            if (isset($parameters['regionValue']) && $parameters['regionValue'] != "") {
                $sQuery = $sQuery->where("p.region= ?", $parameters['regionValue']);
            } else {
                $sQuery = $sQuery->where("p.region IS NOT NULL")->where("p.region != ''");
            }
        }
        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("s.shipment_date >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("s.shipment_date <= ?", $parameters['endDate']);
        }
        $sQuery = $sQuery->where("tn.TestKit_Name IS NOT NULL");

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->having($sWhere);
        }
        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }
        $rResult = $dbAdapter->fetchAll($sQuery);


        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */

        $aResultTotal = $dbAdapter->fetchAll($sQuery);
        $iTotal = sizeof($aResultTotal);

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row[] = $aRow['lab_name'];
            if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
                $row[] = $aRow['network_name'];
            } else if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
                $row[] = $aRow['affiliation'];
            } else if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
                $row[] = $aRow['region'];
            } else {
                $row[] = '';
            }
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function generatePdfTestKitDetailedReport($parameters) {
        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuerySession = new Zend_Session_Namespace('TestkitActionsExcel');
        $rResult = $dbAdapter->fetchAll($sQuerySession->testkitActionsQuery);
        $pResult='';
        if (isset($parameters['testkitId']) && $parameters['testkitId'] != '') {
            $sQuery = $dbAdapter->select()->from(array('res' => 'response_result_dts'), array())
                    ->joinLeft(array('sp' => 'shipment_participant_map'), 'sp.map_id=res.shipment_map_id', array())
                    ->joinLeft(array('p' => 'participant'), 'sp.participant_id=p.participant_id', array('p.lab_name', 'p.region', 'p.affiliation'))
                    ->joinLeft(array('s' => 'shipment'), 's.shipment_id=sp.shipment_id', array())
                    ->group("p.participant_id");

            if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit1") {
                $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_1', array())->where("tn.TestKitName_ID = ?", $parameters['testkitId']);
            }
            if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit2") {
                $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_2', array())->where("tn.TestKitName_ID = ?", $parameters['testkitId']);
            }
            if (isset($parameters['kitType']) && $parameters['kitType'] == "testkit3") {
                $sQuery = $sQuery->joinLeft(array('tn' => 'r_testkitname_dts'), 'tn.TestKitName_ID=res.test_kit_name_3', array())->where("tn.TestKitName_ID = ?", $parameters['testkitId']);
            }
            if (isset($parameters['reportType']) && $parameters['reportType'] == "network") {
                if (isset($parameters['networkValue']) && $parameters['networkValue'] != "") {
                    $sQuery = $sQuery->joinLeft(array('n' => 'r_network_tiers'), 'p.network_tier=n.network_id', array('network_name'))->where("p.network_tier = ?", $parameters['networkValue']);
                } else {
                    $sQuery = $sQuery->joinLeft(array('n' => 'r_network_tiers'), 'p.network_tier=n.network_id', array('network_name'));
                }
            }
            if (isset($parameters['reportType']) && $parameters['reportType'] == "affiliation") {
                if (isset($parameters['affiliateValue']) && $parameters['affiliateValue'] != "") {
                    $iQuery = $dbAdapter->select()->from(array('rpa' => 'r_participant_affiliates'), array('affiliation' => 'affiliate'))
                            ->where('rpa.aff_id=?', $parameters['affiliateValue']);
                    $iResult = $dbAdapter->fetchRow($iQuery);
                    $appliate = $iResult['affiliation'];
                    $sQuery = $sQuery->where('p.affiliation="' . $appliate . '" OR p.affiliation=' . $parameters['affiliateValue']);
                } else {
                    $sQuery = $sQuery->joinLeft(array('pa' => 'r_participant_affiliates'), 'p.affiliation=pa.affiliate', array('affiliation' => 'affiliate'));
                }
            }
            if (isset($parameters['reportType']) && $parameters['reportType'] == "region") {
                if (isset($parameters['regionValue']) && $parameters['regionValue'] != "") {
                    $sQuery = $sQuery->where("p.region= ?", $parameters['regionValue']);
                } else {
                    $sQuery = $sQuery->where("p.region IS NOT NULL")->where("p.region != ''");
                }
            }
			if (isset($parameters['reportType']) && $parameters['reportType'] == "enrolled-programs") {
				if (isset($parameters['enrolledProgramsValue']) && $parameters['enrolledProgramsValue'] != "") {
					$sQuery = $sQuery->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
								->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'))
								->where("rep.r_epid= ?", $parameters['enrolledProgramsValue']);
				} else {
					$sQuery = $sQuery->joinLeft(array('pe' => 'participant_enrolled_programs_map'), 'pe.participant_id=p.participant_id', array())
								->joinLeft(array('rep' => 'r_enrolled_programs'), 'rep.r_epid=pe.ep_id', array('rep.enrolled_programs'));
				}
			}
            if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
                $sQuery = $sQuery->where("s.shipment_date >= ?", $parameters['startDate']);
                $sQuery = $sQuery->where("s.shipment_date <= ?", $parameters['endDate']);
            }
            $sQuery = $sQuery->where("tn.TestKit_Name IS NOT NULL");
            $pResult = $dbAdapter->fetchAll($sQuery);
        }
        $pieChart=$this->getTestKitReport($parameters);

        return array('testkitDtsReport' => $rResult, 'testkitDtsParticipantReport' => $pResult,'testkitChart'=>$pieChart);
    }

    //get vl assay distribution
    public function getAllVlAssayDistributionReports($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array(
            'sl.scheme_name',
            "DATE_FORMAT(s.shipment_date,'%d-%b-%Y')",
            's.shipment_code',
            'sp.shipment_score',
            'sp.documentation_score',
	    "DATE_FORMAT(sp.shipment_test_date,'%d-%b-%Y')",
	    "DATE_FORMAT(sp.shipment_receipt_date,'%d-%b-%Y')",
        );
        $searchColumns = array(
            'sl.scheme_name',
            "DATE_FORMAT(s.shipment_date,'%d-%b-%Y')",
            's.shipment_code',
            'sp.shipment_score',
            'sp.documentation_score',
	    "DATE_FORMAT(sp.shipment_test_date,'%d-%b-%Y')",
	    "DATE_FORMAT(sp.shipment_receipt_date,'%d-%b-%Y')",
        );
        $orderColumns = array(
            'sl.scheme_name',
            "s.shipment_date",
            's.shipment_code',
            'sp.shipment_score',
            'sp.documentation_score',
            'sp.shipment_test_date',
            'sp.shipment_receipt_date',
        );

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = 'map_id';
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
                $colSize = count($searchColumns);

                for ($i = 0; $i < $colSize; $i++) {
                    if ($searchColumns[$i] == "" || $searchColumns[$i] == null) {
                        continue;
                    }
                    if ($i < $colSize - 1) {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
                    } else {
                        $sWhereSub .= $searchColumns[$i] . " LIKE '%" . ($search) . "%' ";
                    }
                }
                $sWhereSub .= ")";
            }
            $sWhere .= $sWhereSub;
        }

        /* Individual column filtering */
        for ($i = 0; $i < count($searchColumns); $i++) {
            if (isset($parameters['bSearchable_' . $i]) && $parameters['bSearchable_' . $i] == "true" && $parameters['sSearch_' . $i] != '') {
                if ($sWhere == "") {
                    $sWhere .= $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                } else {
                    $sWhere .= " AND " . $searchColumns[$i] . " LIKE '%" . ($parameters['sSearch_' . $i]) . "%' ";
                }
            }
        }

        /*
         * SQL queries
         * Get data to display
         */


        $dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id',
						   array("s.shipment_date","sp.shipment_test_date","sp.shipment_receipt_date","sp.shipment_score","sp.documentation_score"))
                ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
		->where("s.scheme_type ='vl'");

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }

        //echo ($sQuery);die;

        $rResult = $dbAdapter->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sWhere = "";
        $sQuery = $dbAdapter->select()->from(array('s' => 'shipment'))
                ->join(array('sl' => 'scheme_list'), 's.scheme_type=sl.scheme_id')
                ->join(array('sp' => 'shipment_participant_map'), 'sp.shipment_id=s.shipment_id',
						   array("s.shipment_date","sp.shipment_test_date","sp.shipment_receipt_date","sp.shipment_score","sp.documentation_score"))
                ->join(array('p' => 'participant'), 'p.participant_id=sp.participant_id')
		->where("s.scheme_type ='vl'");

        if (isset($parameters['startDate']) && $parameters['startDate'] != "" && isset($parameters['endDate']) && $parameters['endDate'] != "") {
            $sQuery = $sQuery->where("DATE(s.shipment_date) >= ?", $parameters['startDate']);
            $sQuery = $sQuery->where("DATE(s.shipment_date) <= ?", $parameters['endDate']);
        }

        if (isset($parameters['shipmentId']) && $parameters['shipmentId'] != "") {
            $sQuery = $sQuery->where("s.shipment_id = ?", $parameters['shipmentId']);
        }

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        $aResultTotal = $dbAdapter->fetchAll($sQuery);
        $iTotal = count($aResultTotal);

        /*
         * Output
         */
        $output = array(
            "sEcho" => intval($parameters['sEcho']),
            "iTotalRecords" => $iTotal,
            "iTotalDisplayRecords" => $iFilteredTotal,
            "aaData" => array()
        );

        foreach ($rResult as $aRow) {
            $row = array();
            $row[] = $aRow['lab_name'];
            $row[] = $aRow['shipment_score'];
            $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['shipment_test_date']);
            $row[] = Application_Service_Common::ParseDateHumanFormat($aRow['shipment_receipt_date']);
            $output['aaData'][] = $row;
        }
        echo json_encode($output);
    }

	public function getShipmentsByDate($schemeType,$startDate,$endDate) {
        $resultArray = array();
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $sQuery = $db->select()->from(array('s' => 'shipment'), array('s.shipment_id', 's.shipment_code', 's.scheme_type', 's.shipment_date',))
                ->where("DATE(s.shipment_date) >= ?", $startDate)
                ->where("DATE(s.shipment_date) <= ?", $endDate)
                ->order("s.shipment_id");
		if(isset($schemeType) && count($schemeType)>0) {
			$sWhere="";
			foreach($schemeType as $val){
				if ($sWhere!="") {
					$sWhere .= " OR ";
                }
				$sWhere.="s.scheme_type='".$val."'";
			}
            $sQuery = $sQuery->where($sWhere);
        }

        $resultArray = $db->fetchAll($sQuery);
        return $resultArray;
    }

	public function getAnnualReport($params){
		if(isset($params['startDate']) && trim($params['startDate'])!="" && trim($params['endDate'])!=""){
			$startDate=$params['startDate'];
			$endDate=$params['endDate'];

			$db = Zend_Db_Table_Abstract::getDefaultAdapter();
			$query = $db->select()->from(array('s' => 'shipment'), array('s.shipment_id', 's.shipment_code', 's.scheme_type', 's.shipment_date',))
								->where("DATE(s.shipment_date) >= ?", $startDate)
								->where("DATE(s.shipment_date) <= ?", $endDate)
								->order("s.shipment_id");

			if(isset($params['scheme']) && count($params['scheme'])>0) {
				$sWhere="";
				foreach($params['scheme'] as $val){
					if ($sWhere!="") {
						$sWhere .= " OR ";
					}
					$sWhere.="s.scheme_type='".$val."'";
				}
				$query = $query->where($sWhere);
			}
			$shipmentResult = $db->fetchAll($query);
			$shipmentIDArray=array();
			foreach($shipmentResult as $val){
				$shipmentIdArray[]=$val['shipment_id'];
				$impShipmentId=implode(",",$shipmentIdArray);
			}

			$sQuery = $db->select()->from(array('spm' => 'shipment_participant_map'), array('spm.map_id','spm.shipment_id','spm.participant_id','spm.shipment_score','spm.final_result'))
									->join(array('s' => 'shipment'),'s.shipment_id=spm.shipment_id',array('shipment_code','scheme_type'))
									->join(array('p' => 'participant'),'p.participant_id=spm.participant_id',array('unique_identifier','lab_name','email','city','state','address','institute_name'))
									->joinLeft(array('c' => 'countries'),'c.id=p.country',array('iso_name'));

			if(isset($params['shipmentId']) && count($params['shipmentId'])>0) {
				$impShipmentId=implode(",",$params['shipmentId']);
				$sQuery->where('spm.shipment_id IN ('.$impShipmentId.')');
			}else{
				//$sQuery->where('spm.shipment_id IN(?)', $impShipmentId);
				$sQuery->where('spm.shipment_id IN ('.$impShipmentId.')');
			}
			//echo $sQuery;die;
			//$shipmentParticipantResult = $db->fetchAll($sQuery);
			return $this->generateAnnualReport($sQuery,$startDate,$endDate);
		}
	}

	public function generateAnnualReport($sQuery,$startDate,$endDate){
		$db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$shipmentParticipantResult=$db->fetchAll($sQuery);
		//Zend_Debug::dump($shipmentParticipantResult);
		$shipmentPassResult=array();
		$shipmentFailResult=array();
		$headings = array('Shipment Code','Participants Identifier','Participants Name','Institute Name','Address','Country','State','City');
		$excel = new PHPExcel();
		$cacheMethod = PHPExcel_CachedObjectStorageFactory::cache_to_phpTemp;
		$cacheSettings = array('memoryCacheSize' => '80MB');
		PHPExcel_Settings::setCacheStorageMethod($cacheMethod, $cacheSettings);
		$output = array();
		$secondSheetOutput = array();
		$thirdSheetOutput = array();
		$sheet = $excel->getActiveSheet();
		$firstSheet = new PHPExcel_Worksheet($excel, 'Pass Result');
		$excel->addSheet($firstSheet, 0);
		$firstSheet->getDefaultColumnDimension()->setWidth(20);
		$firstSheet->getDefaultRowDimension()->setRowHeight(18);
		$firstSheet->setTitle('Pass Result');
		$styleArray = array(
			'font' => array(
				'bold' => true,
			),
			'alignment' => array(
				'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
				'vertical' => PHPExcel_Style_Alignment::VERTICAL_CENTER,
			),
			'borders' => array(
				'outline' => array(
					'style' => PHPExcel_Style_Border::BORDER_THICK,
				),
			)
		);

		$colNo = 0;
		$firstSheet->mergeCells('A1:I1');
		$firstSheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode('Annual Report', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

		$firstSheet->getCellByColumnAndRow(0, 3)->setValueExplicit(html_entity_decode('Selected Date Range', ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $firstSheet->getCellByColumnAndRow(1, 3)->setValueExplicit(html_entity_decode(Pt_Commons_General::humanDateFormat($startDate)." to ".Pt_Commons_General::humanDateFormat($endDate), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);

		$firstSheet->getStyleByColumnAndRow(0, 1)->getFont()->setBold(true);
		$firstSheet->getStyleByColumnAndRow(0, 2)->getFont()->setBold(true);
		$firstSheet->getStyleByColumnAndRow(0, 3)->getFont()->setBold(true);

		foreach ($headings as $field => $value) {
			$firstSheet->getCellByColumnAndRow($colNo, 5)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$firstSheet->getStyleByColumnAndRow($colNo, 5)->getFont()->setBold(true);
			$colNo++;
		}
		//Zend_Debug::dump($shipmentPassResult);
		foreach($shipmentParticipantResult as $shipment){
			$firstSheetRow = array();
			$secondSheetRow = array();
			$thirdSheetRow = array();
			if($shipment['final_result']==1){
				$firstSheetRow[]=$shipment['shipment_code'];
				$firstSheetRow[]=$shipment['unique_identifier'];
				$firstSheetRow[]=$shipment['lab_name'];
				$firstSheetRow[]=$shipment['institute_name'];
				$firstSheetRow[]=$shipment['address'];
				$firstSheetRow[]=$shipment['iso_name'];
				$firstSheetRow[]=$shipment['state'];
				$firstSheetRow[]=$shipment['city'];
				$output[] = $firstSheetRow;
			}

			if($shipment['final_result']==4){
				$secondSheetRow[]=$shipment['shipment_code'];
				$secondSheetRow[]=$shipment['unique_identifier'];
				$secondSheetRow[]=$shipment['lab_name'];
				$secondSheetRow[]=$shipment['institute_name'];
				$secondSheetRow[]=$shipment['address'];
				$secondSheetRow[]=$shipment['iso_name'];
				$secondSheetRow[]=$shipment['state'];
				$secondSheetRow[]=$shipment['city'];
				$secondSheetOutput[] = $secondSheetRow;
			}

			if($shipment['final_result']==2 || $shipment['final_result']==0){
				$thirdSheetRow[]=$shipment['shipment_code'];
				$thirdSheetRow[]=$shipment['unique_identifier'];
				$thirdSheetRow[]=$shipment['lab_name'];
				$thirdSheetRow[]=$shipment['institute_name'];
				$thirdSheetRow[]=$shipment['address'];
				$thirdSheetRow[]=$shipment['iso_name'];
				$thirdSheetRow[]=$shipment['state'];
				$thirdSheetRow[]=$shipment['city'];
				$thirdSheetOutput[] = $thirdSheetRow;
			}
		}

		//foreach($shipmentPassResult as $shipmentKey=>$shipment){
		//	//$row[]=$shipmentKey;
		//
		//	foreach($shipment as $val){
		//		$row = array();
		//		//echo $val[0];
		//		$row[]=$val[0];
		//		$row[]=$val[1];
		//		$output[] = $row;
		//	}
		//}

		foreach ($output as $rowNo => $rowData) {
			$colNo = 0;
			foreach ($rowData as $field => $value) {
				if (!isset($value)) {
					$value = "";
				}
				$firstSheet->getCellByColumnAndRow($colNo, $rowNo + 6)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				if ($colNo == (sizeof($headings) - 1)) {
					//$firstSheet->getColumnDimensionByColumn($colNo)->setWidth(100);
					$firstSheet->getStyleByColumnAndRow($colNo, $rowNo + 6)->getAlignment()->setWrapText(true);
				}
				$colNo++;
			}
		}

		$secondSheet = new PHPExcel_Worksheet($excel, 'Fail Result');
		$excel->addSheet($secondSheet, 1);
		$secondSheet->setTitle('Excluded Result');
		$secondSheet->getDefaultColumnDimension()->setWidth(20);
		$secondSheet->getDefaultRowDimension()->setRowHeight(18);
		$colNo = 0;
		foreach ($headings as $field => $value) {
			$secondSheet->getCellByColumnAndRow($colNo, 2)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$secondSheet->getStyleByColumnAndRow($colNo, 2)->getFont()->setBold(true);
			$colNo++;
		}

		foreach ($secondSheetOutput as $rowNo => $rowData) {
			$colNo = 0;
			foreach ($rowData as $field => $value) {
				if (!isset($value)) {
					$value = "";
				}
				$secondSheet->getCellByColumnAndRow($colNo, $rowNo + 3)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				if ($colNo == (sizeof($headings) - 1)) {
					//$secondSheet->getColumnDimensionByColumn($colNo)->setWidth(100);
					$secondSheet->getStyleByColumnAndRow($colNo, $rowNo + 3)->getAlignment()->setWrapText(true);
				}
				$colNo++;
			}
		}

		$thirdSheet = new PHPExcel_Worksheet($excel, 'Fail Result');
		$excel->addSheet($thirdSheet, 2);
		$thirdSheet->setTitle('Fail Result');
		$thirdSheet->getDefaultColumnDimension()->setWidth(20);
		$thirdSheet->getDefaultRowDimension()->setRowHeight(18);
		$colNo = 0;
		foreach ($headings as $field => $value) {
			$thirdSheet->getCellByColumnAndRow($colNo, 2)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
			$thirdSheet->getStyleByColumnAndRow($colNo, 2)->getFont()->setBold(true);
			$colNo++;
		}

		foreach ($thirdSheetOutput as $rowNo => $rowData) {
			$colNo = 0;
			foreach ($rowData as $field => $value) {
				if (!isset($value)) {
					$value = "";
				}
				$thirdSheet->getCellByColumnAndRow($colNo, $rowNo + 3)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
				if ($colNo == (sizeof($headings) - 1)) {
					//$thirdSheet->getColumnDimensionByColumn($colNo)->setWidth(100);
					$thirdSheet->getStyleByColumnAndRow($colNo, $rowNo + 3)->getAlignment()->setWrapText(true);
				}
				$colNo++;
			}
		}

		if (!file_exists(UPLOAD_PATH) && !is_dir(UPLOAD_PATH)) {
			mkdir(UPLOAD_PATH);
		}

		if (!file_exists(UPLOAD_PATH. DIRECTORY_SEPARATOR."annual-reports") && !is_dir(UPLOAD_PATH. DIRECTORY_SEPARATOR."annual-reports")) {
			mkdir(UPLOAD_PATH. DIRECTORY_SEPARATOR."annual-reports");
		}
		$excel->setActiveSheetIndex(0);
		$writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
		$filename = 'Annual Report-'.date('d-M-Y H:i:s').'.xls';
		$writer->save(UPLOAD_PATH. DIRECTORY_SEPARATOR."annual-reports". DIRECTORY_SEPARATOR . $filename);
		return $filename;

	}

	private function getTbAllSitesResultsSheet($db, $shipmentId, $excel, $sheetIndex) {
        $borderStyle = array(
            'font' => array(
                'bold' => true,
                'size'  => 12,
            ),
            'alignment' => array(
                'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
            ),
            'borders' => array(
                'outline' => array(
                    'style' => PHPExcel_Style_Border::BORDER_THIN,
                ),
            )
        );
        $query = $db->query("SELECT FlattenedEvaluationResults.`Country`, FlattenedEvaluationResults.`Site No.`, FlattenedEvaluationResults.`Site Name/Location`, FlattenedEvaluationResults.`PT-ID`,
    FlattenedEvaluationResults.Submitted, FlattenedEvaluationResults.`Submission Excluded`,
    FlattenedEvaluationResults.`Date PT Received`, FlattenedEvaluationResults.`Date PT Results Reported`,
        
    JSON_UNQUOTE(FlattenedEvaluationResults.attributes_json->\"$.cartridge_lot_no\") AS `Cartridge Lot Number`,
    FlattenedEvaluationResults.assay_name AS `Assay`,
    STR_TO_DATE(JSON_UNQUOTE(FlattenedEvaluationResults.attributes_json->\"$.expiry_date\"), '%d-%b-%Y') AS `Expiry Date`,
    
    FlattenedEvaluationResults.`Date of last instrument calibration`, FlattenedEvaluationResults.`Participated`, FlattenedEvaluationResults.`Reason for No Submission`,

    FlattenedEvaluationResults.`1-Date Tested`, FlattenedEvaluationResults.`1-Instrument Serial`, FlattenedEvaluationResults.`1-Instrument Last Calibrated`,
    FlattenedEvaluationResults.`1-MTB`, FlattenedEvaluationResults.`1-Rif`, FlattenedEvaluationResults.`1-Probe 1`, FlattenedEvaluationResults.`1-Probe 2`,
    FlattenedEvaluationResults.`1-Probe 3`, FlattenedEvaluationResults.`1-Probe 4`, FlattenedEvaluationResults.`1-Probe 5`, FlattenedEvaluationResults.`1-Probe 6`,

    FlattenedEvaluationResults.`2-Date Tested`, FlattenedEvaluationResults.`2-Instrument Serial`, FlattenedEvaluationResults.`2-Instrument Last Calibrated`,
    FlattenedEvaluationResults.`2-MTB`, FlattenedEvaluationResults.`2-Rif`, FlattenedEvaluationResults.`2-Probe 1`, FlattenedEvaluationResults.`2-Probe 2`,
    FlattenedEvaluationResults.`2-Probe 3`, FlattenedEvaluationResults.`2-Probe 4`, FlattenedEvaluationResults.`2-Probe 5`, FlattenedEvaluationResults.`2-Probe 6`,

    FlattenedEvaluationResults.`3-Date Tested`, FlattenedEvaluationResults.`3-Instrument Serial`, FlattenedEvaluationResults.`3-Instrument Last Calibrated`,
    FlattenedEvaluationResults.`3-MTB`, FlattenedEvaluationResults.`3-Rif`, FlattenedEvaluationResults.`3-Probe 1`, FlattenedEvaluationResults.`3-Probe 2`,
    FlattenedEvaluationResults.`3-Probe 3`, FlattenedEvaluationResults.`3-Probe 4`, FlattenedEvaluationResults.`3-Probe 5`, FlattenedEvaluationResults.`3-Probe 6`,

    FlattenedEvaluationResults.`4-Date Tested`, FlattenedEvaluationResults.`4-Instrument Serial`, FlattenedEvaluationResults.`4-Instrument Last Calibrated`,
    FlattenedEvaluationResults.`4-MTB`, FlattenedEvaluationResults.`4-Rif`, FlattenedEvaluationResults.`4-Probe 1`, FlattenedEvaluationResults.`4-Probe 2`,
    FlattenedEvaluationResults.`4-Probe 3`, FlattenedEvaluationResults.`4-Probe 4`, FlattenedEvaluationResults.`4-Probe 5`, FlattenedEvaluationResults.`4-Probe 6`,

    FlattenedEvaluationResults.`5-Date Tested`, FlattenedEvaluationResults.`5-Instrument Serial`, FlattenedEvaluationResults.`5-Instrument Last Calibrated`,
    FlattenedEvaluationResults.`5-MTB`, FlattenedEvaluationResults.`5-Rif`, FlattenedEvaluationResults.`5-Probe 1`, FlattenedEvaluationResults.`5-Probe 2`,
    FlattenedEvaluationResults.`5-Probe 3`, FlattenedEvaluationResults.`5-Probe 4`, FlattenedEvaluationResults.`5-Probe 5`, FlattenedEvaluationResults.`5-Probe 6`,
    
    FlattenedEvaluationResults.`Comments`, FlattenedEvaluationResults.`Comments for reports`,
    FlattenedEvaluationResults.`1-Score`, FlattenedEvaluationResults.`2-Score`, FlattenedEvaluationResults.`3-Score`, FlattenedEvaluationResults.`4-Score`,
    FlattenedEvaluationResults.`5-Score`, 
    
    FlattenedEvaluationResults.`Fin Score`, FlattenedEvaluationResults.`Sat/Unsat`
FROM (
SELECT countries.iso_name AS `Country`,
    participant.participant_id AS `Site No.`,
    CONCAT(participant.lab_name,
      COALESCE(CONCAT(' - ', CASE WHEN participant.state = '' THEN NULL ELSE participant.state END),
               CONCAT(' - ', CASE WHEN participant.city = '' THEN NULL ELSE participant.city END), '')) AS `Site Name/Location`,
    participant.unique_identifier AS `PT-ID`,
    CASE
        WHEN SUBSTRING(shipment_participant_map.evaluation_status,3,1) = '9' OR SUBSTRING(shipment_participant_map.evaluation_status,4,1) = '0' THEN 'No'
        WHEN SUBSTRING(shipment_participant_map.evaluation_status,3,1) = '1' AND SUBSTRING(shipment_participant_map.evaluation_status,4,1) = '1' THEN 'Yes'
        WHEN SUBSTRING(shipment_participant_map.evaluation_status,4,1) = '2' THEN 'Yes (Late)'
    END AS Submitted,
    CASE
        WHEN shipment_participant_map.is_excluded = 'yes' THEN 'Yes'
        ELSE 'No'
    END AS `Submission Excluded`,
    shipment_participant_map.shipment_receipt_date AS `Date PT Received`,
    CAST(shipment_participant_map.shipment_test_report_date AS DATE) AS `Date PT Results Reported`,
    CAST(attributes AS JSON) AS attributes_json,
    r_tb_assay.name AS assay_name,
    GREATEST(MAX(instrument.instrument_last_calibrated_on),
             response_result_tb_1.instrument_last_calibrated_on,
             response_result_tb_2.instrument_last_calibrated_on,
             response_result_tb_3.instrument_last_calibrated_on,
             response_result_tb_4.instrument_last_calibrated_on,
             response_result_tb_5.instrument_last_calibrated_on) AS `Date of last instrument calibration`,
    CASE
      WHEN IFNULL(shipment_participant_map.is_pt_test_not_performed, 'no') = 'no' THEN 'Yes'
      ELSE 'No'
    END AS `Participated`,
    IFNULL(shipment_participant_map.pt_test_not_performed_comments, response_not_tested_reason.not_tested_reason) AS `Reason for No Submission`,

    response_result_tb_1.date_tested AS `1-Date Tested`,
    response_result_tb_1.instrument_serial AS `1-Instrument Serial`,
    response_result_tb_1.instrument_last_calibrated_on AS `1-Instrument Last Calibrated`,
    CASE
      WHEN response_result_tb_1.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_1.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_1.error_code)
      WHEN response_result_tb_1.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_1.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_1.mtb_detected = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_1.mtb_detected = 'trace' THEN 'Trace'
      WHEN response_result_tb_1.mtb_detected = 'na' THEN 'N/A'
      WHEN IFNULL(response_result_tb_1.mtb_detected, '') = '' THEN NULL
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_1.mtb_detected, 1, 1)), SUBSTRING(response_result_tb_1.mtb_detected, 2, 254))
    END AS `1-MTB`,
    CASE
      WHEN response_result_tb_1.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_1.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_1.error_code)
      WHEN response_result_tb_1.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_1.mtb_detected = 'invalid' THEN 'Invalid'
      WHEN response_result_tb_1.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_1.mtb_detected IN ('detected', 'trace', 'veryLow', 'low', 'medium', 'high') AND IFNULL(response_result_tb_1.rif_resistance, 'na') = 'na' THEN 'Not Detected'
      WHEN response_result_tb_1.rif_resistance = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_1.rif_resistance = 'noResult' THEN 'No Result'
      WHEN response_result_tb_1.rif_resistance = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_1.rif_resistance = 'na' THEN 'N/A'
      WHEN response_result_tb_1.mtb_detected = 'notDetected' AND IFNULL(response_result_tb_1.rif_resistance, '') = '' THEN 'N/A'
      WHEN response_result_tb_1.mtb_detected NOT IN ('noResult', 'notDetected', 'invalid') AND IFNULL(response_result_tb_1.rif_resistance, '') = '' THEN 'N/A'
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_1.rif_resistance, 1, 1)), SUBSTRING(response_result_tb_1.rif_resistance, 2, 254))
    END AS `1-Rif`,
    response_result_tb_1.probe_1 AS `1-Probe 1`,
    response_result_tb_1.probe_2 AS `1-Probe 2`,
    response_result_tb_1.probe_3 AS `1-Probe 3`,
    response_result_tb_1.probe_4 AS `1-Probe 4`,
    response_result_tb_1.probe_5 AS `1-Probe 5`,
    response_result_tb_1.probe_6 AS `1-Probe 6`,

    response_result_tb_2.date_tested AS `2-Date Tested`,
    response_result_tb_2.instrument_serial AS `2-Instrument Serial`,
    response_result_tb_2.instrument_last_calibrated_on AS `2-Instrument Last Calibrated`,
    CASE
      WHEN response_result_tb_2.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_2.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_2.error_code)
      WHEN response_result_tb_2.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_2.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_2.mtb_detected = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_2.mtb_detected = 'trace' THEN 'Trace'
      WHEN response_result_tb_2.mtb_detected = 'na' THEN 'N/A'
      WHEN IFNULL(response_result_tb_2.mtb_detected, '') = '' THEN NULL
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_2.mtb_detected, 1, 1)), SUBSTRING(response_result_tb_2.mtb_detected, 2, 254))
    END AS `2-MTB`,
    CASE
      WHEN response_result_tb_2.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_2.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_2.error_code)
      WHEN response_result_tb_2.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_2.mtb_detected = 'invalid' THEN 'Invalid'
      WHEN response_result_tb_2.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_2.mtb_detected IN ('detected', 'trace', 'veryLow', 'low', 'medium', 'high') AND IFNULL(response_result_tb_2.rif_resistance, 'na') = 'na' THEN 'Not Detected'
      WHEN response_result_tb_2.rif_resistance = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_2.rif_resistance = 'noResult' THEN 'No Result'
      WHEN response_result_tb_2.rif_resistance = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_2.rif_resistance = 'na' THEN 'N/A'
      WHEN response_result_tb_2.mtb_detected = 'notDetected' AND IFNULL(response_result_tb_2.rif_resistance, '') = '' THEN 'N/A'
      WHEN response_result_tb_2.mtb_detected NOT IN ('noResult', 'notDetected', 'invalid') AND IFNULL(response_result_tb_2.rif_resistance, '') = '' THEN 'N/A'
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_2.rif_resistance, 1, 1)), SUBSTRING(response_result_tb_2.rif_resistance, 2, 254))
    END AS `2-Rif`,
    response_result_tb_2.probe_1 AS `2-Probe 1`,
    response_result_tb_2.probe_2 AS `2-Probe 2`,
    response_result_tb_2.probe_3 AS `2-Probe 3`,
    response_result_tb_2.probe_4 AS `2-Probe 4`,
    response_result_tb_2.probe_5 AS `2-Probe 5`,
    response_result_tb_2.probe_6 AS `2-Probe 6`,

    response_result_tb_3.date_tested AS `3-Date Tested`,
    response_result_tb_3.instrument_serial AS `3-Instrument Serial`,
    response_result_tb_3.instrument_last_calibrated_on AS `3-Instrument Last Calibrated`,
    CASE
      WHEN response_result_tb_3.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_3.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_3.error_code)
      WHEN response_result_tb_3.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_3.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_3.mtb_detected = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_3.mtb_detected = 'trace' THEN 'Trace'
      WHEN response_result_tb_3.mtb_detected = 'na' THEN 'N/A'
      WHEN IFNULL(response_result_tb_3.mtb_detected, '') = '' THEN NULL
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_3.mtb_detected, 1, 1)), SUBSTRING(response_result_tb_3.mtb_detected, 2, 254))
    END AS `3-MTB`,
    CASE
      WHEN response_result_tb_3.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_3.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_3.error_code)
      WHEN response_result_tb_3.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_3.mtb_detected = 'invalid' THEN 'Invalid'
      WHEN response_result_tb_3.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_3.mtb_detected IN ('detected', 'trace', 'veryLow', 'low', 'medium', 'high') AND IFNULL(response_result_tb_3.rif_resistance, 'na') = 'na' THEN 'Not Detected'
      WHEN response_result_tb_3.rif_resistance = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_3.rif_resistance = 'noResult' THEN 'No Result'
      WHEN response_result_tb_3.rif_resistance = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_3.rif_resistance = 'na' THEN 'N/A'
      WHEN response_result_tb_3.mtb_detected = 'notDetected' AND IFNULL(response_result_tb_3.rif_resistance, '') = '' THEN 'N/A'
      WHEN response_result_tb_3.mtb_detected NOT IN ('noResult', 'notDetected', 'invalid') AND IFNULL(response_result_tb_3.rif_resistance, '') = '' THEN 'N/A'
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_3.rif_resistance, 1, 1)), SUBSTRING(response_result_tb_3.rif_resistance, 2, 254))
    END AS `3-Rif`,
    response_result_tb_3.probe_1 AS `3-Probe 1`,
    response_result_tb_3.probe_2 AS `3-Probe 2`,
    response_result_tb_3.probe_3 AS `3-Probe 3`,
    response_result_tb_3.probe_4 AS `3-Probe 4`,
    response_result_tb_3.probe_5 AS `3-Probe 5`,
    response_result_tb_3.probe_6 AS `3-Probe 6`,

    response_result_tb_4.date_tested AS `4-Date Tested`,
    response_result_tb_4.instrument_serial AS `4-Instrument Serial`,
    response_result_tb_4.instrument_last_calibrated_on AS `4-Instrument Last Calibrated`,
    CASE
      WHEN response_result_tb_4.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_4.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_4.error_code)
      WHEN response_result_tb_4.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_4.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_4.mtb_detected = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_4.mtb_detected = 'trace' THEN 'Trace'
      WHEN response_result_tb_4.mtb_detected = 'na' THEN 'N/A'
      WHEN IFNULL(response_result_tb_4.mtb_detected, '') = '' THEN NULL
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_4.mtb_detected, 1, 1)), SUBSTRING(response_result_tb_4.mtb_detected, 2, 254))
    END AS `4-MTB`,
    CASE
      WHEN response_result_tb_4.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_4.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_4.error_code)
      WHEN response_result_tb_4.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_4.mtb_detected = 'invalid' THEN 'Invalid'
      WHEN response_result_tb_4.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_4.mtb_detected IN ('detected', 'trace', 'veryLow', 'low', 'medium', 'high') AND IFNULL(response_result_tb_4.rif_resistance, 'na') = 'na' THEN 'Not Detected'
      WHEN response_result_tb_4.rif_resistance = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_4.rif_resistance = 'noResult' THEN 'No Result'
      WHEN response_result_tb_4.rif_resistance = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_4.rif_resistance = 'na' THEN 'N/A'
      WHEN response_result_tb_4.mtb_detected = 'notDetected' AND IFNULL(response_result_tb_4.rif_resistance, '') = '' THEN 'N/A'
      WHEN response_result_tb_4.mtb_detected NOT IN ('noResult', 'notDetected', 'invalid') AND IFNULL(response_result_tb_4.rif_resistance, '') = '' THEN 'N/A'
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_4.rif_resistance, 1, 1)), SUBSTRING(response_result_tb_4.rif_resistance, 2, 254))
    END AS `4-Rif`,
    response_result_tb_4.probe_1 AS `4-Probe 1`,
    response_result_tb_4.probe_2 AS `4-Probe 2`,
    response_result_tb_4.probe_3 AS `4-Probe 3`,
    response_result_tb_4.probe_4 AS `4-Probe 4`,
    response_result_tb_4.probe_5 AS `4-Probe 5`,
    response_result_tb_4.probe_6 AS `4-Probe 6`,

    response_result_tb_5.date_tested AS `5-Date Tested`,
    response_result_tb_5.instrument_serial AS `5-Instrument Serial`,
    response_result_tb_5.instrument_last_calibrated_on AS `5-Instrument Last Calibrated`,
    CASE
      WHEN response_result_tb_5.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_5.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_5.error_code)
      WHEN response_result_tb_5.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_5.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_5.mtb_detected = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_5.mtb_detected = 'trace' THEN 'Trace'
      WHEN response_result_tb_5.mtb_detected = 'na' THEN 'N/A'
      WHEN IFNULL(response_result_tb_5.mtb_detected, '') = '' THEN NULL
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_5.mtb_detected, 1, 1)), SUBSTRING(response_result_tb_5.mtb_detected, 2, 254))
    END AS `5-MTB`,
    CASE
      WHEN response_result_tb_5.error_code = 'error' THEN 'Error'
      WHEN IFNULL(response_result_tb_5.error_code, '') != '' THEN CONCAT('Error ', response_result_tb_5.error_code)
      WHEN response_result_tb_5.mtb_detected = 'noResult' THEN 'No Result'
      WHEN response_result_tb_5.mtb_detected = 'invalid' THEN 'Invalid'
      WHEN response_result_tb_5.mtb_detected = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_5.mtb_detected IN ('detected', 'trace', 'veryLow', 'low', 'medium', 'high') AND IFNULL(response_result_tb_5.rif_resistance, 'na') = 'na' THEN 'Not Detected'
      WHEN response_result_tb_5.rif_resistance = 'notDetected' THEN 'Not Detected'
      WHEN response_result_tb_5.rif_resistance = 'noResult' THEN 'No Result'
      WHEN response_result_tb_5.rif_resistance = 'veryLow' THEN 'Very Low'
      WHEN response_result_tb_5.rif_resistance = 'na' THEN 'N/A'
      WHEN response_result_tb_5.mtb_detected = 'notDetected' AND IFNULL(response_result_tb_5.rif_resistance, '') = '' THEN 'N/A'
      WHEN response_result_tb_5.mtb_detected NOT IN ('noResult', 'notDetected', 'invalid') AND IFNULL(response_result_tb_5.rif_resistance, '') = '' THEN 'N/A'
      ELSE CONCAT(UPPER(SUBSTRING(response_result_tb_5.rif_resistance, 1, 1)), SUBSTRING(response_result_tb_5.rif_resistance, 2, 254))
    END AS `5-Rif`,
    response_result_tb_5.probe_1 AS `5-Probe 1`,
    response_result_tb_5.probe_2 AS `5-Probe 2`,
    response_result_tb_5.probe_3 AS `5-Probe 3`,
    response_result_tb_5.probe_4 AS `5-Probe 4`,
    response_result_tb_5.probe_5 AS `5-Probe 5`,
    response_result_tb_5.probe_6 AS `5-Probe 6`,
    
    TRIM(shipment_participant_map.user_comment) AS `Comments`,
    TRIM(COALESCE(CASE WHEN r_evaluation_comments.`comment` = '' THEN NULL ELSE r_evaluation_comments.`comment` END, shipment_participant_map.optional_eval_comment)) AS `Comments for reports`,
    
    CASE
      WHEN response_result_tb_1.calculated_score IN ('pass', 'concern', 'exempt') THEN 20
      WHEN response_result_tb_1.calculated_score = 'partial' THEN 10
      WHEN response_result_tb_1.calculated_score = 'noresult' THEN 5
      WHEN response_result_tb_1.calculated_score IN ('fail', 'excluded') THEN 0
      ELSE 0
    END AS `1-Score`,
    CASE
      WHEN response_result_tb_2.calculated_score IN ('pass', 'concern', 'exempt') THEN 20
      WHEN response_result_tb_2.calculated_score = 'partial' THEN 10
      WHEN response_result_tb_2.calculated_score = 'noresult' THEN 5
      WHEN response_result_tb_2.calculated_score IN ('fail', 'excluded') THEN 0
      ELSE 0
    END AS `2-Score`,
    CASE
      WHEN response_result_tb_3.calculated_score IN ('pass', 'concern', 'exempt') THEN 20
      WHEN response_result_tb_3.calculated_score = 'partial' THEN 10
      WHEN response_result_tb_3.calculated_score = 'noresult' THEN 5
      WHEN response_result_tb_3.calculated_score IN ('fail', 'excluded') THEN 0
      ELSE 0
    END AS `3-Score`,
    CASE
      WHEN response_result_tb_4.calculated_score IN ('pass', 'concern', 'exempt') THEN 20
      WHEN response_result_tb_4.calculated_score = 'partial' THEN 10
      WHEN response_result_tb_4.calculated_score = 'noresult' THEN 5
      WHEN response_result_tb_4.calculated_score IN ('fail', 'excluded') THEN 0
      ELSE 0
    END AS `4-Score`,
    CASE
      WHEN response_result_tb_5.calculated_score IN ('pass', 'concern', 'exempt') THEN 20
      WHEN response_result_tb_5.calculated_score = 'partial' THEN 10
      WHEN response_result_tb_5.calculated_score = 'noresult' THEN 5
      WHEN response_result_tb_5.calculated_score IN ('fail', 'excluded') THEN 0
      ELSE 0
    END AS `5-Score`,
    
    IFNULL(shipment_participant_map.documentation_score, 0) + IFNULL(shipment_participant_map.shipment_score, 0) AS `Fin Score`,
    CASE
      WHEN r_results.result_name = 'Pass' THEN 'Satisfactory'
      ELSE 'Unsatisfactory'
    END AS `Sat/Unsat` 
FROM shipment
JOIN shipment_participant_map ON shipment_participant_map.shipment_id = shipment.shipment_id
JOIN participant ON participant.participant_id = shipment_participant_map.participant_id
JOIN countries ON countries.id = participant.country
LEFT JOIN instrument ON instrument.participant_id = shipment_participant_map.participant_id
LEFT JOIN response_not_tested_reason ON response_not_tested_reason.not_tested_reason_id = shipment_participant_map.not_tested_reason
LEFT JOIN r_evaluation_comments ON r_evaluation_comments.comment_id = shipment_participant_map.evaluation_comment
LEFT JOIN r_results ON r_results.result_id = shipment_participant_map.final_result
LEFT JOIN r_tb_assay ON r_tb_assay.id = JSON_UNQUOTE(JSON_EXTRACT(shipment_participant_map.attributes, \"$.assay\"))
LEFT JOIN response_result_tb AS response_result_tb_1 ON response_result_tb_1.shipment_map_id = shipment_participant_map.map_id AND response_result_tb_1.sample_id = '1'
LEFT JOIN response_result_tb AS response_result_tb_2 ON response_result_tb_2.shipment_map_id = shipment_participant_map.map_id AND response_result_tb_2.sample_id = '2'
LEFT JOIN response_result_tb AS response_result_tb_3 ON response_result_tb_3.shipment_map_id = shipment_participant_map.map_id AND response_result_tb_3.sample_id = '3'
LEFT JOIN response_result_tb AS response_result_tb_4 ON response_result_tb_4.shipment_map_id = shipment_participant_map.map_id AND response_result_tb_4.sample_id = '4'
LEFT JOIN response_result_tb AS response_result_tb_5 ON response_result_tb_5.shipment_map_id = shipment_participant_map.map_id AND response_result_tb_5.sample_id = '5'
WHERE shipment.shipment_id = ?
GROUP BY shipment_participant_map.map_id) AS FlattenedEvaluationResults
ORDER BY FlattenedEvaluationResults.`PT-ID` * 1 ASC;", array($shipmentId));
        $results = $query->fetchAll();

        $sheet = new PHPExcel_Worksheet($excel, "All Sites' Results");
        $excel->addSheet($sheet, $sheetIndex);
        $columnIndex = 0;
        if (count($results) > 0 && count($results[0]) > 0) {
            foreach($results[0] as $columnName => $value) {
                $sheet->getCellByColumnAndRow($columnIndex, 1)->setValueExplicit(html_entity_decode($columnName, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $sheet->getStyleByColumnAndRow($columnIndex, 1)->applyFromArray($borderStyle);
                $columnIndex++;
            }
        }

        $sheet->getDefaultRowDimension()->setRowHeight(15);

        $rowNumber = 1; // $row 0 is already the column headings

        foreach($results as $result){
            $rowNumber++;
            $columnIndex = 0;
            foreach($result as $columnName => $value) {
                $sheet->getCellByColumnAndRow($columnIndex, $rowNumber)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $columnIndex++;
            }
        }

        foreach(range('A','Z') as $columnID) {
            $sheet->getColumnDimension($columnID)
                ->setAutoSize(true);
        }
        return $sheet;
    }

	public function getTbAllSitesResultsReport($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();

        $excel = new PHPExcel();
        $this->getTbAllSitesResultsSheet($db, $params['shipmentId'], $excel, 0);
        $excel->setActiveSheetIndex(0);

        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $shipmentQuery = $db->select('shipment_code')
            ->from('shipment')
            ->where('shipment_id=?', $params['shipmentId']);
        $shipmentResult = $db->fetchRow($shipmentQuery);
        $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
        if (!file_exists(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports")) {
            mkdir(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports", 0777, true);
        }
        $fileSafeShipmentCode = str_replace( ' ', '-', str_replace(array_merge(
            array_map('chr', range(0, 31)),
            array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
        ), '', $shipmentResult['shipment_code']));
        $filename = $fileSafeShipmentCode . '-all-results' . '.xls';
        $writer->save(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports" . DIRECTORY_SEPARATOR . $filename);

        return array(
          "report-name" => $filename
        );
    }

    public function getMonthlyIndicatorsMonths() {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();

        $sQuery = $db->select()->from(array('pmi' => 'participant_monthly_indicators'), array('created_on'))
            ->join(array('p' => 'participant'), 'pmi.participant_id = p.participant_id', array());
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if ($authNameSpace->is_ptcc_coordinator) {
            $sQuery = $sQuery->where("c.id IN (".implode(",",$authNameSpace->countries).")");
        }
        $sQuery = $sQuery->order("created_on DESC")
            ->distinct();
        $firstMonthlyIndicatorSubmissionDate = $db->fetchRow($sQuery);
        $earliestMonthDate = date('Y-m-d');
        if ($firstMonthlyIndicatorSubmissionDate) {
            $earliestMonthDate = date('Y-m-d', strtotime(Application_Service_Common::ParseDbDate($firstMonthlyIndicatorSubmissionDate['created_on'])));
        }
        $indicatorMonths = array(
            array(
                "value" => date("Y:m", strtotime($earliestMonthDate)),
                "text" => date("M, Y", strtotime($earliestMonthDate))
            )
        );

        while (date("Y", strtotime($earliestMonthDate)) < date("Y") ||
            (date("Y", strtotime($earliestMonthDate)) == date("Y") && date("m", strtotime($earliestMonthDate)) < date("m"))) {
            $earliestMonthDate = date('Y-m-d', strtotime("+1 month", strtotime($earliestMonthDate)));
            array_push($indicatorMonths, array(
                "value" => date("Y:m", strtotime($earliestMonthDate)),
                "text" => date("M, Y", strtotime($earliestMonthDate))
            ));
        }
        return array_reverse($indicatorMonths);
    }

    public function getMonthlyIndicatorsReport($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();

        $excel = new PHPExcel();

        $borderStyle = array(
            'font' => array(
                'bold' => true,
                'size'  => 12,
            ),
            'alignment' => array(
                'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
            ),
            'borders' => array(
                'outline' => array(
                    'style' => PHPExcel_Style_Border::BORDER_THIN,
                ),
            )
        );

        $reportHeaderLabelStyle = array(
            'font' => array(
                'bold' => true,
                'size'  => 12,
            ),
            'borders' => array(
                'outline' => array(
                    'style' => PHPExcel_Style_Border::BORDER_THIN,
                ),
            )
        );

        $reportHeaderValueStyle = array(
            'borders' => array(
                'outline' => array(
                    'style' => PHPExcel_Style_Border::BORDER_THIN,
                ),
            )
        );
        $countryName = "All Countries";
        $countryId = $params['countryId'];
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if (!$countryId && $authNameSpace->is_ptcc_coordinator && count($authNameSpace->countries) == 1) {
            $countryId = $authNameSpace->countries[0];
        }
        if ($countryId) {
            $countryResult = $db->fetchRow($db->select()->from(array('c' => 'countries'), array("c.iso_name"))->where("c.id = ?", $countryId));
            if($countryResult) {
                $countryName = $countryResult["iso_name"];
            }
        }
        $yearMonth = explode(":", $params['month']);
        $monthHeaderValue = date('M, Y', strtotime($yearMonth[0]."-".$yearMonth[1]."-01"));


        $sQuery = $db->select()->from(array('p' => 'participant'), array(
                "sorting_unique_identifier" => new Zend_Db_Expr("LPAD(p.unique_identifier, 10, '0')"),
                "PT ID" => "p.unique_identifier",
                "Participant" => new Zend_Db_Expr("p.lab_name")
            ))
            ->join(array('c' => 'countries'),'p.country = c.id', array("Country" => "c.iso_name"))
            ->join(array('pmi' => 'participant_monthly_indicators'),'p.participant_id = pmi.participant_id', array(
                "indicatorYear" => new Zend_Db_Expr("YEAR(`pmi`.`created_on`)"),
                "indicatorMonth" => new Zend_Db_Expr("MONTH(`pmi`.`created_on`)"),
                "Year" => new Zend_Db_Expr("YEAR(`pmi`.`created_on`)"),
                "Month" => new Zend_Db_Expr("MONTHNAME(`pmi`.`created_on`)"),
                "attributes",
                "Submitted On" => "created_on"
            ))
            ->join(array('dm' => 'data_manager'),'pmi.created_by = dm.dm_id', array(
                "Submitted By" => new Zend_Db_Expr("COALESCE(CONCAT(`dm`.`first_name`, ' ', `dm`.`last_name`), `dm`.`first_name`, `dm`.`last_name`)")
            ));
        if ($authNameSpace->is_ptcc_coordinator) {
            $sQuery = $sQuery->where("c.id IN (".implode(",",$authNameSpace->countries).")");
        }
        if (isset($params['countryId']) && $params['countryId'] != "") {
            $sQuery = $sQuery->where("c.id = ?", $params['countryId']);
        }
        if (isset($params['month']) && $params['month'] != "") {
            $sQuery = $sQuery->where("YEAR(`pmi`.`created_on`) = ?", $yearMonth[0]);
            $sQuery = $sQuery->where("MONTH(`pmi`.`created_on`) = ?", $yearMonth[1]);
        }
        $sQuery = $sQuery->order(array("indicatorYear ASC", "indicatorMonth ASC", "sorting_unique_identifier ASC"))
            ->distinct();
        $results = $db->fetchAll($sQuery);

        $firstSheet = new PHPExcel_Worksheet($excel, "Monthly Indicators");
        $excel->addSheet($firstSheet, 0);
        $columnIndex = 0;
        $doNotShow = array(
            "sorting_unique_identifier",
            "indicatorMonth",
            "indicatorYear",
            "attributes",
        );
        $firstSheet->getDefaultRowDimension()->setRowHeight(15);

        $firstSheet->getCellByColumnAndRow($columnIndex, 1)->setValueExplicit(html_entity_decode("Country", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $firstSheet->getCellByColumnAndRow($columnIndex + 1, 1)->setValueExplicit(html_entity_decode($countryName, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $firstSheet->getStyleByColumnAndRow($columnIndex, 1)->applyFromArray($reportHeaderLabelStyle);
        $firstSheet->getStyleByColumnAndRow($columnIndex + 1, 1)->applyFromArray($reportHeaderValueStyle);

        $firstSheet->getCellByColumnAndRow($columnIndex, 2)->setValueExplicit(html_entity_decode("Month", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $firstSheet->getCellByColumnAndRow($columnIndex + 1, 2)->setValueExplicit(html_entity_decode($monthHeaderValue, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $firstSheet->getStyleByColumnAndRow($columnIndex, 2)->applyFromArray($reportHeaderLabelStyle);
        $firstSheet->getStyleByColumnAndRow($columnIndex + 1, 2)->applyFromArray($reportHeaderValueStyle);

        $initialRowNumber = 4;
        $rowNumber = $initialRowNumber;
        if (count($results) > 0 && count($results[0]) > 0) {
            foreach($results[0] as $columnName => $value) {
                if (!in_array($columnName, $doNotShow)) {
                    $firstSheet->getCellByColumnAndRow($columnIndex, $rowNumber)->setValueExplicit(html_entity_decode($columnName, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $firstSheet->getStyleByColumnAndRow($columnIndex, $rowNumber)->applyFromArray($borderStyle);
                    $columnIndex++;
                }
            }
            $attributeLabels = array(
                "countTestsConductedOverMonth" => "Number of Tests",
                "countErrorsEncounteredOverMonth" => "Number of Errors",
                "errorCodesEncounteredOverMonth" => "Error Codes",
            );
            $attributeColumnIndeces = array();
            $nextAttributeColumnIndex = $columnIndex;
            foreach ($results as $result) {
                $rowNumber++;
                $rowColumnIndex = 0;
                foreach($result as $columnName => $value) {
                    if (!in_array($columnName, $doNotShow)) {
                        $firstSheet->getCellByColumnAndRow($rowColumnIndex, $rowNumber)->setValueExplicit(html_entity_decode($value, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $rowColumnIndex++;
                    }
                }
                $attributes = json_decode($result['attributes'], true);
                foreach ($attributes as $attributeName => $attributeValue) {
                    $attributeRowColumnIndex = $rowColumnIndex;
                    if (array_key_exists($attributeName, $attributeColumnIndeces)) {
                        $attributeRowColumnIndex = $attributeColumnIndeces[$attributeName];
                    } else {
                        $attributeColumnIndeces[$attributeName] = $nextAttributeColumnIndex;
                        $attributeRowColumnIndex = $nextAttributeColumnIndex;
                        $attributeLabel = $attributeName;
                        if (array_key_exists($attributeName, $attributeLabels)) {
                            $attributeLabel = $attributeLabels[$attributeName];
                        }
                        $firstSheet->getCellByColumnAndRow($attributeRowColumnIndex, $initialRowNumber)->setValueExplicit(html_entity_decode($attributeLabel, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $firstSheet->getStyleByColumnAndRow($attributeRowColumnIndex, $initialRowNumber)->applyFromArray($borderStyle);
                        $nextAttributeColumnIndex++;
                    }
                    $firstSheet->getCellByColumnAndRow($attributeRowColumnIndex, $rowNumber)->setValueExplicit(html_entity_decode($attributeValue, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                }
            }
        } else {
            $firstSheet->getCellByColumnAndRow($columnIndex, $rowNumber)->setValueExplicit(html_entity_decode("No Monthly Indictors Where Submitted During this Period", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        }
        foreach(range('A','Z') as $columnID) {
            $firstSheet->getColumnDimension($columnID)
                ->setAutoSize(true);
        }

        $excel->setActiveSheetIndex(0);
        $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
        if (!file_exists(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports")) {
            mkdir(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports", 0777, true);
        }
        $filename = 'Monthly-Indicators.xls';
        $writer->save(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports" . DIRECTORY_SEPARATOR . $filename);

        return array(
            "report-name" => $filename
        );
    }

    public function getXmpepIndicatorsReport($params) {
        $db = Zend_Db_Table_Abstract::getDefaultAdapter();
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $excel = new PHPExcel();
        $sheetHeaderStyle = array(
            'font' => array(
                'bold' => true,
                'size' => 16,
            ),
        );
        $columnHeaderStyle = array(
            'font' => array(
                'bold' => true,
                'size' => 12,
            ),
            'alignment' => array(
                'horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER,
            ),
            'borders' => array(
                'outline' => array(
                    'style' => PHPExcel_Style_Border::BORDER_THIN,
                ),
            )
        );
        $rowHeaderStyle = array(
            'font' => array(
                'bold' => true,
                'size' => 12,
            ),
            'borders' => array(
                'outline' => array(
                    'style' => PHPExcel_Style_Border::BORDER_THIN,
                ),
            )
        );
        $nonConcordanceStyle = array(
            'font' => array(
                'color' => array('rgb' => 'FF0000'),
            ),
        );
        $sampleLabelStyle = array(
            'font' => array(
                'bold' => true,
                'size' => 13,
            ),
            'alignment' => array(
                'vertical' => PHPExcel_Style_Alignment::VERTICAL_TOP,
            ),
        );
        $sheetIndex = 0;
        $panelStatisticsQuery = "SELECT COUNT(spm.map_id) AS participating_sites,
  SUM(CASE WHEN SUBSTRING(spm.evaluation_status, 3, 1) = '1' THEN 1 ELSE 0 END) AS response_received,
  SUM(CASE WHEN spm.is_excluded = 'yes' THEN 1 ELSE 0 END) AS excluded,
  SUM(CASE WHEN IFNULL(spm.is_pt_test_not_performed, 'no') = 'no' THEN 1 ELSE 0 END) AS able_to_submit,
  SUM(CASE WHEN spm.shipment_score >= 80 THEN 1 ELSE 0 END) AS scored_higher_than_80,
  SUM(CASE WHEN spm.shipment_score = 100 THEN 1 ELSE 0 END) AS scored_100
FROM shipment_participant_map AS spm
JOIN participant AS p ON p.participant_id = spm.participant_id
WHERE spm.shipment_id = ?";

        $errorCodesQuery = "SELECT res.error_code, COUNT(*) AS number_of_occurrences
FROM shipment_participant_map AS spm
JOIN response_result_tb AS res ON res.shipment_map_id = spm.map_id
JOIN participant AS p ON p.participant_id = spm.participant_id
WHERE spm.shipment_id = ?
AND res.error_code <> ''";

        $nonParticipatingCountriesQuery = "SELECT countries.iso_name AS country_name,
  CASE WHEN spm.is_pt_test_not_performed = 'yes' THEN IFNULL(rntr.not_tested_reason, 'Unknown') ELSE NULL END AS not_tested_reason,
  SUM(CASE WHEN spm.is_pt_test_not_performed = 'yes' THEN 1 ELSE 0 END) AS is_pt_test_not_performed,
  COUNT(spm.map_id) AS number_of_participants
FROM shipment_participant_map AS spm
JOIN participant AS p ON p.participant_id = spm.participant_id
JOIN countries ON countries.id = p.country
LEFT JOIN response_not_tested_reason AS rntr ON rntr.not_tested_reason_id = spm.not_tested_reason
WHERE spm.shipment_id = ?";

        $discordantResultsInnerQuery = "FROM (
  SELECT p.unique_identifier,
    p.lab_name,
    ref.sample_id,
    ref.sample_label,
    res.mtb_detected AS res_mtb,
    CASE WHEN a.short_name = 'MTB Ultra' THEN ref.ultra_mtb_detected ELSE ref.mtb_rif_mtb_detected END AS ref_mtb,
    res.rif_resistance AS res_rif,
    CASE WHEN a.short_name = 'MTB Ultra' THEN ref.ultra_rif_resistance ELSE ref.mtb_rif_rif_resistance END AS ref_rif,
    CASE WHEN res.mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace') THEN 1 ELSE 0 END AS res_mtb_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace')) OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace')) THEN 1 ELSE 0 END AS ref_mtb_detected,
    CASE WHEN res.mtb_detected = 'notDetected' THEN 1 ELSE 0 END AS res_mtb_not_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_mtb_detected = 'notDetected') OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_mtb_detected = 'notDetected') THEN 1 ELSE 0 END AS ref_mtb_not_detected,
    CASE WHEN res.mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace') AND res.rif_resistance = 'detected' THEN 1 ELSE 0 END AS res_rif_resistance_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_rif_resistance = 'detected') OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_rif_resistance = 'detected') THEN 1 ELSE 0 END AS ref_rif_resistance_detected,
    CASE WHEN res.mtb_detected IN ('notDetected', 'detected', 'high', 'medium', 'low', 'veryLow') AND IFNULL(res.rif_resistance, '') IN ('notDetected', 'na', '') THEN 1 ELSE 0 END AS res_rif_resistance_not_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_rif_resistance <> 'detected') OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_rif_resistance <> 'detected') THEN 1 ELSE 0 END AS ref_rif_resistance_not_detected
  FROM shipment_participant_map AS spm
  JOIN participant AS p ON p.participant_id = spm.participant_id
  JOIN response_result_tb AS res ON res.shipment_map_id = spm.map_id
  JOIN reference_result_tb AS ref ON ref.shipment_id = spm.shipment_id
                                  AND ref.sample_id = res.sample_id
  LEFT JOIN r_tb_assay AS a ON a.id = JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, \"$.assay\"))
  WHERE spm.shipment_id = ?
  AND SUBSTR(spm.evaluation_status, 3, 1) = '1'
  AND spm.is_pt_test_not_performed <> 'yes'";

        $discordantCountriesQuery = "SELECT mtb_rif_detection_results.country_name,
  SUM(CASE WHEN (mtb_rif_detection_results.res_mtb_detected = 1 AND mtb_rif_detection_results.ref_mtb_not_detected = 1) OR (mtb_rif_detection_results.res_mtb_not_detected = 1 AND mtb_rif_detection_results.ref_mtb_detected = 1) OR (mtb_rif_detection_results.res_rif_resistance_detected = 1 AND mtb_rif_detection_results.ref_rif_resistance_not_detected = 1) THEN 1 ELSE 0 END) AS discordant,
  COUNT(mtb_rif_detection_results.country_id) AS total_results
FROM (
  SELECT countries.id AS country_id,
    countries.iso_name AS country_name,
    CASE WHEN res.mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace') THEN 1 ELSE 0 END AS res_mtb_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace')) OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace')) THEN 1 ELSE 0 END AS ref_mtb_detected,
    CASE WHEN res.mtb_detected = 'notDetected' THEN 1 ELSE 0 END AS res_mtb_not_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_mtb_detected = 'notDetected') OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_mtb_detected = 'notDetected') THEN 1 ELSE 0 END AS ref_mtb_not_detected,
    CASE WHEN res.mtb_detected IN ('detected', 'high', 'medium', 'low', 'veryLow', 'trace') AND res.rif_resistance = 'detected' THEN 1 ELSE 0 END AS res_rif_resistance_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_rif_resistance = 'detected') OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_rif_resistance = 'detected') THEN 1 ELSE 0 END AS ref_rif_resistance_detected,
    CASE WHEN res.mtb_detected IN ('notDetected', 'detected', 'high', 'medium', 'low', 'veryLow') AND IFNULL(res.rif_resistance, '') IN ('notDetected', 'na', '') THEN 1 ELSE 0 END AS res_rif_resistance_not_detected,
    CASE WHEN (a.short_name = 'MTB Ultra' AND ref.ultra_rif_resistance <> 'detected') OR (IFNULL(a.short_name, 'MTB/RIF') = 'MTB/RIF' AND ref.mtb_rif_rif_resistance <> 'detected') THEN 1 ELSE 0 END AS ref_rif_resistance_not_detected
  FROM shipment_participant_map AS spm
  JOIN participant AS p ON p.participant_id = spm.participant_id
  JOIN countries ON countries.id = p.country
  JOIN response_result_tb AS res ON res.shipment_map_id = spm.map_id
  JOIN reference_result_tb AS ref ON ref.shipment_id = spm.shipment_id
                                  AND ref.sample_id = res.sample_id
  LEFT JOIN r_tb_assay AS a ON a.id = JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, \"$.assay\"))
  WHERE spm.shipment_id = 23
  AND SUBSTR(spm.evaluation_status, 3, 1) = '1'
  AND spm.is_pt_test_not_performed <> 'yes'";
        if ($authNameSpace->is_ptcc_coordinator) {
            $panelStatisticsQuery .= "
AND p.country IN (".implode(",",$authNameSpace->countries).")";
            $errorCodesQuery .= "
AND p.country IN (".implode(",",$authNameSpace->countries).")";
            $nonParticipatingCountriesQuery .= "
AND p.country IN (".implode(",",$authNameSpace->countries).")";
            $discordantResultsInnerQuery .= "
  AND p.country IN (".implode(",",$authNameSpace->countries).")";
            $discordantCountriesQuery .= "
  AND p.country IN (".implode(",",$authNameSpace->countries).")";
        }
        $panelStatisticsQuery .= ";";
        $errorCodesQuery .= "
GROUP BY res.error_code
ORDER BY error_code ASC;";
        $nonParticipatingCountriesQuery .= "
GROUP BY countries.iso_name, rntr.not_tested_reason
ORDER BY countries.iso_name, rntr.not_tested_reason ASC;";
        $discordantResultsInnerQuery .= "
) AS mtb_rif_detection_results";

        $discordantResultsQuery = "SELECT mtb_rif_detection_results.sample_label,
  SUM(CASE WHEN mtb_rif_detection_results.res_mtb_detected = 1 AND mtb_rif_detection_results.ref_mtb_not_detected = 1 THEN 1 ELSE 0 END) AS false_positives,
  SUM(CASE WHEN mtb_rif_detection_results.res_mtb_not_detected = 1 AND mtb_rif_detection_results.ref_mtb_detected = 1 THEN 1 ELSE 0 END) AS false_negatives,
  SUM(CASE WHEN mtb_rif_detection_results.res_rif_resistance_detected = 1 AND mtb_rif_detection_results.ref_rif_resistance_not_detected = 1 THEN 1 ELSE 0 END) AS false_resistances
".$discordantResultsInnerQuery."
GROUP BY mtb_rif_detection_results.sample_id
ORDER BY mtb_rif_detection_results.sample_id ASC;";

        $discordantResultsParticipantsQuery = "SELECT LPAD(mtb_rif_detection_results.unique_identifier, 10, '0') AS sorting_unique_identifier,
  mtb_rif_detection_results.unique_identifier,
  mtb_rif_detection_results.lab_name,
  mtb_rif_detection_results.sample_label,
  mtb_rif_detection_results.sample_id,
  CASE
    WHEN mtb_rif_detection_results.res_mtb = 'error' THEN 'Error'
    WHEN mtb_rif_detection_results.res_mtb = 'notDetected' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.res_mtb = 'noResult' THEN 'No Result'
    WHEN mtb_rif_detection_results.res_mtb = 'veryLow' THEN 'Very Low'
    WHEN mtb_rif_detection_results.res_mtb = 'trace' THEN 'Trace'
    WHEN mtb_rif_detection_results.res_mtb = 'na' THEN 'N/A'
    WHEN IFNULL(mtb_rif_detection_results.res_mtb, '') = '' THEN NULL
    ELSE CONCAT(UPPER(SUBSTRING(mtb_rif_detection_results.res_mtb, 1, 1)), SUBSTRING(mtb_rif_detection_results.res_mtb, 2, 254))
  END AS res_mtb_detected,
  CASE
    WHEN mtb_rif_detection_results.ref_mtb = 'error' THEN 'Error'
    WHEN mtb_rif_detection_results.ref_mtb = 'notDetected' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.ref_mtb = 'noResult' THEN 'No Result'
    WHEN mtb_rif_detection_results.ref_mtb = 'veryLow' THEN 'Very Low'
    WHEN mtb_rif_detection_results.ref_mtb = 'trace' THEN 'Trace'
    WHEN mtb_rif_detection_results.ref_mtb = 'na' THEN 'N/A'
    WHEN IFNULL(mtb_rif_detection_results.ref_mtb, '') = '' THEN NULL
    ELSE CONCAT(UPPER(SUBSTRING(mtb_rif_detection_results.ref_mtb, 1, 1)), SUBSTRING(mtb_rif_detection_results.ref_mtb, 2, 254))
  END AS ref_mtb_detected,
  CASE
    WHEN mtb_rif_detection_results.res_mtb = 'error' THEN 'Error'
    WHEN mtb_rif_detection_results.res_mtb = 'notDetected' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.res_mtb = 'noResult' THEN 'No Result'
    WHEN mtb_rif_detection_results.res_mtb = 'invalid' THEN 'Invalid'
    WHEN mtb_rif_detection_results.res_mtb IN ('detected', 'trace', 'veryLow', 'low', 'medium', 'high') AND IFNULL(mtb_rif_detection_results.res_rif, 'na') = 'na' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.res_rif = 'notDetected' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.res_rif = 'noResult' THEN 'No Result'
    WHEN mtb_rif_detection_results.res_rif = 'veryLow' THEN 'Very Low'
    WHEN mtb_rif_detection_results.res_rif = 'na' THEN 'N/A'
    WHEN mtb_rif_detection_results.res_rif = 'notDetected' AND IFNULL(mtb_rif_detection_results.res_rif, '') = '' THEN 'N/A'
    WHEN mtb_rif_detection_results.res_rif IN ('noResult', 'notDetected', 'invalid') AND IFNULL(mtb_rif_detection_results.res_rif, '') = '' THEN 'N/A'
    ELSE CONCAT(UPPER(SUBSTRING(mtb_rif_detection_results.res_rif, 1, 1)), SUBSTRING(mtb_rif_detection_results.res_rif, 2, 254))
  END AS res_rif_resistance,
  CASE
    WHEN mtb_rif_detection_results.ref_mtb = 'error' THEN 'Error'
    WHEN mtb_rif_detection_results.ref_mtb = 'notDetected' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.ref_mtb = 'noResult' THEN 'No Result'
    WHEN mtb_rif_detection_results.ref_mtb = 'invalid' THEN 'Invalid'
    WHEN mtb_rif_detection_results.ref_mtb IN ('detected', 'trace', 'veryLow', 'low', 'medium', 'high') AND IFNULL(mtb_rif_detection_results.ref_rif, 'na') = 'na' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.ref_rif = 'notDetected' THEN 'Not Detected'
    WHEN mtb_rif_detection_results.ref_rif = 'noResult' THEN 'No Result'
    WHEN mtb_rif_detection_results.ref_rif = 'veryLow' THEN 'Very Low'
    WHEN mtb_rif_detection_results.ref_rif = 'na' THEN 'N/A'
    WHEN mtb_rif_detection_results.ref_rif = 'notDetected' AND IFNULL(mtb_rif_detection_results.ref_rif, '') = '' THEN 'N/A'
    WHEN mtb_rif_detection_results.ref_mtb IN ('noResult', 'notDetected', 'invalid') AND IFNULL(mtb_rif_detection_results.ref_rif, '') = '' THEN 'N/A'
    ELSE CONCAT(UPPER(SUBSTRING(mtb_rif_detection_results.ref_rif, 1, 1)), SUBSTRING(mtb_rif_detection_results.ref_rif, 2, 254))
  END AS ref_rif_resistance,
  CASE
    WHEN mtb_rif_detection_results.res_mtb_detected = 1 AND mtb_rif_detection_results.ref_mtb_not_detected = 1 THEN 'False Positive'
    WHEN mtb_rif_detection_results.res_mtb_not_detected = 1 AND mtb_rif_detection_results.ref_mtb_detected = 1 THEN 'False Negative'
    WHEN mtb_rif_detection_results.res_rif_resistance_detected = 1 AND mtb_rif_detection_results.ref_rif_resistance_not_detected = 1 THEN 'False Resistance Detected'
  END AS non_concordance_reason
".$discordantResultsInnerQuery."
WHERE (mtb_rif_detection_results.res_mtb_detected = 1 AND mtb_rif_detection_results.ref_mtb_not_detected = 1)
OR (mtb_rif_detection_results.res_mtb_not_detected = 1 AND mtb_rif_detection_results.ref_mtb_detected = 1)
OR (mtb_rif_detection_results.res_rif_resistance_detected = 1 AND mtb_rif_detection_results.ref_rif_resistance_not_detected = 1)
ORDER BY sorting_unique_identifier ASC, sample_id ASC;";

        $discordantCountriesQuery .= "
) AS mtb_rif_detection_results
GROUP BY mtb_rif_detection_results.country_id
ORDER BY mtb_rif_detection_results.country_name ASC;";
        $panelStatistics = $db->query($panelStatisticsQuery, array($params['shipmentId']))->fetchAll()[0];
        $shipmentQuery = $db->select('shipment_code')
            ->from('shipment')
            ->where('shipment_id=?', $params['shipmentId']);
        $shipmentResult = $db->fetchRow($shipmentQuery);
        $panelStatisticsSheet = new PHPExcel_Worksheet($excel, "Panel Statistics");
        $excel->addSheet($panelStatisticsSheet, $sheetIndex);
        $sheetIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("Panel Statistics for " . $shipmentResult['shipment_code'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow(0, 1)->applyFromArray($sheetHeaderStyle);
        $panelStatisticsSheet->getRowDimension(1)->setRowHeight(25);
        $rowIndex = 3;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Number of Participating Sites", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($panelStatistics["participating_sites"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Number of Responses Received", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($panelStatistics["response_received"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Number of Responses Excluded", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($panelStatistics["excluded"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Number of Participants Able to Submit", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($panelStatistics["able_to_submit"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Number of Participants Scoring 80% or Higher", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($panelStatistics["scored_higher_than_80"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Number of Participants Scoring 100%", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($panelStatistics["scored_100"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $rowIndex++;
        $columnIndex = 0;

        $nonParticipantingCountries = $db->query($nonParticipatingCountriesQuery, array($params['shipmentId']))->fetchAll();
        $nonParticipatingCountriesExist = false;
        $nonParticipationReasons = array();
        foreach ($nonParticipantingCountries as $nonParticipantingCountry) {
            if (isset($nonParticipantingCountry['not_tested_reason']) && !in_array($nonParticipantingCountry['not_tested_reason'], $nonParticipationReasons)) {
                $nonParticipatingCountriesExist = true;
                array_push($nonParticipationReasons, $nonParticipantingCountry['not_tested_reason']);
            }
        }
        sort($nonParticipationReasons);
        if ($nonParticipatingCountriesExist) {
            $nonParticipatingCountriesMap = array();
            foreach ($nonParticipantingCountries as $nonParticipantingCountry) {
                if (!array_key_exists($nonParticipantingCountry['country_name'], $nonParticipatingCountriesMap)) {
                    $nonParticipatingCountriesMap[$nonParticipantingCountry['country_name']] = array(
                        'not_participated' => 0,
                        'total_participants' => 0
                    );
                    foreach ($nonParticipationReasons as $nonParticipationReason) {
                        $nonParticipatingCountriesMap[$nonParticipantingCountry['country_name']][$nonParticipationReason] = 0;
                    }
                }
                $nonParticipatingCountriesMap[$nonParticipantingCountry['country_name']]['total_participants'] += intval($nonParticipantingCountry['number_of_participants']);
                if (isset($nonParticipantingCountry['not_tested_reason'])) {
                    $nonParticipatingCountriesMap[$nonParticipantingCountry['country_name']][$nonParticipantingCountry['not_tested_reason']] = intval($nonParticipantingCountry['is_pt_test_not_performed']);
                    $nonParticipatingCountriesMap[$nonParticipantingCountry['country_name']]['not_participated'] += intval($nonParticipantingCountry['is_pt_test_not_performed']);
                }
            }
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("List of countries with non-participating sites", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
            $columnIndex++;
            foreach ($nonParticipationReasons as $nonParticipationReason) {
                $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($nonParticipationReason, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
                $columnIndex++;
            }
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Total", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Rate non-participation", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);

            $rowIndex++;
            foreach($nonParticipatingCountriesMap as $nonParticipatingCountryName => $nonParticipatingCountryData) {
                if ($nonParticipatingCountryData['not_participated'] > 0) {
                    $columnIndex = 0;
                    $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($nonParticipatingCountryName, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $columnIndex++;
                    foreach ($nonParticipationReasons as $nonParticipationReason) {
                        if (isset($nonParticipatingCountryData[$nonParticipationReason])) {
                            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($nonParticipatingCountryData[$nonParticipationReason], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        }
                        $columnIndex++;
                    }
                    $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($nonParticipatingCountryData['not_participated'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($nonParticipatingCountryData['not_participated'] / $nonParticipatingCountryData['total_participants'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->getNumberFormat()->applyFromArray(
                        array(
                            'code' => PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00
                        )
                    );
                    $rowIndex++;
                }
            }
            $rowIndex++;
            $columnIndex = 0;
        }

        $errorCodes = $db->query($errorCodesQuery, array($params['shipmentId']))->fetchAll();
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Error Codes Encountered", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Number of Occurrences", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $rowIndex++;
        $columnIndex = 0;
        foreach ($errorCodes as $errorCode) {
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($errorCode['error_code'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($errorCode['number_of_occurrences'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
            $rowIndex++;
            $columnIndex = 0;
        }

        $discordantResults = $db->query($discordantResultsQuery, array($params['shipmentId']))->fetchAll();
        $rowIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Discordant Results", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        foreach ($discordantResults as $discordantResultAggregate) {
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantResultAggregate['sample_label'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
            $columnIndex++;
        }
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Total", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("False positives", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $falsePositivesTotal = 0;
        foreach ($discordantResults as $discordantResultAggregate) {
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantResultAggregate['false_positives'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
            $falsePositivesTotal += intval($discordantResultAggregate['false_positives']);
        }
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($falsePositivesTotal, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("False negatives", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $falseNegativesTotal = 0;
        foreach ($discordantResults as $discordantResultAggregate) {
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantResultAggregate['false_negatives'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
            $falseNegativesTotal += intval($discordantResultAggregate['false_negatives']);
        }
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($falseNegativesTotal, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("False resistance", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $falseResistanceTotal = 0;
        foreach ($discordantResults as $discordantResultAggregate) {
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantResultAggregate['false_resistances'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
            $falseResistanceTotal += intval($discordantResultAggregate['false_resistances']);
        }
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($falseResistanceTotal, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);

        $discordantCountries = $db->query($discordantCountriesQuery, array($params['shipmentId']))->fetchAll();
        $rowIndex++;
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("List the countries reporting discordant results + count of discordant results", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex + 1, $rowIndex)->applyFromArray($rowHeaderStyle);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex + 2, $rowIndex)->applyFromArray($rowHeaderStyle);
        $panelStatisticsSheet->mergeCells("A" . ($rowIndex) . ":C" . ($rowIndex));
        $rowIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Country", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("# Discordant", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("% Discordant", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        foreach ($discordantCountries as $discordantCountry) {
            $rowIndex++;
            $columnIndex = 0;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantCountry['country_name'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode(intval($discordantCountry['discordant']), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode(intval($discordantCountry['discordant']) / intval($discordantCountry['total_results']), ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
            $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->getNumberFormat()->applyFromArray(
                array(
                    'code' => PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00
                )
            );
        }

        $discordantParticipants = $db->query($discordantResultsParticipantsQuery, array($params['shipmentId']))->fetchAll();
        $rowIndex++;
        $rowIndex++;
        $columnIndex = 0;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("List the participants reporting discordant results", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex + 1, $rowIndex)->applyFromArray($rowHeaderStyle);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex + 2, $rowIndex)->applyFromArray($rowHeaderStyle);
        $panelStatisticsSheet->mergeCells("A" . ($rowIndex) . ":H" . ($rowIndex));
        $rowIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("PT ID", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Participant", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Sample", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("MTB Detected", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Expected MTB Detected", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Rif Resistance", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Expected Rif Resistance", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        $columnIndex++;
        $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Reason for Discordance", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        $panelStatisticsSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($rowHeaderStyle);
        foreach ($discordantParticipants as $discordantParticipant) {
            $rowIndex++;
            $columnIndex = 0;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['unique_identifier'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['lab_name'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['sample_label'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['res_mtb_detected'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['ref_mtb_detected'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['res_rif_resistance'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['ref_rif_resistance'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
            $columnIndex++;
            $panelStatisticsSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($discordantParticipant['non_concordance_reason'], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
        }

        foreach (range('A', 'Z') as $columnID) {
            $panelStatisticsSheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        if (!$authNameSpace->is_ptcc_coordinator) {
            $this->getTbAllSitesResultsSheet($db, $params['shipmentId'], $excel, $sheetIndex);
            $nonConcordanceThreshold = 2;
            $expectedConcordance = 0.8;
            $mtbRifAssayName = "Xpert MTB/RIF";
            $mtbRifSubmissions = $db->query("SELECT s.shipment_id,
  s.shipment_code,
  LPAD(p.unique_identifier, 10, '0') AS sorting_unique_identifier,
  p.unique_identifier,
  c.iso_name AS country,
  CONCAT(p.lab_name, COALESCE(CONCAT(' - ', CASE WHEN p.state = '' THEN NULL ELSE p.state END), CONCAT(' - ', CASE WHEN p.city = '' THEN NULL ELSE p.city END), '')) AS participant_name,
  a.name AS assay,
  ref.sample_id,
  ref.sample_label,
  DATEDIFF(res.date_tested, s.shipment_date) AS days_between_shipment_and_test,
  res.probe_1 AS probe_d_ct,
  res.probe_2 AS probe_c_ct,
  res.probe_3 AS probe_e_ct,
  res.probe_4 AS probe_b_ct,
  res.probe_5 AS probe_spc_ct,
  res.probe_6 AS probe_a_ct,
  IFNULL(ref.mtb_rif_probe_d, 0) AS expected_probe_d_ct,
  IFNULL(ref.mtb_rif_probe_c, 0) AS expected_probe_c_ct,
  IFNULL(ref.mtb_rif_probe_e, 0) AS expected_probe_e_ct,
  IFNULL(ref.mtb_rif_probe_b, 0) AS expected_probe_b_ct,
  IFNULL(ref.mtb_rif_probe_spc, 0) AS expected_probe_spc_ct,
  IFNULL(ref.mtb_rif_probe_a, 0) AS expected_probe_a_ct,
  res.calculated_score
FROM shipment_participant_map AS spm
JOIN shipment AS s ON s.shipment_id = spm.shipment_id
JOIN response_result_tb AS res ON res.shipment_map_id = spm.map_id
JOIN reference_result_tb AS ref ON ref.shipment_id = s.shipment_id
							    AND ref.sample_id = res.sample_id
JOIN participant AS p ON p.participant_id = spm.participant_id
JOIN countries AS c ON c.id = p.country
JOIN r_tb_assay AS a ON a.id = JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, \"$.assay\"))
WHERE spm.shipment_id = ?
AND IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'
AND SUBSTRING(spm.evaluation_status, 3, 1) = '1'
AND SUBSTRING(spm.evaluation_status, 4, 1) = '1'
AND IFNULL(spm.is_excluded, 'no') = 'no'
AND a.name = ?
ORDER BY sorting_unique_identifier ASC, res.sample_id ASC;", array($params['shipmentId'], $mtbRifAssayName))
                ->fetchAll();
            if (count($mtbRifSubmissions) > 0) {
                $mtbRifStability = $db->query("SELECT stability_mtb_rif.shipment_id,
  stability_mtb_rif.shipment_code,
  stability_mtb_rif.assay,
  stability_mtb_rif.sample_label,
  stability_mtb_rif.number_of_valid_submissions,
  ROUND(stability_mtb_rif.sum_probe_d_ct / stability_mtb_rif.number_of_valid_submissions_probe_d, 2) AS mean_probe_d_ct,
  ROUND(stability_mtb_rif.sum_probe_c_ct / stability_mtb_rif.number_of_valid_submissions_probe_c, 2) AS mean_probe_c_ct,
  ROUND(stability_mtb_rif.sum_probe_e_ct / stability_mtb_rif.number_of_valid_submissions_probe_e, 2) AS mean_probe_e_ct,
  ROUND(stability_mtb_rif.sum_probe_b_ct / stability_mtb_rif.number_of_valid_submissions_probe_b, 2) AS mean_probe_b_ct,
  ROUND(stability_mtb_rif.sum_probe_spc_ct / stability_mtb_rif.number_of_valid_submissions_probe_spc, 2) AS mean_probe_spc_ct,
  ROUND(stability_mtb_rif.sum_probe_a_ct / stability_mtb_rif.number_of_valid_submissions_probe_a, 2) AS mean_probe_a_ct,
  stability_mtb_rif.expected_probe_d_ct,
  stability_mtb_rif.expected_probe_c_ct,
  stability_mtb_rif.expected_probe_e_ct,
  stability_mtb_rif.expected_probe_b_ct,
  stability_mtb_rif.expected_probe_spc_ct,
  stability_mtb_rif.expected_probe_a_ct
FROM (SELECT s.shipment_id,
        s.shipment_code,
        a.name AS assay,
        ref.sample_id,
        ref.sample_label,
        SUM(CASE WHEN (IFNULL(res.probe_1, 0) > 0 OR IFNULL(ref.mtb_rif_probe_d, 0) = 0) AND
                      (IFNULL(res.probe_2, 0) > 0 OR IFNULL(ref.mtb_rif_probe_c, 0) = 0) AND
                      (IFNULL(res.probe_3, 0) > 0 OR IFNULL(ref.mtb_rif_probe_e, 0) = 0) AND
                      (IFNULL(res.probe_4, 0) > 0 OR IFNULL(ref.mtb_rif_probe_b, 0) = 0) AND
                      (IFNULL(res.probe_5, 0) > 0 OR IFNULL(ref.mtb_rif_probe_spc, 0) = 0) AND
                      (IFNULL(res.probe_6, 0) > 0 OR IFNULL(ref.mtb_rif_probe_a, 0) = 0) THEN 1 ELSE 0 END) AS number_of_valid_submissions,
        SUM(CASE WHEN IFNULL(res.probe_1, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_d,
        SUM(CASE WHEN IFNULL(res.probe_2, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_c,
        SUM(CASE WHEN IFNULL(res.probe_3, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_e,
        SUM(CASE WHEN IFNULL(res.probe_4, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_b,
        SUM(CASE WHEN IFNULL(res.probe_5, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_spc,
        SUM(CASE WHEN IFNULL(res.probe_6, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_a,
        SUM(IFNULL(res.probe_1, 0)) AS sum_probe_d_ct,
        SUM(IFNULL(res.probe_2, 0)) AS sum_probe_c_ct,
        SUM(IFNULL(res.probe_3, 0)) AS sum_probe_e_ct,
        SUM(IFNULL(res.probe_4, 0)) AS sum_probe_b_ct,
        SUM(IFNULL(res.probe_5, 0)) AS sum_probe_spc_ct,
        SUM(IFNULL(res.probe_6, 0)) AS sum_probe_a_ct,
        IFNULL(ref.mtb_rif_probe_d, 0) AS expected_probe_d_ct,
        IFNULL(ref.mtb_rif_probe_c, 0) AS expected_probe_c_ct,
        IFNULL(ref.mtb_rif_probe_e, 0) AS expected_probe_e_ct,
        IFNULL(ref.mtb_rif_probe_b, 0) AS expected_probe_b_ct,
        IFNULL(ref.mtb_rif_probe_spc, 0) AS expected_probe_spc_ct,
        IFNULL(ref.mtb_rif_probe_a, 0) AS expected_probe_a_ct
      FROM reference_result_tb AS ref
      JOIN shipment AS s ON s.shipment_id = ref.shipment_id
      LEFT JOIN shipment_participant_map AS spm ON spm.shipment_id = s.shipment_id
                                                AND IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'
                                                AND SUBSTRING(spm.evaluation_status, 3, 1) = '1'
                                                AND SUBSTRING(spm.evaluation_status, 4, 1) = '1'
                                                AND IFNULL(spm.is_excluded, 'no') = 'no'
      LEFT JOIN r_tb_assay AS a ON a.id = JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, \"$.assay\"))
      LEFT JOIN response_result_tb AS res ON res.shipment_map_id = spm.map_id
                                          AND ref.sample_id = res.sample_id
	      								  AND IFNULL(res.calculated_score, 'pass') = 'pass'
      WHERE ref.shipment_id = ?
      AND a.name = ?
      GROUP BY ref.sample_id) AS stability_mtb_rif
ORDER BY stability_mtb_rif.sample_id;", array($params['shipmentId'], $mtbRifAssayName))
                    ->fetchAll();

                $mtbRifStabilitySheet = new PHPExcel_Worksheet($excel, "Xpert MTB RIF Stability");
                $excel->addSheet($mtbRifStabilitySheet, $sheetIndex);
                $sheetIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("MTB/RIF Panel Stability for " . $mtbRifSubmissions[0]["shipment_code"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow(0, 1)->applyFromArray($sheetHeaderStyle);
                $mtbRifStabilitySheet->getRowDimension(1)->setRowHeight(25);
                $rowIndex = 3;
                $columnIndex = 0;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Sample", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("# Valid Submissions", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe D", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe C", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe E", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe B", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe SPC", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe A", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $rowIndex++;
                foreach ($mtbRifStability as $mtbRifStabilitySample) {
                    try {
                        $columnIndex = 0;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["number_of_valid_submissions"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Mean", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["mean_probe_d_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifStabilitySample["mean_probe_d_ct"] - $mtbRifStabilitySample["expected_probe_d_ct"] > $nonConcordanceThreshold) {
                            $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["mean_probe_c_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifStabilitySample["mean_probe_c_ct"] - $mtbRifStabilitySample["expected_probe_c_ct"] > $nonConcordanceThreshold) {
                            $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["mean_probe_e_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifStabilitySample["mean_probe_e_ct"] - $mtbRifStabilitySample["expected_probe_e_ct"] > $nonConcordanceThreshold) {
                            $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["mean_probe_b_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifStabilitySample["mean_probe_b_ct"] - $mtbRifStabilitySample["expected_probe_b_ct"] > $nonConcordanceThreshold) {
                            $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["mean_probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifStabilitySample["mean_probe_spc_ct"] - $mtbRifStabilitySample["expected_probe_spc_ct"] > $nonConcordanceThreshold) {
                            $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["mean_probe_a_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifStabilitySample["mean_probe_a_ct"] - $mtbRifStabilitySample["expected_probe_a_ct"] > $nonConcordanceThreshold) {
                            $mtbRifStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex = 2;
                        $rowIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Expected", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["expected_probe_d_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["expected_probe_c_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["expected_probe_e_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["expected_probe_b_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["expected_probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbRifStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifStabilitySample["expected_probe_a_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $rowIndex++;
                    } catch (Exception $e) {
                        error_log($e->getMessage(), 0);
                        error_log($e->getTraceAsString(), 0);
                    }
                }
                $rowIndex = 4;
                foreach ($mtbRifStability as $mtbRifStabilitySample) {
                    $mtbRifStabilitySheet->mergeCells("A" . ($rowIndex) . ":A" . ($rowIndex + 1));
                    $mtbRifStabilitySheet->mergeCells("B" . ($rowIndex) . ":B" . ($rowIndex + 1));
                    $rowIndex++;
                    $rowIndex++;
                }
                $rowIndex++;
                $mtbRifStabilitySheet->getCellByColumnAndRow(3, $rowIndex)->setValueExplicit(html_entity_decode("Values highlighted in red indicate mean Ct values which are above ".$nonConcordanceThreshold." cycles from the expected value", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifStabilitySheet->getStyleByColumnAndRow(3, $rowIndex)->applyFromArray($nonConcordanceStyle);
                $mtbRifStabilitySheet->mergeCells("D" . ($rowIndex) . ":I" . ($rowIndex));

                $mtbRifStabilitySheet->getDefaultRowDimension()->setRowHeight(15);
                foreach (range('A', 'Z') as $columnID) {
                    $mtbRifStabilitySheet->getColumnDimension($columnID)->setAutoSize(true);
                }

                $mtbRifConcordanceSheet = new PHPExcel_Worksheet($excel, "Xpert MTB RIF Concordance");
                $excel->addSheet($mtbRifConcordanceSheet, $sheetIndex);
                $sheetIndex++;

                $mtbRifConcordanceSheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("MTB/RIF Panel Concordance for " . $mtbRifSubmissions[0]["shipment_code"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow(0, 1)->applyFromArray($sheetHeaderStyle);
                $mtbRifConcordanceSheet->getRowDimension(1)->setRowHeight(25);
                $rowIndex = 3;
                $columnIndex = 0;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Expected Values", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $mtbRifConcordanceSheet->mergeCells("A" . ($rowIndex) . ":C" . ($rowIndex));
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Concordance", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe D", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe C", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe E", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe B", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe SPC", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe A", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);

                $mtbRifConcordance = array();
                foreach ($mtbRifSubmissions as $mtbRifSubmission) {
                    if (!isset($mtbRifConcordance[$mtbRifSubmission['sample_label']])) {
                        $mtbRifConcordance[$mtbRifSubmission['sample_label']] = array(
                            "withinRange" => 0,
                            "outsideOfRange" => 0,
                            "totalValidSubmissions" => 0,
                        );
                    }
                    if ($mtbRifSubmission["calculated_score"] == 'pass' &&
                        ($mtbRifSubmission["probe_d_ct"] > 0 ||
                            $mtbRifSubmission["probe_c_ct"] > 0 ||
                            $mtbRifSubmission["probe_e_ct"] > 0 ||
                            $mtbRifSubmission["probe_b_ct"] > 0 ||
                            $mtbRifSubmission["probe_spc_ct"] > 0 ||
                            $mtbRifSubmission["probe_a_ct"] > 0)
                    ) {
                        $mtbRifConcordance[$mtbRifSubmission['sample_label']]["totalValidSubmissions"]++;
                        if (
                            $mtbRifSubmission["probe_d_ct"] - $mtbRifSubmission["expected_probe_d_ct"] > $nonConcordanceThreshold ||
                            $mtbRifSubmission["probe_c_ct"] - $mtbRifSubmission["expected_probe_c_ct"] > $nonConcordanceThreshold ||
                            $mtbRifSubmission["probe_e_ct"] - $mtbRifSubmission["expected_probe_e_ct"] > $nonConcordanceThreshold ||
                            $mtbRifSubmission["probe_b_ct"] - $mtbRifSubmission["expected_probe_b_ct"] > $nonConcordanceThreshold ||
                            $mtbRifSubmission["probe_spc_ct"] - $mtbRifSubmission["expected_probe_spc_ct"] > $nonConcordanceThreshold ||
                            $mtbRifSubmission["probe_a_ct"] - $mtbRifSubmission["expected_probe_a_ct"] > $nonConcordanceThreshold
                        ) {
                            $mtbRifConcordance[$mtbRifSubmission['sample_label']]["outsideOfRange"]++;
                        } else {
                            $mtbRifConcordance[$mtbRifSubmission['sample_label']]["withinRange"]++;
                        }
                    }
                }

                $rowIndex = 4;
                $columnIndex = 0;
                $currentParticipantId = $mtbRifSubmissions[0]["unique_identifier"];
                $recordIndex = 0;
                while ($mtbRifSubmissions[$recordIndex]["unique_identifier"] == $currentParticipantId) {
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $mtbRifConcordanceSheet->mergeCells("A" . ($rowIndex) . ":C" . ($rowIndex));
                    $columnIndex++;
                    $concordanceTotals = $mtbRifConcordance[$mtbRifSubmissions[$recordIndex]["sample_label"]];
                    $sampleConcordance = $concordanceTotals["withinRange"] / $concordanceTotals["totalValidSubmissions"];
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($sampleConcordance, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    if ($sampleConcordance < $expectedConcordance) {
                        $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                    }
                    $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->getNumberFormat()->applyFromArray(
                        array(
                            'code' => PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00
                        )
                    );
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["expected_probe_d_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["expected_probe_c_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["expected_probe_b_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["expected_probe_e_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["expected_probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmissions[$recordIndex]["expected_probe_a_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $rowIndex++;
                    $columnIndex = 0;
                    $recordIndex++;
                }

                $rowIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow(2, $rowIndex)->setValueExplicit(html_entity_decode("Values highlighted in red indicate that the percentage of valid results, where a Ct value for a probe was ".$nonConcordanceThreshold." cycles higher than the expected value, is outside of the acceptable range of ".($expectedConcordance * 100)."%", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow(2, $rowIndex)->applyFromArray($nonConcordanceStyle);
                $mtbRifConcordanceSheet->mergeCells("C" . ($rowIndex) . ":J" . ($rowIndex ));

                $rowIndex++;
                $rowIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("PT ID", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Participant", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Country", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Sample", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("# Days Tested After Shipment", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe D", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe C", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe E", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe B", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe SPC", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe A", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $rowIndex++;

                $currentParticipantId = "";
                foreach ($mtbRifSubmissions as $mtbRifSubmission) {
                    try {
                        $columnIndex = 0;
                        if ($currentParticipantId != $mtbRifSubmission["unique_identifier"]) {
                            $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["unique_identifier"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                            $columnIndex++;
                            $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["participant_name"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                            $columnIndex++;
                            $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["country"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                            $columnIndex++;
                            $currentParticipantId = $mtbRifSubmission["unique_identifier"];
                        } else {
                            $columnIndex++;
                            $columnIndex++;
                            $columnIndex++;
                        }
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $columnIndex++;
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["days_between_shipment_and_test"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["probe_d_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifSubmission["probe_d_ct"] - $mtbRifSubmission["expected_probe_d_ct"] > $nonConcordanceThreshold) {
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["probe_c_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifSubmission["probe_c_ct"] - $mtbRifSubmission["expected_probe_c_ct"] > $nonConcordanceThreshold) {
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["probe_e_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifSubmission["probe_e_ct"] - $mtbRifSubmission["expected_probe_e_ct"] > $nonConcordanceThreshold) {
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["probe_b_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifSubmission["probe_b_ct"] - $mtbRifSubmission["expected_probe_b_ct"] > $nonConcordanceThreshold) {
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifSubmission["probe_spc_ct"] - $mtbRifSubmission["expected_probe_spc_ct"] > $nonConcordanceThreshold) {
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbRifConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbRifSubmission["probe_a_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbRifSubmission["probe_a_ct"] - $mtbRifSubmission["expected_probe_a_ct"] > $nonConcordanceThreshold) {
                            $mtbRifConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $rowIndex++;
                    } catch (Exception $e) {
                        error_log($e->getMessage(), 0);
                        error_log($e->getTraceAsString(), 0);
                    }
                }

                foreach (range('A', 'Z') as $columnID) {
                    $mtbRifConcordanceSheet->getColumnDimension($columnID)->setAutoSize(true);
                }
            }

            $mtbUltraAssayName = "Xpert MTB Ultra";
            $mtbUltraSubmissions = $db->query("SELECT s.shipment_id,
  s.shipment_code,
  LPAD(p.unique_identifier, 10, '0') AS sorting_unique_identifier,
  p.unique_identifier,
  c.iso_name AS country,
  CONCAT(p.lab_name, COALESCE(CONCAT(' - ', CASE WHEN p.state = '' THEN NULL ELSE p.state END), CONCAT(' - ', CASE WHEN p.city = '' THEN NULL ELSE p.city END), '')) AS participant_name,
  a.name AS assay,
  ref.sample_id,
  ref.sample_label,
  DATEDIFF(res.date_tested, s.shipment_date) AS days_between_shipment_and_test,
  res.probe_1 AS probe_spc_ct,
  res.probe_2 AS probe_is1081_is6110_ct,
  res.probe_3 AS probe_rpo_b1_ct,
  res.probe_4 AS probe_rpo_b2_ct,
  res.probe_5 AS probe_rpo_b3_ct,
  res.probe_6 AS probe_rpo_b4_ct,
  IFNULL(ref.ultra_probe_spc, 0) AS expected_probe_spc_ct,
  IFNULL(ref.ultra_probe_is1081_is6110, 0) AS expected_probe_is1081_is6110_ct,
  IFNULL(ref.ultra_probe_rpo_b1, 0) AS expected_probe_rpo_b1_ct,
  IFNULL(ref.ultra_probe_rpo_b2, 0) AS expected_probe_rpo_b2_ct,
  IFNULL(ref.ultra_probe_rpo_b3, 0) AS expected_probe_rpo_b3_ct,
  IFNULL(ref.ultra_probe_rpo_b4, 0) AS expected_probe_rpo_b4_ct,
  res.calculated_score
FROM shipment_participant_map AS spm
JOIN shipment AS s ON s.shipment_id = spm.shipment_id
JOIN response_result_tb AS res ON res.shipment_map_id = spm.map_id
JOIN reference_result_tb AS ref ON ref.shipment_id = s.shipment_id
							    AND ref.sample_id = res.sample_id
JOIN participant AS p ON p.participant_id = spm.participant_id
JOIN countries AS c ON c.id = p.country
JOIN r_tb_assay AS a ON a.id = JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, \"$.assay\"))
WHERE spm.shipment_id = ?
AND IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'
AND SUBSTRING(spm.evaluation_status, 3, 1) = '1'
AND SUBSTRING(spm.evaluation_status, 4, 1) = '1'
AND IFNULL(spm.is_excluded, 'no') = 'no'
AND a.name = ?
ORDER BY sorting_unique_identifier ASC, res.sample_id ASC;", array($params['shipmentId'], $mtbUltraAssayName))
                ->fetchAll();
            if (count($mtbUltraSubmissions) > 0) {
                $mtbUltraStability = $db->query("SELECT stability_mtb_ultra.shipment_id,
  stability_mtb_ultra.shipment_code,
  stability_mtb_ultra.assay,
  stability_mtb_ultra.sample_label,
  stability_mtb_ultra.number_of_valid_submissions,
  ROUND(stability_mtb_ultra.sum_probe_spc_ct / stability_mtb_ultra.number_of_valid_submissions_probe_spc, 2) AS mean_probe_spc_ct,
  ROUND(stability_mtb_ultra.sum_probe_is1081_is6110_ct / stability_mtb_ultra.number_of_valid_submissions_probe_is1081_is6110, 2) AS mean_probe_is1081_is6110_ct,
  ROUND(stability_mtb_ultra.sum_probe_rpo_b1_ct / stability_mtb_ultra.number_of_valid_submissions_probe_rpo_b1, 2) AS mean_probe_rpo_b1_ct,
  ROUND(stability_mtb_ultra.sum_probe_rpo_b2_ct / stability_mtb_ultra.number_of_valid_submissions_probe_rpo_b2, 2) AS mean_probe_rpo_b2_ct,
  ROUND(stability_mtb_ultra.sum_probe_rpo_b3_ct / stability_mtb_ultra.number_of_valid_submissions_probe_rpo_b3, 2) AS mean_probe_rpo_b3_ct,
  ROUND(stability_mtb_ultra.sum_probe_rpo_b4_ct / stability_mtb_ultra.number_of_valid_submissions_probe_rpo_b4, 2) AS mean_probe_rpo_b4_ct,
  stability_mtb_ultra.expected_probe_spc_ct,
  stability_mtb_ultra.expected_probe_is1081_is6110_ct,
  stability_mtb_ultra.expected_probe_rpo_b1_ct,
  stability_mtb_ultra.expected_probe_rpo_b2_ct,
  stability_mtb_ultra.expected_probe_rpo_b3_ct,
  stability_mtb_ultra.expected_probe_rpo_b4_ct
FROM (SELECT s.shipment_id,
        s.shipment_code,
        a.name AS assay,
        ref.sample_id,
        ref.sample_label,
        SUM(CASE WHEN (IFNULL(res.probe_1, 0) > 0 OR IFNULL(ref.ultra_probe_spc, 0) = 0) AND
                      (IFNULL(res.probe_2, 0) > 0 OR IFNULL(ref.ultra_probe_is1081_is6110, 0) = 0) AND
                      (IFNULL(res.probe_3, 0) > 0 OR IFNULL(ref.ultra_probe_rpo_b1, 0) = 0) AND
                      (IFNULL(res.probe_4, 0) > 0 OR IFNULL(ref.ultra_probe_rpo_b2, 0) = 0) AND
                      (IFNULL(res.probe_5, 0) > 0 OR IFNULL(ref.ultra_probe_rpo_b3, 0) = 0) AND
                      (IFNULL(res.probe_6, 0) > 0 OR IFNULL(ref.ultra_probe_rpo_b4, 0) = 0) THEN 1 ELSE 0 END) AS number_of_valid_submissions,
        SUM(CASE WHEN IFNULL(res.probe_1, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_spc,
        SUM(CASE WHEN IFNULL(res.probe_2, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_is1081_is6110,
        SUM(CASE WHEN IFNULL(res.probe_3, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_rpo_b1,
        SUM(CASE WHEN IFNULL(res.probe_4, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_rpo_b2,
        SUM(CASE WHEN IFNULL(res.probe_5, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_rpo_b3,
        SUM(CASE WHEN IFNULL(res.probe_6, 0) > 0 THEN 1 ELSE 1 END) AS number_of_valid_submissions_probe_rpo_b4,
        SUM(IFNULL(res.probe_1, 0)) AS sum_probe_spc_ct,
        SUM(IFNULL(res.probe_2, 0)) AS sum_probe_is1081_is6110_ct,
        SUM(IFNULL(res.probe_3, 0)) AS sum_probe_rpo_b1_ct,
        SUM(IFNULL(res.probe_4, 0)) AS sum_probe_rpo_b2_ct,
        SUM(IFNULL(res.probe_5, 0)) AS sum_probe_rpo_b3_ct,
        SUM(IFNULL(res.probe_6, 0)) AS sum_probe_rpo_b4_ct,
        IFNULL(ref.ultra_probe_spc, 0) AS expected_probe_spc_ct,
        IFNULL(ref.ultra_probe_is1081_is6110, 0) AS expected_probe_is1081_is6110_ct,
        IFNULL(ref.ultra_probe_rpo_b1, 0) AS expected_probe_rpo_b1_ct,
        IFNULL(ref.ultra_probe_rpo_b2, 0) AS expected_probe_rpo_b2_ct,
        IFNULL(ref.ultra_probe_rpo_b3, 0) AS expected_probe_rpo_b3_ct,
        IFNULL(ref.ultra_probe_rpo_b4, 0) AS expected_probe_rpo_b4_ct
      FROM reference_result_tb AS ref
      JOIN shipment AS s ON s.shipment_id = ref.shipment_id
      LEFT JOIN shipment_participant_map AS spm ON spm.shipment_id = s.shipment_id
                                                AND IFNULL(spm.is_pt_test_not_performed, 'no') = 'no'
                                                AND SUBSTRING(spm.evaluation_status, 3, 1) = '1'
                                                AND SUBSTRING(spm.evaluation_status, 4, 1) = '1'
                                                AND IFNULL(spm.is_excluded, 'no') = 'no'
      LEFT JOIN r_tb_assay AS a ON a.id = JSON_UNQUOTE(JSON_EXTRACT(spm.attributes, \"$.assay\"))
      LEFT JOIN response_result_tb AS res ON res.shipment_map_id = spm.map_id
                                          AND ref.sample_id = res.sample_id
	      								  AND IFNULL(res.calculated_score, 'pass') = 'pass'
      WHERE ref.shipment_id = ?
      AND a.name = ?
      GROUP BY ref.sample_id) AS stability_mtb_ultra
ORDER BY stability_mtb_ultra.sample_id;", array($params['shipmentId'], $mtbUltraAssayName))
                    ->fetchAll();

                $mtbUltraStabilitySheet = new PHPExcel_Worksheet($excel, "Xpert MTB Ultra Stability");
                $excel->addSheet($mtbUltraStabilitySheet, $sheetIndex);
                $sheetIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("MTB Ultra Panel Stability for " . $mtbUltraSubmissions[0]["shipment_code"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow(0, 1)->applyFromArray($sheetHeaderStyle);
                $mtbUltraStabilitySheet->getRowDimension(1)->setRowHeight(25);
                $rowIndex = 3;
                $columnIndex = 0;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Sample", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("# Valid Submissions", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe SPC", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe IS1081-IS6110", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB1", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB2", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB3", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB4", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $rowIndex++;
                foreach ($mtbUltraStability as $mtbUltraStabilitySample) {
                    try {
                        $columnIndex = 0;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["number_of_valid_submissions"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Mean", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["mean_probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraStabilitySample["mean_probe_spc_ct"] - $mtbUltraStabilitySample["expected_probe_spc_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["mean_probe_is1081_is6110_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraStabilitySample["mean_probe_is1081_is6110_ct"] - $mtbUltraStabilitySample["expected_probe_is1081_is6110_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["mean_probe_rpo_b1_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraStabilitySample["mean_probe_rpo_b1_ct"] - $mtbUltraStabilitySample["expected_probe_rpo_b1_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["mean_probe_rpo_b2_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraStabilitySample["mean_probe_rpo_b2_ct"] - $mtbUltraStabilitySample["expected_probe_rpo_b2_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["mean_probe_rpo_b3_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraStabilitySample["mean_probe_rpo_b3_ct"] - $mtbUltraStabilitySample["expected_probe_rpo_b3_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["mean_probe_rpo_b4_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraStabilitySample["mean_probe_rpo_b4_ct"] - $mtbUltraStabilitySample["expected_probe_rpo_b4_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraStabilitySheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex = 2;
                        $rowIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Expected", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["expected_probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["expected_probe_is1081_is6110_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["expected_probe_rpo_b1_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["expected_probe_rpo_b2_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["expected_probe_rpo_b3_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbUltraStabilitySheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraStabilitySample["expected_probe_rpo_b4_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $rowIndex++;
                    } catch (Exception $e) {
                        error_log($e->getMessage(), 0);
                        error_log($e->getTraceAsString(), 0);
                    }
                }
                $rowIndex = 4;
                foreach ($mtbUltraStability as $mtbUltraStabilitySample) {
                    $mtbUltraStabilitySheet->mergeCells("A" . ($rowIndex) . ":A" . ($rowIndex + 1));
                    $mtbUltraStabilitySheet->mergeCells("B" . ($rowIndex) . ":B" . ($rowIndex + 1));
                    $rowIndex++;
                    $rowIndex++;
                }
                $rowIndex++;
                $mtbUltraStabilitySheet->getCellByColumnAndRow(3, $rowIndex)->setValueExplicit(html_entity_decode("Values highlighted in red indicate mean Ct values which are above ".$nonConcordanceThreshold." cycles from the expected value", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraStabilitySheet->getStyleByColumnAndRow(3, $rowIndex)->applyFromArray($nonConcordanceStyle);
                $mtbUltraStabilitySheet->mergeCells("D" . ($rowIndex) . ":I" . ($rowIndex));

                $mtbUltraStabilitySheet->getDefaultRowDimension()->setRowHeight(15);
                foreach (range('A', 'Z') as $columnID) {
                    $mtbUltraStabilitySheet->getColumnDimension($columnID)->setAutoSize(true);
                }

                $mtbUltraConcordanceSheet = new PHPExcel_Worksheet($excel, "Xpert MTB Ultra Concordance");
                $excel->addSheet($mtbUltraConcordanceSheet, $sheetIndex);
                $sheetIndex++;

                $mtbUltraConcordanceSheet->getCellByColumnAndRow(0, 1)->setValueExplicit(html_entity_decode("MTB Ultra Panel Concordance for " . $mtbUltraSubmissions[0]["shipment_code"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow(0, 1)->applyFromArray($sheetHeaderStyle);
                $mtbUltraConcordanceSheet->getRowDimension(1)->setRowHeight(25);
                $rowIndex = 3;
                $columnIndex = 0;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Expected Values", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $mtbUltraConcordanceSheet->mergeCells("A" . ($rowIndex) . ":C" . ($rowIndex));
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Concordance", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe SPC", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe IS1081-IS6110", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB1", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB2", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB3", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB3", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);

                $mtbUltraConcordance = array();
                foreach ($mtbUltraSubmissions as $mtbUltraSubmission) {
                    if (!isset($mtbUltraConcordance[$mtbUltraSubmission['sample_label']])) {
                        $mtbUltraConcordance[$mtbUltraSubmission['sample_label']] = array(
                            "withinRange" => 0,
                            "outsideOfRange" => 0,
                            "totalValidSubmissions" => 0,
                        );
                    }
                    if ($mtbUltraSubmission["calculated_score"] == 'pass' &&
                        ($mtbUltraSubmission["probe_spc_ct"] > 0 ||
                            $mtbUltraSubmission["probe_is1081_is6110_ct"] > 0 ||
                            $mtbUltraSubmission["probe_rpo_b1_ct"] > 0 ||
                            $mtbUltraSubmission["probe_rpo_b2_ct"] > 0 ||
                            $mtbUltraSubmission["probe_rpo_b3_ct"] > 0 ||
                            $mtbUltraSubmission["probe_rpo_b4_ct"] > 0)
                    ) {
                        $mtbUltraConcordance[$mtbUltraSubmission['sample_label']]["totalValidSubmissions"]++;
                        if (
                            $mtbUltraSubmission["probe_spc_ct"] - $mtbUltraSubmission["expected_probe_spc_ct"] > $nonConcordanceThreshold ||
                            $mtbUltraSubmission["probe_is1081_is6110_ct"] - $mtbUltraSubmission["expected_probe_is1081_is6110_ct"] > $nonConcordanceThreshold ||
                            $mtbUltraSubmission["probe_rpo_b1_ct"] - $mtbUltraSubmission["expected_probe_rpo_b1_ct"] > $nonConcordanceThreshold ||
                            $mtbUltraSubmission["probe_rpo_b2_ct"] - $mtbUltraSubmission["expected_probe_rpo_b2_ct"] > $nonConcordanceThreshold ||
                            $mtbUltraSubmission["probe_rpo_b3_ct"] - $mtbUltraSubmission["expected_probe_rpo_b3_ct"] > $nonConcordanceThreshold ||
                            $mtbUltraSubmission["probe_rpo_b4_ct"] - $mtbUltraSubmission["expected_probe_rpo_b4_ct"] > $nonConcordanceThreshold
                        ) {
                            $mtbUltraConcordance[$mtbUltraSubmission['sample_label']]["outsideOfRange"]++;
                        } else {
                            $mtbUltraConcordance[$mtbUltraSubmission['sample_label']]["withinRange"]++;
                        }
                    }
                }

                $rowIndex = 4;
                $columnIndex = 0;
                $currentParticipantId = $mtbUltraSubmissions[0]["unique_identifier"];
                $recordIndex = 0;
                while ($mtbUltraSubmissions[$recordIndex]["unique_identifier"] == $currentParticipantId) {
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                    $mtbUltraConcordanceSheet->mergeCells("A" . ($rowIndex) . ":C" . ($rowIndex));
                    $columnIndex++;
                    $concordanceTotals = $mtbUltraConcordance[$mtbUltraSubmissions[$recordIndex]["sample_label"]];
                    $sampleConcordance = $concordanceTotals["withinRange"] / $concordanceTotals["totalValidSubmissions"];
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($sampleConcordance, ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    if ($sampleConcordance < $expectedConcordance) {
                        $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                    }
                    $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->getNumberFormat()->applyFromArray(
                        array(
                            'code' => PHPExcel_Style_NumberFormat::FORMAT_PERCENTAGE_00
                        )
                    );
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["expected_probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["expected_probe_is1081_is6110_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["expected_probe_rpo_b1_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["expected_probe_rpo_b2_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["expected_probe_rpo_b3_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $columnIndex++;
                    $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmissions[$recordIndex]["expected_probe_rpo_b4_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    $rowIndex++;
                    $columnIndex = 0;
                    $recordIndex++;
                }

                $rowIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow(2, $rowIndex)->setValueExplicit(html_entity_decode("Values highlighted in red indicate that the percentage of valid results, where a Ct value for a probe was ".$nonConcordanceThreshold." cycles higher than the expected value, is outside of the acceptable range of ".($expectedConcordance * 100)."%", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow(2, $rowIndex)->applyFromArray($nonConcordanceStyle);
                $mtbUltraConcordanceSheet->mergeCells("C" . ($rowIndex) . ":J" . ($rowIndex));

                $rowIndex++;
                $rowIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("PT ID", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Participant", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Country", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Sample", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("# Days Tested After Shipment", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe SPC", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe IS1081-IS6110", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB1", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB2", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB3", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $columnIndex++;
                $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode("Ct for Probe rpoB4", ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($columnHeaderStyle);
                $rowIndex++;

                $currentParticipantId = "";
                foreach ($mtbUltraSubmissions as $mtbUltraSubmission) {
                    try {
                        $columnIndex = 0;
                        if ($currentParticipantId != $mtbUltraSubmission["unique_identifier"]) {
                            $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["unique_identifier"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                            $columnIndex++;
                            $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["participant_name"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                            $columnIndex++;
                            $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["country"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($sampleLabelStyle);
                            $columnIndex++;
                            $currentParticipantId = $mtbUltraSubmission["unique_identifier"];
                        } else {
                            $columnIndex++;
                            $columnIndex++;
                            $columnIndex++;
                        }
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["sample_label"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_STRING);
                        $columnIndex++;
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["days_between_shipment_and_test"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        $columnIndex++;
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["probe_spc_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraSubmission["probe_spc_ct"] - $mtbUltraSubmission["expected_probe_spc_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["probe_is1081_is6110_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraSubmission["probe_is1081_is6110_ct"] - $mtbUltraSubmission["expected_probe_is1081_is6110_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["probe_rpo_b1_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraSubmission["probe_rpo_b1_ct"] - $mtbUltraSubmission["expected_probe_rpo_b1_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["probe_rpo_b2_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraSubmission["probe_rpo_b2_ct"] - $mtbUltraSubmission["expected_probe_rpo_b2_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["probe_rpo_b3_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraSubmission["probe_rpo_b3_ct"] - $mtbUltraSubmission["expected_probe_rpo_b3_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $columnIndex++;
                        $mtbUltraConcordanceSheet->getCellByColumnAndRow($columnIndex, $rowIndex)->setValueExplicit(html_entity_decode($mtbUltraSubmission["probe_rpo_b4_ct"], ENT_QUOTES, 'UTF-8'), PHPExcel_Cell_DataType::TYPE_NUMERIC);
                        if ($mtbUltraSubmission["probe_rpo_b4_ct"] - $mtbUltraSubmission["expected_probe_rpo_b4_ct"] > $nonConcordanceThreshold) {
                            $mtbUltraConcordanceSheet->getStyleByColumnAndRow($columnIndex, $rowIndex)->applyFromArray($nonConcordanceStyle);
                        }
                        $rowIndex++;
                    } catch (Exception $e) {
                        error_log($e->getMessage(), 0);
                        error_log($e->getTraceAsString(), 0);
                    }
                }

                foreach (range('A', 'Z') as $columnID) {
                    $mtbUltraConcordanceSheet->getColumnDimension($columnID)->setAutoSize(true);
                }
            }
        }

        $excel->setActiveSheetIndex(0);
        $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel5');
        if (!file_exists(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports")) {
            mkdir(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports", 0777, true);
        }
        $fileSafeShipmentCode = str_replace( ' ', '-', str_replace(array_merge(
            array_map('chr', range(0, 31)),
            array('<', '>', ':', '"', '/', '\\', '|', '?', '*')
        ), '', $shipmentResult['shipment_code']));
        $filename = $fileSafeShipmentCode . '-xmpep-indicators' . '.xls';
        $writer->save(UPLOAD_PATH  . DIRECTORY_SEPARATOR . "generated-reports" . DIRECTORY_SEPARATOR . $filename);

        return array(
            "report-name" => $filename
        );
    }
}

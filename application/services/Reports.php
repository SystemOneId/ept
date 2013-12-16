<?php

class Application_Service_Reports {
	
	public function getAllShipments($parameters)
    {

        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array('distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')", 's.shipment_code' ,'sl.scheme_name' ,'s.number_of_samples' ,new Zend_Db_Expr('count("participant_id")'),new Zend_Db_Expr("SUM(shipment_test_date <> '')"),new Zend_Db_Expr("(SUM(shipment_test_date <> '')/count('participant_id'))*100"),new Zend_Db_Expr("SUM(final_result = 1)"),'s.status');
        $searchColumns = array('distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')", 's.shipment_code' ,'sl.scheme_name' ,'s.number_of_samples','s.status');
        $havingColumns = array(new Zend_Db_Expr('count("participant_id")'),new Zend_Db_Expr("SUM(shipment_test_date <> '')"),new Zend_Db_Expr("(SUM(shipment_test_date <> '')/count('participant_id'))*100"),new Zend_Db_Expr("SUM(final_result = 1)"));
        $orderColumns = array('distribution_code','distribution_date', 's.shipment_code' ,'sl.scheme_name' ,'s.number_of_samples' ,new Zend_Db_Expr('count("participant_id")'),new Zend_Db_Expr("SUM(shipment_test_date <> '')"),new Zend_Db_Expr("(SUM(shipment_test_date <> '')/count('participant_id'))*100"),new Zend_Db_Expr("SUM(final_result = 1)"),'s.status');

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
                    if($searchColumns[$i] == "" || $searchColumns[$i] == null){
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

		
		

        //
        //
        //$sHaving = "";
        //if (isset($parameters['sSearch']) && $parameters['sSearch'] != "") {
        //    $searchArray = explode(" ", $parameters['sSearch']);
        //    $sHavingSub = "";
        //    foreach ($searchArray as $search) {
        //        if ($sHavingSub == "") {
        //            $sHavingSub .= "(";
        //        } else {
        //            $sHavingSub .= " AND (";
        //        }
        //        $colSize = count($havingColumns);
        //
        //        for ($i = 0; $i < $colSize; $i++) {
        //            if($havingColumns[$i] == "" || $havingColumns[$i] == null){
        //                continue;
        //            }
        //            if ($i < $colSize - 1) {
        //                $sHavingSub .= $havingColumns[$i] . " LIKE '%" . ($search) . "%' OR ";
        //            } else {
        //                $sHavingSub .= $havingColumns[$i] . " LIKE '%" . ($search) . "%' ";
        //            }
        //        }
        //        $sHavingSub .= ")";
        //    }
        //    $sHaving .= $sHavingSub;
        //}			

        /*
         * SQL queries
         * Get data to display
         */
		
		$dbAdapter = Zend_Db_Table_Abstract::getDefaultAdapter();

        $sQuery = $dbAdapter->select()->from(array('s'=>'shipment'))
							->join(array('sl'=>'scheme_list'),'s.scheme_type=sl.scheme_id')
							->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id')
							->joinLeft(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id',array('participant_count' => new Zend_Db_Expr('count("participant_id")'), 'reported_count'=> new Zend_Db_Expr("SUM(shipment_test_date <> '')"),'reported_percentage' => new Zend_Db_Expr("ROUND((SUM(shipment_test_date <> '')/count('participant_id'))*100,2)"), 'number_passed'=> new Zend_Db_Expr("SUM(final_result = 1)")))
							->joinLeft(array('p'=>'participant'),'p.participant_id=sp.participant_id')
							->joinLeft(array('pmm'=>'participant_manager_map'),'pmm.participant_id=p.participant_id')
							->joinLeft(array('rr'=>'r_results'),'sp.final_result=rr.result_id')
							->group('s.shipment_id');
							

		if(isset($parameters['scheme']) && $parameters['scheme'] !=""){
			$sQuery = $sQuery->where("s.scheme_type = ?",$parameters['scheme']);
		}
		
		if(isset($parameters['startDate']) && $parameters['startDate'] !="" && isset($parameters['endDate']) && $parameters['endDate'] !=""){
			$sQuery = $sQuery->where("s.shipment_date >= ?",$parameters['startDate']);
			$sQuery = $sQuery->where("s.shipment_date <= ?",$parameters['endDate']);
		}
		
		if(isset($parameters['dataManager']) && $parameters['dataManager'] !=""){
			$sQuery = $sQuery->where("pmm.dm_id = ?",$parameters['dataManager']);
		}
		

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
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

        //die($sQuery);

        $rResult = $dbAdapter->fetchAll($sQuery);


        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $dbAdapter->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $dbAdapter->select()->from(array('s'=>'shipment'), new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));
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
		//$aColumns = array('distribution_code', "DATE_FORMAT(distribution_date,'%d-%b-%Y')",
		//'s.shipment_code' ,'sl.scheme_name' ,'s.number_of_samples' ,
		//'sp.participant_count','sp.reported_count','sp.number_passed','s.status');
        foreach ($rResult as $aRow) {
            
            $shipmentResults = $shipmentDb->getPendingShipmentsByDistribution($aRow['distribution_id']);
            
            $row = array();
            $row[] = $aRow['distribution_code'];
            $row[] = Pt_Commons_General::humanDateFormat($aRow['distribution_date']);
			$row[] = $aRow['shipment_code'];
			$row[] = $aRow['scheme_name'];
			$row[] = $aRow['number_of_samples'];
            $row[] = $aRow['participant_count'];
            $row[] = ($aRow['reported_count'] != "") ? $aRow['reported_count'] : 0;
            $row[] = ($aRow['reported_percentage'] != "") ? $aRow['reported_percentage'] : "0";
            $row[] = $aRow['number_passed'];
            $row[] = ucwords($aRow['status']);
            
            

            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }
	
	public function getShipments($distributionId){
	    $db = Zend_Db_Table_Abstract::getDefaultAdapter();
		$sql = $db->select()->from(array('s'=>'shipment'))
							->join(array('d'=>'distributions'),'d.distribution_id=s.distribution_id')
							->join(array('sp'=>'shipment_participant_map'),'sp.shipment_id=s.shipment_id',array('participant_count' => new Zend_Db_Expr('count("participant_id")'), 'reported_count'=> new Zend_Db_Expr("SUM(shipment_test_date <> '')"), 'number_passed'=> new Zend_Db_Expr("SUM(final_result = 1)")))
							->join(array('rr'=>'r_results'),'sp.final_result=rr.result_id')
							->where("s.distribution_id = ?",$distributionId)
							->group('s.shipment_id');
			  
	    $result = $db->fetchAll($sql);
		$session = new Zend_Session_Namespace('tempSpace');
		$session->shipments = $result;
		return $result;
	}	
}


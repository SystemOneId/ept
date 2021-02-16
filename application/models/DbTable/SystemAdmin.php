<?php

class Application_Model_DbTable_SystemAdmin extends Zend_Db_Table_Abstract {
    protected $_name = 'system_admin';
    protected $_primary = 'admin_id';

    public function getAllAdmin($parameters) {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */

        $aColumns = array('a.first_name', 'a.last_name', 'a.primary_email', 'a.phone');

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = $this->_primary;


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

        if ($sWhere == "") {
            $sWhere .= "is_ptcc_coordinator = 0";
        } else {
            $sWhere .= " AND is_ptcc_coordinator = 0";
        }
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if ($authNameSpace->is_ptcc_coordinator) {
            $sWhere .= " AND pcm.country_id IN (".implode(",",$authNameSpace->countries).")";
        }

        /*
         * SQL queries
         * Get data to display
         */

        $sQuery = $this->getAdapter()->select()->from(array('a' => $this->_name))
            ->joinLeft(array('pcm' => 'ptcc_country_map'), 'a.admin_id=pcm.admin_id', array());

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }
        $rResult = $this->getAdapter()->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $this->getAdapter()->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $this->getAdapter()->select()->from($this->_name, new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));
        $aResultTotal = $this->getAdapter()->fetchCol($sQuery);
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
            $row[] = $aRow['first_name'];
            $row[] = $aRow['last_name'];
            $row[] = $aRow['primary_email'];
            $row[] = $aRow['phone'];
            $row[] = '<a href="/admin/system-admins/edit/id/' . $aRow['admin_id'] . '" class="btn btn-warning btn-xs" style="margin-right: 2px;"><i class="icon-pencil"></i> Edit</a>';

            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function getAllPtccProfiles($parameters)
    {
        /* Array of database columns which should be read and sent back to DataTables. Use a space where
         * you want to insert a non-database field (for example a counter or static image)
         */
        $aColumns = array('pp.first_name', 'pp.last_name', 'pp.primary_email', 'pp.phone', 'co.iso_name');

        /* Indexed column (used for fast and accurate table cardinality) */
        $sIndexColumn = $this->_primary;

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
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if ($authNameSpace->is_ptcc_coordinator) {
            if ($sWhere != "") {
                $sWhere .= " AND";
            }
            $sWhere .= " pcm.country_id IN (".implode(",",$authNameSpace->countries).")";
        }

        if ($sWhere == "") {
            $sWhere .= "is_ptcc_coordinator = 1";
        } else {
            $sWhere .= " AND is_ptcc_coordinator = 1";
        }

        /*
         * SQL queries
         * Get data to display
         */
        $sQuery = $this->getAdapter()->select()
            ->from(array('pp' => $this->_name))
            ->joinLeft(array('pcm' => 'ptcc_country_map'), 'pp.admin_id=pcm.admin_id', array())
            ->joinLeft(array('co' => 'countries'), 'pcm.country_id=co.id',
                array('iso_name' => 'GROUP_CONCAT(co.iso_name SEPARATOR \',\')'))
            ->group('pp.admin_id');

        if (isset($sWhere) && $sWhere != "") {
            $sQuery = $sQuery->where($sWhere);
        }

        if (isset($sOrder) && $sOrder != "") {
            $sQuery = $sQuery->order($sOrder);
        }

        if (isset($sLimit) && isset($sOffset)) {
            $sQuery = $sQuery->limit($sLimit, $sOffset);
        }
        $rResult = $this->getAdapter()->fetchAll($sQuery);

        /* Data set length after filtering */
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_COUNT);
        $sQuery = $sQuery->reset(Zend_Db_Select::LIMIT_OFFSET);
        $aResultFilterTotal = $this->getAdapter()->fetchAll($sQuery);
        $iFilteredTotal = count($aResultFilterTotal);

        /* Total data set length */
        $sQuery = $this->getAdapter()->select()->from($this->_name, new Zend_Db_Expr("COUNT('" . $sIndexColumn . "')"));
        $aResultTotal = $this->getAdapter()->fetchCol($sQuery);
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
            $row[] = $aRow['first_name'];
            $row[] = $aRow['last_name'];
            $row[] = $aRow['primary_email'];
            $row[] = $aRow['phone'];
            $row[] = $aRow['iso_name'];
            $row[] = '<a href="/admin/ptcc-profiles/edit/id/' . $aRow['admin_id'] . '" class="btn btn-warning btn-xs" style="margin-right: 2px;"><i class="icon-pencil"></i> Edit</a>';
            $output['aaData'][] = $row;
        }

        echo json_encode($output);
    }

    public function addSystemAdmin ($params) {
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $data = array(
            'first_name'=>$params['firstName'],
            'last_name'=>$params['lastName'],
            'primary_email'=>$params['primaryEmail'],
            'secondary_email'=>$params['secondaryEmail'],
            'password'=>$params['password'],
            'phone'=>$params['phone'],
            'status'=>$params['status'],
            'is_ptcc_coordinator'=>0,
            'force_password_reset'=>1,
            'created_by' => $authNameSpace->admin_id,
            'created_on' => new Zend_Db_Expr('now()')
        );
        return $this->insert($data);
    }

  
    public function getSystemAdminDetails ($adminId) {
        $dbAdapter =$this->getAdapter();
        $sql = $dbAdapter->select()->from(array('a' => $this->_name));
        $authNameSpace = new Zend_Session_Namespace('administrators');
        if ($authNameSpace->is_ptcc_coordinator) {
            $sql = $sql->joinLeft(array('pcm' => 'ptcc_country_map'), 'a.admin_id=pcm.admin_id', array())
            ->where("pcm.country_id IN (".implode(",",$authNameSpace->countries).")");
        }
        $sql = $sql->where("a.admin_id = ? ",$adminId);
        return $dbAdapter->fetchRow($sql);
    }

    public function updateSystemAdmin ($params) {
	    $authNameSpace = new Zend_Session_Namespace('administrators');
        $data = array(
            'first_name'=>$params['firstName'],
            'last_name'=>$params['lastName'],
            'primary_email'=>$params['primaryEmail'],
            'secondary_email'=>$params['secondaryEmail'],
            'phone'=>$params['phone'],
            'status'=>$params['status'],
		    'updated_by' => $authNameSpace->admin_id,
            'updated_on' => new Zend_Db_Expr('now()')
        );
        if (isset($params['password']) && $params['password'] !="") {
            $data['password']= $params['password'];
            $data['force_password_reset']= 1;
        }
        return $this->update($data,"admin_id=".$params['adminId']);
    }

    public function upsertPtccProfile($params) {
        $authNameSpace = new Zend_Session_Namespace('administrators');
        $dbAdapter = $this->getAdapter();
        $dbAdapter->beginTransaction();
        $adminId = 0;
        try {
            if (isset($params['ptccProfileId']) && intval($params['ptccProfileId']) > 0) {
                $adminId = $params['ptccProfileId'];
                $data = array(
                    'first_name' => $params['firstName'],
                    'last_name' => $params['lastName'],
                    'primary_email' => $params['primaryEmail'],
                    'secondary_email' => $params['secondaryEmail'],
                    'phone' => $params['phone'],
                    'updated_by' => $authNameSpace->admin_id,
                    'updated_on' => new Zend_Db_Expr('now()')
                );
                if (isset($params['status'])) {
                    $data['status'] = $params['status'];
                }
                if (isset($params['password']) && $params['password'] !="") {
                    $data['password'] = $params['password'];
                    $data['force_password_reset']= 1;
                }
                $this->update($data,'admin_id = '.$adminId);
                if (isset($params['countryId'])) {
                    $where = $dbAdapter->quoteInto("admin_id = ?", $adminId);
                    $dbAdapter->delete('ptcc_country_map', $where);
                    foreach ($params['countryId'] as $countryId) {
                        $dbAdapter->insert('ptcc_country_map', array(
                            'admin_id' => $adminId,
                            'country_id' => $countryId,
                            'show_details_on_report' => $params['showDetailsOnReport'] == "yes" ? "1" : "0"
                        ));
                    }
                }
            } else {
                $data = array(
                    'first_name' => $params['firstName'],
                    'last_name' => $params['lastName'],
                    'primary_email' => $params['primaryEmail'],
                    'secondary_email' => $params['secondaryEmail'],
                    'password' => $params['password'],
                    'phone' => $params['phone'],
                    'status' => $params['status'],
                    'is_ptcc_coordinator'=>1,
                    'force_password_reset' => 1,
                    'created_by' => $authNameSpace->admin_id,
                    'created_on' => new Zend_Db_Expr('now()')
                );
                $adminId = $this->insert($data);
                foreach ($params['countryId'] as $countryId) {
                    $dbAdapter->insert('ptcc_country_map', array(
                        'admin_id' => $adminId,
                        'country_id' => $countryId
                    ));
                }
            }
            $dbAdapter->commit();
        } catch (Exception $e) {
            $dbAdapter->rollBack();
            error_log($e->getMessage());
            error_log($e->getTraceAsString());
        }
        return $adminId;
    }

    public function getPtccProfileDetails($adminId = null) {
        $ptccProfile = null;
        $dbAdapter = $this->getAdapter();
        if ($adminId != null) {
            $sql = $dbAdapter->select()
                ->from(array('a' => $this->_name))
                ->joinLeft(array('pcm' => 'ptcc_country_map'), 'a.admin_id=pcm.admin_id', array('show_details_on_report'));
            $authNameSpace = new Zend_Session_Namespace('administrators');
            if ($authNameSpace->is_ptcc_coordinator) {
                $sql = $sql->where("pcm.country_id IN (".implode(",",$authNameSpace->countries).")");
            }
            $sql = $sql->where("a.admin_id = ? ", $adminId);
            $ptccProfile = $dbAdapter->fetchRow($sql);
            $ptccProfile['countries'] = $dbAdapter->fetchAll($dbAdapter->select()
                ->from(array('pcm' => 'ptcc_country_map'), array())
                ->join(array('c' => 'countries'), 'c.id=pcm.country_id', array(
                    'id' => 'c.id',
                    'name' => 'c.iso_name'
                ))
                ->where("pcm.admin_id = ?", $adminId)
                ->order('name'));
            $ptccProfile['other_countries'] = $dbAdapter->fetchAll($dbAdapter->select()
                ->from(array('c' => 'countries'), array(
                    'id' => 'c.id',
                    'name' => 'c.iso_name'
                ))
                ->joinLeft(array('pcm' => 'ptcc_country_map'), 'c.id=pcm.country_id', array())
                ->where("pcm.admin_id is null"));
        } else {
            $ptccProfile = array(
                'first_name' => null,
                'last_name' => null,
                'primary_email' => null,
                'password' => null,
                'secondary_email' => null,
                'phone' => null,
                'status' => null,
                'show_details_on_report' => "1",
                'countries' => array(),
                'other_countries' => $dbAdapter->fetchAll($dbAdapter->select()
                    ->from(array('c' => 'countries'), array(
                        'id' => 'c.id',
                        'name' => 'c.iso_name'
                    ))
                    ->order('name'))
            );
        }
        return $ptccProfile;
    }
}

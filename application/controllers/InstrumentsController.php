<?php

class InstrumentsController extends Zend_Controller_Action
{
    public function init()
    {
        /* Initialize action controller here */
    }

    public function indexAction()
    {
        if ($this->getRequest()->isPost()) {
            $data = $this->getRequest()->getPost();
            $instrumentIds = $data['instrumentId'];
            $db = Zend_Db_Table_Abstract::getDefaultAdapter();
            $db->beginTransaction();
            try {
                $instrumentsDb = new Application_Model_DbTable_Instruments();
                $existingInstruments = $instrumentsDb->getInstruments(null, false);
                foreach ($existingInstruments as $instrument_id => $instrumentDetails) {
                    if (!in_array($instrument_id, $instrumentIds)) {
                        $instrumentsDb->deleteInstrument($instrument_id);
                    }
                } 
                foreach ($instrumentIds as $key => $instrumentId) {
                    $instrumentDetails = array(
                        'instrument_id' => $instrumentId,
                        'instrument_serial' => $data['instrumentSerial'][$key],
                        'instrument_installed_on' => $data['dateInstalled'][$key],
                        'instrument_last_calibrated_on' => $data['dateLastCalibrated'][$key]
                    );
                    $instrumentsDb->upsertInstrument($data['participant'][$key], $instrumentDetails);
                }
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                error_log($e->getMessage());
                error_log($e->getTraceAsString());
            }
            $this->_redirect("/participant/dashboard");
        } else {
            $instrumentsDb = new Application_Model_DbTable_Instruments();
            $this->view->instruments = $instrumentsDb->getInstruments(null, true);
            $participantService = new Application_Service_Participants();
            $this->view->participants = $participantService->getUsersParticipants();;
        }
    }
}




<?php

class Api_ResultsController extends Zend_Controller_Action {
    public function init() {
        $ajaxContext = $this->_helper->getHelper('AjaxContext');
        $ajaxContext->addActionContext('index', 'html')
            ->initContext();
        $this->_helper->layout()->setLayout('api');
    }

    public function indexAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else {
            $this->getResponse()->setHeader("Content-Type", "application/json");
            $shipmentService = new Application_Service_Shipments();
            $shipmentService->getShipmentCurrent(array_merge(
                array(
                    "currentType" => "active",
                    "forMobileApp" => true
                ), $this->getRequest()->getParams()));
        }
    }

    public function resultAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else {
            $sID = intval($this->getRequest()->getParam('sid'));
            $pID = intval($this->getRequest()->getParam('pid'));
            $schemeService = new Application_Service_Schemes();
            $shipment = $schemeService->getShipmentData($sID, $pID);
            if (!$shipment) {
                $this->getResponse()->setBody('NOT FOUND');
                $this->getResponse()->setHttpResponseCode(404);
            } else {
                $shipment['attributes'] = json_decode($shipment['attributes'],true);
                $assays = $schemeService->getTbAssayReferenceMap();
                $unableToSubmitReasons = $schemeService->getNotTestedReasonsReferenceMap('tb');
                $sampleIds = $schemeService->getTbSampleIds($sID, $pID);
                $attributes = $shipment["attributes"];
                if (isset($attributes)) {
                    if (!isset($attributes["cartridge_lot_no"]) && !isset($attributes["mtb_rif_kit_lot_no"])) {
                        $attributes["cartridge_lot_no"] = "";
                        $attributes["mtb_rif_kit_lot_no"] = "";
                    }
                    else if (isset($attributes["mtb_rif_kit_lot_no"]) && !isset($attributes["cartridge_lot_no"])) {
                        $attributes["cartridge_lot_no"] = $attributes["mtb_rif_kit_lot_no"];
                    }
                    else if (!isset($attributes["mtb_rif_kit_lot_no"]) && isset($attributes["cartridge_lot_no"])) {
                        $attributes["mtb_rif_kit_lot_no"] = $attributes["cartridge_lot_no"];
                    }
                    if (!isset($attributes["expiry_date"])) {
                        $attributes["expiry_date"] = "";
                    }
                    if (!isset($attributes["assay"])) {
                        $attributes["assay"] = "";
                    }
                    if (!isset($attributes["count_tests_conducted_over_month"])) {
                        $attributes["count_tests_conducted_over_month"] = "";
                    }
                    if (!isset($attributes["count_errors_encountered_over_month"])) {
                        $attributes["count_errors_encountered_over_month"] = "";
                    }
                    if (!isset($attributes["error_codes_encountered_over_month"])) {
                        $attributes["error_codes_encountered_over_month"] = "";
                    }
                } else {
                    $attributes = array(
                        "cartridge_lot_no" => "",
                        "mtb_rif_kit_lot_no" => "",
                        "expiry_date" => "",
                        "assay" => "",
                        "count_tests_conducted_over_month" => "",
                        "count_errors_encountered_over_month" => "",
                        "error_codes_encountered_over_month" => ""
                    );
                }
                $response = array(
                    'cartridgeLotNo' => $attributes["cartridge_lot_no"],
                    'mtbRifKitLotNo' => $attributes["cartridge_lot_no"],
                    'expiryDate' => Pt_Commons_General::dbDateToString($attributes["expiry_date"]),
                    'testReceiptDate' => Pt_Commons_General::dbDateToString($shipment['shipment_test_report_date']),
                    'assay' => $attributes["assay"],
                    'countTestsConductedOverMonth' => $attributes["count_tests_conducted_over_month"],
                    'countErrorsEncounteredOverMonth' => $attributes["count_errors_encountered_over_month"],
                    'errorCodesEncounteredOverMonth' => $attributes["error_codes_encountered_over_month"],
                    'qcDone' => $shipment['qc_done'],
                    'qcDate' => Pt_Commons_General::dbDateToString($shipment['qc_date']),
                    'qcDoneBy' => $shipment['qc_done_by'],
                    'dateReceived' => Pt_Commons_General::dbDateToString($shipment['shipment_receipt_date']),
                    'smid' => $shipment['map_id'],
                    'assays' => $assays,
                    'unableToSubmit' => $shipment['is_pt_test_not_performed'],
                    'unableToSubmitReason' => (isset($shipment['pt_test_not_performed_comments']) && trim($shipment['pt_test_not_performed_comments']) != "") ? "other" : $shipment['not_tested_reason'],
                    'unableToSubmitComment' => $shipment['pt_test_not_performed_comments'],
                    'unableToSubmitReasons' => $unableToSubmitReasons,

                    'sampleIds' => $sampleIds,
                    'samples' => array(),

                    'supervisorApproval' => $shipment['supervisor_approval'],
                    'participantSupervisor' => $shipment['participant_supervisor'],
                    'userComments' => $shipment['user_comment'],
                    'deadlineDate' => Pt_Commons_General::dbDateToString($shipment['lastdate_response'])
                );

                $instrumentDb = new Application_Model_DbTable_Instruments();
                $instruments = $instrumentDb->getInstrumentsReferenceMap($pID, false);
                foreach ($sampleIds as $sampleId) {
                    $sample = $schemeService->getTbSample($sID,$pID,$sampleId);
                    $response['samples'][(string)$sampleId] = $responseSample = array(
                        'sampleId' => $sample['sample_id'],
                        'sampleLabel' => $sample['sample_label'],
                        'instrumentSerial' => $sample['res_instrument_serial'],
                        'instrumentInstalledOn' => Pt_Commons_General::dbDateToString($sample['res_instrument_installed_on']),
                        'instrumentLastCalibratedOn' => Pt_Commons_General::dbDateToString($sample['res_instrument_last_calibrated_on']),
                        'dateTested' => Pt_Commons_General::dbDateToString($sample['res_date_tested']),
                        'mtbDetected' => $sample['res_mtb_detected'],
                        'rifResistance' => $sample['res_rif_resistance'],
                        'probe1' => $sample['res_probe_1'],
                        'probe2' => $sample['res_probe_2'],
                        'probe3' => $sample['res_probe_3'],
                        'probe4' => $sample['res_probe_4'],
                        'probe5' => $sample['res_probe_5'],
                        'probe6' => $sample['res_probe_6'],
                        'probeD' => $sample['res_probe_1'],
                        'probeC' => $sample['res_probe_2'],
                        'probeE' => $sample['res_probe_3'],
                        'probeB' => $sample['res_probe_4'],
                        'spc' => $sample['res_probe_5'],
                        'probeA' => $sample['res_probe_6'],
                        'moduleName' => $sample['res_module_name'],
                        'instrumentUser' => $sample['res_instrument_user'],
                        'cartridgeExpirationDate' => Pt_Commons_General::dbDateToString($sample['res_cartridge_expiration_date']),
                        'reagentLotId' => $sample['res_reagent_lot_id'],
                        'errorCode' => $sample['res_error_code'],
                        'smid' => $sample['map_id'],
                        'instruments' => $instruments
                    );
                }

                $this->getResponse()->setHeader("Content-Type", "application/json");
                echo json_encode($response);
            }
        }
    }

    public function resultHeaderAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else if ($this->getRequest()->isPut()) {
            $sID = intval($this->getRequest()->getParam('sid'));
            $pID = intval($this->getRequest()->getParam('pid'));
            $params = Zend_Json::decode($this->getRequest()->getRawBody());
            $rawSubmissionService = new Application_Service_RawSubmission();
            $rawSubmissionService->addRawSubmission(array(
                "function" => "api/controllers/ResultsController/resultHeaderAction PUT",
                "body" => $params,
                "dm_id" => $authNameSpace->dm_id,
                "sID" => $sID,
                "pID" => $pID
            ));
            $params['shipmentId'] = $sID;
            $params['participantId'] = $pID;
            $shipmentService = new Application_Service_Shipments();
            $shipmentService->updateTbResultHeader($params);
            $this->getResponse()->setBody('OK');
            $this->getResponse()->setHttpResponseCode(200);
        }
    }

    public function resultItemAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else if ($this->getRequest()->isPut()) {
            $sID = intval($this->getRequest()->getParam('sid'));
            $pID = intval($this->getRequest()->getParam('pid'));
            $sampleID = intval($this->getRequest()->getParam('id'));
            $params = Zend_Json::decode($this->getRequest()->getRawBody());
            $rawSubmissionService = new Application_Service_RawSubmission();
            $rawSubmissionService->addRawSubmission(array(
                "function" => "modules/api/controllers/ResultsController/resultItemAction PUT",
                "body" => $params,
                "dm_id" => $authNameSpace->dm_id,
                "sID" => $sID,
                "pID" => $pID,
                "sampleID" => $sampleID
            ));
            $params['shipmentId'] = $sID;
            $params['participantId'] = $pID;
            $params['sampleId'] = $sampleID;
            $cartridgeExpirationDate = null;
            if (isset($params['expiryDate'])) {
                $cartridgeExpirationDate = Pt_Commons_General::dateFormat($params['expiryDate']);
            }
            if ($cartridgeExpirationDate == "" || $cartridgeExpirationDate == "0000-00-00") {
                $cartridgeExpirationDate = null;
            }
            $cartridgeLotNo = null;
            if (isset($params['cartridgeLotNo'])) {
                $cartridgeLotNo = $params['cartridgeLotNo'];
            } else if (isset($params['mtbRifKitLotNo'])) {
                $cartridgeLotNo = $params['mtbRifKitLotNo'];
            }
            $shipmentService = new Application_Service_Shipments();
            $shipmentService->updateTbResult($params, $cartridgeExpirationDate, $cartridgeLotNo);
            $this->getResponse()->setBody('OK');
            $this->getResponse()->setHttpResponseCode(200);
        }
    }

    public function resultFooterAction() {
        $authNameSpace = new Zend_Session_Namespace('datamanagers');
        if (!isset($authNameSpace->dm_id)) {
            $this->getResponse()->setHttpResponseCode(401);
            Zend_Session::namespaceUnset('datamanagers');
        } else if ($this->getRequest()->isPut()) {
            $sID = intval($this->getRequest()->getParam('sid'));
            $pID = intval($this->getRequest()->getParam('pid'));
            $params = Zend_Json::decode($this->getRequest()->getRawBody());
            $params['shipmentId'] = $sID;
            $params['participantId'] = $pID;
            $submitResponse = $this->getRequest()->getParam('submitResponse');
            $rawSubmissionService = new Application_Service_RawSubmission();
            $rawSubmissionService->addRawSubmission(array(
                "function" => "api/controllers/ResultsController/resultFooterAction PUT",
                "body" => $params,
                "dm_id" => $authNameSpace->dm_id,
                "sID" => $sID,
                "pID" => $pID,
                "submitResponse" => $submitResponse
            ));
            if(isset($submitResponse)) {
                $params['submitResponse'] = $submitResponse;
            } else {
                $params['submitResponse'] = 'no';
            }
            $shipmentService = new Application_Service_Shipments();
            if($shipmentService->updateTbResultFooter($params)) {
                $shipmentService->sendShipmentSavedEmailToParticipantsAndPTCC($pID, $sID);
            }
            $this->getResponse()->setBody('OK');
            $this->getResponse()->setHttpResponseCode(200);
        }
    }
}




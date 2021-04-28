<?php

class Application_Service_GxAlertResult {
    public function submitResultFromGxAlert($payload) {

        $shipmentCodeSampleIdPattern = "/(\d{4}-[AaBbCcDdEe])-([1-9])/";
        preg_match_all($shipmentCodeSampleIdPattern, $payload["patientId"], $shipmentCodeSampleIdMatches);
        $shipmentCodeAndSampleId = $this->TryGetShipmentCodeAndSampleIdFromValue($payload["patientId"]);
        if (!isset($shipmentCodeAndSampleId)) {
            $shipmentCodeAndSampleId = $this->TryGetShipmentCodeAndSampleIdFromValue($payload["sampleId"]);
        }
        if (isset($shipmentCodeAndSampleId)) {
            $shipmentParticipantMapDatabase = new Application_Model_DbTable_ShipmentParticipantMap();
            $shipmentParticipantMap = $shipmentParticipantMapDatabase
                ->getByShipmentCodeAndParticipantUniqueId(
                    $shipmentCodeAndSampleId["shipment_code"], $payload["ePTParticipantId"]);
            if (isset($shipmentParticipantMap) && count($shipmentParticipantMap) > 0) {
                $gxAlertResultDb = new Application_Model_DbTable_GxAlertResult();
                $fullPayload = array_merge(
                    array(
                        "gxAlertTestId" => null,
                        "patientId" => null,
                        "sampleId" => null,
                        "assayName" => null,
                        "gxAlertDeploymentId" => null,
                        "messageSentOn" => null,
                        "testStartTime" => null,
                        "testEndTime" => null,
                        "cartridgeExpirationDate" => null,
                        "cartridgeSerial" => null,
                        "moduleSerial" => null,
                        "instrumentSerial" => null,
                        "reagentLotId" => null,
                        "MTBResult" => null,
                        "RIFResult" => null,
                        "testErrorCode" => null,
                        "geneXpertHostId" => null,
                        "geneXpertSenderUser" => null,
                        "labName" => null,
                        "country" => null,
                        "state" => null,
                        "district" => null,
                        "city" => null,
                        "instrumentInstalledOn" => null,
                        "instrumentLastCalibratedOn" => null,
                        "moduleName" => null,
                        "probeD" => null,
                        "probeC" => null,
                        "probeE" => null,
                        "probeB" => null,
                        "spc" => null,
                        "probeA" => null,
                        "TestCountLast30Days" => null,
                        "ErrorCountLast30Days" => null,
                        "ErrorCodesEncounteredLast30Days" => null,
                        "ePTParticipantId" => null
                    ), $payload
                );
                return $gxAlertResultDb->saveResult($shipmentParticipantMap["map_id"], $shipmentCodeAndSampleId["sample_id"],
                    $fullPayload["ePTParticipantId"], $fullPayload["gxAlertTestId"], $fullPayload["gxAlertDeploymentId"],
                    $fullPayload["messageSentOn"], $fullPayload["patientId"], $fullPayload["sampleId"], $fullPayload["testStartTime"],
                    $fullPayload["testEndTime"], $fullPayload["MTBResult"], $fullPayload["RIFResult"], $fullPayload["testErrorCode"],
                    $fullPayload["probeD"], $fullPayload["probeC"], $fullPayload["probeE"], $fullPayload["probeB"], $fullPayload["spc"],
                    $fullPayload["probeA"], $fullPayload["assayName"], $fullPayload["reagentLotId"], $fullPayload["cartridgeExpirationDate"],
                    $fullPayload["cartridgeSerial"], $fullPayload["labName"], $fullPayload["geneXpertHostId"],
                    $fullPayload["geneXpertSenderUser"], $fullPayload["instrumentSerial"], $fullPayload["instrumentInstalledOn"],
                    $fullPayload["instrumentLastCalibratedOn"], $fullPayload["country"], $fullPayload["state"], $fullPayload["district"],
                    $fullPayload["city"], $fullPayload["moduleSerial"], $fullPayload["moduleName"], $fullPayload["TestCountLast30Days"],
                    $fullPayload["ErrorCountLast30Days"], $fullPayload["ErrorCodesEncounteredLast30Days"]);
            }
        }
        return null;
    }

    private function TryGetShipmentCodeAndSampleIdFromValue($value) {
        $shipmentCodeSampleIdPattern = "/(\d{4}-[AaBbCcDdEe])-([1-9])/";
        preg_match_all($shipmentCodeSampleIdPattern, $value, $shipmentCodeSampleIdMatches);
        if (count($shipmentCodeSampleIdMatches) >= 3) {
            $shipmentCode = null;
            $sampleId = null;
            for ($i = 1; $i < count($shipmentCodeSampleIdMatches); $i++) {
                if (count($shipmentCodeSampleIdMatches[$i]) &&
                    isset($shipmentCodeSampleIdMatches[$i][0]) &&
                    $shipmentCodeSampleIdMatches[$i][0] != "")
                    if ($i == 1) {
                        $shipmentCode = $shipmentCodeSampleIdMatches[$i][0];
                    } else if ($i == 2 && isset($shipmentCode)) {
                        $sampleId = $shipmentCodeSampleIdMatches[$i][0];
                    }
            }
            if (isset($sampleId)) {
                return array(
                    "shipment_code" => $shipmentCode,
                    "sample_id" => $sampleId
                );
            }
        }
        return null;
    }
}

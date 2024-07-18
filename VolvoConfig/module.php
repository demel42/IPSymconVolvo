<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VolvoConfig extends IPSModule
{
    use Volvo\StubsCommonLib;
    use VolvoLocalLib;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));
        $this->RegisterAttributeString('DataCache', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{E730BFFA-6E1F-F615-D1B3-4D43A13B7285}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = [];
        $this->MaintainReferences($propertyNames);

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $this->SetupDataCache(24 * 60 * 60);

        $this->MaintainStatus(IS_ACTIVE);
    }

    private function getConfiguratorValues()
    {
        $entries = [];

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return $entries;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            return $entries;
        }

        $location = '';

        $dataCache = $this->ReadDataCache();
        if (false && isset($dataCache['data']['vehicles'])) {
            $vehicles = $dataCache['data']['vehicles'];
            $this->SendDebug(__FUNCTION__, 'vehicles (from cache)=' . print_r($vehicles, true), 0);
        } else {
            $vehicles = [];
            $SendData = [
                'DataID'   => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
                'CallerID' => $this->InstanceID,
                'Function' => 'GetVehicles'
            ];
            $data = $this->SendDataToParent(json_encode($SendData));
            $jvehicles = @json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'jvehicles=' . print_r($jvehicles, true), 0);
            if ($jvehicles != false) {
                foreach ($jvehicles['data'] as $ent) {
                    $vin = $ent['vin'];
                    $SendData = [
                        'DataID'   => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
                        'CallerID' => $this->InstanceID,
                        'Function' => 'GetApiConnectedVehicle',
                        'vin'      => $vin,
                        'detail'   => '',
                    ];
                    $data = $this->SendDataToParent(json_encode($SendData));
                    $jvehicle = @json_decode($data, true);
                    $this->SendDebug(__FUNCTION__, 'jvehicle=' . print_r($jvehicle, true), 0);
                    $vehicles[] = $jvehicle['data'];
                }
                if (is_array($vehicles)) {
                    $dataCache['data']['vehicles'] = $vehicles;
                }
                $this->WriteDataCache($dataCache, time());
            }
        }

        $guid = '{6C6B7979-37AA-69B7-2E19-7E10D92A97E3}'; // VolvoVehicle
        $instIDs = IPS_GetInstanceListByModuleID($guid);

        if (is_array($vehicles)) {
            foreach ($vehicles as $vehicle) {
                $this->SendDebug(__FUNCTION__, 'vehicle=' . print_r($vehicle, true), 0);
                $vin = $vehicle['vin'];
                $year = $vehicle['modelYear'];
                $model = $vehicle['descriptions']['model'];
                $fuelType = $vehicle['fuelType'];
                switch ($fuelType) {
                    case 'DIESEL':
                    case 'PETROL':
                        $driveType = self::$VOLVO_DRIVE_TYPE_COMBUSTION;
                        break;
                    case 'PETROL/ELECTRIC':
                        $driveType = self::$VOLVO_DRIVE_TYPE_HYBRID;
                        break;
                    case 'ELECTRIC':
                        $driveType = self::$VOLVO_DRIVE_TYPE_ELECTRIC;
                        break;
                    default:
                        $driveType = self::$VOLVO_DRIVE_TYPE_UNKNOWN;
                        break;
                }

                $instanceID = 0;
                $vehicleName = '';
                foreach ($instIDs as $instID) {
                    if ($vin == IPS_GetProperty($instID, 'vin')) {
                        $this->SendDebug(__FUNCTION__, 'vehicle found: ' . IPS_GetName($instID) . ' (' . $instID . ')', 0);
                        $instanceID = $instID;
                        $vehicleName = IPS_GetName($instID);
                        break;
                    }
                }

                if ($instanceID && IPS_GetInstance($instanceID)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                    continue;
                }

                $entry = [
                    'instanceID'  => $instanceID,
                    'vehicleName' => $vehicleName,
                    'vin'         => $vin,
                    'model'       => $model,
                    'year'        => $year,
                    'driveType'   => $this->DriveType2String($driveType),
                    'create'      => [
                        'moduleID'      => $guid,
                        'location'      => $location,
                        'info'          => $model . ' (' . $year . ')',
                        'configuration' => [
                            'vin'        => $vin,
                            'drive_type' => $driveType,
                        ]
                    ]
                ];

                $entries[] = $entry;
                $this->SendDebug(__FUNCTION__, 'instanceID=' . $instanceID . ', entry=' . print_r($entry, true), 0);
            }
        }

        foreach ($instIDs as $instID) {
            $fnd = false;
            foreach ($entries as $entry) {
                if ($entry['instanceID'] == $instID) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd) {
                continue;
            }

            if (IPS_GetInstance($instID)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                continue;
            }

            $vehicleName = IPS_GetName($instID);
            $vin = IPS_GetProperty($instID, 'vin');
            $driveType = IPS_GetProperty($instID, 'model');

            $entry = [
                'instanceID'  => $instID,
                'vehicleName' => $vehicleName,
                'vin'         => $vin,
                'model'       => '',
                'year'        => '',
                'driveType'   => $this->DriveType2String($driveType),
            ];
            $entries[] = $entry;
            $this->SendDebug(__FUNCTION__, 'lost: instanceID=' . $instID . ', entry=' . print_r($entry, true), 0);
        }

        return $entries;
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Volvo configurator');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $entries = $this->getConfiguratorValues();
        $this->SendDebug(__FUNCTION__, 'entries=' . print_r($entries, true), 0);
        $formElements[] = [
            'name'     => 'Volvo configuration',
            'type'     => 'Configurator',
            'rowCount' => count($entries),
            'add'      => false,
            'delete'   => false,
            'columns'  => [
                [
                    'caption' => 'Name',
                    'name'    => 'vehicleName',
                    'width'   => 'auto',
                ],
                [
                    'caption' => 'VIN',
                    'name'    => 'vin',
                    'width'   => '200px',
                ],
                [
                    'caption' => 'Model',
                    'name'    => 'model',
                    'width'   => '150px'
                ],
                [
                    'caption' => 'Year',
                    'name'    => 'year',
                    'width'   => '100px'
                ],
                [
                    'caption' => 'Drive type',
                    'name'    => 'driveType',
                    'width'   => '200px'
                ],
            ],
            'values'            => $entries,
            'discoveryInterval' => 60 * 60 * 24,
        ];

        $formElements[] = $this->GetRefreshDataCacheFormAction();
        /* TEST */
        $formElements[] = [
            'type'    => 'Button',
            'caption' => 'Reload',
            'onClick' => 'IPS_RequestAction($id, "ReloadForm", "");',
        ];
        /* TEST */

        $this->SendDebug(__FUNCTION__, 'formElements=' . print_r($formElements, true), 0);

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        /* TEST */
        $formActions[] = $this->GetRefreshDataCacheFormAction();
        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Reload',
            'onClick' => 'IPS_RequestAction($id, "ReloadForm", "");',
        ];
        /* TEST */

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $this->SendDebug(__FUNCTION__, 'ident ' . $ident, 0);
        $r = true;
        switch ($ident) {
            case 'ReloadForm':
                $this->ReloadForm();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }
}

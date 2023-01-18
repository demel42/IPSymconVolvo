<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';
require_once __DIR__ . '/../libs/images.php';

class VolvoConfig extends IPSModule
{
    use Volvo\StubsCommonLib;
    use VolvoLocalLib;
    use VolvoImagesLib;

    private $ModuleDir;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('ImportCategoryID', 0);

        $this->RegisterAttributeString('UpdateInfo', '');
        $this->RegisterAttributeString('DataCache', '');

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{2B3E3F00-33AC-4A54-8E20-F8B57241913D}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = ['ImportCategoryID'];
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

        $catID = $this->ReadPropertyInteger('ImportCategoryID');

        $dataCache = $this->ReadDataCache();
        if (isset($dataCache['data']['vehicles'])) {
            $vehicles = $dataCache['data']['vehicles'];
            $this->SendDebug(__FUNCTION__, 'vehicles (from cache)=' . print_r($vehicles, true), 0);
        } else {
            $SendData = [
                'DataID'   => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
                'CallerID' => $this->InstanceID,
                'Function' => 'GetVehicles'
            ];
            $data = $this->SendDataToParent(json_encode($SendData));
            $vehicles = @json_decode($data, true);
            $this->SendDebug(__FUNCTION__, 'vehicles=' . print_r($vehicles, true), 0);
            if (is_array($vehicles)) {
                $dataCache['data']['vehicles'] = $vehicles;
            }
            $this->WriteDataCache($dataCache, time());
        }

        $guid = '{6C6B7979-37AA-69B7-2E19-7E10D92A97E3}'; // VolvoVehicle
        $instIDs = IPS_GetInstanceListByModuleID($guid);

        if (is_array($vehicles)) {
            foreach ($vehicles as $vehicle) {
                $this->SendDebug(__FUNCTION__, 'vehicle=' . print_r($vehicle, true), 0);
                $vin = $vehicle['vin'];

                /*
                $model = $this->GetArrayElem($vehicle, 'attributes.model', '');
                $year = $this->GetArrayElem($vehicle, 'attributes.year', '');
                $bodyType = $this->GetArrayElem($vehicle, 'attributes.bodyType', '');
                $driveTrain = $this->GetArrayElem($vehicle, 'attributes.driveTrain', '');
                switch ($driveTrain) {
                    case 'COMBUSTION':
                        $driveType = self::$BMW_DRIVE_TYPE_COMBUSTION;
                        break;
                    case 'HYBRID':
                        $driveType = self::$BMW_DRIVE_TYPE_HYBRID;
                        break;
                    case 'ELECTRIC':
                        $driveType = self::$BMW_DRIVE_TYPE_ELECTRIC;
                        break;
                    default:
                        $driveType = self::$BMW_DRIVE_TYPE_UNKNOWN;
                        break;
                }
                 */

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
                    // continue;
                }

                $entry = [
                    'instanceID'  => $instanceID,
                    'vehicleName' => $vehicleName,
                    'vin'         => $vin,
                    'model'       => $model,
                    'year'        => $year,
                    'bodyType'    => $bodyType,
                    'driveType'   => $this->DriveType2String($driveType),
                    'create'      => [
                        'moduleID'      => $guid,
                        'location'      => $this->GetConfiguratorLocation($catID),
                        'info'          => $model . ' (' . $bodyType . '/' . $year . ')',
                        'configuration' => [
                            'vin'   => $vin,
                            'model' => $driveType,
                        ]
                    ]
                ];

                $entries[] = $entry;
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
                'bodyType'    => '',
                'driveType'   => $this->DriveType2String($driveType),
            ];
            $entries[] = $entry;
            $this->SendDebug(__FUNCTION__, 'missing entry=' . print_r($entry, true), 0);
        }

        return $entries;
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('BMW configurator');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'SelectCategory',
            'name'    => 'ImportCategoryID',
            'caption' => 'category for BMW vehicles to be created'
        ];

        $entries = $this->getConfiguratorValues();
        $formElements[] = [
            'name'     => 'BMW configuration',
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
                    'caption' => 'Body type',
                    'name'    => 'bodyType',
                    'width'   => '100px'
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

        return $formActions;
    }

    public function RequestAction($ident, $value)
    {
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

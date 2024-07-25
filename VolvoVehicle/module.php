<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VolvoVehicle extends IPSModule
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

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('vin', '');
        $this->RegisterPropertyInteger('drive_type', self::$VOLVO_DRIVE_TYPE_UNKNOWN);

        $this->RegisterPropertyBoolean('log_no_parent', true);

        $this->RegisterPropertyInteger('update_interval', 60);

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->SetBuffer('VehicleData', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->ConnectParent('{E730BFFA-6E1F-F615-D1B3-4D43A13B7285}');
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $vin = $this->ReadPropertyString('vin');
        if ($vin == '') {
            $this->SendDebug(__FUNCTION__, '"vin" is empty', 0);
            $r[] = $this->Translate('A registered VIN is required');
        }

        return $r;
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainReferences();

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('UpdateStatus', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('UpdateStatus', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('UpdateStatus', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $drive_type = $this->ReadPropertyInteger('drive_type');
        $has_fuel = in_array($drive_type, [self::$VOLVO_DRIVE_TYPE_HYBRID, self::$VOLVO_DRIVE_TYPE_COMBUSTION]);
        $has_electric = in_array($drive_type, [self::$VOLVO_DRIVE_TYPE_ELECTRIC, self::$VOLVO_DRIVE_TYPE_HYBRID]);

        $vpos = 1;
        $this->MaintainVariable('Mileage', $this->Translate('Mileage'), VARIABLETYPE_INTEGER, 'Volvo.Mileage', $vpos++, true);
        if ($has_fuel) {
            $this->MaintainVariable('FuelAmount', $this->Translate('Fuel amount'), VARIABLETYPE_FLOAT, 'Volvo.FuelAmount', $vpos++, true);
            $this->MaintainVariable('RemainingFuelRange', $this->Translate('Remaining fuel range'), VARIABLETYPE_FLOAT, 'Volvo.Range', $vpos++, true);
        }

        if ($has_electric) {
            $this->MaintainVariable('RemainingElectricRange', $this->Translate('Remaining electric range'), VARIABLETYPE_FLOAT, 'Volvo.Range', $vpos++, true);
        }

        $vpos = 10;
        $this->MaintainVariable('EngineState', $this->Translate('Engine'), VARIABLETYPE_INTEGER, 'Volvo.EngineState', $vpos++, true);

        $vpos = 20;
        if ($has_electric) {
            $this->MaintainVariable('BatteryCapacity', $this->Translate('Battery capacity'), VARIABLETYPE_FLOAT, 'Volvo.BatteryCapacity', $vpos++, true);
            $this->MaintainVariable('BatteryChargeLevel', $this->Translate('Battery charge level'), VARIABLETYPE_FLOAT, 'Volvo.BatteryChargeLevel', $vpos++, true);
            $this->MaintainVariable('ConnectionState', $this->Translate('Connection state'), VARIABLETYPE_INTEGER, 'Volvo.ConnectionState', $vpos++, true);
            $this->MaintainVariable('ChargingState', $this->Translate('Charging state'), VARIABLETYPE_INTEGER, 'Volvo.ChargingState', $vpos++, true);
            $this->MaintainVariable('EstimatedChargingTime', $this->Translate('Estimated charging time'), VARIABLETYPE_INTEGER, 'Volvo.Minutes', $vpos++, true);
        }

        $vpos = 30;
        $this->MaintainVariable('CentralLockState', $this->Translate('Central lock'), VARIABLETYPE_INTEGER, 'Volvo.CentralLockState', $vpos++, true);
        $this->MaintainVariable('FrontLeftDoorState', $this->Translate('Door front left'), VARIABLETYPE_INTEGER, 'Volvo.DoorState', $vpos++, true);
        $this->MaintainVariable('FrontRightDoorState', $this->Translate('Door front right'), VARIABLETYPE_INTEGER, 'Volvo.DoorState', $vpos++, true);
        $this->MaintainVariable('RearLeftDoorState', $this->Translate('Door rear left'), VARIABLETYPE_INTEGER, 'Volvo.DoorState', $vpos++, true);
        $this->MaintainVariable('RearRightDoorState', $this->Translate('Door rear right'), VARIABLETYPE_INTEGER, 'Volvo.DoorState', $vpos++, true);
        $this->MaintainVariable('HoodState', $this->Translate('Hood'), VARIABLETYPE_INTEGER, 'Volvo.DoorState', $vpos++, true);
        $this->MaintainVariable('TailgateState', $this->Translate('Tailgate'), VARIABLETYPE_INTEGER, 'Volvo.DoorState', $vpos++, true);
        $this->MaintainVariable('TankLidState', $this->Translate('Tanklid'), VARIABLETYPE_INTEGER, 'Volvo.DoorState', $vpos++, true);

        $vpos = 40;
        $this->MaintainVariable('FrontLeftWindowState', $this->Translate('Window front left'), VARIABLETYPE_INTEGER, 'Volvo.WindowState', $vpos++, true);
        $this->MaintainVariable('FrontRightWindowState', $this->Translate('Window front right'), VARIABLETYPE_INTEGER, 'Volvo.WindowState', $vpos++, true);
        $this->MaintainVariable('RearLeftWindowState', $this->Translate('Window rear left'), VARIABLETYPE_INTEGER, 'Volvo.WindowState', $vpos++, true);
        $this->MaintainVariable('RearRightWindowState', $this->Translate('Window rear right'), VARIABLETYPE_INTEGER, 'Volvo.WindowState', $vpos++, true);
        $this->MaintainVariable('SunroofState', $this->Translate('Sunroof'), VARIABLETYPE_INTEGER, 'Volvo.WindowState', $vpos++, true);

        $vpos = 50;
        $this->MaintainVariable('FrontLeftTyreState', $this->Translate('Tyre front left'), VARIABLETYPE_INTEGER, 'Volvo.TyreState', $vpos++, true);
        $this->MaintainVariable('FrontRightTyreState', $this->Translate('Tyre front right'), VARIABLETYPE_INTEGER, 'Volvo.TyreState', $vpos++, true);
        $this->MaintainVariable('RearLeftTyreState', $this->Translate('Tyre rear left'), VARIABLETYPE_INTEGER, 'Volvo.TyreState', $vpos++, true);
        $this->MaintainVariable('RearRightTyreState', $this->Translate('Tyre rear right'), VARIABLETYPE_INTEGER, 'Volvo.TyreState', $vpos++, true);

        $vpos = 70;
        $this->MaintainVariable('CurrentLongitude', $this->Translate('Current longitude'), VARIABLETYPE_FLOAT, 'Volvo.Location', $vpos++, true);
        $this->MaintainVariable('CurrentLatitude', $this->Translate('Current latitude'), VARIABLETYPE_FLOAT, 'Volvo.Location', $vpos++, true);
        $this->MaintainVariable('CurrentAltitude', $this->Translate('Current altitude'), VARIABLETYPE_INTEGER, 'Volvo.Altitude', $vpos++, true);
        $this->MaintainVariable('CurrentDirection', $this->Translate('Current direction'), VARIABLETYPE_INTEGER, 'Volvo.Heading', $vpos++, true);
        $this->MaintainVariable('LastPositionMessage', $this->Translate('Last position message'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $vpos = 90;
        $this->MaintainVariable('Warnings', $this->Translate('Warnings'), VARIABLETYPE_STRING, '~HTMLBox', $vpos++, true);
        $this->MaintainVariable('HasFailure', $this->Translate('Failures exists'), VARIABLETYPE_BOOLEAN, 'Volvo.Failure', $vpos++, true);

        if ($has_fuel) {
            $this->MaintainVariable('AverageFuelConsumption', $this->Translate('Average fuel consumption'), VARIABLETYPE_FLOAT, 'Volvo.FuelConsumption', $vpos++, true);
            $this->MaintainVariable('AverageFuelConsumptionAutomatic', $this->Translate('Average fuel consumption (TA)'), VARIABLETYPE_FLOAT, 'Volvo.FuelConsumption', $vpos++, true);
        }
        if ($has_electric) {
            $this->MaintainVariable('AverageEnergyConsumption', $this->Translate('Average energy consumption'), VARIABLETYPE_FLOAT, 'Volvo.EnergyConsumption', $vpos++, true);
        }
        $this->MaintainVariable('AverageSpeed', $this->Translate('Average speed'), VARIABLETYPE_FLOAT, 'Volvo.Speed', $vpos++, true);
        $this->MaintainVariable('TripMeterManual', $this->Translate('Trip meter'), VARIABLETYPE_FLOAT, 'Volvo.Distance', $vpos++, true);
        $this->MaintainVariable('AverageSpeedAutomatic', $this->Translate('Average speed (TA)'), VARIABLETYPE_FLOAT, 'Volvo.Speed', $vpos++, true);
        $this->MaintainVariable('TripMeterAutomatic', $this->Translate('Trip meter (TA)'), VARIABLETYPE_FLOAT, 'Volvo.Distance', $vpos++, true);

        $vpos = 100;
        $this->MaintainVariable('LastCommand', $this->Translate('Last command'), VARIABLETYPE_STRING, '', $vpos++, true);
        $this->MaintainVariable('LockDoors', $this->Translate('Lock doors'), VARIABLETYPE_INTEGER, 'Volvo.TriggerCommand', $vpos++, true);
        $this->MaintainVariable('UnlockDoors', $this->Translate('Unlock doors'), VARIABLETYPE_INTEGER, 'Volvo.TriggerCommand', $vpos++, true);
        $this->MaintainVariable('StartClimatization', $this->Translate('Start climatization'), VARIABLETYPE_INTEGER, 'Volvo.TriggerCommand', $vpos++, true);
        $this->MaintainVariable('StopClimatization', $this->Translate('Stop climatization'), VARIABLETYPE_INTEGER, 'Volvo.TriggerCommand', $vpos++, true);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('UpdateStatus', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Volvo vehicle');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'name'    => 'vin',
                    'type'    => 'ValidationTextBox',
                    'caption' => 'VIN'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'drive_type',
                    'caption' => 'mode of driving',
                    'options' => $this->DriveTypeAsOptions(),
                ],
            ],
            'caption' => 'Vehicle data',
        ];

        $formElements[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'update_interval',
            'suffix'  => 'Minutes',
            'minimum' => 0,
            'caption' => 'Update interval',
        ];

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'log_no_parent',
            'caption' => 'Generate message when the gateway is inactive',
        ];

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

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Update status',
            'onClick' => 'IPS_RequestAction($id, "UpdateStatus", "");',
        ];

        $vehicleData = @json_decode($this->GetBuffer('VehicleData'), true);
        if ($vehicleData != false) {
            $items = [];
            foreach ($vehicleData['commands'] as $command) {
                switch ($command) {
                    case 'LOCK':
                        $items[] = [
                            'type'    => 'Button',
                            'caption' => 'Lock doors',
                            'onClick' => 'IPS_RequestAction($id, "LockDoors", "");',
                        ];
                        break;
                    case 'UNLOCK':
                        $items[] = [
                            'type'    => 'Button',
                            'caption' => 'Unlock doors',
                            'onClick' => 'IPS_RequestAction($id, "UnlockDoors", "");',
                        ];
                        break;
                    case 'CLIMATIZATION_START':
                        $items[] = [
                            'type'    => 'Button',
                            'caption' => 'Start climatization',
                            'onClick' => 'IPS_RequestAction($id, "StartClimatization", "");',
                        ];
                        break;
                    case 'CLIMATIZATION_STOP':
                        $items[] = [
                            'type'    => 'Button',
                            'caption' => 'Stop climatization',
                            'onClick' => 'IPS_RequestAction($id, "StopClimatization", "");',
                        ];
                        break;
                }
            }
            $formActions[] = [
                'type'    => 'RowLayout',
                'items'   => $items,
            ];
        }

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
            ],
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Test area',
            'expanded'  => false,
            'items'     => [
                [
                    'type'    => 'TestCenter',
                ],
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function SetUpdateInterval(int $min = null)
    {
        if ($min == '') {
            $min = $this->ReadPropertyInteger('update_interval');
        }
        $this->MaintainTimer('UpdateStatus', $min * 60 * 1000);
    }

    private function UpdateStatus()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
        }

        $drive_type = $this->ReadPropertyInteger('drive_type');
        $has_fuel = in_array($drive_type, [self::$VOLVO_DRIVE_TYPE_HYBRID, self::$VOLVO_DRIVE_TYPE_COMBUSTION]);
        $has_electric = in_array($drive_type, [self::$VOLVO_DRIVE_TYPE_ELECTRIC, self::$VOLVO_DRIVE_TYPE_HYBRID]);

        $this->SendDebug(__FUNCTION__, 'start ...', 0);
        $time_start = microtime(true);
        $fnd = false;
        $chg = false;

        $vehicleData = @json_decode($this->GetBuffer('VehicleData'), true);
        if ($vehicleData == false) {
            $vehicleData = [];

            $vehicle = $this->GetApiConnectedVehicle();
            if ($vehicle != false) {
                $this->SendDebug(__FUNCTION__, 'vehicle=' . print_r($vehicle, true), 0);

                $model = $this->GetArrayElem($vehicle, 'data.descriptions.model', '');
                $year = $this->GetArrayElem($vehicle, 'data.modelYear', '');
                $summary = $model . ' (' . $year . ')';
                $this->SetSummary($summary);

                if ($has_electric) {
                    $batteryCapacityKWH = $this->GetArrayElem($vehicle, 'data.batteryCapacityKWH', 0, $fnd);
                    if ($fnd) {
                        $this->SendDebug(__FUNCTION__, '... BatteryCapacity (vehicle:data.batteryCapacityKWH)=' . $batteryCapacityKWH, 0);
                        $this->SaveValue('BatteryCapacity', $batteryCapacityKWH, $chg);
                    }
                }
                $vehicleData['basics'] = $vehicle['data'];
            }

            $commands = $this->GetApiConnectedVehicle('commands');
            if ($commands != false) {
                $this->SendDebug(__FUNCTION__, 'commands=' . print_r($commands, true), 0);
                $cmds = [];
                foreach ($commands['data'] as $c) {
                    $cmds[] = $c['command'];
                }
                $vehicleData['commands'] = $cmds;
            }

            $resources = $this->GetApiExtendedVehicle('resources');
            if ($resources) {
                $this->SendDebug(__FUNCTION__, 'resources=' . print_r($resources, true), 0);
                $vehicleData['resources'] = $resources;
            }

            $this->SetBuffer('VehicleData', json_encode($vehicleData));
            $this->SendDebug(__FUNCTION__, 'VehicleData=' . print_r($vehicleData, true), 0);
        }

        $this->SendDebug(__FUNCTION__, 'commands=' . print_r($vehicleData['commands'], true), 0);
        $this->MaintainAction('LockDoors', in_array('LOCK', $vehicleData['commands']));
        $this->MaintainAction('UnlockDoors', in_array('UNLOCK', $vehicleData['commands']));
        $this->MaintainAction('StartClimatization', in_array('CLIMATIZATION_START', $vehicleData['commands']));
        $this->MaintainAction('StopClimatization', in_array('CLIMATIZATION_STOP', $vehicleData['commands']));

        $odometer = $this->GetApiConnectedVehicle('odometer');
        if ($odometer != false) {
            $this->SendDebug(__FUNCTION__, 'odometer=' . print_r($odometer, true), 0);

            $mileage = $this->GetArrayElem($odometer, 'data.odometer.value', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... Mileage (odometer:data.odometer.value)=' . $mileage, 0);
                $this->SaveValue('Mileage', $mileage, $chg);
            }
        }

        $engine_status = $this->GetApiConnectedVehicle('engine-status');
        if ($engine_status != false) {
            $this->SendDebug(__FUNCTION__, 'engine_status=' . print_r($engine_status, true), 0);

            $engineStatus = $this->GetArrayElem($engine_status, 'data.engineStatus.value', '', $fnd);
            if ($fnd) {
                $engineState = $this->MapEngineState($engineStatus);
                $this->SendDebug(__FUNCTION__, '... EngineState (engine-status:data.engineStatus.value)=' . $engineStatus . '/' . $engineState, 0);
                $this->SaveValue('EngineState', $engineState, $chg);
            }
        }

        $doors = $this->GetApiConnectedVehicle('doors');
        if ($doors != false) {
            $this->SendDebug(__FUNCTION__, 'doors=' . print_r($doors, true), 0);

            $centralLock = $this->GetArrayElem($doors, 'data.centralLock.value', '', $fnd);
            if ($fnd) {
                $centralLockState = $this->MapCentralLockState($centralLock);
                $this->SendDebug(__FUNCTION__, '... CentralLockState (doors:data.centralLock.value)=' . $centralLock . '/' . $centralLockState, 0);
                $this->SaveValue('CentralLockState', $centralLockState, $chg);
            }

            $frontLeftDoor = $this->GetArrayElem($doors, 'data.frontLeftDoor.value', '', $fnd);
            if ($fnd) {
                $frontLeftDoorState = $this->MapDoorState($frontLeftDoor);
                $this->SendDebug(__FUNCTION__, '... FrontLeftDoorState (doors:data.frontLeftDoor.value)=' . $frontLeftDoor . '/' . $frontLeftDoorState, 0);
                $this->SaveValue('FrontLeftDoorState', $frontLeftDoorState, $chg);
            }

            $frontRightDoor = $this->GetArrayElem($doors, 'data.frontRightDoor.value', '', $fnd);
            if ($fnd) {
                $frontRightDoorState = $this->MapDoorState($frontRightDoor);
                $this->SendDebug(__FUNCTION__, '... FrontRightDoorState (doors:data.frontRightDoor.value)=' . $frontRightDoor . '/' . $frontRightDoorState, 0);
                $this->SaveValue('FrontRightDoorState', $frontRightDoorState, $chg);
            }

            $rearLeftDoor = $this->GetArrayElem($doors, 'data.rearLeftDoor.value', '', $fnd);
            if ($fnd) {
                $rearLeftDoorState = $this->MapDoorState($rearLeftDoor);
                $this->SendDebug(__FUNCTION__, '... RearLeftDoorState (doors:data.rearLeftDoor.value)=' . $rearLeftDoor . '/' . $rearLeftDoorState, 0);
                $this->SaveValue('RearLeftDoorState', $rearLeftDoorState, $chg);
            }

            $rearRightDoor = $this->GetArrayElem($doors, 'data.rearRightDoor.value', '', $fnd);
            if ($fnd) {
                $rearRightDoorState = $this->MapDoorState($rearRightDoor);
                $this->SendDebug(__FUNCTION__, '... RearRightDoorState (doors:data.rearRightDoor.value)=' . $rearRightDoor . '/' . $rearRightDoorState, 0);
                $this->SaveValue('RearRightDoorState', $rearRightDoorState, $chg);
            }

            $hood = $this->GetArrayElem($doors, 'data.hood.value', '', $fnd);
            if ($fnd) {
                $hoodState = $this->MapDoorState($hood);
                $this->SendDebug(__FUNCTION__, '... HoodState (doors:data.hood.value)=' . $hood . '/' . $hoodState, 0);
                $this->SaveValue('HoodState', $hoodState, $chg);
            }

            $tailgate = $this->GetArrayElem($doors, 'data.tailgate.value', '', $fnd);
            if ($fnd) {
                $tailgateState = $this->MapDoorState($tailgate);
                $this->SendDebug(__FUNCTION__, '... TailgateState (doors:data.tailgate.value)=' . $tailgate . '/' . $tailgateState, 0);
                $this->SaveValue('TailgateState', $tailgateState, $chg);
            }

            $tankLid = $this->GetArrayElem($doors, 'data.tankLid.value', '', $fnd);
            if ($fnd) {
                $tankLidState = $this->MapDoorState($tankLid);
                $this->SendDebug(__FUNCTION__, '... TankLidState (doors:data.tankLid.value)=' . $tankLid . '/' . $tankLidState, 0);
                $this->SaveValue('TankLidState', $tankLidState, $chg);
            }
        }

        $windows = $this->GetApiConnectedVehicle('windows');
        if ($windows != false) {
            $this->SendDebug(__FUNCTION__, 'windows=' . print_r($windows, true), 0);

            $frontLeftWindow = $this->GetArrayElem($windows, 'data.frontLeftWindow.value', '', $fnd);
            if ($fnd) {
                $frontLeftWindowState = $this->MapWindowState($frontLeftWindow);
                $this->SendDebug(__FUNCTION__, '... FrontLeftWindowState (windows:data.frontLeftWindow.value)=' . $frontLeftWindow . '/' . $frontLeftWindowState, 0);
                $this->SaveValue('FrontLeftWindowState', $frontLeftWindowState, $chg);
            }

            $frontRightWindow = $this->GetArrayElem($windows, 'data.frontRightWindow.value', '', $fnd);
            if ($fnd) {
                $frontRightWindowState = $this->MapWindowState($frontRightWindow);
                $this->SendDebug(__FUNCTION__, '... FrontRightWindowState (windows:data.frontRightWindow.value)=' . $frontRightWindow . '/' . $frontRightWindowState, 0);
                $this->SaveValue('FrontRightWindowState', $frontRightWindowState, $chg);
            }

            $rearLeftWindow = $this->GetArrayElem($windows, 'data.rearLeftWindow.value', '', $fnd);
            if ($fnd) {
                $rearLeftWindowState = $this->MapWindowState($rearLeftWindow);
                $this->SendDebug(__FUNCTION__, '... RearLeftWindowState (windows:data.rearLeftWindow.value)=' . $rearLeftWindow . '/' . $rearLeftWindowState, 0);
                $this->SaveValue('RearLeftWindowState', $rearLeftWindowState, $chg);
            }

            $rearRightWindow = $this->GetArrayElem($windows, 'data.rearRightWindow.value', '', $fnd);
            if ($fnd) {
                $rearRightWindowState = $this->MapWindowState($rearRightWindow);
                $this->SendDebug(__FUNCTION__, '... RearRightWindowState (windows:data.rearRightWindow.value)=' . $rearRightWindow . '/' . $rearRightWindowState, 0);
                $this->SaveValue('RearRightWindowState', $rearRightWindowState, $chg);
            }

            $sunroof = $this->GetArrayElem($windows, 'data.sunroof.value', '', $fnd);
            if ($fnd) {
                $sunroofState = $this->MapWindowState($sunroof);
                $this->SendDebug(__FUNCTION__, '... SunroofState (windows:data.sunroof.value)=' . $sunroof . '/' . $sunroofState, 0);
                $this->SaveValue('SunroofState', $sunroofState, $chg);
            }
        }

        $tyres = $this->GetApiConnectedVehicle('tyres');
        if ($tyres != false) {
            $this->SendDebug(__FUNCTION__, 'tyres=' . print_r($tyres, true), 0);

            $frontLeft = $this->GetArrayElem($tyres, 'data.frontLeft.value', '', $fnd);
            if ($fnd) {
                $frontLeftTyreState = $this->MapTyreState($frontLeft);
                $this->SendDebug(__FUNCTION__, '... FrontLeftTyreState (tyres:data.frontLeftTyre.value)=' . $frontLeft . '/' . $frontLeftTyreState, 0);
                $this->SaveValue('FrontLeftTyreState', $frontLeftTyreState, $chg);
            }

            $frontRight = $this->GetArrayElem($tyres, 'data.frontRight.value', '', $fnd);
            if ($fnd) {
                $frontRightTyreState = $this->MapTyreState($frontRight);
                $this->SendDebug(__FUNCTION__, '... FrontRightTyreState (tyres:data.frontRightTyre.value)=' . $frontRight . '/' . $frontRightTyreState, 0);
                $this->SaveValue('FrontRightTyreState', $frontRightTyreState, $chg);
            }

            $rearLeft = $this->GetArrayElem($tyres, 'data.rearLeft.value', '', $fnd);
            if ($fnd) {
                $rearLeftTyreState = $this->MapTyreState($rearLeft);
                $this->SendDebug(__FUNCTION__, '... RearLeftTyreState (tyres:data.rearLeftTyre.value)=' . $rearLeft . '/' . $rearLeftTyreState, 0);
                $this->SaveValue('RearLeftTyreState', $rearLeftTyreState, $chg);
            }

            $rearRight = $this->GetArrayElem($tyres, 'data.rearRight.value', '', $fnd);
            if ($fnd) {
                $rearRightTyreState = $this->MapTyreState($rearRight);
                $this->SendDebug(__FUNCTION__, '... RearRightTyreState (tyres:data.rearRightTyre.value)=' . $rearRight . '/' . $rearRightTyreState, 0);
                $this->SaveValue('RearRightTyreState', $rearRightTyreState, $chg);
            }
        }

        $has_failure = false;
        $tbl = '';

        $diagnostics = $this->GetApiConnectedVehicle('diagnostics');
        if ($diagnostics != false) {
            $this->SendDebug(__FUNCTION__, 'diagnostics=' . print_r($diagnostics, true), 0);

            $entryList = [
                'serviceWarning'          => 'Service',
                'washerFluidLevelWarning' => 'Washer fluid level',
            ];

            foreach ($entryList as $key => $txt) {
                $value = $this->GetArrayElem($diagnostics, 'data.' . $key . '.value', '', $fnd);
                if ($fnd == false) {
                    continue;
                }
                $timestamp = $this->GetArrayElem($diagnostics, 'data.' . $key . '.timestamp', '', $fnd);
                $ts = $fnd ? date('d.m.Y H:i:s', strtotime($timestamp)) : '-';
                $this->SendDebug(__FUNCTION__, '..... (diagnostics:data.' . $key . '.value)=' . $value . ', (.timestamp)=' . $timestamp, 0);

                if (in_array($value, ['UNSPECIFIED', ''])) {
                    continue;
                }
                if ($value != 'NO_WARNING') {
                    $has_failure = true;
                }

                $tbl .= '<tr>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($txt) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($value) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $ts . '</td>' . PHP_EOL;
                $tbl .= '</tr>' . PHP_EOL;
            }
        }

        $warnings = $this->GetApiConnectedVehicle('warnings');
        if ($warnings != false) {
            $this->SendDebug(__FUNCTION__, 'warnings=' . print_r($warnings, true), 0);

            $entryList = [
                'brakeLightLeftWarning'           => 'Brake light left',
                'brakeLightCenterWarning'         => 'Brake light center',
                'brakeLightRightWarning'          => 'Brake light right',
                'fogLightFrontWarning'            => 'Fog light front',
                'fogLightRearWarning'             => 'Fog light rear',
                'positionLightFrontLeftWarning'   => 'Position light front left',
                'positionLightFrontRightWarning'  => 'Position light front right',
                'positionLightRearLeftWarning'    => 'Position light rear left',
                'positionLightRearRightWarning'   => 'Position light rear right',
                'highBeamLeftWarning'             => 'High beam left',
                'highBeamRightWarning'            => 'High beam right',
                'lowBeamLeftWarning'              => 'Low beam left',
                'lowBeamRightWarning'             => 'Low beam right',
                'daytimeRunningLightLeftWarning'  => 'Daytime running light left',
                'daytimeRunningLightRightWarning' => 'Daytime running light right',
                'turnIndicationFrontLeftWarning'  => 'Turn indicator front left',
                'turnIndicationFrontRightWarning' => 'Turn indicator front right',
                'turnIndicationRearLeftWarning'   => 'Turn indicator rear left',
                'turnIndicationRearRightWarning'  => 'Turn indicator rear right',
                'registrationPlateLightWarning'   => 'Rgistration plate light',
                'sideMarkLightsWarning'           => 'Side mark lights',
                'hazardLightsWarning'             => 'Hazard lights',
                'reverseLightsWarning'            => 'Reverse lights',
            ];

            foreach ($entryList as $key => $txt) {
                $value = $this->GetArrayElem($warnings, 'data.' . $key . '.value', '', $fnd);
                if ($fnd == false) {
                    continue;
                }
                $timestamp = $this->GetArrayElem($warnings, 'data.' . $key . '.timestamp', '', $fnd);
                $ts = $fnd ? date('d.m.Y H:i:s', strtotime($timestamp)) : '-';
                $this->SendDebug(__FUNCTION__, '..... (warnings:data.' . $key . '.value)=' . $value . ', (.timestamp)=' . $timestamp, 0);

                if (in_array($value, ['UNSPECIFIED', ''])) {
                    continue;
                }
                if ($value != 'NO_WARNING') {
                    $has_failure = true;
                }

                $tbl .= '<tr>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($txt) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($value) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $ts . '</td>' . PHP_EOL;
                $tbl .= '</tr>' . PHP_EOL;
            }
        }

        $engine_diagnostics = $this->GetApiConnectedVehicle('engine');
        if ($engine_diagnostics != false) {
            $this->SendDebug(__FUNCTION__, 'engine_diagnostics=' . print_r($engine_diagnostics, true), 0);

            $entryList = [
                'oilLevelWarning'           => 'Oil level',
                'engineCoolantLevelWarning' => 'Engine coolant level',
            ];

            foreach ($entryList as $key => $txt) {
                $value = $this->GetArrayElem($engine_diagnostics, 'data.' . $key . '.value', '', $fnd);
                if ($fnd == false) {
                    continue;
                }
                $timestamp = $this->GetArrayElem($engine_diagnostics, 'data.' . $key . '.timestamp', '', $fnd);
                $ts = $fnd ? date('d.m.Y H:i:s', strtotime($timestamp)) : '-';
                $this->SendDebug(__FUNCTION__, '.....  (engine:data.' . $key . '.value)=' . $value . ', (.timestamp)=' . $timestamp, 0);

                if (in_array($value, ['UNSPECIFIED', ''])) {
                    continue;
                }
                if ($value != 'NO_WARNING') {
                    $has_failure = true;
                }

                $tbl .= '<tr>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($txt) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($value) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $ts . '</td>' . PHP_EOL;
                $tbl .= '</tr>' . PHP_EOL;
            }
        }

        $brakes_diagnostics = $this->GetApiConnectedVehicle('brakes');
        if ($brakes_diagnostics != false) {
            $this->SendDebug(__FUNCTION__, 'brakes_diagnostics=' . print_r($brakes_diagnostics, true), 0);

            $entryList = [
                'brakeFluidLevelWarning' => 'Brake fluid level',
            ];

            foreach ($entryList as $key => $txt) {
                $this->SendDebug(__FUNCTION__, 'key=' . $key . ', txt=' . $txt, 0);
                $value = $this->GetArrayElem($brakes_diagnostics, 'data.' . $key . '.value', '', $fnd);
                if ($fnd == false) {
                    continue;
                }
                $timestamp = $this->GetArrayElem($brakes_diagnostics, 'data.' . $key . '.timestamp', '', $fnd);
                $ts = $fnd ? date('d.m.Y H:i:s', strtotime($timestamp)) : '-';
                $this->SendDebug(__FUNCTION__, '.....  (brakes:data.' . $key . '.value)=' . $value . ', (.timestamp)=' . $timestamp, 0);

                if (in_array($value, ['UNSPECIFIED', ''])) {
                    continue;
                }
                if ($value != 'NO_WARNING') {
                    $has_failure = true;
                }

                $tbl .= '<tr>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($txt) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $this->Translate($value) . '</td>' . PHP_EOL;
                $tbl .= '<td>' . $ts . '</td>' . PHP_EOL;
                $tbl .= '</tr>' . PHP_EOL;
            }
        }

        if ($tbl != '') {
            $html = '<style>' . PHP_EOL;
            $html .= 'th, td { padding: 2px 10px; text-align: left; }' . PHP_EOL;
            $html .= '</style>' . PHP_EOL;
            $html .= '<table>' . PHP_EOL;
            $html .= '<tr>' . PHP_EOL;
            $html .= '<th>' . $this->Translate('Warnings') . '</th>' . PHP_EOL;
            $html .= '<th>' . $this->Translate('Text') . '</th>' . PHP_EOL;
            $html .= '<th>' . $this->Translate('Timestamp') . '</th>' . PHP_EOL;
            $html .= '</tr>' . PHP_EOL;
            $html .= $tbl;
            $html .= '</table>' . PHP_EOL;
        } else {
            $html = $this->Translate('No warnings');
        }

        $this->SendDebug(__FUNCTION__, '... Warnings=' . $html, 0);
        $this->SaveValue('Warnings', $html, $chg);
        $this->SendDebug(__FUNCTION__, '... HasFailure=' . $this->bool2str($has_failure), 0);
        $this->SaveValue('HasFailure', $has_failure, $chg);

        $statistics = $this->GetApiConnectedVehicle('statistics');
        if ($statistics != false) {
            $this->SendDebug(__FUNCTION__, 'statistics=' . print_r($statistics, true), 0);

            if ($has_fuel) {
                $averageFuelConsumption = $this->GetArrayElem($statistics, 'data.averageFuelConsumption.value', 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... AverageFuelConsumption (statistics:data.averageFuelConsumption.value)=' . $averageFuelConsumption, 0);
                    $this->SaveValue('AverageFuelConsumption', $averageFuelConsumption, $chg);
                }

                $averageFuelConsumptionAutomatic = $this->GetArrayElem($statistics, 'data.averageFuelConsumptionAutomatic.value', 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... AverageFuelConsumptionAutomatic (statistics:data.averageFuelConsumptionAutomatic.value)=' . $averageFuelConsumptionAutomatic, 0);
                    $this->SaveValue('AverageFuelConsumptionAutomatic', $averageFuelConsumptionAutomatic, $chg);
                }

                $distanceToEmptyTank = $this->GetArrayElem($statistics, 'data.distanceToEmptyTank.value', 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... RemainingFuelRange (statistics:data.distanceToEmptyTank.value)=' . $distanceToEmptyTank, 0);
                    $this->SaveValue('RemainingFuelRange', $distanceToEmptyTank, $chg);
                }
            }

            if ($has_electric) {
                $averageEnergyConsumption = $this->GetArrayElem($statistics, 'data.averageEnergyConsumption.value', 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... AverageEnergyConsumption (statistics:data.averageEnergyConsumption.value)=' . $averageEnergyConsumption, 0);
                    $this->SaveValue('AverageEnergyConsumption', $averageEnergyConsumption, $chg);
                }

                $distanceToEmptyBattery = $this->GetArrayElem($statistics, 'data.distanceToEmptyBattery.value', 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... RemainingElectricRange (statistics:data.distanceToEmptyBattery.value)=' . $distanceToEmptyBattery, 0);
                    $this->SaveValue('RemainingElectricRange', $distanceToEmptyBattery, $chg);
                }
            }

            $averageSpeed = $this->GetArrayElem($statistics, 'data.averageSpeed.value', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... AverageSpeed (statistics:data.averageSpeed.value)=' . $averageSpeed, 0);
                $this->SaveValue('AverageSpeed', $averageSpeed, $chg);
            }

            $averageSpeedAutomatic = $this->GetArrayElem($statistics, 'data.averageSpeedAutomatic.value', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... AverageSpeedAutomatic (statistics:data.averageSpeedAutomatic.value)=' . $averageSpeedAutomatic, 0);
                $this->SaveValue('AverageSpeedAutomatic', $averageSpeedAutomatic, $chg);
            }

            $tripMeterManual = $this->GetArrayElem($statistics, 'data.tripMeterManual.value', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... TripMeterManual (statistics:data.tripMeterManual.value)=' . $tripMeterManual, 0);
                $this->SaveValue('TripMeterManual', $tripMeterManual, $chg);
            }

            $tripMeterAutomatic = $this->GetArrayElem($statistics, 'data.tripMeterAutomatic.value', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... TripMeterAutomatic (statistics:data.tripMeterAutomatic.value)=' . $tripMeterAutomatic, 0);
                $this->SaveValue('TripMeterAutomatic', $tripMeterAutomatic, $chg);
            }
        }

        if ($has_fuel) {
            $fuel = $this->GetApiConnectedVehicle('fuel');
            if ($fuel != false) {
                $this->SendDebug(__FUNCTION__, 'fuel=' . print_r($fuel, true), 0);

                $fuelAmount = $this->GetArrayElem($fuel, 'data.fuelAmount.value', 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... FuelAmount (fuel:data.fuelAmount.value)=' . $fuelAmount, 0);
                    $this->SaveValue('FuelAmount', $fuelAmount, $chg);
                }
            }
        }

        if ($has_electric) {
            $recharge_status = $this->GetApiEnergy('recharge-status');
            if ($recharge_status != false) {
                $this->SendDebug(__FUNCTION__, 'recharge_status=' . print_r($recharge_status, true), 0);

                $batteryChargeLevel = $this->GetArrayElem($recharge_status, 'data.batteryChargeLevel.value', 0, $fnd);
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... BatteryChargeLevel (recharge-status:data.batteryChargeLevel.value)=' . $batteryChargeLevel, 0);
                    $this->SaveValue('BatteryChargeLevel', $batteryChargeLevel, $chg);
                }

                $chargingConnectionStatus = $this->GetArrayElem($recharge_status, 'data.chargingConnectionStatus.value', '', $fnd);
                if ($fnd) {
                    $connectionState = $this->MapConnectionState($chargingConnectionStatus);
                    $this->SendDebug(__FUNCTION__, '... ConnectionState (recharge-status:data.chargingConnectionStatus.value)=' . $chargingConnectionStatus . '/' . $connectionState, 0);
                    $this->SaveValue('ConnectionState', $connectionState, $chg);
                }

                $chargingSystemStatus = $this->GetArrayElem($recharge_status, 'data.chargingSystemStatus.value', '', $fnd);
                if ($fnd) {
                    $chargingState = $this->MapChargingState($chargingSystemStatus);
                    $this->SendDebug(__FUNCTION__, '... ChargingState (recharge-status:data.chargingSystemStatus.value)=' . $chargingSystemStatus . '/' . $chargingState, 0);
                    $this->SaveValue('ChargingState', $chargingState, $chg);
                }

                $estimatedChargingTime = $this->GetArrayElem($recharge_status, 'data.estimatedChargingTime.value', 0, $fnd); // minutes
                if ($fnd) {
                    $this->SendDebug(__FUNCTION__, '... EstimatedChargingTime (recharge-status:data.estimatedChargingTime.value)=' . $estimatedChargingTime, 0);
                    $this->SaveValue('EstimatedChargingTime', $estimatedChargingTime, $chg);
                }
            }
        }

        $location = $this->GetApiLocation('location');
        if ($location != false) {
            $this->SendDebug(__FUNCTION__, 'location=' . print_r($location, true), 0);

            $longitude = $this->GetArrayElem($location, 'data.geometry.coordinates.0', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... CurrentLongitude (location:data.geometry.coordinates.0)=' . $longitude, 0);
                $this->SaveValue('CurrentLongitude', $longitude, $chg);
            }

            $latitude = $this->GetArrayElem($location, 'data.geometry.coordinates.1', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... CurrentLatitude (location:data.geometry.coordinates.1)=' . $latitude, 0);
                $this->SaveValue('CurrentLatitude', $latitude, $chg);
            }

            $altitude = $this->GetArrayElem($location, 'data.geometry.coordinates.2', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... CurrentAltitude (location:data.geometry.coordinates.0)=' . $altitude, 0);
                $this->SaveValue('CurrentAltitude', $altitude, $chg);
            }

            $heading = $this->GetArrayElem($location, 'data.properties.heading', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... CurrentDirection (location:data.properties.heading)=' . $heading, 0);
                $this->SaveValue('CurrentDirection', $heading, $chg);
            }

            $timestamp = $this->GetArrayElem($location, 'data.properties.timestamp', '', $fnd);
            if ($fnd) {
                $ts = strtotime($timestamp);
                $this->SendDebug(__FUNCTION__, '... LastPositionMessage (location:data.properties.timestamp)=' . $timestamp . '/' . date('d.m.Y H:i:s', $ts), 0);
                $this->SaveValue('LastPositionMessage', $ts, $chg);
            }
        }

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, '... finished in ' . $duration . 's', 0);

        $this->SetUpdateInterval();
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'UpdateStatus':
                $this->UpdateStatus();
                break;
            case 'LockDoors':
                $r = $this->LockDoors();
                break;
            case 'UnlockDoors':
                $r = $this->UnlockDoors();
                break;
            case 'StartClimatization':
                $r = $this->StartClimatization();
                break;
            case 'StopClimatization':
                $r = $this->StopClimatization();
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

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $r = false;
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    private function GetApiConnectedVehicle($detail = '')
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $vin = $this->ReadPropertyString('vin');

        $SendData = [
            'DataID'   => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
            'CallerID' => $this->InstanceID,
            'Function' => 'GetApiConnectedVehicle',
            'vin'      => $vin,
            'detail'   => $detail,
        ];
        $data = $this->SendDataToParent(json_encode($SendData));
        $this->SendDebug(__FUNCTION__, 'SendData=' . json_encode($SendData) . ', data=' . $data, 0);
        $jdata = @json_decode($data, true);
        return $jdata;
    }

    public function LockDoors()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        $this->SetValue('LockDoors', self::$VOLVO_TRIGGER_COMMAND_PENDING);

        $this->SendDebug(__FUNCTION__, 'call api ...', 0);
        $r = $this->PostApiConnectedVehicle('commands/lock', []);
        $this->SendDebug(__FUNCTION__, 'r=' . print_r($r, true), 0);

        $invokeStatus = $this->GetArrayElem($r, 'data.invokeStatus', '');
        $s = $this->Translate('Lock doors') . ': ' . $this->Translate($invokeStatus);
        $message = $this->GetArrayElem($r, 'data.message', '');
        if ($message != '') {
            $s .= ' (' . $message . ')';
        }
        $this->SetValue('LastCommand', $s);
        $this->SetValue('LockDoors', self::$VOLVO_TRIGGER_COMMAND_EXECUTE);
        if ($invokeStatus == 'COMPLETED') {
            $this->SetValue('CentralLockState', self::$VOLVO_CENTRALLOCK_STATE_LOCKED);
        }

        return true;
    }

    public function UnlockDoors()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        $this->SetValue('UnlockDoors', self::$VOLVO_TRIGGER_COMMAND_PENDING);

        $this->SendDebug(__FUNCTION__, 'call api ...', 0);
        $r = $this->PostApiConnectedVehicle('commands/unlock', ['unlockDuration' => 120]);
        $this->SendDebug(__FUNCTION__, 'r=' . print_r($r, true), 0);

        $invokeStatus = $this->GetArrayElem($r, 'data.invokeStatus', '');
        $s = $this->Translate('Unlock doors') . ': ' . $this->Translate($invokeStatus);
        $message = $this->GetArrayElem($r, 'data.message', '');
        if ($message != '') {
            $s .= ' (' . $message . ')';
        }
        $this->SetValue('LastCommand', $s);
        $this->SetValue('UnlockDoors', self::$VOLVO_TRIGGER_COMMAND_EXECUTE);
        if ($invokeStatus == 'COMPLETED') {
            $this->SetValue('CentralLockState', self::$VOLVO_CENTRALLOCK_STATE_UNLOCKED);
        }

        return true;
    }

    public function StartClimatization()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        $this->SetValue('StartClimatization', self::$VOLVO_TRIGGER_COMMAND_PENDING);

        $this->SendDebug(__FUNCTION__, 'call api ...', 0);
        $r = $this->PostApiConnectedVehicle('commands/climatization-start', []);
        $this->SendDebug(__FUNCTION__, 'r=' . print_r($r, true), 0);

        $invokeStatus = $this->GetArrayElem($r, 'data.invokeStatus', '');
        $s = $this->Translate('Start climatization') . ': ' . $this->Translate($invokeStatus);
        $message = $this->GetArrayElem($r, 'data.message', '');
        if ($message != '') {
            $s .= ' (' . $message . ')';
        }
        $this->SetValue('LastCommand', $s);
        $this->SetValue('StartClimatization', self::$VOLVO_TRIGGER_COMMAND_EXECUTE);

        return true;
    }

    public function StopClimatization()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        $this->SetValue('StopClimatization', self::$VOLVO_TRIGGER_COMMAND_PENDING);

        $this->SendDebug(__FUNCTION__, 'call api ...', 0);
        $r = $this->PostApiConnectedVehicle('commands/climatization-stop', []);
        $this->SendDebug(__FUNCTION__, 'r=' . print_r($r, true), 0);

        $invokeStatus = $this->GetArrayElem($r, 'data.invokeStatus', '');
        $s = $this->Translate('Stop climatization') . ': ' . $this->Translate($invokeStatus);
        $message = $this->GetArrayElem($r, 'data.message', '');
        if ($message != '') {
            $s .= ' (' . $message . ')';
        }
        $this->SetValue('LastCommand', $s);
        $this->SetValue('StopClimatization', self::$VOLVO_TRIGGER_COMMAND_EXECUTE);

        return true;
    }

    private function PostApiConnectedVehicle($detail = '', $postfields = [])
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $vin = $this->ReadPropertyString('vin');

        $SendData = [
            'DataID'       => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
            'CallerID'     => $this->InstanceID,
            'Function'     => 'PostApiConnectedVehicle',
            'vin'          => $vin,
            'detail'       => $detail,
            'postfields'   => $postfields,
        ];
        $data = $this->SendDataToParent(json_encode($SendData));
        $this->SendDebug(__FUNCTION__, 'SendData=' . json_encode($SendData) . ', data=' . $data, 0);
        $jdata = @json_decode($data, true);
        return $jdata;
    }

    private function GetApiEnergy($detail = '')
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $vin = $this->ReadPropertyString('vin');

        $SendData = [
            'DataID'   => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
            'CallerID' => $this->InstanceID,
            'Function' => 'GetApiEnergy',
            'vin'      => $vin,
            'detail'   => $detail,
        ];
        $data = $this->SendDataToParent(json_encode($SendData));
        $this->SendDebug(__FUNCTION__, 'SendData=' . json_encode($SendData) . ', data=' . $data, 0);
        $jdata = @json_decode($data, true);
        return $jdata;
    }

    private function GetApiLocation($detail = '')
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $vin = $this->ReadPropertyString('vin');

        $SendData = [
            'DataID'   => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
            'CallerID' => $this->InstanceID,
            'Function' => 'GetApiLocation',
            'vin'      => $vin,
            'detail'   => $detail,
        ];
        $data = $this->SendDataToParent(json_encode($SendData));
        $this->SendDebug(__FUNCTION__, 'SendData=' . json_encode($SendData) . ', data=' . $data, 0);
        $jdata = @json_decode($data, true);
        return $jdata;
    }

    private function GetApiExtendedVehicle($detail = '')
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $vin = $this->ReadPropertyString('vin');

        $SendData = [
            'DataID'   => '{83DF672B-CA66-5372-A632-E9A5406332A7}', // an VolvoIO
            'CallerID' => $this->InstanceID,
            'Function' => 'GetApiExtendedVehicle',
            'vin'      => $vin,
            'detail'   => $detail,
        ];
        $data = $this->SendDataToParent(json_encode($SendData));
        $this->SendDebug(__FUNCTION__, 'SendData=' . json_encode($SendData) . ', data=' . $data, 0);
        $jdata = @json_decode($data, true);
        return $jdata;
    }
}

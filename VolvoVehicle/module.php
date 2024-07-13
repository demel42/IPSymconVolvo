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

        /*
        $active_googlemap = $this->ReadPropertyBoolean('active_googlemap');
        $active_current_position = $this->ReadPropertyBoolean('active_current_position');
        if ($active_googlemap == true && $active_current_position == false) {
            $this->SendDebug(__FUNCTION__, '"active_googlemap" needs "active_current_position"', 0);
            $r[] = $this->Translate('Show position in Map need saving position');
        }

        $api_key = $this->ReadPropertyString('googlemap_api_key');
        if ($active_googlemap == true && $api_key == false) {
            $this->SendDebug(__FUNCTION__, '"active_googlemap" needs "api_key"', 0);
            $r[] = $this->Translate('Show position in GoogleMap need the API-Key');
        }
         */

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

        $vpos = 1;

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
            'suffix'  => 'Seconds',
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

    private function SetUpdateInterval(int $sec = null)
    {
        if ($sec == '') {
            $sec = $this->ReadPropertyInteger('update_interval');
        }
        $this->MaintainTimer('UpdateStatus', $sec * 1000);
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

        $this->SendDebug(__FUNCTION__, 'start ...', 0);
        $time_start = microtime(true);

        $vehicle = $this->GetApiConnectedVehicle();
        if ($vehicle != false) {
            $this->SendDebug(__FUNCTION__, 'vehicle=' . print_r($vehicle, true), 0);
            $model = $this->GetArrayElem($vehicle, 'data.descriptions.model', '');
            $year = $this->GetArrayElem($vehicle, 'data.modelYear', '');
            $summary = $model . ' (' . $year . ')';
            $this->SetSummary($summary);
        }

        $data = $this->GetApiConnectedVehicle('engine');
        $data = $this->GetApiConnectedVehicle('diagnostics');
        $data = $this->GetApiConnectedVehicle('brakes');
        $data = $this->GetApiConnectedVehicle('windows');
        $data = $this->GetApiConnectedVehicle('doors');
        $data = $this->GetApiConnectedVehicle('fuel');
        $data = $this->GetApiConnectedVehicle('engine-status');
        $data = $this->GetApiConnectedVehicle('odometer');
        $data = $this->GetApiConnectedVehicle('statistics');
        $data = $this->GetApiConnectedVehicle('tyres');
        $data = $this->GetApiConnectedVehicle('warnings');

        $data = $this->GetApiEnergy('recharge-status');

        $data = $this->GetApiLocation('location');

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
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
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
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
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
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
        return $jdata;
    }
}

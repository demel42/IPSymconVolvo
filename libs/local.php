<?php

declare(strict_types=1);

trait VolvoLocalLib
{
    public static $IS_UNAUTHORIZED = IS_EBASE + 10;
    public static $IS_FORBIDDEN = IS_EBASE + 11;
    public static $IS_SERVERERROR = IS_EBASE + 12;
    public static $IS_HTTPERROR = IS_EBASE + 13;
    public static $IS_NOLOGIN = IS_EBASE + 14;
    public static $IS_INVALIDDATA = IS_EBASE + 15;
    public static $IS_APIERROR = IS_EBASE + 16;

    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        $formStatus[] = ['code' => self::$IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (unauthorized)'];
        $formStatus[] = ['code' => self::$IS_FORBIDDEN, 'icon' => 'error', 'caption' => 'Instance is inactive (forbidden)'];
        $formStatus[] = ['code' => self::$IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (server error)'];
        $formStatus[] = ['code' => self::$IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (http error)'];
        $formStatus[] = ['code' => self::$IS_NOLOGIN, 'icon' => 'error', 'caption' => 'Instance is inactive (not logged in)'];
        $formStatus[] = ['code' => self::$IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid data)'];
        $formStatus[] = ['code' => self::$IS_APIERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (api error)'];

        return $formStatus;
    }

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            case self::$IS_UNAUTHORIZED:
            case self::$IS_FORBIDDEN:
            case self::$IS_SERVERERROR:
            case self::$IS_HTTPERROR:
            case self::$IS_INVALIDDATA:
                $class = self::$STATUS_RETRYABLE;
                break;
            case self::$IS_NOLOGIN:
                @$connection_type = $this->ReadPropertyInteger('connection_type');
                // bei Entwicklerschlüssel macht das Modul das Login selber
                $class = $connection_type == self::$CONNECTION_DEVELOPER ? self::$STATUS_RETRYABLE : self::$STATUS_INVALID;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    // Authentifizierungs-Methode
    public static $CONNECTION_UNDEFINED = 0;
    public static $CONNECTION_OAUTH = 1;
    public static $CONNECTION_DEVELOPER = 2;

    // Antriebsart
    private static $VOLVO_DRIVE_TYPE_UNKNOWN = 0;
    private static $VOLVO_DRIVE_TYPE_ELECTRIC = 1;
    private static $VOLVO_DRIVE_TYPE_HYBRID = 2;
    private static $VOLVO_DRIVE_TYPE_COMBUSTION = 3;

    // Motor-Status
    private static $VOLVO_ENGINE_STATE_UNKNOWN = 0;
    private static $VOLVO_ENGINE_STATE_STOPPED = 1;
    private static $VOLVO_ENGINE_STATE_RUNNING = 2;

    // Ladekabel/Stecker
    private static $VOLVO_CONNECTION_STATE_UNSPECIFIED = 0;
    private static $VOLVO_CONNECTION_STATE_CONNECTED_AC = 1;
    private static $VOLVO_CONNECTION_STATE_CONNECTED_DC = 2;
    private static $VOLVO_CONNECTION_STATE_DISCONNECTED = 3;
    private static $VOLVO_CONNECTION_STATE_FAULT = 4;

    // Ladezustand
    private static $VOLVO_CHARGING_STATE_UNSPECIFIED = 0;
    private static $VOLVO_CHARGING_STATE_IDLE = 1;
    private static $VOLVO_CHARGING_STATE_SCHEDULED = 2;
    private static $VOLVO_CHARGING_STATE_CHARGING = 3;
    private static $VOLVO_CHARGING_STATE_DONE = 4;
    private static $VOLVO_CHARGING_STATE_FAULT = 5;

    // Zentralverriegelung
    private static $VOLVO_CENTRALLOCK_STATE_UNKNOWN = 0;
    private static $VOLVO_CENTRALLOCK_STATE_UNLOCKED = 1;
    private static $VOLVO_CENTRALLOCK_STATE_LOCKED = 2;

    // Tür
    private static $VOLVO_DOOR_STATE_UNKNOWN = 0;
    private static $VOLVO_DOOR_STATE_OPEN = 1;
    private static $VOLVO_DOOR_STATE_CLOSED = 2;
    private static $VOLVO_DOOR_STATE_AJAR = 3;

    // Fenster
    private static $VOLVO_WINDOW_STATE_UNKNOWN = 0;
    private static $VOLVO_WINDOW_STATE_OPEN = 1;
    private static $VOLVO_WINDOW_STATE_CLOSED = 2;
    private static $VOLVO_WINDOW_STATE_AJAR = 3;

    // Reifen
    private static $VOLVO_TYRE_STATE_UNSPECIFIED = 0;
    private static $VOLVO_TYRE_STATE_NO_WARNING = 1;
    private static $VOLVO_TYRE_STATE_VERY_LOW_PRESSURE = 2;
    private static $VOLVO_TYRE_STATE_LOW_PRESSURE = 3;
    private static $VOLVO_TYRE_STATE_HIGH_PRESSURE = 4;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);

        $associations = [
            ['Wert' => false, 'Name' => $this->Translate('No'), 'Farbe' => -1],
            ['Wert' => true, 'Name' => $this->Translate('Yes'), 'Farbe' => 0xEE0000],
        ];
        $this->CreateVarProfile('Volvo.Failure', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$VOLVO_ENGINE_STATE_UNKNOWN, 'Name' => $this->Translate('unknown'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$VOLVO_ENGINE_STATE_STOPPED, 'Name' => $this->Translate('stopped'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_ENGINE_STATE_RUNNING, 'Name' => $this->Translate('running'), 'Farbe' => 0x228B22],
        ];
        $this->CreateVarProfile('Volvo.EngineState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$VOLVO_CONNECTION_STATE_UNSPECIFIED, 'Name' => $this->Translate('unspecified'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$VOLVO_CONNECTION_STATE_CONNECTED_AC, 'Name' => $this->Translate('connected AC'), 'Farbe' => 0x228B22],
            ['Wert' => self::$VOLVO_CONNECTION_STATE_CONNECTED_DC, 'Name' => $this->Translate('connected DC'), 'Farbe' => 0x228B22],
            ['Wert' => self::$VOLVO_CONNECTION_STATE_DISCONNECTED, 'Name' => $this->Translate('disconnected'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_CONNECTION_STATE_FAULT, 'Name' => $this->Translate('fault'), 'Farbe' => 0xEE0000],
        ];
        $this->CreateVarProfile('Volvo.ConnectionState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$VOLVO_CHARGING_STATE_UNSPECIFIED, 'Name' => $this->Translate('unspecified'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$VOLVO_CHARGING_STATE_IDLE, 'Name' => $this->Translate('idle'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_CHARGING_STATE_SCHEDULED, 'Name' => $this->Translate('scheduled'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_CHARGING_STATE_CHARGING, 'Name' => $this->Translate('charging'), 'Farbe' => 0x228B22],
            ['Wert' => self::$VOLVO_CHARGING_STATE_DONE, 'Name' => $this->Translate('done'), 'Farbe' => 0x0000FF],
            ['Wert' => self::$VOLVO_CHARGING_STATE_FAULT, 'Name' => $this->Translate('fault'), 'Farbe' => 0xEE0000],
        ];
        $this->CreateVarProfile('Volvo.ChargingState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$VOLVO_CENTRALLOCK_STATE_UNKNOWN, 'Name' => $this->Translate('unknown'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_CENTRALLOCK_STATE_UNLOCKED, 'Name' => $this->Translate('unlocked'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_CENTRALLOCK_STATE_LOCKED, 'Name' => $this->Translate('locked'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('Volvo.CentralLockState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$VOLVO_DOOR_STATE_UNKNOWN, 'Name' => $this->Translate('unknown'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_DOOR_STATE_OPEN, 'Name' => $this->Translate('open'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_DOOR_STATE_CLOSED, 'Name' => $this->Translate('closed'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_DOOR_STATE_AJAR, 'Name' => $this->Translate('ajar'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('Volvo.DoorState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$VOLVO_WINDOW_STATE_UNKNOWN, 'Name' => $this->Translate('unknown'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_WINDOW_STATE_OPEN, 'Name' => $this->Translate('open'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_WINDOW_STATE_CLOSED, 'Name' => $this->Translate('closed'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_WINDOW_STATE_AJAR, 'Name' => $this->Translate('ajar'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('Volvo.WindowState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$VOLVO_TYRE_STATE_UNSPECIFIED, 'Name' => $this->Translate('unknown'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_TYRE_STATE_NO_WARNING, 'Name' => $this->Translate('ok'), 'Farbe' => -1],
            ['Wert' => self::$VOLVO_TYRE_STATE_VERY_LOW_PRESSURE, 'Name' => $this->Translate('very low pressure'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$VOLVO_TYRE_STATE_LOW_PRESSURE, 'Name' => $this->Translate('low pressure'), 'Farbe' => 0xEE0000],
            ['Wert' => self::$VOLVO_TYRE_STATE_HIGH_PRESSURE, 'Name' => $this->Translate('high pressure'), 'Farbe' => 0xEE0000],
        ];
        $this->CreateVarProfile('Volvo.TyreState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $this->CreateVarProfile('Volvo.Mileage', VARIABLETYPE_INTEGER, ' km', 0, 0, 0, 0, 'Distance', '', $reInstall);
        $this->CreateVarProfile('Volvo.FuelAmount', VARIABLETYPE_FLOAT, ' l', 0, 0, 0, 0, 'Gauge', '', $reInstall);
        $this->CreateVarProfile('Volvo.Range', VARIABLETYPE_FLOAT, ' km', 0, 0, 0, 0, 'Gauge', '', $reInstall);

        $this->CreateVarProfile('Volvo.Location', VARIABLETYPE_FLOAT, ' °', 0, 0, 0, 5, 'Car', '', $reInstall);
        $this->CreateVarProfile('Volvo.Altitude', VARIABLETYPE_INTEGER, ' m', 0, 360, 0, 0, 'WindDirection', '', $reInstall);
        $this->CreateVarProfile('Volvo.Heading', VARIABLETYPE_INTEGER, ' °', 0, 360, 0, 0, 'WindDirection', '', $reInstall);

        $this->CreateVarProfile('Volvo.BatteryCapacity', VARIABLETYPE_FLOAT, ' kWh', 0, 0, 0, 1, '', '', $reInstall);
        $this->CreateVarProfile('Volvo.BatteryChargeLevel', VARIABLETYPE_FLOAT, ' %', 0, 0, 0, 0, '', '', $reInstall);

        $this->CreateVarProfile('Volvo.Minutes', VARIABLETYPE_INTEGER, ' min', 0, 0, 0, 0, '', '', $reInstall);

        $this->CreateVarProfile('Volvo.FuelConsumption', VARIABLETYPE_FLOAT, ' l/100km', 0, 0, 0, 1, 'Gauge', '', $reInstall);
        $this->CreateVarProfile('Volvo.EnergyConsumption', VARIABLETYPE_FLOAT, ' kWh/100km', 0, 0, 0, 1, 'Gauge', '', $reInstall);
        $this->CreateVarProfile('Volvo.Speed', VARIABLETYPE_FLOAT, ' km/h', 0, 0, 0, 0, 'Gauge', '', $reInstall);
        $this->CreateVarProfile('Volvo.Distance', VARIABLETYPE_FLOAT, ' km', 0, 0, 0, 1, 'Distance', '', $reInstall);
    }

    private function DriveTypeMapping()
    {
        return [
            self::$VOLVO_DRIVE_TYPE_ELECTRIC => [
                'caption' => 'electric',
            ],
            self::$VOLVO_DRIVE_TYPE_HYBRID => [
                'caption' => 'hybrid',
            ],
            self::$VOLVO_DRIVE_TYPE_COMBUSTION => [
                'caption' => 'combustion',
            ],
        ];
    }

    private function DriveTypeAsOptions()
    {
        $maps = $this->DriveTypeMapping();
        $opts = [];
        foreach ($maps as $u => $e) {
            $opts[] = [
                'caption' => $e['caption'],
                'value'   => $u,
            ];
        }
        return $opts;
    }

    private function DriveType2String($driveType)
    {
        $maps = $this->DriveTypeMapping();
        if (isset($maps[$driveType])) {
            $ret = $this->Translate($maps[$driveType]['caption']);
        } else {
            $ret = $this->Translate('Unknown drive type') . ' ' . $driveType;
        }
        return $ret;
    }

    private function MapEngineState($s)
    {
        $str2enum = [
            'UNKNOWN' => self::$VOLVO_ENGINE_STATE_UNKNOWN,
            'STOPPED' => self::$VOLVO_ENGINE_STATE_STOPPED,
            'RUNNING' => self::$VOLVO_ENGINE_STATE_UNKNOWN,
        ];

        if (isset($str2enum[$s])) {
            $e = $str2enum[$s];
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown value "' . $s . '"', 0);
            $e = self::$VOLVO_ENGINE_STATE_UNKNOWN;
        }
        return $e;
    }

    private function MapConnectionState($s)
    {
        $str2enum = [
            'CONNECTION_STATUS_UNSPECIFIED'  => self::$VOLVO_CONNECTION_STATE_UNSPECIFIED,
            'CONNECTION_STATUS_CONNECTED_AC' => self::$VOLVO_CONNECTION_STATE_CONNECTED_AC,
            'CONNECTION_STATUS_CONNECTED_DC' => self::$VOLVO_CONNECTION_STATE_CONNECTED_DC,
            'CONNECTION_STATUS_DISCONNECTED' => self::$VOLVO_CONNECTION_STATE_DISCONNECTED,
            'CONNECTION_STATUS_FAULT'        => self::$VOLVO_CONNECTION_STATE_FAULT,
        ];

        if (isset($str2enum[$s])) {
            $e = $str2enum[$s];
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown value "' . $s . '"', 0);
            $e = self::$VOLVO_CONNECTION_STATE_UNSPECIFIED;
        }
        return $e;
    }

    private function MapChargingState($s)
    {
        $str2enum = [
            'CHARGING_SYSTEM_UNSPECIFIED' => self::$VOLVO_CHARGING_STATE_UNSPECIFIED,
            'CHARGING_SYSTEM_IDLE'        => self::$VOLVO_CHARGING_STATE_IDLE,
            'CHARGING_SYSTEM_SCHEDULED'   => self::$VOLVO_CHARGING_STATE_SCHEDULED,
            'CHARGING_SYSTEM_CHARGING'    => self::$VOLVO_CHARGING_STATE_CHARGING,
            'CHARGING_SYSTEM_DONE'        => self::$VOLVO_CHARGING_STATE_DONE,
            'CHARGING_SYSTEM_FAULT'       => self::$VOLVO_CHARGING_STATE_FAULT,
        ];

        if (isset($str2enum[$s])) {
            $e = $str2enum[$s];
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown value "' . $s . '"', 0);
            $e = self::$VOLVO_CHARGING_STATE_UNSPECIFIED;
        }
        return $e;
    }

    private function MapCentralLockState($s)
    {
        $str2enum = [
            'UNKNOWN'  => self::$VOLVO_CENTRALLOCK_STATE_UNKNOWN,
            'UNLOCKED' => self::$VOLVO_CENTRALLOCK_STATE_UNLOCKED,
            'LOCKED'   => self::$VOLVO_CENTRALLOCK_STATE_LOCKED,
        ];

        if (isset($str2enum[$s])) {
            $e = $str2enum[$s];
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown value "' . $s . '"', 0);
            $e = self::$VOLVO_CENTRALLOCK_STATE_UNKNOWN;
        }
        return $e;
    }

    private function MapDoorState($s)
    {
        $str2enum = [
            'UNKNOWN' => self::$VOLVO_DOOR_STATE_UNKNOWN,
            'OPEN'    => self::$VOLVO_DOOR_STATE_OPEN,
            'CLOSED'  => self::$VOLVO_DOOR_STATE_CLOSED,
            'AJAR'    => self::$VOLVO_DOOR_STATE_AJAR,
        ];

        if (isset($str2enum[$s])) {
            $e = $str2enum[$s];
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown value "' . $s . '"', 0);
            $e = self::$VOLVO_DOOR_STATE_UNKNOWN;
        }
        return $e;
    }

    private function MapWindowState($s)
    {
        $str2enum = [
            'UNKNOWN' => self::$VOLVO_WINDOW_STATE_UNKNOWN,
            'OPEN'    => self::$VOLVO_WINDOW_STATE_OPEN,
            'CLOSED'  => self::$VOLVO_WINDOW_STATE_CLOSED,
            'AJAR'    => self::$VOLVO_WINDOW_STATE_AJAR,
        ];

        if (isset($str2enum[$s])) {
            $e = $str2enum[$s];
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown value "' . $s . '"', 0);
            $e = self::$VOLVO_WINDOW_STATE_UNKNOWN;
        }
        return $e;
    }

    private function MapTyreState($s)
    {
        $str2enum = [
            'UNSPECIFIED'       => self::$VOLVO_TYRE_STATE_UNSPECIFIED,
            'NO_WARNING'        => self::$VOLVO_TYRE_STATE_NO_WARNING,
            'VERY_LOW_PRESSURE' => self::$VOLVO_TYRE_STATE_VERY_LOW_PRESSURE,
            'LOW_PRESSURE'      => self::$VOLVO_TYRE_STATE_LOW_PRESSURE,
            'HIGH_PRESSURE'     => self::$VOLVO_TYRE_STATE_HIGH_PRESSURE,
        ];

        if (isset($str2enum[$s])) {
            $e = $str2enum[$s];
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown value "' . $s . '"', 0);
            $e = self::$VOLVO_TYRE_STATE_UNSPECIFIED;
        }
        return $e;
    }
}

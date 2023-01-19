<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VolvoIO extends IPSModule
{
    use Volvo\StubsCommonLib;
    use VolvoLocalLib;

    private $oauthIdentifer = 'volvo';

    private static $user_agent = 'okhttp/4.10.0';

    private static $authorization_token = 'aDRZZjBiOlU4WWtTYlZsNnh3c2c1WVFxWmZyZ1ZtSWFEcGhPc3kxUENhVXNpY1F0bzNUUjVrd2FKc2U0QVpkZ2ZJZmNMeXc=';
    private static $access_token_manager_id = 'JWTh4Yf0b';

    private static $scopes = [
        'openid',
        'email',
        'profile',
        'care_by_volvo:financial_information:invoice:read',
        'care_by_volvo:financial_information:payment_method',
        'care_by_volvo:subscription:read',
        'customer:attributes',
        'customer:attributes:write',
        'order:attributes',
        'vehicle:attributes',
        'tsp_customer_api:all',
        'conve:brake_status',
        'conve:climatization_start_stop',
        'conve:command_accessibility',
        'conve:commands',
        'conve:diagnostics_engine_status',
        'conve:diagnostics_workshop',
        'conve:doors_status',
        'conve:engine_status',
        'conve:environment',
        'conve:fuel_status',
        'conve:honk_flash',
        'conve:lock',
        'conve:lock_status',
        'conve:navigation',
        'conve:odometer_status',
        'conve:trip_statistics',
        'conve:tyre_status',
        'conve:unlock',
        'conve:vehicle_relation',
        'conve:warnings',
        'conve:windows_status',
        'energy:battery_charge_level',
        'energy:charging_connection_status',
        'energy:charging_system_status',
        'energy:electric_range',
        'energy:estimated_charging_time',
        'energy:recharge_status',
        'vehicle:attributes'
    ];

    private static $semaphoreTM = 5 * 1000;

    private $ModuleDir;
    private $SemaphoreID;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyInteger('connection_type', self::$CONNECTION_UNDEFINED);

        $this->RegisterPropertyString('vcc_api_key', '');

        $this->RegisterPropertyString('username', '');
        $this->RegisterPropertyString('password', '');

        $this->RegisterAttributeString('ApiRefreshToken', json_encode([]));

        $this->RegisterAttributeString('UpdateInfo', '');
        $this->RegisterAttributeString('ApiCallStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->SetBuffer('ApiAccessToken', json_encode([]));
        $this->SetBuffer('ConnectionType', '');
        $this->SetBuffer('LastApiCall', 0);

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $connection_type = $this->ReadPropertyInteger('connection_type');
        switch ($connection_type) {
            case self::$CONNECTION_UNDEFINED:
                $this->SendDebug(__FUNCTION__, '"connection_type" must be selected', 0);
                $r[] = $this->Translate('Connection type must be set');
                break;
            case self::$CONNECTION_DEVELOPER:
                $vcc_api_key = $this->ReadPropertyString('vcc_api_key');
                if ($vcc_api_key == '') {
                    $this->SendDebug(__FUNCTION__, '"vcc_api_key" is needed', 0);
                    $r[] = $this->Translate('\'VCC api key\' must be specified');
                }
                $username = $this->ReadPropertyString('username');
                $password = $this->ReadPropertyString('password');
                if ($username == '' || $password == '') {
                    $this->SendDebug(__FUNCTION__, '"username" and/or "password" is empty', 0);
                    $r[] = $this->Translate('Username and password of the Volvo account are required');
                }
                break;
            default:
                break;
        }

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainReferences();

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

        $apiLimits = [
            [
                'value' => 10000,
                'unit'  => 'day',
            ],
        ];
        $this->ApiCallsSetInfo($apiLimits, '');

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $connection_type = $this->ReadPropertyInteger('connection_type');
        if ($this->GetBuffer('ConnectionType') != $connection_type) {
            if ($this->GetBuffer('ConnectionType') == '') {
                $this->ClearToken();
            }
            $this->SetBuffer('ConnectionType', $connection_type);
        }

        $this->MaintainStatus(IS_ACTIVE);

        if ($connection_type == self::$CONNECTION_OAUTH) {
            if ($this->GetConnectUrl() == false) {
                $this->MaintainStatus(self::$IS_NOSYMCONCONNECT);
                return;
            }
            if (IPS_GetKernelRunlevel() == KR_READY) {
                $this->RegisterOAuth($this->oauthIdentifer);
            }
            $refresh_token = $this->ReadAttributeString('ApiRefreshToken');
            if ($refresh_token == '') {
                $this->MaintainStatus(self::$IS_NOLOGIN);
                return;
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $connection_type = $this->ReadPropertyInteger('connection_type');
            if ($connection_type == self::$CONNECTION_OAUTH) {
                $this->RegisterOAuth($this->oauthIdentifer);
            }
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Volvo I/O');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $connection_type = $this->ReadPropertyInteger('connection_type');
        if ($connection_type == self::$CONNECTION_OAUTH) {
            $formElements[] = [
                'type'    => 'Label',
                'caption' => $this->GetConnectStatusText(),
            ];
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'    => 'Select',
            'name'    => 'connection_type',
            'caption' => 'Connection type',
            'options' => [
                [
                    'caption' => 'Please select a connection type',
                    'value'   => self::$CONNECTION_UNDEFINED
                ],
                [
                    'caption' => 'via IP-Symcon Connect',
                    'value'   => self::$CONNECTION_OAUTH
                ],
                [
                    'caption' => 'with Volvo developer key',
                    'value'   => self::$CONNECTION_DEVELOPER
                ]
            ]
        ];

        switch ($connection_type) {
            case self::$CONNECTION_OAUTH:
                $formElements[] = [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Volvo Login',
                    'items'   => [
                        [
                            'type'    => 'Label',
                            'caption' => 'Push "Login at Volvo" in the action part of this configuration form.'
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'At the webpage from Volvo log in with your Husqvarna username and password.'
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'If the connection to IP-Symcon was successfull you get the message: "Volvo successfully connected!". Close the browser window.'
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Return to this configuration form.'
                        ],
                    ],
                ];
                break;
            case self::$CONNECTION_DEVELOPER:
                $formElements[] = [
                    'type'    => 'ExpansionPanel',
                    'items'   => [
                        [
                            'name'    => 'vcc_api_key',
                            'type'    => 'ValidationTextBox',
                            'width'   => '400px',
                            'caption' => 'VCC api key'
                        ],
                        [
                            'name'    => 'username',
                            'type'    => 'ValidationTextBox',
                            'caption' => 'Username'
                        ],
                        [
                            'name'    => 'password',
                            'type'    => 'PasswordTextBox',
                            'caption' => 'Password'
                        ],
                    ],
                    'caption' => 'Account data',
                ];
                break;
        }

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

        $connection_type = $this->ReadPropertyInteger('connection_type');
        if ($connection_type == self::$CONNECTION_OAUTH) {
            $formActions[] = [
                'type'    => 'Button',
                'caption' => 'Login at Volvo',
                'onClick' => 'echo "' . $this->Login() . '";',
            ];
        }

        $formActions[] = [
            'type'    => 'Button',
            'label'   => 'Test access',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "TestAccess", "");',
        ];

        $formActions[] = [
            'type'      => 'ExpansionPanel',
            'caption'   => 'Expert area',
            'expanded'  => false,
            'items'     => [
                $this->GetInstallVarProfilesFormItem(),
                [
                    'type'    => 'Button',
                    'label'   => 'Clear token',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "ClearToken", "");',
                ],
                $this->GetApiCallStatsFormItem(),
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'TestAccess':
                $this->TestAccess();
                break;
            case 'ClearToken':
                $this->ClearToken();
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
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }
        $r = false;
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident "' . $ident . '"', 0);
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
    }

    private function Login()
    {
        $url = 'https://oauth.ipmagic.de/authorize/' . $this->oauthIdentifer . '?username=' . urlencode(IPS_GetLicensee());
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    protected function ProcessOAuthData()
    {
        if (isset($_GET['code']) == false) {
            $this->SendDebug(__FUNCTION__, 'code missing, _GET=' . print_r($_GET, true), 0);
            $this->WriteAttributeString('ApiRefreshToken', json_encode([]));
            $this->SetBuffer('ApiAccessToken', json_encode([]));
            $this->MaintainStatus(self::$IS_NOLOGIN);
            return;
        }

        $code = $_GET['code'];
        $this->SendDebug(__FUNCTION__, 'code=' . $code, 0);

        $jdata = $this->Call4ApiAccessToken(['code' => $code]);
        if ($jdata == false) {
            $this->SendDebug(__FUNCTION__, 'got no token', 0);
            $this->WriteAttributeString('ApiRefreshToken', json_encode([]));
            $this->SetBuffer('ApiAccessToken', json_encode([]));
            $this->MaintainStatus(self::$IS_NOLOGIN);
            return false;
        }

        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
        $access_token = $jdata['access_token'];
        $expiration = time() + $jdata['expires_in'];
        $jtoken = [
            'access_token' => $access_token,
            'expiration'   => $expiration,
        ];
        $this->SetBuffer('ApiAccessToken', json_encode($jtoken));
        $this->SendDebug(__FUNCTION__, 'new access_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);

        $refresh_token = $jdata['refresh_token'];
        $expiration = time() + (7 * 24 * 60 * 60);
        $jtoken = [
            'refresh_token' => $refresh_token,
            'expiration'    => $expiration,
        ];
        $this->WriteAttributeString('ApiRefreshToken', json_encode($jtoken));
        $this->SendDebug(__FUNCTION__, 'new refresh_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);

        if ($this->GetStatus() == self::$IS_NOLOGIN) {
            $this->MaintainStatus(IS_ACTIVE);
        }
    }

    protected function Call4ApiAccessToken($content)
    {
        $url = 'https://oauth.ipmagic.de/access_token/' . $this->oauthIdentifer;
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '    content=' . print_r($content, true), 0);

        $statuscode = 0;
        $err = '';
        $jdata = false;

        $time_start = microtime(true);
        $options = [
            'http' => [
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($content)
            ]
        ];
        $context = stream_context_create($options);
        $cdata = @file_get_contents($url, false, $context);
        $duration = round(microtime(true) - $time_start, 2);
        $httpcode = 0;
        if ($cdata == false) {
            $this->LogMessage('file_get_contents() failed: url=' . $url . ', context=' . print_r($context, true), KL_WARNING);
            $this->SendDebug(__FUNCTION__, 'file_get_contents() failed: url=' . $url . ', context=' . print_r($context, true), 0);
        } elseif (isset($http_response_header[0]) && preg_match('/HTTP\/[0-9\.]+\s+([0-9]*)/', $http_response_header[0], $r)) {
            $httpcode = $r[1];
        } else {
            $this->LogMessage('missing http_response_header, cdata=' . $cdata, KL_WARNING);
            $this->SendDebug(__FUNCTION__, 'missing http_response_header, cdata=' . $cdata, 0);
        }
        $this->SendDebug(__FUNCTION__, ' => httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, '    cdata=' . $cdata, 0);

        if ($httpcode != 200) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_FORBIDDEN;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode == 409) {
                $data = $cdata;
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } else {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        } elseif ($cdata == '') {
            $statuscode = self::$IS_INVALIDDATA;
            $err = 'no data';
        } else {
            $jdata = json_decode($cdata, true);
            if ($jdata == '') {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'malformed response';
            } else {
                if (!isset($jdata['refresh_token'])) {
                    $statuscode = self::$IS_INVALIDDATA;
                    $err = 'malformed response';
                }
            }
        }
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }
        return $jdata;
    }

    private function build_url($url, $params)
    {
        $n = 0;
        if (is_array($params)) {
            foreach ($params as $param => $value) {
                $url .= ($n++ ? '&' : '?') . $param . '=' . rawurlencode(strval($value));
            }
        }
        return $url;
    }

    private function build_header($headerfields)
    {
        $header = [];
        foreach ($headerfields as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $header;
    }

    private function RefreshAccessToken()
    {
        $jtoken = @json_decode($this->ReadAttributeString('ApiRefreshToken'), true);
        if ($jtoken != false) {
            $this->SendDebug(__FUNCTION__, 'jtoken=' . print_r($jtoken, true), 0);
            $refresh_token = isset($jtoken['refresh_token']) ? $jtoken['refresh_token'] : '';
            $expiration = isset($jtoken['expiration']) ? $jtoken['expiration'] : 0;
            if ($expiration < time()) {
                $this->SendDebug(__FUNCTION__, 'refresh_token expired', 0);
                $refresh_token = '';
            }
            if ($refresh_token != '') {
                $this->SendDebug(__FUNCTION__, 'old refresh_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);
            }
        } else {
            $this->SendDebug(__FUNCTION__, 'no saved refresh_token', 0);
            $refresh_token = '';
        }
        if ($refresh_token == '') {
            return $refresh_token;
        }

        $username = $this->ReadPropertyString('username');
        $password = $this->ReadPropertyString('password');
        $vcc_api_key = $this->ReadPropertyString('vcc_api_key');

        $url = 'https://volvoid.eu.volvocars.com/as/token.oauth2';

        $headerfields = [
            'Authorization' => 'Basic ' . self::$authorization_token,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ];
        $header = $this->build_header($headerfields);

        $postfields = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ];
        $postdata = http_build_query($postfields);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postdata,
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-POST, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... headerfields=' . print_r($headerfields, true), 0);
        $this->SendDebug(__FUNCTION__, '... postfields=' . print_r($postfields, true), 0);

        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, ' => response=' . $response, 0);

        $statuscode = 0;
        $err = '';
        $access_token = '';

        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        }
        if ($statuscode == 0) {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);
            $jbody = json_decode($body, true);
            if ($jbody == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'invalid/malformed data';
            }
        }

        if ($statuscode == self::$IS_UNAUTHORIZED) {
            $this->WriteAttributeString('ApiRefreshToken', json_encode([]));
            $this->SetBuffer('ApiAccessToken', json_encode([]));
        }

        if ($statuscode == 0) {
            if (isset($jbody['access_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"access_token" missing';
            }
        }
        if ($statuscode == 0) {
            if (isset($jbody['refresh_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"refresh_token" missing';
            }
        }
        if ($statuscode == 0) {
            if (isset($jbody['expires_in']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"expires_in" missing';
            }
        }

        if ($statuscode == 0) {
            $access_token = $jbody['access_token'];
            $expiration = time() + $jbody['expires_in'];
            $jtoken = [
                'access_token' => $access_token,
                'expiration'   => $expiration,
            ];
            $this->SetBuffer('ApiAccessToken', json_encode($jtoken));
            $this->SendDebug(__FUNCTION__, 'new access_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);

            $refresh_token = $jbody['refresh_token'];
            $expiration = time() + (7 * 24 * 60 * 60);
            $jtoken = [
                'refresh_token' => $refresh_token,
                'expiration'    => $expiration,
            ];
            $this->WriteAttributeString('ApiRefreshToken', json_encode($jtoken));
            $this->SendDebug(__FUNCTION__, 'new refresh_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);
        }

        $this->ApiCallsCollect($url, $err, $statuscode);

        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return '';
        }

        $this->MaintainStatus(IS_ACTIVE);

        return $access_token;
    }

    private function DeveloperApiAccessToken()
    {
        $jtoken = @json_decode($this->GetBuffer('ApiAccessToken'), true);
        if ($jtoken != false) {
            $this->SendDebug(__FUNCTION__, 'jtoken=' . print_r($jtoken, true), 0);
            $access_token = isset($jtoken['access_token']) ? $jtoken['access_token'] : '';
            $expiration = isset($jtoken['expiration']) ? $jtoken['expiration'] : 0;
            if ($expiration < time()) {
                $this->SendDebug(__FUNCTION__, 'access_token expired', 0);
                $access_token = '';
            }
            if ($access_token != '') {
                $this->SendDebug(__FUNCTION__, 'old access_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);
                return $access_token;
            }
        } else {
            $this->SendDebug(__FUNCTION__, 'no saved access_token', 0);
        }

        $access_token = $this->RefreshAccessToken();
        if ($access_token != '') {
            return $access_token;
        }

        $username = $this->ReadPropertyString('username');
        $password = $this->ReadPropertyString('password');
        $vcc_api_key = $this->ReadPropertyString('vcc_api_key');

        $url = 'https://volvoid.eu.volvocars.com/as/token.oauth2';

        $headerfields = [
            'Authorization' => 'Basic ' . self::$authorization_token,
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'user-agent'    => self::$user_agent,
        ];
        $header = $this->build_header($headerfields);

        $postfields = [
            'grant_type'              => 'password',
            'username'                => $username,
            'password'                => $password,
            'access_token_manager_id' => self::$access_token_manager_id,
            'scope'                   => implode(' ', self::$scopes),
        ];
        $postdata = http_build_query($postfields);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postdata,
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-POST, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... headerfields=' . print_r($headerfields, true), 0);
        $this->SendDebug(__FUNCTION__, '... postfields=' . print_r($postfields, true), 0);

        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, ' => response=' . $response, 0);

        $statuscode = 0;
        $err = '';
        $access_token = '';

        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        }
        if ($statuscode == 0) {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);
            $jbody = json_decode($body, true);
            if ($jbody == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'invalid/malformed data';
            }
        }

        if ($statuscode == self::$IS_UNAUTHORIZED) {
            $this->WriteAttributeString('ApiRefreshToken', json_encode([]));
            $this->SetBuffer('ApiAccessToken', json_encode([]));
        }

        if ($statuscode == 0) {
            if (isset($jbody['access_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"access_token" missing';
            }
        }
        if ($statuscode == 0) {
            if (isset($jbody['refresh_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"refresh_token" missing';
            }
        }
        if ($statuscode == 0) {
            if (isset($jbody['expires_in']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"expires_in" missing';
            }
        }

        if ($statuscode == 0) {
            $access_token = $jbody['access_token'];
            $expiration = time() + $jbody['expires_in'];
            $jtoken = [
                'access_token' => $access_token,
                'expiration'   => $expiration,
            ];
            $this->SetBuffer('ApiAccessToken', json_encode($jtoken));
            $this->SendDebug(__FUNCTION__, 'new access_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);

            $refresh_token = $jbody['refresh_token'];
            $expiration = time() + (7 * 24 * 60 * 60);
            $jtoken = [
                'refresh_token' => $refresh_token,
                'expiration'    => $expiration,
            ];
            $this->WriteAttributeString('ApiRefreshToken', json_encode($jtoken));
            $this->SendDebug(__FUNCTION__, 'new refresh_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);
        }

        $this->ApiCallsCollect($url, $err, $statuscode);

        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return '';
        }

        $this->MaintainStatus(IS_ACTIVE);

        return $access_token;
    }

    private function GetApiAccessToken($access_token = '', $expiration = 0)
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return false;
        }

        $connection_type = $this->ReadPropertyInteger('connection_type');
        switch ($connection_type) {
            case self::$CONNECTION_OAUTH:
                break;
            case self::$CONNECTION_DEVELOPER:
                $access_token = $this->DeveloperApiAccessToken();
                break;
            default:
                $access_token = false;
                break;
        }

        IPS_SemaphoreLeave($this->SemaphoreID);

        return $access_token;
    }

    private function TestAccess()
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            $this->PopupMessage($this->GetStatusText());
            return;
        }

        $txt = '';

        $r = $this->GetVehicles();
        /*
        if ($r == false) {
            $txt .= $this->Translate('invalid account-data') . PHP_EOL;
            $txt .= PHP_EOL;
        } else {
            $txt = $this->Translate('valid account-data') . PHP_EOL;
            $vehicles = json_decode($r, true);
            $n_vehicles = count($vehicles);
            $txt .= $n_vehicles . ' ' . $this->Translate('registered vehicles found');
        }
         */
        $this->SendDebug(__FUNCTION__, 'txt=' . $txt, 0);
        $this->PopupMessage($txt);
    }

    private function ClearToken()
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return false;
        }

        $this->WriteAttributeString('ApiRefreshToken', json_encode([]));
        $this->SetBuffer('ApiAccessToken', json_encode([]));

        IPS_SemaphoreLeave($this->SemaphoreID);
    }

    protected function SendData($buf)
    {
        $data = ['DataID' => '{76557D1D-4782-3FBA-81C8-78494D4B6908}', 'Buffer' => $buf];
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);
        $this->SendDataToChildren(json_encode($data));
    }

    public function ForwardData($data)
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        $jdata = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($jdata, true), 0);

        $callerID = $jdata['CallerID'];
        $this->SendDebug(__FUNCTION__, 'caller=' . $callerID . '(' . IPS_GetName($callerID) . ')', 0);
        $_IPS['CallerID'] = $callerID;

        $ret = '';

        if (isset($jdata['Function'])) {
            switch ($jdata['Function']) {
                case 'GetVehicles':
                    $ret = $this->GetVehicles();
                    break;
                default:
                    $this->SendDebug(__FUNCTION__, 'unknown function "' . $jdata['Function'] . '"', 0);
                    break;
            }
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown message-structure', 0);
        }

        $this->SendDebug(__FUNCTION__, 'ret=' . print_r($ret, true), 0);
        return $ret;
    }

    private function do_HttpRequest($endpoint, $params, $headerfields, $postfields, $mode)
    {
        $url = $this->build_url('https://api.volvocars.com/' . $endpoint, $params);
        $header = $this->build_header($headerfields);
        $postdata = http_build_query($postfields);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $mode,
        ];

        switch ($mode) {
            case 'POST':
            case 'PUT':
                $curl_opts[CURLOPT_POSTFIELDS] = $postdata;
                break;
            default:
                break;
        }

        $this->SendDebug(__FUNCTION__, 'http-' . $mode . ', url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... headerfields=' . print_r($headerfields, true), 0);
        $this->SendDebug(__FUNCTION__, '... postfields=' . print_r($postfields, true), 0);

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, ' => response=' . $response, 0);

        $statuscode = 0;
        $err = '';
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        }
        if ($statuscode == 0) {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);
        }

        if ($statuscode == self::$IS_UNAUTHORIZED) {
            $this->WriteAttributeString('ApiRefreshToken', json_encode([]));
            $this->SetBuffer('ApiAccessToken', json_encode([]));
        }

        $this->ApiCallsCollect($url, $err, $statuscode);

        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return '';
        }

        $this->MaintainStatus(IS_ACTIVE);
        IPS_SemaphoreLeave($this->SemaphoreID);
        return $body;
    }

    private function GetVehicles()
    {
        $vcc_api_key = $this->ReadPropertyString('vcc_api_key');

        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            return false;
        }

        $headerfields = [
            'Accept'        => 'application/vnd.volvocars.api.connected-vehicle.vehiclelist.v1+json',
            'Authorization' => 'Bearer ' . $access_token,
            'vcc-api-key'   => $vcc_api_key,
        ];

        $body = $this->do_HttpRequest('connected-vehicle/v1/vehicles', [], $headerfields, [], 'GET');
        return $body;
    }
}

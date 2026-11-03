<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class VolvoIO extends IPSModule
{
    use Volvo\StubsCommonLib;
    use VolvoLocalLib;

    private $oauthIdentifer = 'volvo';

    private static $user_agent = 'vca-android/5.58.0';

    // öffentlicher Client der Volvo Cars App: Authorization Code + PKCE, Tokens sind an einen DPoP-Schlüssel gebunden
    // Volvo hat den bisherigen Client "h4Yf0b" (pi.flow) am 26.10.2026 abgeschaltet
    private static $client_id = 'mo2qy9h_10';
    private static $redirect_uri = 'vca://www.volvocars.com/l/vca/oauth/callback';

    private static $authorization_url = 'https://volvoid.eu.volvocars.com/as/authorization.oauth2';
    private static $token_url = 'https://volvoid.eu.volvocars.com/as/token.oauth2';

    private static $scopes = [
        'openid',

        'conve:brake_status',
        'conve:climatization_start_stop',
        'conve:command_accessibility',
        'conve:commands',
        'conve:diagnostics_engine_status',
        'conve:diagnostics_workshop',
        'conve:doors_status',
        'conve:engine_status',
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
        'energy:state:read',

        'location:read',
    ];

    private static $semaphoreTM = 5 * 1000;

    private $SemaphoreID;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyInteger('connection_type', self::$CONNECTION_UNDEFINED);

        $this->RegisterPropertyString('vcc_api_key', '');

        $this->RegisterPropertyString('username', '');
        $this->RegisterPropertyString('password', '');

        $this->RegisterPropertyBoolean('collectApiCallStats', true);

        $this->RegisterAttributeInteger('ConnectionType', self::$CONNECTION_UNDEFINED);

        $this->RegisterAttributeString('ApiAccessToken', json_encode([]));
        $this->RegisterAttributeString('ApiRefreshToken', json_encode([]));
        $this->RegisterAttributeString('ApiDpopKey', '');

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function Destroy()
    {
        if (IPS_InstanceExists($this->InstanceID) == false) {
            $this->CleanupOAuth();
        }
        parent::Destroy();
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
                $username = $this->ReadPropertyString('username');
                if ($username == '') {
                    $this->SendDebug(__FUNCTION__, '"userid" is needed', 0);
                    $r[] = $this->Translate('Username of the Volvo account must be specified');
                }
                $password = $this->ReadPropertyString('password');
                if ($password == '') {
                    $this->SendDebug(__FUNCTION__, '"password" is needed', 0);
                    $r[] = $this->Translate('Password of the Volvo account must be specified');
                }
                $vcc_api_key = $this->ReadPropertyString('vcc_api_key');
                if ($vcc_api_key == '') {
                    $this->SendDebug(__FUNCTION__, '"vcc_api_key" is needed', 0);
                    $r[] = $this->Translate('\'VCC api key\' of the Volvo-API application must be specified');
                }
                break;
            case self::$CONNECTION_OAUTH:
                $this->SendDebug(__FUNCTION__, '"connection_type" ' . $connection_type . '(OAUTH) is not currently operational', 0);
                $r[] = $this->Translate('Connection type \'via IP-Symcon Connect\' is currently not operational');
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

        $vpos = 1000;
        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        $this->MaintainMedia('ApiCallStats', $this->Translate('API call statistics'), MEDIATYPE_DOCUMENT, '.txt', false, $vpos++, $collectApiCallStats);

        if ($collectApiCallStats) {
            $apiLimits = [
                [
                    'value' => 10000,
                    'unit'  => 'day',
                ],
            ];
            $apiNotes = '';
            $this->ApiCallSetInfo($apiLimits, $apiNotes);
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $connection_type = $this->ReadPropertyInteger('connection_type');
        if ($this->ReadAttributeInteger('ConnectionType') != $connection_type) {
            $this->ClearToken();
            $this->WriteAttributeInteger('ConnectionType', $connection_type);
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
            if ($this->GetRefreshToken() == '') {
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
            'width'   => '400px',
            'caption' => 'Connection type',
            'options' => [
                [
                    'caption' => 'Please select a connection type',
                    'value'   => self::$CONNECTION_UNDEFINED
                ],
                [
                    'caption' => 'via IP-Symcon Connect (not operational)',
                    'value'   => self::$CONNECTION_OAUTH
                ],
                [
                    'caption' => 'with Volvo developer key',
                    'value'   => self::$CONNECTION_DEVELOPER
                ]
            ]
        ];

        if ($connection_type == self::$CONNECTION_DEVELOPER) {
            $formElements[] = [
                'type'  => 'ExpansionPanel',
                'items' => [
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
        }
        if ($connection_type == self::$CONNECTION_OAUTH) {
            $formElements[] = [
                'type'    => 'ExpansionPanel',
                'caption' => 'Volvo login',
                'items'   => [
                    [
                        'type'    => 'Label',
                        'caption' => 'Push "Login at Volvo" in the action part of this configuration form.'
                    ],
                    [
                        'type'    => 'Label',
                        'caption' => 'At the webpage from Volvo log in with your Volvo-ID and password.'
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
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'collectApiCallStats',
            'caption' => 'Collect data of API calls'
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

        $connection_type = $this->ReadPropertyInteger('connection_type');
        if ($connection_type == self::$CONNECTION_OAUTH) {
            $formActions[] = [
                'type'    => 'Button',
                'caption' => 'Login at Volvo',
                'onClick' => 'echo "' . $this->Login() . '";',
            ];
        }

        if ($connection_type == self::$CONNECTION_DEVELOPER) {
            $formActions[] = [
                'type'    => 'PopupButton',
                'caption' => 'Perform login with code',
                'popup'   => [
                    'caption' => 'Perform login with code',
                    'items'   => [
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Label',
                                    'caption' => 'Step 1',
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Request code',
                                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "Login_RequestOTP", "");',
                                ],
                            ],
                        ],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                [
                                    'type'    => 'Label',
                                    'caption' => 'Step 2',
                                ],
                                [
                                    'type'    => 'ValidationTextBox',
                                    'name'    => 'otpCode',
                                    'caption' => 'Code (from mail)'
                                ],
                                [
                                    'type'    => 'Button',
                                    'caption' => 'Complete login',
                                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "Login_ConfirmOTP", json_encode(["otpCode" => $otpCode]));',
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        $formActions[] = [
            'type'    => 'Button',
            'label'   => 'Test access',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "TestAccess", "");',
        ];

        $items = [
            $this->GetInstallVarProfilesFormItem(),
            [
                'type'    => 'Button',
                'caption' => 'Clear token',
                'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "ClearToken", "");',
            ],
        ];

        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        if ($collectApiCallStats) {
            $items[] = $this->GetApiCallStatsFormItem();
        }

        $formActions[] = [
            'type'     => 'ExpansionPanel',
            'caption'  => 'Expert area',
            'expanded' => false,
            'items'    => $items,
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function base64url(string $data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // Die Anmeldung ist an einen eigenen EC-Schlüssel gebunden (DPoP, RFC 9449)
    private function DpopCreateKey()
    {
        // openssl_pkey_new() braucht eine openssl.cnf, die Symcon nicht überall mitbringt.
        // Daher wird der P-256-Schlüssel direkt aus einer Zufallszahl gebildet, den öffentlichen Teil berechnet openssl beim Laden.
        $order = hex2bin('ffffffff00000000ffffffffffffffffbce6faada7179e84f3b9cac2fc632551');
        do {
            $secret = random_bytes(32);
        } while (strcmp($secret, $order) >= 0 || ltrim($secret, "\0") === '');

        $der = "\x30\x31\x02\x01\x01\x04\x20" . $secret . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";

        if (openssl_pkey_get_private($pem) === false) {
            $this->SendDebug(__FUNCTION__, 'unable to create key: ' . openssl_error_string(), 0);
            return '';
        }
        return $pem;
    }

    private function DpopProof(string $pem, string $method, string $url)
    {
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            $this->SendDebug(__FUNCTION__, 'unable to load key: ' . openssl_error_string(), 0);
            return '';
        }
        $ec = openssl_pkey_get_details($key)['ec'];

        $header = [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x'   => $this->base64url(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
                'y'   => $this->base64url(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            ],
        ];
        $payload = [
            'htm' => $method,
            'htu' => $url,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(16)),
        ];
        $input = $this->base64url(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . $this->base64url(json_encode($payload, JSON_UNESCAPED_SLASHES));

        if (openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to sign: ' . openssl_error_string(), 0);
            return '';
        }

        // openssl liefert die Signatur als DER, ES256 verlangt r und s mit je 32 Byte
        $pos = 2;
        $signature = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $part = ltrim(substr($der, $pos + 2, $len), "\0");
            $signature .= str_pad($part, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $input . '.' . $this->base64url($signature);
    }

    private function AuthResolveUrl(string $location, string $base)
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $root = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (substr($location, 0, 2) == '//') {
            return $p['scheme'] . ':' . $location;
        }
        if (substr($location, 0, 1) == '/') {
            return $root . $location;
        }
        $path = isset($p['path']) ? $p['path'] : '/';
        return $root . substr($path, 0, strrpos($path, '/') + 1) . $location;
    }

    // Verhält sich wie ein Browser: Cookies je Host und Weiterleitungen, bis eine Seite kommt oder Volvo zur App zurückleitet
    private function AuthFetch(string $method, string $url, array $form, array &$cookies)
    {
        for ($hop = 0; $hop < 10; $hop++) {
            $url = preg_replace('#^http://#', 'https://', $url);
            if (preg_match('#^https://([a-z0-9-]+\.)*volvocars\.com(:\d+)?([/?]|$)#i', $url) == false) {
                return ['err' => 'unexpected url ' . strtok($url, '?')];
            }
            $host = parse_url($url, PHP_URL_HOST);

            $headerfields = [
                'User-Agent' => self::$user_agent,
            ];
            if (isset($cookies[$host]) && count($cookies[$host]) > 0) {
                $headerfields['Cookie'] = implode('; ', $cookies[$host]);
            }
            if ($method == 'POST') {
                $headerfields['Content-Type'] = 'application/x-www-form-urlencoded';
            }

            $curl_opts = [
                CURLOPT_URL            => $url,
                CURLOPT_HTTPHEADER     => $this->build_header($headerfields),
                CURLOPT_HEADER         => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
            ];
            if ($method == 'POST') {
                $curl_opts[CURLOPT_POST] = true;
                $curl_opts[CURLOPT_POSTFIELDS] = http_build_query($form);
            }

            // keine Formularwerte ausgeben, darin steht das Passwort
            $this->SendDebug(__FUNCTION__, 'http-' . $method . ', url=' . strtok($url, '?'), 0);

            $time_start = microtime(true);

            $ch = curl_init();
            curl_setopt_array($ch, $curl_opts);
            $response = curl_exec($ch);
            $cerrno = curl_errno($ch);
            $cerror = $cerrno ? curl_error($ch) : '';
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_info = curl_getinfo($ch);
            if (IPS_GetKernelVersion() < 8.5) {
                curl_close($ch);
            }

            $duration = round(microtime(true) - $time_start, 2);
            $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);

            if ($cerrno) {
                return ['err' => 'got curl-errno ' . $cerrno . ' (' . $cerror . ')'];
            }

            $head = substr($response, 0, $curl_info['header_size']);
            $body = substr($response, $curl_info['header_size']);

            if (preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)/mi', $head, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if ($match[2] == '') {
                        unset($cookies[$host][$match[1]]);
                    } else {
                        $cookies[$host][$match[1]] = $match[1] . '=' . $match[2];
                    }
                }
            }

            if ($httpcode >= 300 && $httpcode < 400 && preg_match('/^Location:\s*(\S+)/mi', $head, $match)) {
                $location = $match[1];
                if (strpos($location, self::$redirect_uri) === 0) {
                    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
                    return [
                        'code' => isset($query['code']) ? $query['code'] : '',
                        'err'  => isset($query['error_description']) ? $query['error_description'] : (isset($query['error']) ? $query['error'] : ''),
                    ];
                }
                $url = $this->AuthResolveUrl($location, $url);
                $method = 'GET';
                continue;
            }

            if ($httpcode >= 400) {
                return ['err' => 'got http-code ' . $httpcode, 'html' => $body];
            }

            return ['html' => $body];
        }

        return ['err' => 'too many redirects'];
    }

    // Die Anmeldeseiten kommen von Keycloak, die Angaben stehen im eingebetteten kcContext
    private function AuthParsePage(string $html)
    {
        $page = [];
        foreach (['pageId', 'loginAction', 'summary'] as $key) {
            $page[$key] = '';
            if (preg_match('/"' . $key . '":\s*"((?:[^"\\\\]|\\\\.)*)"/', $html, $match)) {
                $value = json_decode('"' . $match[1] . '"');
                $page[$key] = html_entity_decode(is_string($value) ? $value : $match[1], ENT_QUOTES | ENT_HTML5);
            }
        }
        return $page;
    }

    private function AuthFailed(string $function, int $statuscode, string $err)
    {
        $this->SendDebug($function, $err, 0);
        $this->MaintainStatus($statuscode);
        $this->SetBuffer('Cookies', '');
        $this->SetBuffer('NextUrl', '');
        $this->SetBuffer('CodeVerifier', '');
        $msg = $this->TranslateFormat('Login failed: {$msg}', ['{$msg}' => $err]);
        $this->PopupMessage($msg);
        return false;
    }

    private function Login_RequestOTP()
    {
        $this->SendDebug(__FUNCTION__, '', 0);

        if (function_exists('openssl_pkey_get_private') == false) {
            return $this->AuthFailed(__FUNCTION__, self::$IS_AUTHERROR, 'php-extension "openssl" is missing');
        }
        // schon vor dem Versand der Mail prüfen, ob der Schlüssel erzeugt werden kann
        if ($this->DpopCreateKey() == '') {
            return $this->AuthFailed(__FUNCTION__, self::$IS_AUTHERROR, 'unable to create DPoP key');
        }

        $verifier = $this->base64url(random_bytes(48));

        $params = [
            'client_id'             => self::$client_id,
            'redirect_uri'          => self::$redirect_uri,
            'response_type'         => 'code',
            'scope'                 => implode(' ', self::$scopes),
            'code_challenge'        => $this->base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'acr_values'            => 'urn:volvoid:aal:bronze:2sv',
        ];
        $url = $this->build_url(self::$authorization_url, $params);

        $cookies = [];

        $this->SendDebug(__FUNCTION__, '*** step 1: open login page', 0);
        $ret = $this->AuthFetch('GET', $url, [], $cookies);
        $page = $this->AuthParsePage(isset($ret['html']) ? $ret['html'] : '');
        $this->SendDebug(__FUNCTION__, 'page=' . print_r($page, true), 0);
        $err = isset($ret['err']) && $ret['err'] != '' ? $ret['err'] : '';
        if ($err == '' && ($page['pageId'] != 'login' || $page['loginAction'] == '')) {
            $err = 'unexpected login page "' . $page['pageId'] . '"';
        }
        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        if ($collectApiCallStats) {
            $this->ApiCallCollect(self::$authorization_url, $err, $err != '' ? self::$IS_AUTHERROR : 0);
        }
        if ($err != '') {
            return $this->AuthFailed(__FUNCTION__, self::$IS_AUTHERROR, $err);
        }

        $this->SendDebug(__FUNCTION__, '*** step 2: send username and password', 0);
        $form = [
            'username'     => $this->ReadPropertyString('username'),
            'password'     => $this->ReadPropertyString('password'),
            'credentialId' => '',
        ];
        $ret = $this->AuthFetch('POST', $page['loginAction'], $form, $cookies);
        $page = $this->AuthParsePage(isset($ret['html']) ? $ret['html'] : '');
        $this->SendDebug(__FUNCTION__, 'page=' . print_r($page, true), 0);
        $err = isset($ret['err']) && $ret['err'] != '' ? $ret['err'] : '';
        if ($err == '' && ($page['pageId'] != 'login-otp-email' || $page['loginAction'] == '')) {
            $err = $page['summary'] != '' ? $page['summary'] : 'no request for OTP, page "' . $page['pageId'] . '"';
        }
        if ($collectApiCallStats) {
            $this->ApiCallCollect(strtok($page['loginAction'], '?'), $err, $err != '' ? self::$IS_AUTHERROR : 0);
        }
        if ($err != '') {
            return $this->AuthFailed(__FUNCTION__, self::$IS_AUTHERROR, $err);
        }

        $this->SetBuffer('Cookies', json_encode($cookies));
        $this->SetBuffer('NextUrl', $page['loginAction']);
        $this->SetBuffer('CodeVerifier', $verifier);

        $this->PopupMessage($this->Translate('Sent OTP'));
    }

    private function Login_ConfirmOTP(string $params)
    {
        $jparams = json_decode($params, true);
        $otpCode = isset($jparams['otpCode']) ? trim($jparams['otpCode']) : '';
        if ($otpCode == '') {
            $this->SendDebug(__FUNCTION__, 'no otpCode', 0);
            return false;
        }

        $url = $this->GetBuffer('NextUrl');
        $verifier = $this->GetBuffer('CodeVerifier');
        $cookies = json_decode($this->GetBuffer('Cookies'), true);
        if ($url == '' || $verifier == '' || is_array($cookies) == false) {
            $this->SendDebug(__FUNCTION__, 'no saved login', 0);
            return false;
        }

        $this->SendDebug(__FUNCTION__, '*** step 1: send otp', 0);
        $ret = $this->AuthFetch('POST', $url, ['otp' => $otpCode], $cookies);

        if (isset($ret['code']) == false || $ret['code'] == '') {
            $page = $this->AuthParsePage(isset($ret['html']) ? $ret['html'] : '');
            $this->SendDebug(__FUNCTION__, 'page=' . print_r($page, true), 0);
            if ($page['pageId'] == 'login-otp-email' && $page['loginAction'] != '') {
                // falscher Code: Volvo zeigt das Formular mit neuer Adresse erneut, der nächste Versuch läuft darüber
                $this->SetBuffer('NextUrl', $page['loginAction']);
                $this->SetBuffer('Cookies', json_encode($cookies));
                $msg = $this->TranslateFormat('Login failed: {$msg}', ['{$msg}' => $page['summary'] != '' ? $page['summary'] : 'invalid code']);
                $this->PopupMessage($msg);
                return false;
            }
            $err = isset($ret['err']) && $ret['err'] != '' ? $ret['err'] : ($page['summary'] != '' ? $page['summary'] : 'no authorization code, page "' . $page['pageId'] . '"');
            return $this->AuthFailed(__FUNCTION__, self::$IS_AUTHERROR, $err);
        }

        $this->SendDebug(__FUNCTION__, '*** step 2: fetch token', 0);
        $pem = $this->DpopCreateKey();
        if ($pem == '') {
            return $this->AuthFailed(__FUNCTION__, self::$IS_AUTHERROR, 'unable to create DPoP key');
        }

        $url = self::$token_url;

        $headerfields = [
            'DPoP'         => $this->DpopProof($pem, 'POST', $url),
            'User-Agent'   => self::$user_agent,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ];
        $header = $this->build_header($headerfields);

        $postfields = [
            'grant_type'    => 'authorization_code',
            'client_id'     => self::$client_id,
            'code'          => $ret['code'],
            'redirect_uri'  => self::$redirect_uri,
            'code_verifier' => $verifier,
        ];
        $postdata = http_build_query($postfields);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postdata,
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-POST, url=' . $url, 0);

        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        if (IPS_GetKernelVersion() < 8.5) {
            curl_close($ch);
        }

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);

        $statuscode = 0;
        $err = '';
        $jbody = false;

        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            $body = substr($response, $curl_info['header_size']);
            $jbody = json_decode($body, true);
            if ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode . (isset($jbody['error']) ? ' (' . $jbody['error'] . ')' : '');
            } elseif ($jbody == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'invalid/malformed data';
            }
        }
        if ($statuscode == 0) {
            foreach (['access_token', 'refresh_token', 'expires_in'] as $field) {
                if (isset($jbody[$field]) == false) {
                    $statuscode = self::$IS_INVALIDDATA;
                    $err = 'missing field "' . $field . '"';
                    break;
                }
            }
        }

        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        if ($collectApiCallStats) {
            $this->ApiCallCollect($url, $err, $statuscode);
        }

        if ($statuscode) {
            return $this->AuthFailed(__FUNCTION__, $statuscode, $err);
        }

        $this->WriteAttributeString('ApiDpopKey', $pem);

        $access_token = $jbody['access_token'];
        $expiration = time() + $jbody['expires_in'];
        $this->SetAccessToken($access_token, $expiration);

        $refresh_token = $jbody['refresh_token'];
        $this->SetRefreshToken($refresh_token);

        $this->SetBuffer('Cookies', '');
        $this->SetBuffer('NextUrl', '');
        $this->SetBuffer('CodeVerifier', '');

        $msg = $this->Translate('Login succeeded');
        $this->PopupMessage($msg);

        $this->MaintainStatus(IS_ACTIVE);
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
            case 'Login_RequestOTP':
                $this->Login_RequestOTP();
                break;
            case 'Login_ConfirmOTP':
                $this->Login_ConfirmOTP($value);
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

    private function random_string($length)
    {
        $result = '';
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        for ($i = 0; $i < $length; $i++) {
            $result .= substr($characters, rand(0, strlen($characters)), 1);
        }
        return $result;
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

    public function Login()
    {
        $params = [
            'username' => IPS_GetLicensee(),
            'scope'    => implode(' ', self::$scopes),
        ];
        $url = $this->build_url('https://oauth.ipmagic.de/authorize/' . $this->oauthIdentifer, $params);
        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);
        return $url;
    }

    protected function ProcessOAuthData()
    {
        if (isset($_GET['code']) == false) {
            $this->SendDebug(__FUNCTION__, 'code missing, _GET=' . print_r($_GET, true), 0);
            $this->SetRefreshToken('');
            $this->SetAccessToken('');
            $this->MaintainStatus(self::$IS_NOLOGIN);
            return;
        }

        $code = $_GET['code'];
        $this->SendDebug(__FUNCTION__, 'code=' . $code, 0);

        $jdata = $this->Call4ApiAccessToken(['code' => $code]);
        if ($jdata == false) {
            $this->SendDebug(__FUNCTION__, 'got no token', 0);
            $this->SetRefreshToken('');
            $this->SetAccessToken('');
            $this->MaintainStatus(self::$IS_NOLOGIN);
            return false;
        }

        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
        $access_token = $jdata['access_token'];
        $expiration = time() + $jdata['expires_in'];
        $this->SetAccessToken($access_token, $expiration);

        $refresh_token = $jdata['refresh_token'];
        $this->SetRefreshToken($refresh_token);

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
        } else {
            if (IPS_GetKernelVersion() >= 8.5) {
                $http_response_header = http_get_last_response_headers();
            }
            if (isset($http_response_header[0]) && preg_match('/HTTP\/[0-9\.]+\s+([0-9]*)/', $http_response_header[0], $r)) {
                $httpcode = $r[1];
            } else {
                $this->LogMessage('missing http_response_header, cdata=' . $cdata, KL_WARNING);
                $this->SendDebug(__FUNCTION__, 'missing http_response_header, cdata=' . $cdata, 0);
            }
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

    private function SetRefreshToken($refresh_token = '')
    {
        if ($refresh_token == '') {
            $this->SendDebug(__FUNCTION__, 'clear refresh_token', 0);
        }
        $jtoken = [
            'tstamp'        => time(),
            'refresh_token' => $refresh_token,
        ];
        $this->WriteAttributeString('ApiRefreshToken', json_encode($jtoken));
    }

    private function GetRefreshToken()
    {
        $jtoken = @json_decode($this->ReadAttributeString('ApiRefreshToken'), true);
        if ($jtoken != false) {
            $refresh_token = isset($jtoken['refresh_token']) ? $jtoken['refresh_token'] : '';
        } else {
            $this->SendDebug(__FUNCTION__, 'no saved refresh_token', 0);
            $refresh_token = '';
        }
        return $refresh_token;
    }

    private function SetAccessToken($access_token = '', $expiration = 0)
    {
        if ($access_token == '') {
            $this->SendDebug(__FUNCTION__, 'clear access_token', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'new access_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);
        }
        $jtoken = [
            'tstamp'       => time(),
            'access_token' => $access_token,
            'expiration'   => $expiration,
        ];
        $this->WriteAttributeString('ApiAccessToken', json_encode($jtoken));
    }

    private function GetAccessToken()
    {
        $jtoken = @json_decode($this->ReadAttributeString('ApiAccessToken'), true);
        if ($jtoken != false) {
            $access_token = isset($jtoken['access_token']) ? $jtoken['access_token'] : '';
            $expiration = isset($jtoken['expiration']) ? $jtoken['expiration'] : 0;
            if ($expiration < time()) {
                $this->SendDebug(__FUNCTION__, 'access_token expired', 0);
                $access_token = '';
            }
            if ($access_token != '') {
                $this->SendDebug(__FUNCTION__, 'old access_token, valid until ' . date('d.m.y H:i:s', $expiration), 0);
            }
        } else {
            $this->SendDebug(__FUNCTION__, 'no saved access_token', 0);
            $access_token = '';
        }
        return $access_token;
    }

    private function RefreshAccessToken()
    {
        $refresh_token = $this->GetRefreshToken();
        if ($refresh_token == '') {
            return $refresh_token;
        }

        // Tokens des abgeschalteten Clients sind ohne DPoP-Schlüssel und nicht mehr gültig
        $pem = $this->ReadAttributeString('ApiDpopKey');
        if ($pem == '') {
            $this->SendDebug(__FUNCTION__, 'refresh_token without DPoP key, new login is needed', 0);
            $this->SetRefreshToken('');
            $this->SetAccessToken('');
            return '';
        }

        $url = self::$token_url;

        $headerfields = [
            'DPoP'         => $this->DpopProof($pem, 'POST', $url),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ];
        $header = $this->build_header($headerfields);

        $postfields = [
            'grant_type'    => 'refresh_token',
            'client_id'     => self::$client_id,
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
        if (IPS_GetKernelVersion() < 8.5) {
            curl_close($ch);
        }

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
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode != 200) {
                $jerr = json_decode($body, true);
                if (isset($jerr['error']) && in_array($jerr['error'], ['invalid_grant', 'invalid_client'])) {
                    // Anmeldung abgelaufen oder widerrufen: hilft nur eine neue Anmeldung
                    $statuscode = self::$IS_UNAUTHORIZED;
                    $err = 'got http-code ' . $httpcode . ' (' . $jerr['error'] . ')';
                } else {
                    $statuscode = self::$IS_HTTPERROR;
                    $err = 'got http-code ' . $httpcode;
                }
            }
        }
        if ($statuscode == 0) {
            $jbody = json_decode($body, true);
            if ($jbody == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'invalid/malformed data';
            }
        }

        if ($statuscode == self::$IS_UNAUTHORIZED) {
            $this->SetRefreshToken('');
            $this->SetAccessToken('');
        }

        if ($statuscode == 0) {
            if (isset($jbody['access_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'missing field "access_token"';
            }
        }
        /*
        if ($statuscode == 0) {
            if (isset($jbody['refresh_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'missing field "refresh_token"';
            }
        }
         */
        if ($statuscode == 0) {
            if (isset($jbody['expires_in']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'missing field "expires_in"';
            }
        }

        if ($statuscode == 0) {
            $access_token = $jbody['access_token'];
            $expiration = time() + $jbody['expires_in'];
            $this->SetAccessToken($access_token, $expiration);

            if (isset($jbody['refresh_token'])) {
                $refresh_token = $jbody['refresh_token'];
                $this->SetRefreshToken($refresh_token);
            }
        }

        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        if ($collectApiCallStats) {
            $this->ApiCallCollect($url, $err, $statuscode);
        }

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
        $access_token = $this->RefreshAccessToken();
        if ($access_token == '') {
            $this->SendDebug(__FUNCTION__, 'grant_type "password" is not longer supported, invoke login with OTP manually', 0);
            $this->MaintainStatus(self::$IS_NOLOGIN);
        }

        return $access_token;
    }

    private function GetApiAccessToken($renew = false)
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return false;
        }

        if ($renew == false) {
            $access_token = $this->GetAccessToken();
            if ($access_token != '') {
                IPS_SemaphoreLeave($this->SemaphoreID);
                return $access_token;
            }
        }

        $connection_type = $this->ReadPropertyInteger('connection_type');
        switch ($connection_type) {
            case self::$CONNECTION_OAUTH:
                $refresh_token = $this->GetRefreshToken();
                if ($refresh_token == '') {
                    $this->SendDebug(__FUNCTION__, 'has no refresh_token', 0);
                    $this->SetAccessToken('');
                    $this->MaintainStatus(self::$IS_NOLOGIN);
                    IPS_SemaphoreLeave($this->SemaphoreID);
                    return false;
                }
                $jdata = $this->Call4ApiAccessToken(['refresh_token' => $refresh_token]);
                $access_token = $jdata != false ? $jdata['access_token'] : false;
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

        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            $msg = $this->Translate('invalid account-data') . PHP_EOL;
            $this->PopupMessage($msg);
            return;
        }

        $data = $this->GetVehicles();
        if ($data == false) {
            $txt .= $this->Translate('invalid account-data') . PHP_EOL;
            $txt .= PHP_EOL;
        } else {
            $txt = $this->Translate('valid account-data') . PHP_EOL;
            $jdata = json_decode($data, true);
            $n_vehicles = count($jdata['data']);
            switch ($n_vehicles) {
                case 0:
                    $txt .= $this->Translate('no registered vehicle found');
                    break;
                case 1:
                    $txt .= $this->Translate('one registered vehicle found');
                    break;
                default:
                    $txt .= $n_vehicles . ' ' . $this->Translate('registered vehicle found');
                    break;
            }
        }
        $this->SendDebug(__FUNCTION__, 'txt=' . $txt, 0);
        $this->PopupMessage($txt);
    }

    private function ClearToken()
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return false;
        }

        $this->SetRefreshToken('');
        $this->SetAccessToken('');
        $this->WriteAttributeString('ApiDpopKey', '');

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
                case 'GetApiConnectedVehicle':
                    $ret = $this->GetApiConnectedVehicle($jdata['vin'], $jdata['detail']);
                    break;
                case 'PostApiConnectedVehicle':
                    $ret = $this->PostApiConnectedVehicle($jdata['vin'], $jdata['detail'], $jdata['postfields']);
                    break;
                case 'GetApiEnergy':
                    $ret = $this->GetApiEnergy($jdata['vin'], $jdata['detail']);
                    break;
                case 'GetApiLocation':
                    $ret = $this->GetApiLocation($jdata['vin'], $jdata['detail']);
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
        $access_token = $this->GetApiAccessToken();
        if ($access_token == false) {
            return false;
        }
        $headerfields['Authorization'] = 'Bearer ' . $access_token;

        $connection_type = $this->ReadPropertyInteger('connection_type');
        switch ($connection_type) {
            case self::$CONNECTION_OAUTH:
                $url = 'https://oauth.ipmagic.de/proxy/volvo/';
                break;
            case self::$CONNECTION_DEVELOPER:
                $headerfields['vcc-api-key'] = $this->ReadPropertyString('vcc_api_key');

                $url = 'https://api.volvocars.com/';
                break;
            default:
                return false;
        }
        $url .= $endpoint;
        if (is_array($params) && count($params) > 0) {
            $url .= '?' . http_build_query($params);
        }

        $header = $this->build_header($headerfields);

        if ($postfields != '') {
            if (isset($headerfields['Content-Type']) && preg_match('#application/json#', $headerfields['Content-Type'])) {
                $postdata = json_encode($postfields);
            } else {
                $postdata = http_build_query($postfields);
            }
        } else {
            $postdata = '';
        }

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
        switch ($mode) {
            case 'POST':
            case 'PUT':
                $this->SendDebug(__FUNCTION__, '... postfields=' . print_r($postfields, true), 0);
                break;
            default:
                break;
        }

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return false;
        }

        $time_start = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        if (IPS_GetKernelVersion() < 8.5) {
            curl_close($ch);
        }

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, ' => response=' . $response, 0);

        $statuscode = 0;
        $err = '';
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode;
            }
        }

        if ($statuscode == self::$IS_UNAUTHORIZED) {
            // $this->SetRefreshToken('');
            // $this->SetAccessToken('');
        }

        $collectApiCallStats = $this->ReadPropertyBoolean('collectApiCallStats');
        if ($collectApiCallStats) {
            $this->ApiCallCollect($url, $err, $statuscode);
        }

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
        $headerfields = [
            'Accept' => 'application/json',
        ];

        $body = $this->do_HttpRequest('connected-vehicle/v2/vehicles', [], $headerfields, [], 'GET');
        return $body;
    }

    private function GetApiConnectedVehicle($vin, $detail)
    {
        $uri = 'connected-vehicle/v2/vehicles/' . $vin;
        if ($detail != '') {
            $uri .= '/' . $detail;
        }

        $headerfields = [
            'Accept' => 'application/json',
        ];

        $body = $this->do_HttpRequest($uri, [], $headerfields, [], 'GET');
        return $body;
    }

    private function PostApiConnectedVehicle($vin, $detail, $postfields)
    {
        $uri = 'connected-vehicle/v2/vehicles/' . $vin;
        if ($detail != '') {
            $uri .= '/' . $detail;
        }

        $headerfields = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $body = $this->do_HttpRequest($uri, [], $headerfields, $postfields, 'POST');
        return $body;
    }

    private function GetApiEnergy($vin, $detail)
    {
        $uri = 'energy/v2/vehicles/' . $vin;
        if ($detail != '') {
            $uri .= '/' . $detail;
        }

        $headerfields = [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $body = $this->do_HttpRequest($uri, [], $headerfields, [], 'GET');
        return $body;
    }

    private function GetApiLocation($vin, $detail)
    {
        $uri = 'location/v1/vehicles/' . $vin;
        if ($detail != '') {
            $uri .= '/' . $detail;
        }

        $headerfields = [
            'Accept' => 'application/json',
        ];

        $body = $this->do_HttpRequest($uri, [], $headerfields, [], 'GET');
        return $body;
    }
}

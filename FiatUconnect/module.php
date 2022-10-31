<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';
require_once __DIR__ . '/../libs/AwsV4.php';

class FiatUconnect extends IPSModule
{
    use FiatUconnect\StubsCommonLib;
    use FiatUconnectLocalLib;

    private static $uconnect_endpoint = 'https://myuconnect.fiat.com';

    private static $sessionExpiration = (24 * 60 * 60);

    private static $login_api_key = '3_mOx_J2dRgjXYCdyhchv3b5lhi54eBcdCTX4BI8MORqmZCoQWhA0mV2PTlptLGUQI';
    private static $login_endpoint = 'https://loginmyuconnect.fiat.com';

    private static $token_endpoint = 'https://authz.sdpr-01.fcagcv.com/v2/cognito/identity/token';

    private static $api_key = '2wGyL6PHec9o1UeLPYpoYa1SkEWqeBur9bLsi24i';
    private static $api_endpoint = 'https://channels.sdpr-01.fcagcv.com';

    private static $auth_api_key = 'JWRYW7IYhW9v0RqDghQSx4UcRYRILNmc8zAuh5ys';
    private static $auth_api_endpoint = 'https://mfa.fcl-01.fcagcv.com';

    private static $cognito_endpoint = 'https://cognito-identity.eu-west-1.amazonaws.com/';

    private static $user_agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 12_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/12.1.2 Mobile/15E148 Safari/604.1';

    private $ModuleDir;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->ModuleDir = __DIR__;
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('user', '');
        $this->RegisterPropertyString('password', '');
        $this->RegisterPropertyString('vin', '');

        $this->RegisterPropertyInteger('update_interval', 5);

        $this->RegisterAttributeString('UpdateInfo', '');
        $this->RegisterAttributeString('external_update_interval', '');

        $this->InstallVarProfiles(false);

        $this->RegisterAttributeString('ApiSettings', '');

        $this->SetBuffer('Summary', '');

        $this->RegisterTimer('UpdateStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateStatus", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->OverwriteUpdateInterval();
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $user = $this->ReadPropertyString('user');
        $password = $this->ReadPropertyString('password');
        $vin = $this->ReadPropertyString('vin');
        if ($user == '' || $password == '' || $vin == '') {
            $this->SendDebug(__FUNCTION__, '"user", "password" and/or "vin" is empty', 0);
            $r[] = $this->Translate('User and password of the Fiat uconnect-account are required and and a registered VIN');
        }

        return $r;
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
        $this->MaintainVariable('Mileage', $this->Translate('Mileage'), VARIABLETYPE_INTEGER, 'Fiat.Mileage', $vpos++, true);
        $this->MaintainVariable('RemainingRange', $this->Translate('Remaining range'), VARIABLETYPE_INTEGER, 'Fiat.Mileage', $vpos++, true);

        $vpos = 20;
        $this->MaintainVariable('StateOfCharge', $this->Translate('Current battery charge level (SoC)'), VARIABLETYPE_FLOAT, 'Fiat.StateOfCharge', $vpos++, true);
        $this->MaintainVariable('ChargingStatus', $this->Translate('Charging status'), VARIABLETYPE_STRING, 'Fiat.ChargingStatus', $vpos++, true);
        $this->MaintainVariable('PlugInStatus', $this->Translate('Plugin status'), VARIABLETYPE_INTEGER, 'Fiat.PlugInStatus', $vpos++, true);
        $this->MaintainVariable('BatteryVoltage', $this->Translate('Battery voltage'), VARIABLETYPE_FLOAT, 'Fiat.Voltage', $vpos++, true);

        $vpos = 50;
        $this->MaintainVariable('DistanceToService', $this->Translate('Service in'), VARIABLETYPE_INTEGER, 'Fiat.Mileage', $vpos++, true);

        $vpos = 60;
        $this->MaintainVariable('LastUpdateFromVehicle', $this->Translate('Last status update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $vpos = 70;
        $this->MaintainVariable('CurrentLatitude', $this->Translate('Current latitude'), VARIABLETYPE_FLOAT, 'Fiat.Location', $vpos++, true);
        $this->MaintainVariable('CurrentLongitude', $this->Translate('Current longitude'), VARIABLETYPE_FLOAT, 'Fiat.Location', $vpos++, true);
        $this->MaintainVariable('CurrentAltitude', $this->Translate('Current altitude'), VARIABLETYPE_FLOAT, 'Fiat.Altitude', $vpos++, true);
        $this->MaintainVariable('LastPositionUpdate', $this->Translate('Last position update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('UpdateStatus', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->OverwriteUpdateInterval();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Fiat uconnect');

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
                    'name'    => 'user',
                    'type'    => 'ValidationTextBox',
                    'caption' => 'User'
                ],
                [
                    'name'    => 'password',
                    'type'    => 'PasswordTextBox',
                    'caption' => 'Password'
                ],
                [
                    'name'    => 'vin',
                    'type'    => 'ValidationTextBox',
                    'caption' => 'VIN'
                ],
            ],
            'caption' => 'Account data',
        ];

        $formElements[] = [
            'type'    => 'NumberSpinner',
            'name'    => 'update_interval',
            'suffix'  => 'minutes',
            'minimum' => 0,
            'caption' => 'Update interval',
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
            'expanded ' => false,
            'items'     => [
                [
                    'type'    => 'Button',
                    'label'   => 'Relogin',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "Relogin", "");',
                ],
                $this->GetInstallVarProfilesFormItem(),
            ],
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function SetUpdateInterval(int $min = null)
    {
        if (is_null($min)) {
            $min = $this->ReadAttributeString('external_update_interval');
            if ($min == '') {
                $min = $this->ReadPropertyInteger('update_interval');
            }
        }
        $this->MaintainTimer('UpdateStatus', $min * 60 * 1000);
    }

    public function OverwriteUpdateInterval(int $min = null)
    {
        if (is_null($min)) {
            $this->WriteAttributeString('external_update_interval', '');
        } else {
            $this->WriteAttributeString('external_update_interval', $min);
        }
        $this->SetUpdateInterval($min);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'UpdateStatus':
                $this->UpdateStatus();
                break;
            case 'Relogin':
                $this->Relogin();
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

    private function extract_host($url)
    {
        return preg_match('|^http[s]*://([^/]*)|', $url, $r) ? $r[1] : '';
    }

    private function Relogin()
    {
        $this->WriteAttributeString('ApiSettings', '');
        $this->Login();
    }

    private function Login()
    {
        $user = $this->ReadPropertyString('user');
        $password = $this->ReadPropertyString('password');

        $this->SendDebug(__FUNCTION__, '*** accounts.webSdkBootstrap', 0);

        $params = [
            'apiKey'      => self::$login_api_key,
            'pageURL'     => self::$uconnect_endpoint . '/de/de/vehicle-services',
        ];
        $url = $this->build_url(self::$login_endpoint . '/accounts.webSdkBootstrap', $params);

        $headerfields = [
            'accept'          => '*/*',
            'accept-language' => 'de-de',
            'user-agent'      => self::$user_agent,
            'origin'          => self::$uconnect_endpoint,
            'referer'         => self::$uconnect_endpoint . '/de/de/vehicle-services',
        ];
        $header = $this->build_header($headerfields);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-GET, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... header=' . print_r($header, true), 0);

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

        $statuscode = 0;
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        }
        if ($statuscode) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode . '(' . $this->HttpCode2Text($httpcode) . ')';
            }
        }
        if ($statuscode == 0) {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);

            preg_match_all('/^Set-Cookie:\s+(.*);/miU', $head, $results);
            $cookies = explode(';', implode(';', $results[1]));
            $this->SendDebug(__FUNCTION__, ' => cookies=' . print_r($cookies, true), 0);
        }
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }

        $this->SendDebug(__FUNCTION__, '*** accounts.login', 0);

        $url = $this->build_url(self::$login_endpoint . '/accounts.login', []);

        $postfields = [
            'loginID'               => $user,
            'password'              => $password,
            'sessionExpiration'     => self::$sessionExpiration,
            'include'               => 'profile,data,emails,subscriptions,preferences',
            'APIKey'                => self::$login_api_key,
            'lang'                  => 'de@de',
            'loginMode'             => 'standard',
            'authMode'              => 'cookie',
            'targetEnv'             => 'jssdk',
            'sdk'                   => 'js_latest',
            'sdkBuild'              => '12234',
            'includeUserInfo'       => true,
            'riskContext'           => '{"b0":52569,"b2":8,"b5":1}',
            'source'                => 'showScreenSet',
            'format'                => 'json',
        ];
        $postdata = http_build_query($postfields);

        $headerfields = [
            'accept'          => '*/*',
            'accept-language' => 'de-de',
            'content-type'    => 'application/x-www-form-urlencoded',
            'user-agent'      => self::$user_agent,
            'origin'          => self::$uconnect_endpoint,
            'referer'         => self::$uconnect_endpoint . '/de/de/login',
        ];
        $header = $this->build_header($headerfields);
        foreach ($cookies as $cookie) {
            $header[] = 'Cookie: ' . $cookie;
        }

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $postdata,
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-POST, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... header=' . print_r($header, true), 0);
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

        $statuscode = 0;
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        }
        if ($statuscode) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode . '(' . $this->HttpCode2Text($httpcode) . ')';
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
        if ($statuscode == 0) {
            if (isset($jbody['sessionInfo']['login_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"sessionInfo.login_token" missing';
            }
        }
        if ($statuscode == 0) {
            if (isset($jbody['sessionInfo']['expires_in']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"sessionInfo.expires_in" missing';
            }
        }
        if ($statuscode == 0) {
            if (isset($jbody['userInfo']['UID']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"userInfo.UID" missing';
            }
        }
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }

        $loginToken = $jbody['sessionInfo']['login_token'];
        $expires_in = $jbody['sessionInfo']['expires_in'];
        $user_id = $jbody['userInfo']['UID'];
        $this->SendDebug(__FUNCTION__, ' => login_token=' . $loginToken . ', expires_in=' . $expires_in . ', UID=' . $user_id, 0);

        $this->SendDebug(__FUNCTION__, '*** accounts.getJWT', 0);

        $params = [
            'fields'      => 'profile.firstName,profile.lastName,profile.email,country,locale,data.disclaimerCodeGSDP',
            'APIKey'      => self::$login_api_key,
            'login_token' => $loginToken,
            'authMode'    => 'cookie',
            'pageURL'     => self::$uconnect_endpoint . '/de/de/dashboard',
            'sdk'         => 'js_latest',
            'sdkBuild'    => '12234',
            'format='     => 'json',
        ];
        $url = $this->build_url(self::$login_endpoint . '/accounts.getJWT', $params);

        $headerfields = [
            'accept'          => '*/*',
            'accept-language' => 'de-de',
            'user-agent'      => self::$user_agent,
            'origin'          => self::$uconnect_endpoint,
            'referer'         => self::$uconnect_endpoint . '/de/de/dashboard',
        ];
        $header = $this->build_header($headerfields);
        foreach ($cookies as $cookie) {
            $header[] = 'Cookie: ' . $cookie;
        }

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-GET, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... header=' . print_r($header, true), 0);

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

        $statuscode = 0;
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        }
        if ($statuscode) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode . '(' . $this->HttpCode2Text($httpcode) . ')';
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
        if ($statuscode == 0) {
            if (isset($jbody['id_token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"id_token" missing';
            }
        }
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }

        $idToken = $jbody['id_token'];
        $this->SendDebug(__FUNCTION__, ' => id_token=' . $idToken, 0);

        $this->SendDebug(__FUNCTION__, '*** token', 0);

        $url = $this->build_url(self::$token_endpoint, []);

        $postfields = [
            'gigya_token' => $idToken,
        ];
        $postdata = json_encode($postfields);

        $headerfields = [
            'accept'              => '*/*',
            'accept-language'     => 'de-de',
            'user-agent'          => self::$user_agent,
            'origin'              => self::$uconnect_endpoint,
            'referer'             => self::$uconnect_endpoint . '/de/de/dashboard',
            'content-type'        => 'application/json',
            'locale'              => 'de_de',
            'clientrequestid'     => $this->random_string(16),
            'x-api-key'           => self::$api_key,
            'x-clientapp-version' => '1.0',
            'x-originator-type'   => 'web',
        ];
        $header = $this->build_header($headerfields);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $postdata,
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-POST, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... header=' . print_r($header, true), 0);
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

        $statuscode = 0;
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        }
        if ($statuscode) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode . '(' . $this->HttpCode2Text($httpcode) . ')';
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
        if ($statuscode == 0) {
            if (isset($jbody['Token']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"Token" missing';
            }
        }
        if ($statuscode == 0) {
            if (isset($jbody['IdentityId']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"IdentityId" missing';
            }
        }
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }

        $token = $jbody['Token'];
        $identityId = $jbody['IdentityId'];
        $this->SendDebug(__FUNCTION__, ' => token=' . $token . ', identityId=' . $identityId, 0);

        $this->SendDebug(__FUNCTION__, '*** cognito', 0);

        $url = $this->build_url(self::$cognito_endpoint, []);

        $postfields = [
            'IdentityId' => $identityId,
            'Logins'     => [
                'cognito-identity.amazonaws.com' => $token,
            ],
        ];
        $postdata = json_encode($postfields);

        $headerfields = [
            'accept'               => '*/*',
            'accept-language'      => 'de-de',
            'user-agent'           => self::$user_agent,
            'origin'               => self::$uconnect_endpoint,
            'referer'              => self::$uconnect_endpoint . '/de/de/dashboard',
            'content-type'         => 'application/x-amz-json-1.1',
            'x-amz-user-agent'     => 'aws-sdk-js/2.283.1 callback',
            'x-amz-target'         => 'AWSCognitoIdentityService.GetCredentialsForIdentity',
            'x-amz-content-sha256' => hash('sha256', $postdata, false),
        ];
        $header = $this->build_header($headerfields);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $postdata,
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-POST, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... header=' . print_r($header, true), 0);
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

        $statuscode = 0;
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        }
        if ($statuscode) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode . '(' . $this->HttpCode2Text($httpcode) . ')';
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
        if ($statuscode == 0) {
            if (isset($jbody['Credentials']) == false) {
                $statuscode = self::$IS_INVALIDDATA;
                $err = '"Credentials" missing';
            }
        }
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }
        $credentials = $jbody['Credentials'];

        $this->SendDebug(__FUNCTION__, 'credentials=' . print_r($credentials, true), 0);

        $this->MaintainStatus(IS_ACTIVE);

        $expiration = time() + $expires_in;
        $this->SendDebug(__FUNCTION__, 'new credentials, valid until ' . date('d.m.y H:i:s', $expiration), 0);
        $jdata = [
            'expiration'  => $expiration,
            'credentials' => $credentials,
            'user_id'     => $user_id,
        ];
        $this->WriteAttributeString('ApiSettings', json_encode($jdata));
        return $jdata;
    }

    private function GetApiSettings()
    {
        $data = $this->ReadAttributeString('ApiSettings');
        if ($data != false) {
            $jdata = json_decode($data, true);
            $expiration = isset($jdata['expiration']) ? $jdata['expiration'] : 0;
            if ($expiration > time()) {
                $this->SendDebug(__FUNCTION__, 'old credentials, valid until ' . date('d.m.y H:i:s', $expiration), 0);
                return $jdata;
            }
            $this->SendDebug(__FUNCTION__, 'credentials expired', 0);
        } else {
            $this->SendDebug(__FUNCTION__, 'no/empty buffer "AccessToken"', 0);
        }
        return $this->Login();
    }

    private function CallApi($path)
    {
        $this->SendDebug(__FUNCTION__, 'path=' . $path, 0);

        $settings = $this->GetApiSettings();
        $credentials = $settings['credentials'];

        $url = $this->build_url(self::$api_endpoint . $path, []);

        $headerfields = [
            'accept'               => 'application/json, text/plain, */*',
            'accept-language'      => 'de-de',
            'content-encoding'     => 'amz-1.0',
            'content-type'         => 'application/json',
            'host'                 => $this->extract_host(self::$api_endpoint),
            'x-clientapp-version'  => '1.0',
            'clientrequestid'      => $this->random_string(16),
            'x-api-key'            => self::$api_key,
            'x-originator-type'    => 'web',
            'locale'               => 'de_de',
            'x-clientapp-name'     => 'CWP',
            'x-amz-security-token' => $credentials['SessionToken'],
        ];
        ksort($headerfields);

        $awsv4 = new awsv4($credentials['AccessKeyId'], $credentials['SecretKey']);
        $awsv4->setRegionName('eu-west-1');
        $awsv4->setServiceName('execute-api');
        $awsv4->setPath($path);
        $awsv4->setPayload('');
        $awsv4->setRequestMethod('GET');
        foreach ($headerfields as $key => $value) {
            $awsv4->addHeader($key, $value);
        }
        $aws_headers = $awsv4->getHeaders();
        $header = $this->build_header($aws_headers);

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_COOKIEFILE     => '',
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $this->SendDebug(__FUNCTION__, 'http-GET, url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, '... header=' . print_r($header, true), 0);

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

        $statuscode = 0;
        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        }
        if ($statuscode) {
            if ($httpcode == 401) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (unauthorized)';
            } elseif ($httpcode == 403) {
                $statuscode = self::$IS_UNAUTHORIZED;
                $err = 'got http-code ' . $httpcode . ' (forbidden)';
            } elseif ($httpcode >= 500 && $httpcode <= 599) {
                $statuscode = self::$IS_SERVERERROR;
                $err = 'got http-code ' . $httpcode . ' (server error)';
            } elseif ($httpcode != 200) {
                $statuscode = self::$IS_HTTPERROR;
                $err = 'got http-code ' . $httpcode . '(' . $this->HttpCode2Text($httpcode) . ')';
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
        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }

        $this->SendDebug(__FUNCTION__, 'data=' . print_r($jbody, true), 0);
        return $jbody;
    }

    private function UpdateStatus()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $settings = $this->GetApiSettings();
        if ($settings == false) {
            $this->MaintainStatus(self::$IS_UNAUTHORIZED);
            return;
        }

        $user_id = $settings['user_id'];
        $vin = $this->ReadPropertyString('vin');

        if ($this->GetBuffer('Summary') == '') {
            $path = '/v4/accounts/' . $user_id . '/vehicles/';
            $jdata = $this->CallApi($path);
            if ($jdata != false) {
                foreach ($jdata['vehicles'] as $vehicle) {
                    $this->SendDebug(__FUNCTION__, 'vehicle=' . print_r($vehicle, true), 0);
                    if ($vehicle['vin'] != $vin) {
                        continue;
                    }
                    $modelDescription = $this->GetArrayElem($vehicle, 'modelDescription', '');
                    $fuelType = $this->GetArrayElem($vehicle, 'fuelType', '');
                    $model = $this->GetArrayElem($vehicle, 'model', '');
                    $make = $this->GetArrayElem($vehicle, 'make', '');
                    $year = $this->GetArrayElem($vehicle, 'year', '');
                    $summary = $make . ' ' . $modelDescription . $fuelType . ' (' . $model . '/' . $year . ')';
                    $this->SetSummary($summary);
                    $this->SetBuffer('Summary', $summary);
                }
            }
        }

        $isChanged = false;

        $path = '/v2/accounts/' . $user_id . '/vehicles/' . $vin . '/status';
        $jdata = $this->CallApi($path);
        if ($jdata != false) {
            $val = $this->GetArrayElem($jdata, 'vehicleInfo.odometer.odometer.value', '');
            if ($val != '') {
                $this->SaveValue('Mileage', intval($val), $isChanged);
            }

            $val = $this->GetArrayElem($jdata, 'vehicleInfo.batteryInfo.batteryVoltage.value', '');
            if ($val != '') {
                $this->SaveValue('BatteryVoltage', floatval($val), $isChanged);
            }

            $val = $this->GetArrayElem($jdata, 'vehicleInfo.distanceToService.distanceToService.value', '');
            if ($val != '') {
                $this->SaveValue('DistanceToService', intval($val), $isChanged);
            }

            $tstamp = $this->GetArrayElem($jdata, 'vehicleInfo.timestamp', '');
            $this->SaveValue('LastUpdateFromVehicle', intval($tstamp), $isChanged);

            $val = $this->GetArrayElem($jdata, 'evInfo.battery.distanceToEmpty.value', '');
            if ($val != '') {
                $this->SaveValue('RemainingRange', intval($val), $isChanged);
            }

            $val = $this->GetArrayElem($jdata, 'evInfo.battery.stateOfCharge', '');
            if ($val != '') {
                $this->SaveValue('StateOfCharge', intval($val), $isChanged);
            }

            $val = $this->GetArrayElem($jdata, 'evInfo.battery.chargingStatus', '');
            $this->SaveValue('ChargingStatus', $val, $isChanged);

            $val = $this->GetArrayElem($jdata, 'evInfo.battery.plugInStatus', '');
            $this->SaveValue('PlugInStatus', intval($val), $isChanged);

            $val = $this->GetArrayElem($jdata, 'evInfo.battery.chargingLevel', ''); // LEVEL_2
            $this->SendDebug(__FUNCTION__, 'battery.chargingLevel=' . $val, 0);

            $val = $this->GetArrayElem($jdata, 'evInfo.battery.timeToFullyChargeL2', 0);
            $this->SendDebug(__FUNCTION__, 'battery.timeToFullyChargeL2=' . $val, 0);

            $val = $this->GetArrayElem($jdata, 'evInfo.battery.timeToFullyChargeL3', 0);
            $this->SendDebug(__FUNCTION__, 'battery.timeToFullyChargeL3=' . $val, 0);
        }

        $path = '/v1/accounts/' . $user_id . '/vehicles/' . $vin . '/location/lastknown';
        $jdata = $this->CallApi($path);
        if ($jdata != false) {
            $val = $this->GetArrayElem($jdata, 'latitude', '');
            if ($val != '') {
                $this->SaveValue('CurrentLatitude', floatval($val), $isChanged);
            }

            $val = $this->GetArrayElem($jdata, 'longitude', '');
            if ($val != '') {
                $this->SaveValue('CurrentLongitude', floatval($val), $isChanged);
            }

            $val = $this->GetArrayElem($jdata, 'altitude', '');
            if ($val != '') {
                $this->SaveValue('CurrentAltitude', floatval($val), $isChanged);
            }

            $val = $this->GetArrayElem($jdata, 'timeStamp', '');
            if ($val != '') {
                $this->SaveValue('LastPositionUpdate', intval($val), $isChanged);
            }
        }

        // $path = '/v1/accounts/' . $user_id . '/vehicles/' . $vin . '/svla/status';
        // $path = '/v1/accounts/' . $user_id . '/vehicles/' . $vin . '/vhr';

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('UpdateStatus'), 0);
    }
}

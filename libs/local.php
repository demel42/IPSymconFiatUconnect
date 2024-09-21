<?php

declare(strict_types=1);

trait FiatUconnectLocalLib
{
    public static $IS_UNAUTHORIZED = IS_EBASE + 10;
    public static $IS_FORBIDDEN = IS_EBASE + 11;
    public static $IS_SERVERERROR = IS_EBASE + 12;
    public static $IS_HTTPERROR = IS_EBASE + 13;
    public static $IS_INVALIDDATA = IS_EBASE + 14;
    public static $IS_APIERROR = IS_EBASE + 15;
    public static $IS_INVALIDPIN = IS_EBASE + 16;

    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        $formStatus[] = ['code' => self::$IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (unauthorized)'];
        $formStatus[] = ['code' => self::$IS_FORBIDDEN, 'icon' => 'error', 'caption' => 'Instance is inactive (forbidden)'];
        $formStatus[] = ['code' => self::$IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (server error)'];
        $formStatus[] = ['code' => self::$IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (http error)'];
        $formStatus[] = ['code' => self::$IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid data)'];
        $formStatus[] = ['code' => self::$IS_APIERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (api error)'];
        $formStatus[] = ['code' => self::$IS_INVALIDPIN, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid pin)'];

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
            case self::$IS_APIERROR:
                $class = self::$STATUS_RETRYABLE;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }

        $associations = [
            ['Wert' => 0, 'Name' => $this->Translate('disconnected'), 'Farbe' => -1],
            ['Wert' => 1, 'Name' => $this->Translate('connected'), 'Farbe' => 0xEE0000],
        ];
        $this->CreateVarProfile('Fiat.PlugInStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $this->CreateVarProfile('Fiat.Mileage', VARIABLETYPE_INTEGER, ' km', 0, 0, 0, 0, 'Distance', '', $reInstall);
        $this->CreateVarProfile('Fiat.TimeToFullyCharge', VARIABLETYPE_INTEGER, ' min', 0, 0, 0, 0, 'Clock', '', $reInstall);

        $this->CreateVarProfile('Fiat.StateOfCharge', VARIABLETYPE_FLOAT, ' %', 0, 0, 0, 0, '', '', $reInstall);
        $this->CreateVarProfile('Fiat.BatteryCapacity', VARIABLETYPE_FLOAT, ' kWh', 0, 0, 0, 1, '', '', $reInstall);
        $this->CreateVarProfile('Fiat.Voltage', VARIABLETYPE_FLOAT, ' V', 0, 0, 0, 1, '', '', $reInstall);
        $this->CreateVarProfile('Fiat.Location', VARIABLETYPE_FLOAT, ' °', 0, 0, 0, 5, 'Car', '', $reInstall);
        $this->CreateVarProfile('Fiat.Altitude', VARIABLETYPE_FLOAT, ' m', 0, 0, 0, 0, 'Car', '', $reInstall);

        $associations = [
            ['Wert' => 'NOT_CHARGING', 'Name' => $this->Translate('Not charging'), 'Farbe' => -1],
            ['Wert' => 'CHARGING', 'Name' => $this->Translate('Charging'), 'Farbe' => 0x228B22],
            ['Wert' => 'CHARGE_COMPLETE', 'Name' => $this->Translate('Charge complete'), 'Farbe' => 0x0000FF],
        ];
        $this->CreateVarProfile('Fiat.ChargingStatus', VARIABLETYPE_STRING, '', 0, 0, 0, 0, '', $associations, $reInstall);
    }
}

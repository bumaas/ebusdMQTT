<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);
define('MQTT_GROUP_TOPIC', 'ebusd');

trait ebusd2MQTTHelper
{

    protected function MsgBox(string $Message): void
    {
        $this->UpdateFormField('MsgText', 'caption', $Message);

        $this->UpdateFormField('MsgBox', 'visible', true);
    }
    protected function GetParent($instanceID)
    {
        $instance = IPS_GetInstance($instanceID);

        return ($instance['ConnectionID'] > 0) ? $instance['ConnectionID'] : 0;
    }

    protected function RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize): bool
    {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, VARIABLETYPE_INTEGER);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] !== VARIABLETYPE_INTEGER) {
                $this->SendDebug(__FUNCTION__, 'Type of variable does not match the variable profile "' . $Name . '"', 0);
                return false;
            }
        }
        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
        return true;
    }

    protected function RegisterProfileIntegerEx($Name, $Icon, $Prefix, $Suffix, $Associations): void
    {
        if (count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        } else {
            $MinValue = $Associations[0][0];
            $MaxValue = $Associations[count($Associations) - 1][0];
        }
        $this->RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0);
        foreach ($Associations as $Association) {
            IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
        }
    }

    protected function RegisterProfileFloat($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize, $Digits = 0): bool
    {
        if (IPS_VariableProfileExists($Name) === false) {
            IPS_CreateVariableProfile($Name, VARIABLETYPE_FLOAT);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] !== VARIABLETYPE_FLOAT) {
                $this->SendDebug(__FUNCTION__, 'Type of variable does not match the variable profile "' . $Name . '"', 0);
                return false;
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
        IPS_SetVariableProfileDigits($Name, $Digits);

        return true;
    }

    protected function RegisterProfileFloatEx($Name, $Icon, $Prefix, $Suffix, $Associations): void
    {
        if (count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        } else {
            $MinValue = $Associations[0][0];
            $MaxValue = $Associations[count($Associations) - 1][0];
        }

        $this->RegisterProfileFloat($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0);

        foreach ($Associations as $Association) {
            IPS_SetVariableProfileAssociation($Name, $Association[0], $Association[1], $Association[2], $Association[3]);
        }
    }

    private function getPayload(string $messageId, $Value): string
    {
        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($configurationMessages[$messageId])){
            debug_print_backtrace();
            trigger_error('Unexpected messageId: ' . $messageId, E_USER_ERROR);
        }
        $messageDef            = $configurationMessages[$messageId];

        //einige messages (z. B. z1ActualRoomTempDesired) haben mehr als nur ein Feld, aber nur ein Feld ist relevant
        foreach ($messageDef['fielddefs'] as $fieldDef) {
            if ($fieldDef['type'] !== 'IGN') {
                break;
            }
        }

        if (!isset($fieldDef)){
            trigger_error('no valid fielDef found', E_USER_NOTICE);
            return '';
        }

        $dataTypeDef = self::DataTypes[$fieldDef['type']];
        switch ($dataTypeDef['VariableType']) {
            case VARIABLETYPE_INTEGER:
                $ret = (string)$Value;
                break;
            case VARIABLETYPE_FLOAT:
                $ret = number_format($Value, $dataTypeDef['Digits'], '.', '');
                break;
            case VARIABLETYPE_STRING:
                $ret = $Value;
                break;
            case VARIABLETYPE_BOOLEAN:
                $ret = (string)((int)$Value); //todo
                break;
            default:
                $ret = '';
                trigger_error('Unexpected VariableType: ' . $dataTypeDef['VariableType'], E_USER_ERROR);
        }

        return $ret;
    }

    private function isArchived(string $ident): bool
    {
        $ahID  = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0]; // Archive Handler
        $varID = $this->GetIDForIdent($ident);
        return AC_GetLoggingStatus($ahID, $varID);
    }
}

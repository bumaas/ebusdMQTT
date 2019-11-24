<?php

declare(strict_types=1);
define('MQTT_GROUP_TOPIC', 'ebusd');

trait ebusd2MQTTHelper
{
    public function RequestAction($Ident, $Value)
    {
        $this->SendDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, json_encode($Value)), 0);

        $message = $Ident;

        $topic   = sprintf('%s/%s/%s/set', MQTT_GROUP_TOPIC, $this->ReadPropertyString(self::PROP_MQTTTOPIC), $Ident);
        $payload = $this->getPayload($message, $Value);
        $this->publish($topic, $payload);
    }

    public function publish(string $topic, string $payload)
    {
        $Data['DataID']           = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
        $Data['PacketType']       = 3;
        $Data['QualityOfService'] = 0;
        $Data['Retain']           = false;
        $Data['Topic']            = $topic;
        $Data['Payload']          = $payload;

        $DataJSON = json_encode($Data, JSON_UNESCAPED_SLASHES);
        $ret      = $this->SendDataToParent($DataJSON);
        $this->SendDebug(__FUNCTION__, sprintf('Call: %s, Return: %s', $DataJSON, $ret), 0);
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

    protected function RegisterProfileIntegerEx($Name, $Icon, $Prefix, $Suffix, $Associations)
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

    protected function RegisterProfileFloatEx($Name, $Icon, $Prefix, $Suffix, $Associations)
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
        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true);
        $messageDef            = $configurationMessages[$messageId];

        //einige messages (z.B z1ActualRoomTempDesired) haben mehr als nur ein Feld, aber nur ein Feld ist relevant
        foreach ($messageDef['fielddefs'] as $fieldDef) {
            if ($fieldDef['type'] !== 'IGN') {
                break;
            }
        }

        $dataTypeDef = self::DataTypes[$fieldDef['type']];
        switch ($dataTypeDef['VariableType']) {
            case VARIABLETYPE_INTEGER:
                $ret = (string) $Value;
                break;
            case VARIABLETYPE_FLOAT:
                $ret = number_format($Value, $dataTypeDef['Digits'], '.', '');
                break;
            case VARIABLETYPE_STRING:
                $ret = $Value;
                break;
            case VARIABLETYPE_BOOLEAN:
                $ret = (string) ((int) $Value); //todo
                break;
            default:
                $ret = '';
                trigger_error('Unexpected VariableType: ' . $dataTypeDef['VariableType'], E_USER_ERROR);
        }

        return $ret;
    }
}

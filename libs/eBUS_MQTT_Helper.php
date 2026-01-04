<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);
const MQTT_GROUP_TOPIC = 'ebusd';

trait ebusd2MQTTHelper
{
    private const string MODULE_ID_MQTT_SERVER     = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    private const string MODULE_ID_ARCHIVE_HANDLER = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    //Datenfluss
    private const string DATA_ID_MQTT_SERVER_TX    = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    private static array $ebusDataTypesDefinitionsCache = [];


    /**
     * Retrieves a list of supported eBUS data types and their corresponding configuration details.
     *
     * This method returns an array of eBUS data types, grouped by their corresponding variable types.
     * Each data type includes relevant metadata such as `VariableType`, `MinValue`, `MaxValue`, `StepSize`, etc.,
     * where applicable.
     *
     * @return array An associative array where keys represent eBUS data types and values define their
     *               corresponding configuration details, including variable type and optional constraints.
     */
    protected function getEbusDataTypeDefinitions(): array
    {
        if (!empty(self::$ebusDataTypesDefinitionsCache)) {
            return self::$ebusDataTypesDefinitionsCache;
        }

        // die von ebusd unterstützen Datentypen
        //siehe https://github.com/john30/ebusd/wiki/4.3.-Builtin-data-types
        self::$ebusDataTypesDefinitionsCache = [
            // VARIABLETYPE_BOOLEAN
            'BI0'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            'BI1'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            'BI2'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            'BI3'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            'BI4'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            'BI5'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            'BI6'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            'BI7'   => ['VariableType' => VARIABLETYPE_BOOLEAN],
            // VARIABLETYPE_INTEGER
            'BDY'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 7, 'StepSize' => 1],
            'HDY'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 7, 'StepSize' => 1],
            'BCD'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 99, 'StepSize' => 1],
            'BCD:2' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 9999, 'StepSize' => 1],
            'BCD:3' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 999999, 'StepSize' => 1],
            'BCD:4' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 99999999, 'StepSize' => 1],
            'HCD'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 99999999, 'StepSize' => 1],
            'HCD:1' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 99, 'StepSize' => 1],
            'HCD:2' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 9999, 'StepSize' => 1],
            'HCD:3' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 999999, 'StepSize' => 1],
            'PIN'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 9999, 'StepSize' => 1],
            'UCH'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 256, 'StepSize' => 1],
            'SCH'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -127, 'MaxValue' => 127, 'StepSize' => 1],
            'D1B'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -127, 'MaxValue' => 127, 'StepSize' => 1],
            'UIN'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 65534, 'StepSize' => 1],
            'UIR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 65534, 'StepSize' => 1],
            'SIN'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -32767, 'MaxValue' => 32767, 'StepSize' => 1],
            'SIR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -32767, 'MaxValue' => 32767, 'StepSize' => 1],
            'U3N'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 16777214, 'StepSize' => 1],
            'U3R'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 16777214, 'StepSize' => 1],
            'S3N'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -8_388_607, 'MaxValue' => 8_388_607, 'StepSize' => 1],
            'S3R'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -8_388_607, 'MaxValue' => 8_388_607, 'StepSize' => 1],
            'ULG'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 0, 'StepSize' => 1], //MaxValue 4294967294 ist zu groß
            'ULR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 0, 'StepSize' => 1], //MaxValue 4294967294 ist zu groß
            'SLG'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -2_147_483_647, 'MaxValue' => 2_147_483_647, 'StepSize' => 1],
            'SLR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -2_147_483_647, 'MaxValue' => 2_147_483_647, 'StepSize' => 1],
            // VARIABLETYPE_FLOAT
            'D1C'   => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => 0, 'MaxValue' => 100, 'StepSize' => 0.5, 'Digits' => 1],
            'D2B'   => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -127.99, 'MaxValue' => 127.99, 'StepSize' => 0.01, 'Digits' => 2],
            'D2C'   => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -2047.9, 'MaxValue' => 2047.9, 'StepSize' => 0.1, 'Digits' => 1],
            'FLT'   => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -32.767, 'MaxValue' => 32.767, 'StepSize' => 0.001, 'Digits' => 3],
            'FLR'   => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -32.767, 'MaxValue' => 32.767, 'StepSize' => 0.001, 'Digits' => 3],
            'EXP'   => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -3.0e38, 'MaxValue' => 3.0e38, 'StepSize' => 0.001, 'Digits' => 3],
            'EXR'   => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -3.0e38, 'MaxValue' => 3.0e38, 'StepSize' => 0.001, 'Digits' => 3],
            // VARIABLETYPE_STRING
            'STR'   => ['VariableType' => VARIABLETYPE_STRING],
            'NTS'   => ['VariableType' => VARIABLETYPE_STRING],
            'HEX'   => ['VariableType' => VARIABLETYPE_STRING],
            'BDA'   => ['VariableType' => VARIABLETYPE_STRING],
            'BDA:3' => ['VariableType' => VARIABLETYPE_STRING],
            'HDA'   => ['VariableType' => VARIABLETYPE_STRING],
            'HDA:3' => ['VariableType' => VARIABLETYPE_STRING],
            'DAY'   => ['VariableType' => VARIABLETYPE_STRING],
            'BTI'   => ['VariableType' => VARIABLETYPE_STRING],
            'HTI'   => ['VariableType' => VARIABLETYPE_STRING],
            'VTI'   => ['VariableType' => VARIABLETYPE_STRING],
            'BTM'   => ['VariableType' => VARIABLETYPE_STRING],
            'HTM'   => ['VariableType' => VARIABLETYPE_STRING],
            'VTM'   => ['VariableType' => VARIABLETYPE_STRING],
            'MIN'   => ['VariableType' => VARIABLETYPE_STRING],
            'TTM'   => ['VariableType' => VARIABLETYPE_STRING],
            'TTH'   => ['VariableType' => VARIABLETYPE_STRING],
            'TTQ'   => ['VariableType' => VARIABLETYPE_STRING]
        ];

        return self::$ebusDataTypesDefinitionsCache;
    }

    protected function GetArchiveHandlerID(): int
    {
        static $archiveHandlerID = null;
        if ($archiveHandlerID === null) {
            $ids              = IPS_GetInstanceListByModuleID(self::MODULE_ID_ARCHIVE_HANDLER);
            $archiveHandlerID = count($ids) > 0 ? $ids[0] : 0;
        }
        return $archiveHandlerID;
    }

    protected function MsgBox(string $Message): void
    {
        $this->UpdateFormField('MsgText', 'caption', $Message);

        $this->UpdateFormField('MsgBox', 'visible', true);
    }

    protected function GetParent($instanceID): int
    {
        $instance = IPS_GetInstance($instanceID);

        return ($instance['ConnectionID'] > 0) ? $instance['ConnectionID'] : 0;
    }

    protected function RegisterProfileInteger(string $Name, string $Icon, string $Prefix, string $Suffix, int $MinValue, int $MaxValue, int $StepSize): bool
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

    protected function RegisterProfileIntegerEx(string $Name, string $Icon, string $Prefix, string $Suffix, array $Associations): void
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

    protected function RegisterProfileFloat(string $Name, string $Icon, string $Prefix, string $Suffix, float $MinValue, float $MaxValue, float $StepSize, int $Digits = 0): bool
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

    protected function RegisterProfileFloatEx(string $Name, string $Icon, string $Prefix, string $Suffix, array $Associations): void
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

    private function getPayload(string $messageId, mixed $Value): string
    {
        $configAttr            = $this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES);
        $configurationMessages = json_decode($configAttr, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($configurationMessages[$messageId])) {
            $this->logDebug(__FUNCTION__, 'Unexpected messageId: ' . $messageId);
            throw new \RuntimeException('Unexpected messageId: ' . $messageId);
        }
        $messageDef = $configurationMessages[$messageId];

        $fieldDef = null;

        //Einige Messages (z. B. z1ActualRoomTempDesired) haben mehr als nur ein Feld, aber nur ein Feld ist relevant
        foreach ($messageDef['fielddefs'] as $currentField) {
            if ($currentField['type'] !== 'IGN') {
                $fieldDef = $currentField;
                break;
            }
        }

        if ($fieldDef === null) {
            trigger_error('no valid fielDef found');
            return '';
        }

        $ebusTypes = $this->getEbusDataTypeDefinitions();
        if (!isset($ebusTypes[$fieldDef['type']])) {
            trigger_error('Unsupported ebus type: ' . $fieldDef['type']);
            return '';
        }


        $dataTypeDef = $ebusTypes[$fieldDef['type']];

        return match ($dataTypeDef['VariableType']) {
            VARIABLETYPE_INTEGER, VARIABLETYPE_STRING => (string)$Value,
            VARIABLETYPE_FLOAT => number_format((float)$Value, $dataTypeDef['Digits'] ?? 0, '.', ''),
            VARIABLETYPE_BOOLEAN => (string)((int)(bool)$Value),
            default => throw new Exception('Unexpected VariableType: ' . $dataTypeDef['VariableType']),
        };
    }

    private function isArchived(string $ident): bool
    {
        $ahID = $this->GetArchiveHandlerID();
        if ($ahID === 0) {
            return false;
        }
        $varID = @$this->GetIDForIdent($ident);
        if ($varID === 0) {
            return false;
        }
        return AC_GetLoggingStatus($ahID, $varID);
    }

    protected function readURL(string $url): ?array
    {
        $this->logDebug(__FUNCTION__, 'URL: ' . $url);
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // Schneller Abbruch, wenn Host nicht erreichbar ist
        curl_setopt($ch, CURLOPT_USERAGENT, 'Symcon eBUS Module');

        $result_json = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($result_json === false) {
            $this->logDebug(__FUNCTION__, sprintf('CURL Error: %s for %s', $curlError, $url));
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logDebug(__FUNCTION__, sprintf('HTTP Error: %d for %s', $httpCode, $url));
            return null;
        }

        try {
            $data = json_decode($result_json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (JsonException $e) {
            $this->logDebug(__FUNCTION__, sprintf('JSON Decode Error: %s. Content: %s', $e->getMessage(), substr($result_json, 0, 100)));
            return null;
        }
    }

    protected function resolveAssociationValue(string $value, array $valueMap): ?int
    {
        if ($this->trace) {
            $this->logDebug(__FUNCTION__, sprintf('Value: %s, Associations: %s', $value, json_encode($valueMap, JSON_THROW_ON_ERROR)));
        }

        foreach ($valueMap as $association) {
            [$id, $text] = $association;
            if ($text === $value) {
                return $id;
            }
        }
        return null;
    }

    protected function getIPSVariableType(array $fielddef): int
    {
        $type = $fielddef['type'] ?? '';

        if (!isset($this->getEbusDataTypeDefinitions()[$type])) {
            trigger_error('Unsupported type: ' . $type);
        }

        // Ein Divisor erzwingt in der IPS-Logik immer einen Float,
        // um Nachkommastellen darzustellen.
        if (($fielddef['divisor'] ?? 0) > 0) {
            return VARIABLETYPE_FLOAT;
        }

        return $this->getEbusDataTypeDefinitions()[$type]['VariableType'];
    }

    protected function getFieldIdentName(array $message, int $fieldId): string
    {
        $name      = $message['name'];
        $fielddefs = $message['fielddefs'] ?? [];
        $count     = count($fielddefs);

        // Spezialfall: tempsensor oder presssensor (2 Felder)
        if ($count === 2) {
            $combined = ($fielddefs[0]['name'] ?? '') . ($fielddefs[1]['name'] ?? '');
            if ($combined === 'tempsensor' || $combined === 'presssensor') {
                return $fieldId === 0 ? $name : $name . '_sensorstatus';
            }
        }

        // Spezialfall: tempmirrorsensor (3 Felder)
        if ($count === 3) {
            $combined = ($fielddefs[0]['name'] ?? '') . ($fielddefs[1]['name'] ?? '') . ($fielddefs[2]['name'] ?? '');
            if ($combined === 'tempmirrorsensor') {
                if ($fieldId === 0) {
                    return $name;
                }
                if ($fieldId === 1) {
                    return $name . '_tempmirror';
                }
                return $name . '_sensorstatus';
            }
        }

        // Standardfall: Wenn mehr als 1 nutzbares Feld vorhanden ist, Index anhängen
        if ($this->countRelevantFieldDefs($fielddefs) > 1) {
            $name .= '_' . $fieldId;
        }

        // Alles außer a-z, 0-9 und _ ersetzen
        return preg_replace('/[^a-z0-9_]/i', '_', $name);
    }

    protected function countRelevantFieldDefs(array $fieldDefs): int
    {
        $count = 0;
        foreach ($fieldDefs as $f) {
            if (($f['type'] ?? '') !== 'IGN') {
                $count++;
            }
        }
        return $count;
    }

    protected function getFieldLabel(array $message, int $fieldId): string
    {
        //wenn die Meldung kommentiert ist und es nur ein Feld in der Meldung gibt, dann wird dieser Kommentar genommen
        if (!empty($message['comment']) && ($this->countRelevantFieldDefs($message['fielddefs']) === 1)) {
            return $message['comment'];
        }

        //wenn die Meldung kommentiert ist, aber aus mehreren Einträgen besteht
        if (!empty($message['comment'])) {
            $labels      = explode('/', $message['comment']);
            $countLabels = count($labels);
            if (($countLabels > 1) && ($fieldId < $countLabels)) {
                return $labels[$fieldId];
            }
        }

        //fielddefs auswerten:
        $fielddefs = $message['fielddefs'];

        //Spezialfall tempsensor, presssensor
        if ((count($fielddefs) === 2) && in_array($fielddefs[0]['name'] . $fielddefs[1]['name'], ['tempsensor', 'presssensor'])) {
            if ($fieldId === 0) {
                return $message['comment'];
            }
            return $message['comment'] . ' (Sensor)';
        }

        //Spezialfall tempmirrorsensor
        if ((count($fielddefs) === 3) && ($fielddefs[0]['name'] . $fielddefs[1]['name'] . $fielddefs[2]['name'] === 'tempmirrorsensor')) {
            if ($fieldId === 0) {
                return $message['comment'];
            }
            if ($fieldId === 1) {
                return $message['comment'] . ' (TempMirror)';
            }
            return $message['comment'] . ' (Sensor)';
        }

        //Spezialfall TTM Zeiten (von/bis)
        if ($message['fielddefs'][$fieldId]['type'] === 'TTM') {
            if (($fieldId % 2) === 1) {
                return sprintf('%s %s %s', $message['comment'], ceil($fieldId / 2), $this->Translate('from'));
            }
            return sprintf('%s %s %s', $message['comment'], ceil($fieldId / 2), $this->Translate('to'));
        }

        if ($message['fielddefs'][$fieldId]['comment'] !== '') {
            if ($message['fielddefs'][$fieldId]['comment'] === 'Temperatur') {
                return sprintf('%s (%s)', $message['fielddefs'][$fieldId]['comment'], $message['fielddefs'][$fieldId]['name']);
            }
            return $message['fielddefs'][$fieldId]['comment'];
        }

        return $message['fielddefs'][$fieldId]['name'];
    }

    protected function logDebug(string $message, string $data): void
    {
        $this->SendDebug($message, $data, 0);

        if (function_exists('IPSLogger_Dbg') && $this->ReadPropertyBoolean(self::PROP_WRITEDEBUGINFORMATIONTOIPSLOGGER)) {
            IPSLogger_Dbg(__CLASS__ . '.' . IPS_GetObject($this->InstanceID)['ObjectName'] . '.' . $message, $data);
        }
    }

}

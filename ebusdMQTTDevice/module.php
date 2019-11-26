<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);
require_once __DIR__ . '/../libs/eBUS_MQTT_Helper.php';
//"onClick": "$module = new IPSModule($id); IPS_RequestAction($id, 'CreateVariables', $VariableList);"


class ebusdMQTTDevice extends IPSModule
{
    use ebusd2MQTTHelper;

    private const STATUS_INST_IP_IS_EMPTY      = 202;
    private const STATUS_INST_IP_IS_INVALID    = 204; //IP Adresse ist ungültig
    private const STATUS_INST_TOPIC_IS_INVALID = 203;

    //property names
    private const PROP_HOST           = 'Host';
    private const PROP_PORT           = 'Port';
    private const PROP_CIRCUITNAME    = 'CircuitName';
    private const PROP_UPDATEINTERVAL = 'UpdateInterval';

    //attribute names
    private const ATTR_EBUSD_CONFIGURATION_MESSAGES = 'ebusdConfigurationMessages';
    private const ATTR_VARIABLELIST                 = 'VariableList';

    //timer names
    private const TIMER_REFRESH_ALL_MESSAGES = 'refreshAllMessages';


    private $trace = true;

    // die von ebusd unterstützen Datentypen
    //siehe https://github.com/john30/ebusd/wiki/4.3.-Builtin-data-types
    //die Liste ist noch nicht vollständig! :todo

    private const DataTypes = [
        // VARIABLETYPE_BOOLEAN
        'BI0' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        'BI1' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        'BI2' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        'BI3' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        'BI4' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        'BI5' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        'BI6' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        'BI7' => ['VariableType' => VARIABLETYPE_BOOLEAN],
        // VARIABLETYPE_INTEGER
        'UCH' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 254, 'StepSize' => 1],
        'UIN' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 65534, 'StepSize' => 1],
        'SIN' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 32767, 'StepSize' => 1],
        'ULG' => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 0, 'StepSize' => 1], //MaxValue 4294967294 ist zu groß
        // VARIABLETYPE_FLOAT
        'D1C' => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => 0, 'MaxValue' => 100, 'StepSize' => 0.5, 'Digits' => 1],
        'D2B' => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -127.99, 'MaxValue' => 127.99, 'StepSize' => 0.01, 'Digits' => 2],
        'D2C' => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -2047.9, 'MaxValue' => 2047.9, 'StepSize' => 0.1, 'Digits' => 1],
        'FLT' => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -32.767, 'MaxValue' => 32.767, 'StepSize' => 0.001, 'Digits' => 3],
        'EXP' => ['VariableType' => VARIABLETYPE_FLOAT, 'MinValue' => -3.0e38, 'MaxValue' => 3.0e38, 'StepSize' => 0.001, 'Digits' => 3],
        // VARIABLETYPE_STRING
        'STR' => ['VariableType' => VARIABLETYPE_STRING],
        'HEX' => ['VariableType' => VARIABLETYPE_STRING],
        'BDA' => ['VariableType' => VARIABLETYPE_STRING],
        'HDA' => ['VariableType' => VARIABLETYPE_STRING],
        'BTI' => ['VariableType' => VARIABLETYPE_STRING],
        'HTI' => ['VariableType' => VARIABLETYPE_STRING],
        'VTI' => ['VariableType' => VARIABLETYPE_STRING],
        'VTM' => ['VariableType' => VARIABLETYPE_STRING],
        'TTM' => ['VariableType' => VARIABLETYPE_STRING]];

    //ok-Zeichen für die Auswahlliste
    private const OK_SIGN = "\u{2714}";

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}'); //MQTT Server

        $this->RegisterPropertyString(self::PROP_HOST, '');
        $this->RegisterPropertyString(self::PROP_PORT, '8080');
        $this->RegisterPropertyString(self::PROP_CIRCUITNAME, '');
        $this->RegisterPropertyInteger(self::PROP_UPDATEINTERVAL, 0);

        $this->RegisterAttributeString(self::ATTR_VARIABLELIST, '');
        $this->RegisterAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, '');

        $this->RegisterTimer(self::TIMER_REFRESH_ALL_MESSAGES, 0, 'EBM_refreshAllMessages(' . $this->InstanceID . ');');

    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        //Setze Filter für ReceiveData
        $filter = '.*(ebusd\/' . strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)) . '|ebusd\/global).*';
        if ($this->trace) {
            $this->SendDebug('Filter', $filter, 0);
        }
        $this->SetReceiveDataFilter($filter);

        $this->SetTimerInterval(self::TIMER_REFRESH_ALL_MESSAGES, $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL) * 60 * 1000);

        $this->SetInstanceStatus();
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug(__FUNCTION__, $JSONString, 0);

        $mqttTopic = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        if (empty($mqttTopic)) {
            return;
        }


        $Buffer = json_decode($JSONString, false);

        if (($Buffer === false) || ($Buffer === null) || !property_exists($Buffer, 'Topic') || !property_exists($Buffer, 'Payload')) {
            return;
        }

        // Buffer decodieren und in eine Variable schreiben
        $this->SendDebug('MQTT Topic/Payload', sprintf('Topic: %s -- Payload: %s', $Buffer->Topic, $Buffer->Payload), 0);

        $Payload = json_decode($Buffer->Payload, true);

        if (($Payload === false) || ($Payload === null)) {
            return;
        }

        //Globale Meldungen werden extra behandelt
        if (strpos($Buffer->Topic, MQTT_GROUP_TOPIC . '/global/') === 0) {
            $this->checkGlobalMessage($Buffer->Topic, $Buffer->Payload);
            return;
        }

        //Sicherheitsabfrage: hier sollten nur noch Meldungen zum Topic ankommen
        if (strpos($Buffer->Topic, sprintf('%s/%s/', MQTT_GROUP_TOPIC, $mqttTopic)) === false) {
            $this->SendDebug('MQTT Topic invalid', $Buffer->Topic, 0);
            return;
        }

        $messageId = str_replace(MQTT_GROUP_TOPIC . '/' . strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)) . '/', '', $Buffer->Topic);
        if ($this->trace) {
            $this->SendDebug('MQTT messageId', $messageId, 0);
        }

        // ist die Konfiguration der Message bekannt?
        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true);
        if (!array_key_exists($messageId, $configurationMessages)) {
            $this->SendDebug(
                'MQTT messageId - not found', sprintf('%s, %s', $messageId, $this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES)), 0
            );

            $this->LogMessage(sprintf('Message %s nicht in Konfiguration gefunden.', $messageId), KL_ERROR);
            return;
        }

        $messageDef = $configurationMessages[$messageId];

        //ist die Message zum Speichern markiert?
        $jsonVariableList = $this->ReadAttributeString(self::ATTR_VARIABLELIST);
        //$this->SendDebug(__FUNCTION__, 'VariableList:' . (int) $jsonVariableList, 0);
        $keep = false;
        if (is_string($jsonVariableList)) {
            foreach (json_decode($jsonVariableList, true) as $entry) {
                if (($entry['messagename'] === $messageId) && ($entry['keep'] === true)) {
                    $keep = true;
                    break;
                }
            }
        }

        if ($keep === false) {
            return;
        }

        foreach ($this->getFieldValues($messageDef, $Payload, false) as $value) {
            //wenn die Statusvariable existiert, dann wird sie geschrieben
            if ($this->GetIDForIdent($value['ident']) !== 0) {
                $this->SetValue($value['ident'], $value['value']);
            }
        }

    }

    public function GetConfigurationForm()
    {
        $Form                         = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $Form['actions'][0]['values'] = json_decode($this->ReadAttributeString(self::ATTR_VARIABLELIST), true);

        if ($this->trace) {
            $this->SendDebug('form', json_encode($Form), 0);
        }
        return json_encode($Form);
    }

    protected function SetValue($Ident, $Value): bool
    {
        $oldValue = $this->GetValue($Ident);

        $id = $this->GetIDForIdent($Ident);

        if (($oldValue === $Value) && (IPS_GetVariable($id)['VariableUpdated'] !== 0)) {
            $this->SendDebug(__FUNCTION__, sprintf('%s: %s - not changed', $Ident, $Value), 0);
            return true;
        }

        $this->SendDebug(
            __FUNCTION__, sprintf('%s: old: %s (%s), new: %s (%s)', $Ident, $oldValue, gettype($oldValue), $Value, gettype($Value)), 0
        );
        return parent::SetValue($Ident, $Value);
    }

    public function RequestAction($Ident, $Value) {
        switch ($Ident){
            case 'CreateVariables':
                $this->CreateVariables(json_decode($Value, true));
                echo 'Fertig';
                break;
        }
    }


    public function ReadConfiguration(): ?array
    {

        $mqttTopic = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        $url       = sprintf(
            'http://%s:%s/data/%s/?def&verbose&exact&write', $this->ReadPropertyString(self::PROP_HOST), $this->ReadPropertyString(self::PROP_PORT),
            $mqttTopic
        );
        $result    = $this->readURL($url);

        //alle Felder auswerten
        if (!array_key_exists($mqttTopic, $result)) {
            trigger_error(sprintf('configuration of topic \'%s\' not found (URL: %s)', $mqttTopic, $url));
            return null;
        }
        $configurationMessages = $result[$mqttTopic]['messages'];
        if (count($configurationMessages) === 1) {
            trigger_error('Unexpected count of messages: ' . count($configurationMessages));
            return null;
        }

        $configurationMessages = $this->selectAndPrepareConfigurationMessages($configurationMessages);

        $this->WriteAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, json_encode($configurationMessages));

        $variableList = $this->getVariableList(json_encode($configurationMessages));

        $this->UpdateFormField(self::ATTR_VARIABLELIST, 'values', $variableList);
        $this->WriteAttributeString(self::ATTR_VARIABLELIST, $variableList);

        return $configurationMessages;

    }

    public function ReadCurrentValues(): int
    {

        $mqttTopic = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        $readCounter        = 0;
        $progressBarCounter = 0;
        $variableList       = json_decode($this->ReadAttributeString(self::ATTR_VARIABLELIST), true);
        if (!is_array($variableList)) {
            return 0;
        }

        $formField = null;
        $this->UpdateFormField('ProgressBar', 'maximum', count($variableList));
        $this->UpdateFormField('ProgressBar', 'visible', true);
        foreach ($variableList as $entry) {
            $this->UpdateFormField('ProgressBar', 'current', $progressBarCounter++);
            $this->UpdateFormField('ProgressBar', 'caption', $entry['messagename']);
            if ($entry['readable'] === self::OK_SIGN) {
                $readCounter++;
                $entry['value'] = '' . $this->getCurrentValue($mqttTopic, $entry['messagename']);
            }
            $formField[] = $entry;
        }

        $this->SendDebug(__FUNCTION__, 'formField: ' . json_encode($formField), 0);
        $this->UpdateFormField('ProgressBar', 'visible', false);
        $this->UpdateFormField(self::ATTR_VARIABLELIST, 'values', json_encode($formField));
        return $readCounter;

    }

    public function CreateVariables(IPSList $variableList): int
    {
        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true);
        $count                 = 0;
        foreach ($variableList as $item) {
            if ($item['keep'] === true) {
                $this->RegisterVariablesOfMessage($configurationMessages[$item['messagename']]);
                $this->publish(
                    sprintf('%s/%s/%s/set', MQTT_GROUP_TOPIC, strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $item['messagename']),
                    '?' . (int) $item['pollpriority']
                );
                $count++;
            }
        }

        $this->WriteAttributeString(self::ATTR_VARIABLELIST, json_encode((array_values((array) $variableList))[2]));
        return $count;
    }

    public function refreshMessage(string $messageId): bool
    {
        $jsonVariableList = $this->ReadAttributeString(self::ATTR_VARIABLELIST);
        if ($jsonVariableList === '') {
            return false;
        }

        foreach (json_decode($jsonVariableList, true) as $entry) {
            if ($entry['messagename'] === $messageId) {
                if ($entry['keep'] && ($entry['readable'] === self::OK_SIGN)) {
                    $this->publish(sprintf('%s/%s/%s/get', MQTT_GROUP_TOPIC, strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $messageId), '');
                    return true;
                }
                return false;
            }
        }

        return false;
    }

    public function refreshAllMessages(): bool
    {
        $jsonVariableList = $this->ReadAttributeString(self::ATTR_VARIABLELIST);
        if ($jsonVariableList === '') {
            return false;
        }

        $ret = true;
        foreach (json_decode($jsonVariableList, true) as $entry) {
            if ($entry['keep'] && ($entry['readable'] === self::OK_SIGN)) {
                $this->publish(sprintf('%s/%s/%s/get', MQTT_GROUP_TOPIC, strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $entry['messagename']), '');
                $ret = true;
            }
        }

        return $ret;
    }

    private function getCurrentValue(string $mqttTopic, string $messageId): ?string
    {
        set_time_limit(10);

        $url    = sprintf(
            'http://%s:%s/data/%s/%s?def&verbose&exact&required&maxage=600', $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT), $mqttTopic, $messageId
        );
        $result = $this->readURL($url);

        //alle Felder auswerten
        if ((!array_key_exists($mqttTopic, $result)) || (!array_key_exists($messageId, $result[$mqttTopic]['messages']))) {
            $this->SendDebug(__FUNCTION__, sprintf('current values of message \'%s\' not found (URL: %s)', $messageId, $url), 0);
            return null;
        }

        $message = $result[$mqttTopic]['messages'][$messageId];

        $valueString = '';
        if (isset($message['fields'])) {
            foreach ($this->getFieldValues($message, $message['fields'], true) as $value) {
                $valueString .= $value['value'] . '/';
            }
            $valueString = substr($valueString, 0, -1);
        }

        return $valueString;
    }

    private function readURL(string $url): ?array
    {
        $this->SendDebug(__FUNCTION__, 'URL: ' . $url, 0);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); //max 5 Sekunden für Verbindungsaufbau

        $result_json = curl_exec($ch);
        curl_close($ch);

        if ($result_json === false) {
            $this->SendDebug('CURL return', sprintf('empty result for %s', $url), 0);
            return null;
        }
        return json_decode($result_json, true);

    }

    private function getFieldIdentName(array $message, int $fieldId): string
    {
        $ret       = $message['name'];
        $fielddefs = $message['fielddefs'];

        //Spezialfall tempsensor, presssensor
        if ((count($fielddefs) === 2) && in_array($fielddefs[0]['name'] . $fielddefs[1]['name'], ['tempsensor', 'presssensor'])) {
            if ($fieldId === 0) {
                return $message['name'];
            }
            return $message['name'] . '_sensorstatus';
        }
        //Spezialfall tempmirrorsensor
        if ((count($fielddefs) === 3) && ($fielddefs[0]['name'] . $fielddefs[1]['name'] . $fielddefs[2]['name'] === 'tempmirrorsensor')) {
            if ($fieldId === 0) {
                return $message['name'];
            }
            if ($fieldId === 1) {
                return $message['name'] . '_tempmirror';
            }
            return $message['name'] . '_sensorstatus';
        }

        if ($this->getNumberOfFieldDefs($fielddefs) > 1) {
            $ret .= '_' . $fieldId;
        }

        return preg_replace('/[^a-z0-9_]/i', '_', $ret); //alles bis auf a-z, A-Z, 0-9 und '_' durch '_' ersetzen
    }

    private function getNumberOfFieldDefs(array $fieldDefs): int
    {
        $count = 0;
        foreach ($fieldDefs as $fielddef) {
            if ($fielddef['type'] !== 'IGN') {
                $count++;
            }
        }
        return $count;
    }

    private function getFieldLabel(array $message, int $fieldId): string
    {
        if ($this->getNumberOfFieldDefs($message['fielddefs']) === 1) {
            return $message['comment'];
        }

        $labels = explode('/', $message['comment']);
        if (count($labels) > 1) {
            return $labels[$fieldId];
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

        if ($message['fielddefs'][$fieldId]['comment'] !== '') {
            if ($message['fielddefs'][$fieldId]['comment'] === 'Temperatur') {
                return sprintf('%s (%s)', $message['fielddefs'][$fieldId]['comment'], $message['fielddefs'][$fieldId]['name']);
            }
            return $message['fielddefs'][$fieldId]['comment'];
        }

        return $message['fielddefs'][$fieldId]['name'];
    }

    private function getFieldValue(string $messageId, array $fields, int $key, int $variableType, array $associations = [],
                                   bool $numericValues = false)
    {
        if ($this->trace) {
            $this->SendDebug(
                __FUNCTION__, sprintf(
                '%s[%s]: %s, %s, %s', $messageId, $key, $variableType, json_encode($fields), json_encode($associations)
            ), 0
            );
        }

        $fieldValues = array_values($fields); //den Key durch Index ersetzen

        if (!array_key_exists($key, $fieldValues)) {
            $this->SendDebug(
                __FUNCTION__, sprintf('key is not set: [%s]: %s', $key, json_encode($fieldValues)), 0
            );
            return null;
        }

        if (!array_key_exists('value', $fieldValues[$key])) {
            $this->SendDebug(
                __FUNCTION__, sprintf('Value is not set: [%s]: %s', $key, json_encode($fieldValues[$key])), 0
            );
            return null;
        }

        $value = $fieldValues[$key]['value'];

        if (!$numericValues && count($associations)) {
            if (is_string($fieldValues[$key]['value'])) {
                $value = $this->getValueOfAssoziation($fieldValues[$key]['value'], $associations);
                if ($value === null) {
                    $this->SendDebug(
                        __FUNCTION__, sprintf(
                        'Value %s of field %s not defined in associations %s', $fieldValues[$key]['value'], $key, json_encode($associations)
                    ), 0
                    );
                }
            } else {
                $value = $fieldValues[$key]['value'];
            }
        }


        if (!$numericValues) {
            switch ($variableType) {
                case VARIABLETYPE_BOOLEAN:
                    $ret = (bool) $value;
                    break;
                case VARIABLETYPE_INTEGER:
                    $ret = (int) $value;
                    break;
                case VARIABLETYPE_FLOAT:
                    $ret = (float) $value;
                    break;
                case VARIABLETYPE_STRING:
                    $ret = (string) $value;
                    break;
                default:
                    $ret = null;
                    trigger_error('Unexpected VariableType: ' . $variableType);
            }
        } else {
            $ret = $value;
        }

        if ($this->trace) {
            $this->SendDebug(__FUNCTION__, sprintf('return: %s', $ret), 0);
        }
        return $ret;

    }

    private function getValueOfAssoziation(string $value, $associations): ?int
    {
        if ($this->trace) {
            $this->SendDebug(__FUNCTION__, sprintf('Value: %s, Associations: %s', $value, json_encode($associations)), 0);
        }

        foreach ($associations as $assValue) {
            if ($assValue[1] === $value) {
                return $assValue[0];
            }
        }
        trigger_error(__FUNCTION__ . ': association value not found: ' . $value);
        return null;
    }

    private function getVariableList(string $jsonConfigurationMessages): string
    {
        $elements = [];
        foreach (json_decode($jsonConfigurationMessages, true) as $message) {
            $variableName = '';
            $keep         = false;
            foreach ($message['fielddefs'] as $fielddefkey => $fielddef) {
                $fieldLabel = $this->getFieldLabel($message, $fielddefkey);
                $fieldLabel = implode(
                    json_decode('["' . $fieldLabel . '"]', true)
                ); //wandelt unicode escape Sequenzen wie in 'd.27 Zubeh\u00f6rrelais 1' in utf8 um
                if (($fielddef['type'] !== 'IGN') && ($fieldLabel !== '')) {
                    $variableName .= '/' . $fieldLabel;
                }

                $ident = $this->getFieldIdentName($message, $fielddefkey);
                $keep  = $keep || @$this->GetIDForIdent($ident);
            }

            $elements[] = [
                'messagename'  => $message['name'],
                'variablename' => substr($variableName, 1),
                'identname'    => $this->getFieldIdentName($message, $fielddefkey),
                'readable'     => ($message['passive'] === false) ? self::OK_SIGN : '',
                'writable'     => ($message['write'] === true) ? self::OK_SIGN : '',
                'value'        => '',
                'keep'         => $keep,
                'pollpriority' => ''];
        }
        return json_encode($elements);
    }

    private function RegisterVariablesOfMessage(array $configurationMessage): void
    {
        foreach ($configurationMessage['fielddefs'] as $fielddefkey => $fielddef) {
            if ($this->trace) {
                $this->SendDebug(
                    __FUNCTION__, sprintf('Message: %s, Field: %s: %s', $configurationMessage['name'], $fielddefkey, json_encode($fielddef)), 0
                );
            }
            if ($fielddef['type'] === 'IGN') { //Ignore
                continue;
            }
            if ($fielddef['name'] !== '') {
                $profileName = 'EBM.' . $configurationMessage['name'] . '.' . $fielddef['name'];
            } else {
                $profileName = 'EBM.' . $configurationMessage['name'];
            }

            $ident      = $this->getFieldIdentName($configurationMessage, $fielddefkey);
            $objectName = $this->getFieldLabel($configurationMessage, $fielddefkey);
            if (($ident === '') || ($objectName === '')) {
                continue;
            }

            $variableTyp = $this->getIPSVariableType($fielddef['type']);

            //Assoziationen vorhanden
            if (isset($fielddef['values'])) {
                if ($this->trace) {
                    $this->SendDebug('Values', json_encode($fielddef['values']), 0);
                }
                $ass = [];
                if ($fielddef['unit'] !== '') {
                    $unit = ' ' . $fielddef['unit'];
                } else {
                    $unit = '';
                }
                foreach ($fielddef['values'] as $key => $value) {
                    $ass[] = [$key, $value . $unit, '', -1];
                }
                if ($this->trace) {
                    $this->SendDebug(
                        'Associations', sprintf(
                        'Name: "%s", Assoziationen: %s', $profileName, json_encode($ass)
                    ), 0
                    );
                }

                switch ($variableTyp) {
                    case VARIABLETYPE_INTEGER:
                        $this->RegisterProfileIntegerEx($profileName, '', '', '', $ass);
                        break;
                    case VARIABLETYPE_FLOAT:
                        $this->RegisterProfileFloatEx($profileName, '', '', '', $ass);
                        break;
                }
            } else {
                $TypeDef = self::DataTypes[$fielddef['type']];
                if ($fielddef['unit'] !== '') {
                    $unit = ' ' . $fielddef['unit'];
                } else {
                    $unit = '';
                }
                switch ($variableTyp) {
                    case VARIABLETYPE_INTEGER:
                        $this->RegisterProfileInteger(
                            $profileName, '', '', $unit, $TypeDef['MinValue'], $TypeDef['MaxValue'], $TypeDef['StepSize']
                        );
                        break;

                    case VARIABLETYPE_FLOAT:
                        $this->RegisterProfileFloat(
                            $profileName, '', '', $fielddef['unit'], $TypeDef['MinValue'], $TypeDef['MaxValue'], $TypeDef['StepSize'],
                            $TypeDef['Digits']
                        );
                }
            }

            switch ($variableTyp) {
                case VARIABLETYPE_BOOLEAN:
                    $id = $this->RegisterVariableBoolean($ident, $objectName, '~Switch');
                    if ($id > 0) {
                        $this->SendDebug(
                            __FUNCTION__, sprintf('Boolean Variable angelegt. Ident: %s, Label: %s, Profil: %s', $ident, $objectName, '~Switch'), 0
                        );
                    } else {
                        $this->SendDebug(
                            __FUNCTION__, sprintf(
                            'Boolean Variable konnte nicht angelegt werden. Ident: %s, Label: %s, Profil: %s', $ident, $objectName, '~Switch'
                        ), 0
                        );
                    }
                    break;

                case VARIABLETYPE_STRING:
                    $id = $this->RegisterVariableString($ident, $objectName);
                    if ($id > 0) {
                        $this->SendDebug(
                            __FUNCTION__, sprintf('String Variable angelegt. Ident: %s, Label: %s', $ident, $objectName), 0
                        );

                    } else {
                        $this->SendDebug(
                            __FUNCTION__, sprintf('String Variable konnte nicht angelegt werden. Ident: %s, Label: %s', $ident, $objectName), 0
                        );
                    }
                    break;

                case VARIABLETYPE_INTEGER:
                    $id = $this->RegisterVariableInteger($ident, $objectName, $profileName);
                    if ($id > 0) {
                        $this->SendDebug(
                            __FUNCTION__, sprintf('Integer Variable angelegt. Ident: %s, Label: %s', $ident, $profileName), 0
                        );
                    } else {
                        $this->SendDebug(
                            __FUNCTION__, sprintf('Integer Variable konnte nicht angelegt werden. Ident: %s, Label: %s', $ident, $profileName), 0
                        );
                    }
                    break;

                case VARIABLETYPE_FLOAT:
                    $id = $this->RegisterVariableFloat($ident, $objectName, $profileName);
                    if ($id > 0) {
                        $this->SendDebug(
                            __FUNCTION__, sprintf('Float Variable angelegt. Ident: %s, Label: %s', $ident, $profileName), 0
                        );
                    } else {
                        $this->SendDebug(
                            __FUNCTION__, sprintf('Float Variable konnte nicht angelegtwerden. Ident: %s, Label: %s', $ident, $profileName), 0
                        );
                    }
                    break;
            }

            if ($configurationMessage['write']) {
                $this->EnableAction($ident);
            }
        }
    }

    private function checkGlobalMessage(string $topic, string $payload): void
    {
        $this->SendDebug(__FUNCTION__, sprintf('%s: %s', $topic, $payload), 0);
    }

    private function selectAndPrepareConfigurationMessages(array $configurationMessages): array
    {
        $ret = [];

        foreach ($configurationMessages as $key => $message) {
            if (strpos($key, '-w') === false) {
                /** @noinspection MissingOrEmptyGroupStatementInspection */
                /** @noinspection PhpStatementHasEmptyBodyInspection */
                if ($message['passive'] || $message['write']) {
                    // continue;
                }
                $message['write']  = array_key_exists($key . '-w', $configurationMessages);
                $message['lastup'] = 0;
                $ret[$key]         = $message;
            }
        }
        return $ret;
    }

    private function getFieldValues(array $message, array $Payload, bool $numericValues = false): array
    {
        $ret         = [];
        $ignoredKeys = 0;
        foreach ($message['fielddefs'] as $fielddefkey => $fielddef) {
            if ($this->trace) {
                $this->SendDebug('--fielddef--: ', $fielddefkey . ':' . json_encode($fielddef), 0);
            }
            if ($fielddef['type'] === 'IGN') { //Ignore
                ++$ignoredKeys;
                continue;
            }
            $profileName = 'EBM.' . $message['name'] . '.' . $fielddef['name'];
            $ident       = $this->getFieldIdentName($message, $fielddefkey);
            $label       = $this->getFieldLabel($message, $fielddefkey);
            if (($ident === '') || ($label === '')) {
                continue;
            }

            $variableType = $this->getIPSVariableType($fielddef['type']);

            //Assoziationen vorhanden
            if (isset($fielddef['values'])) {
                if ($this->trace) {
                    $this->SendDebug('Values', json_encode($fielddef['values']), 0);
                }
                $ass = [];
                foreach ($fielddef['values'] as $key => $value) {
                    $ass[] = [$key, $value, '', -1];
                }
                if ($this->trace) {
                    $this->SendDebug(
                        'Associations', sprintf(
                        'Name: "%s", Suffix: "%s", Assoziationen: %s', $profileName, $fielddef['unit'], json_encode($ass)
                    ), 0
                    );
                }

                $value = $this->getFieldValue($message['name'], $Payload, $fielddefkey - $ignoredKeys, $variableType, $ass, $numericValues);
            } else {
                $value = $this->getFieldValue($message['name'], $Payload, $fielddefkey - $ignoredKeys, $variableType);
            }

            $ret[] = ['ident' => $ident, 'value' => $value];

        }
        return $ret;
    }

    private function getIPSVariableType(string $type): int
    {
        if (array_key_exists($type, self::DataTypes)) {
            return self::DataTypes[$type]['VariableType'];
        }

        trigger_error('Unsupported type: ' . $type, E_USER_ERROR);
        return -1;
    }

    private function SetInstanceStatus(): void
    {
        //IP Prüfen
        $host = $this->ReadPropertyString(self::PROP_HOST);

        if ($host === '') {
            $this->SetStatus(self::STATUS_INST_IP_IS_EMPTY);
            return;
        }

        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID); //IP Adresse ist ungültig
            return;
        }

        //Verbindung prüfen
        $url    = sprintf(
            'http://%s:%s/data/%s', $this->ReadPropertyString(self::PROP_HOST), $this->ReadPropertyString(self::PROP_PORT),
            strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME))
        );
        $result = $this->readURL($url);

        if (($result === null) || (!array_key_exists('global', $result))) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }

        if ((!array_key_exists('global', $result))) {
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        if (!array_key_exists(strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $result)) {
            $this->SetStatus(self::STATUS_INST_TOPIC_IS_INVALID);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }


}


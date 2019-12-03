<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);
require_once __DIR__ . '/../libs/eBUS_MQTT_Helper.php';


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
    private const ATTR_POLLPRIORITIES               = 'PollPriorities';
    private const ATTR_CIRCUITOPTIONLIST            = 'CircuitOptionList';
    private const ATTR_SIGNAL                       = 'GlobalSignal';
    private const ATTR_CHECKCONNECTIONTIMER         = 'CheckConnectionTimer';

    //timer names
    private const TIMER_REFRESH_ALL_MESSAGES = 'refreshAllMessages';
    private const TIMER_CHECK_CONNECTION     = 'checkConnection';

    //form element names
    private const FORM_ELEMENT_IDENTNAMES   = 'identname';
    private const FORM_ELEMENT_KEEP         = 'keep';
    private const FORM_ELEMENT_POLLPRIORITY = 'pollpriority';
    private const FORM_ELEMENT_MESSAGENAME  = 'messagename';
    private const FORM_ELEMENT_OBJECTIDENTS = 'objectidents';

    private $trace = false;

    // die von ebusd unterstützen Datentypen
    //siehe https://github.com/john30/ebusd/wiki/4.3.-Builtin-data-types

    private const DataTypes = [
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
        'SIN'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 32767, 'StepSize' => 1],
        'SIR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 32767, 'StepSize' => 1],
        'U3N'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 16777214, 'StepSize' => 1],
        'U3R'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 16777214, 'StepSize' => 1],
        'S3N'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -8388607, 'MaxValue' => 8388607, 'StepSize' => 1],
        'S3R'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -8388607, 'MaxValue' => 8388607, 'StepSize' => 1],
        'ULG'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 0, 'StepSize' => 1], //MaxValue 4294967294 ist zu groß
        'ULR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => 0, 'MaxValue' => 0, 'StepSize' => 1], //MaxValue 4294967294 ist zu groß
        'SLG'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -2147483647, 'MaxValue' => 2147483647, 'StepSize' => 1],
        'SLR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -2147483647, 'MaxValue' => 2147483647, 'StepSize' => 1],
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

    //ok-Zeichen für die Auswahlliste
    private const OK_SIGN = "\u{2714}";

    //global
    private const MODEL_GLOBAL_NAME  = 'global';
    private const EMPTY_OPTION_VALUE = ['caption' => '-', 'value' => ''];

    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}'); //MQTT Server

        $this->RegisterPropertyString(self::PROP_HOST, '');
        $this->RegisterPropertyString(self::PROP_PORT, '8080');
        $this->RegisterPropertyString(self::PROP_CIRCUITNAME, '');
        $this->RegisterPropertyInteger(self::PROP_UPDATEINTERVAL, 0);

        $this->RegisterAttributeString(self::ATTR_VARIABLELIST, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_POLLPRIORITIES, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_CIRCUITOPTIONLIST, json_encode([self::EMPTY_OPTION_VALUE], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeBoolean(self::ATTR_SIGNAL, true);
        $this->RegisterAttributeInteger(self::ATTR_CHECKCONNECTIONTIMER, 0);

        $this->RegisterTimer(self::TIMER_REFRESH_ALL_MESSAGES, 0, 'IPS_RequestAction(' . $this->InstanceID . ', "timerRefreshAllMessages", "");');
        $this->RegisterTimer(self::TIMER_CHECK_CONNECTION, 0, 'IPS_RequestAction(' . $this->InstanceID . ', "timerCheckConnection", "");');

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        //Setze Filter für ReceiveData
        $filter = sprintf('.*(ebusd\/%s|ebusd\/%s).*', strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), self::MODEL_GLOBAL_NAME);
        if ($this->trace) {
            $this->SendDebug('Filter', $filter, 0);
        }
        $this->SetReceiveDataFilter($filter);

        $this->checkConnection();

        if ($this->GetStatus() === IS_ACTIVE) {
            $this->SetTimerInterval(self::TIMER_REFRESH_ALL_MESSAGES, $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL) * 60 * 1000);
        } else {
            $this->SetTimerInterval(self::TIMER_REFRESH_ALL_MESSAGES, 0);
        }

        //we will set the instance status when the parent status changes
        $instIDMQTTServer = $this->GetParent($this->InstanceID);
        if ($instIDMQTTServer > 0) {
            $this->RegisterMessage($instIDMQTTServer, IM_CHANGESTATUS);
            $instIDMQTTServerSocket = $this->GetParent($instIDMQTTServer);
            if ($instIDMQTTServerSocket > 0) {
                $this->RegisterMessage($instIDMQTTServerSocket, IM_CHANGESTATUS);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug(__FUNCTION__, sprintf('Message: %s, Data: %s', $Message, json_encode($Data, JSON_THROW_ON_ERROR)), 0);

        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        switch ($Message) {
            case IPS_KERNELMESSAGE:
                if ($Data[0] === KR_READY) {
                    $this->ApplyChanges();
                }
                break;

            case IM_CHANGESTATUS:
                $this->ApplyChanges();
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $this->SendDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, json_encode($Value, JSON_THROW_ON_ERROR)), 0);

        if (!$this->HasActiveParent()) {
            $this->checkConnection();
            $this->SendDebug(__FUNCTION__, 'No active parent - ignored', 0);
            return;
        }

        switch ($Ident) {
            case 'btnReadCircuits':
                $this->setCircuitOptions();
                return;

            case 'btnReadConfiguration':
                $ret = $this->ReadConfiguration();
                if ($ret === null) {
                    $this->MsgBox('Fehler');
                } else {
                    $this->MsgBox(count($ret) . ' Einträge gefunden');
                }
                return;

            case 'timerCheckConnection':
                $this->checkConnection();
                return;

            case 'timerRefreshAllMessages':
                $this->refreshAllMessages();
                return;
        }

        $message = $Ident;

        $topic   = sprintf('%s/%s/%s/set', MQTT_GROUP_TOPIC, $this->ReadPropertyString(self::PROP_CIRCUITNAME), $Ident);
        $payload = $this->getPayload($message, $Value);
        $this->publish($topic, $payload);
    }

    public function ReceiveData($JSONString)
    {
        $this->SendDebug(__FUNCTION__, $JSONString, 0);

        //prüfen, ob CircuitName vorhanden
        $mqttTopic = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        if (empty($mqttTopic)) {
            return;
        }

        //prüfen, ob buffer gefüllt ist und Topic und Payload vorhanden sind
        $Buffer = json_decode($JSONString, false, 512, JSON_THROW_ON_ERROR);
        if (($Buffer === false) || ($Buffer === null) || !property_exists($Buffer, 'Topic') || !property_exists($Buffer, 'Payload')) {
            return;
        }

        //prüfen, ob Payload gefüllt ist
        $this->SendDebug('MQTT Topic/Payload', sprintf('Topic: %s -- Payload: %s', $Buffer->Topic, $Buffer->Payload), 0);

        /** @noinspection JsonEncodingApiUsageInspection */
        $Payload = json_decode($Buffer->Payload, true);

        /*
        if (json_last_error() !== 0) {
            $txtError =
                sprintf('ERROR! (will be solved with ebusd > 3.4) - JSON Error (%s) at Topic "%s": %s, json: %s', json_last_error(), $Buffer->Topic, json_last_error_msg(), $Buffer->Payload);
            $this->SendDebug(__FUNCTION__, $txtError, 0);
            //trigger_error($txtError, E_USER_NOTICE);
            return;
        }
        */

        //* ebusd schickt LF im json String! php 7.3.8 verträgt das nicht
        try {
            //$Buffer->Payload = str_replace("\n", '', $Buffer->Payload);
            $Payload = json_decode($Buffer->Payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $txtError = sprintf(
                'ERROR! (will be solved with ebusd > 3.4) - JSON Error (%s) at Topic "%s": %s, json: %s',
                json_last_error(),
                $Buffer->Topic,
                json_last_error_msg(),
                $Buffer->Payload
            );
            $this->SendDebug(__FUNCTION__, $txtError, 0);
            trigger_error($txtError, E_USER_NOTICE);
            //   $Payload = json_decode($Buffer->Payload, true);
        }
        //

        if ($Payload === null) {
            $this->SendDebug(__FUNCTION__, 'Payload is not set: ' . $Buffer->Payload, 0);
            return;
        }

        //Globale Meldungen werden extra behandelt
        if (strpos($Buffer->Topic, MQTT_GROUP_TOPIC . '/global/') === 0) {
            $this->checkGlobalMessage($Buffer->Topic, $Buffer->Payload);
            return;
        }

        //prüfen, ob der Topic korrekt ist
        if (strpos($Buffer->Topic, sprintf('%s/%s/', MQTT_GROUP_TOPIC, $mqttTopic)) === false) {
            $this->SendDebug('MQTT Topic invalid', $Buffer->Topic, 0);
            return;
        }

        $messageId = str_replace(MQTT_GROUP_TOPIC . '/' . strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)) . '/', '', $Buffer->Topic);
        if ($this->trace) {
            $this->SendDebug('MQTT messageId', $messageId, 0);
        }

        // ist die Konfiguration der Message bekannt?
        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true, 512, JSON_THROW_ON_ERROR);
        if (!array_key_exists($messageId, $configurationMessages)) {
            $this->SendDebug(
                'MQTT messageId - not found',
                sprintf('%s, %s', $messageId, $this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES)),
                0
            );

            $this->LogMessage(sprintf('Message %s nicht in Konfiguration gefunden.', $messageId), KL_ERROR);
            return;
        }

        $messageDef = $configurationMessages[$messageId];

        //ist die Message zum Speichern markiert?
        $jsonVariableList = $this->ReadAttributeString(self::ATTR_VARIABLELIST);

        $keep = false;
        foreach (json_decode($jsonVariableList, true, 512, JSON_THROW_ON_ERROR) as $entry) {
            if (($entry[self::FORM_ELEMENT_MESSAGENAME] === $messageId) && ($entry[self::FORM_ELEMENT_KEEP] === true)) {
                $keep = true;
                break;
            }
        }

        if ($keep === false) {
            return;
        }

        foreach ($this->getFieldValues($messageDef, $Payload, false) as $value) {
            //wenn die Statusvariable existiert, dann wird sie geschrieben
            if (@$this->GetIDForIdent($value['ident'])) {
                $this->SetValue($value['ident'], $value['value']);
            }
        }
    }

    public function GetConfigurationForm()
    {
        $variableList = json_decode($this->ReadAttributeString(self::ATTR_VARIABLELIST), true, 512, JSON_THROW_ON_ERROR);

        $Form                                                   =
            json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $Form['elements'][0]['items'][1]['items'][0]['options'] =
            json_decode($this->ReadAttributeString(self::ATTR_CIRCUITOPTIONLIST), true, 3, JSON_THROW_ON_ERROR);
        $Form['actions'][1]['values']                           = $this->getUpdatedVariableList($variableList);
        $Form['actions'][1]['columns'][6]['edit']['enabled']    = ($this->GetStatus() === IS_ACTIVE);
        $Form['actions'][1]['columns'][7]['edit']['enabled']    = ($this->GetStatus() === IS_ACTIVE);
        $Form['actions'][2]['items'][0]['enabled']              = ($this->GetStatus() === IS_ACTIVE);
        $Form['actions'][2]['items'][1]['enabled']              = ($this->GetStatus() === IS_ACTIVE);
        $Form['actions'][2]['items'][2]['enabled']              = ($this->GetStatus() === IS_ACTIVE);

        if ($this->trace) {
            $this->SendDebug(__FUNCTION__, json_encode($Form, JSON_THROW_ON_ERROR), 0);
        }
        return json_encode($Form, JSON_THROW_ON_ERROR);
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
            __FUNCTION__,
            sprintf('%s: old: %s (%s), new: %s (%s)', $Ident, $oldValue, gettype($oldValue), $Value, gettype($Value)),
            0
        );
        return parent::SetValue($Ident, $Value);
    }

    protected function SetStatus($Status): bool
    {
        $this->UpdateFormField('BtnReadConfiguration', 'enabled', $Status === IS_ACTIVE);
        $this->UpdateFormField('BtnReadValues', 'enabled', $Status === IS_ACTIVE);
        $this->UpdateFormField('BtnCreateUpdateVariables', 'enabled', $Status === IS_ACTIVE);

        return parent::SetStatus($Status);
    }

    //------------------------------------------------------------------------------------------------------------------------
    // my own public functions
    public function publish(string $topic, string $payload): void
    {
        $Data['DataID']           = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
        $Data['PacketType']       = 3;
        $Data['QualityOfService'] = 0;
        $Data['Retain']           = false;
        $Data['Topic']            = $topic;
        $Data['Payload']          = $payload;

        $DataJSON = json_encode($Data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $ret      = $this->SendDataToParent($DataJSON);
        $this->SendDebug(__FUNCTION__, sprintf('Call: %s, Return: %s', $DataJSON, $ret), 0);
    }

    /** @noinspection PhpUndefinedClassInspection */
    public function UpdateCurrentValues(IPSList $variableList): int
    {
        $mqttTopic = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        $readCounter        = 0;
        $progressBarCounter = 0;
        $variableList       = (array_values((array)$variableList))[2];

        $formField = null;
        $this->UpdateFormField('ProgressBar', 'maximum', count($variableList));
        $this->UpdateFormField('ProgressBar', 'visible', true);
        foreach ($variableList as $entry) {
            $this->UpdateFormField('ProgressBar', 'current', $progressBarCounter++);
            $this->UpdateFormField('ProgressBar', 'caption', $entry[self::FORM_ELEMENT_MESSAGENAME]);
            //alle lesbaren Werte holen
            if ($entry['readable'] === self::OK_SIGN) {
                $readCounter++;
                $entry['value'] = '' . $this->getCurrentValue($mqttTopic, $entry[self::FORM_ELEMENT_MESSAGENAME]);
            }
            $formField[] = $entry;
        }

        $this->SendDebug(__FUNCTION__, 'formField: ' . json_encode($formField, JSON_THROW_ON_ERROR), 0);
        $this->UpdateFormField('ProgressBar', 'visible', false);
        $this->UpdateFormField(self::ATTR_VARIABLELIST, 'values', json_encode($formField, JSON_THROW_ON_ERROR));
        return $readCounter;
    }

    /** @noinspection PhpUndefinedClassInspection */
    public function CreateAndUpdateVariables(IPSList $variableList): int
    {
        $this->SendDebug(__FUNCTION__, 'start', 0);
        $variableList = (array_values((array)$variableList))[2];

        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true, 512, JSON_THROW_ON_ERROR);
        $count                 = 0;
        foreach ($variableList as $item) {
            if ($item[self::FORM_ELEMENT_KEEP] === true) {
                $count += $this->RegisterVariablesOfMessage($configurationMessages[$item[self::FORM_ELEMENT_MESSAGENAME]]);
            }
        }

        //VariableList aktualisieren bzgl. der Idents
        $this->UpdateFormField('VariableList', 'values', json_encode($this->getUpdatedVariableList($variableList), JSON_THROW_ON_ERROR));

        //Pollprioritäten aktualisieren
        $oldPollPriorities = json_decode($this->ReadAttributeString(self::ATTR_POLLPRIORITIES), true, 512, JSON_THROW_ON_ERROR);
        $newPollPriorities = $this->getPollPriorities($variableList);

        $this->publishPollPriorities($oldPollPriorities, $newPollPriorities);
        $this->SendDebug(__FUNCTION__, json_encode($variableList, JSON_THROW_ON_ERROR), 0);

        $this->WriteAttributeString(self::ATTR_POLLPRIORITIES, json_encode($newPollPriorities, JSON_THROW_ON_ERROR));
        $this->WriteAttributeString(self::ATTR_VARIABLELIST, json_encode($variableList, JSON_THROW_ON_ERROR));
        return $count;
    }

    //------------------------------------------------------------------------------------------------------------------------
    // my private functions
    private function ReadConfiguration(): ?array
    {
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        $url         = sprintf(
            'http://%s:%s/data/%s/?def&verbose&exact&write',
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT),
            $circuitName
        );
        $result      = $this->readURL($url);

        //alle Felder des Ergebnisses auswerten
        if (!array_key_exists($circuitName, $result)) {
            trigger_error(sprintf('configuration of topic \'%s\' not found (URL: %s)', $circuitName, $url));
            return null;
        }
        $configurationMessages = $result[$circuitName]['messages'];
        if (count($configurationMessages) === 1) {
            trigger_error('Unexpected count of messages: ' . count($configurationMessages));
            return null;
        }

        //ebusd Konfiguration aufbereiten und als Attribut speichern
        $configurationMessages = $this->selectAndPrepareConfigurationMessages($configurationMessages);
        $this->WriteAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, json_encode($configurationMessages, JSON_THROW_ON_ERROR));

        //Ausgabeliste aufbereiten und als Attribut speichern
        $variableList = $this->getVariableList(json_encode($configurationMessages, JSON_THROW_ON_ERROR));
        $this->UpdateFormField(self::ATTR_VARIABLELIST, 'values', $variableList);
        $this->WriteAttributeString(self::ATTR_VARIABLELIST, $variableList);

        return $configurationMessages;
    }

    private function refreshAllMessages(): bool
    {
        $jsonVariableList = $this->ReadAttributeString(self::ATTR_VARIABLELIST);

        $ret = true;
        foreach (json_decode($jsonVariableList, true, 512, JSON_THROW_ON_ERROR) as $entry) {
            if ($entry[self::FORM_ELEMENT_KEEP] && ($entry['readable'] === self::OK_SIGN)) {
                $this->publish(
                    sprintf(
                        '%s/%s/%s/get',
                        MQTT_GROUP_TOPIC,
                        strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)),
                        $entry[self::FORM_ELEMENT_MESSAGENAME]
                    ),
                    ''
                );
                $ret = true;
            }
        }

        return $ret;
    }

    private function checkConnection(): void
    {
        $this->SetInstanceStatus();

        if ($this->trace) {
            $this->SendDebug(__FUNCTION__, 'InstanceStatus: ' . $this->GetStatus(), 0);
        }

        if ($this->GetStatus() === IS_INACTIVE) {
            $this->SetTimerInterval(self::TIMER_REFRESH_ALL_MESSAGES, $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL) * 60 * 1000);
            $checkConnectionTimer = min($this->ReadAttributeInteger(self::ATTR_CHECKCONNECTIONTIMER) * 2, 60 * 3); //3 Minuten
        } else {
            $this->SetTimerInterval(self::TIMER_REFRESH_ALL_MESSAGES, 0);
            $checkConnectionTimer = 0;
        }
        $this->WriteAttributeInteger(self::ATTR_CHECKCONNECTIONTIMER, $checkConnectionTimer);
        $this->SetTimerInterval(self::TIMER_CHECK_CONNECTION, $checkConnectionTimer * 60 * 1000);
    }

    private function getUpdatedVariableList(array $variableList): array
    {
        //Die VariablenListe wird aktualisiert: alle Messages werden daraufhin überprüft, ob die zugehörigen Variablen existieren
        $variableListUpdated = [];
        foreach ($variableList as $item) {
            //$this->SendDebug(__FUNCTION__, json_encode($item), 0);
            $identList     = [];
            $variableFound = false;
            foreach (explode('/', $item[self::FORM_ELEMENT_IDENTNAMES]) as $ident) {
                if (@$this->GetIDForIdent($ident)) {
                    if ($this->isArchived($ident)) {
                        $identList[] = $ident . '(A)';
                    } else {
                        $identList[] = $ident;
                    }
                    $variableFound = true;
                } else {
                    $identList[] = '';
                }
            }
            if ($variableFound) {
                $item[self::FORM_ELEMENT_OBJECTIDENTS] = implode(', ', $identList);
            } else {
                $item[self::FORM_ELEMENT_OBJECTIDENTS] = '';
            }

            $variableListUpdated[] = $item;
        }
        return $variableListUpdated;
    }

    private function setCircuitOptions(): void
    {
        $url = sprintf(
            'http://%s:%s/data',
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT)
        );

        $result = $this->readURL($url);

        if ($result === null) { //z.B. wenn Verbindungsdaten falsch oder leer
            $result = [];
        }

        $options = [self::EMPTY_OPTION_VALUE];

        foreach ($result as $circuitname => $circuit) {
            if (!in_array($circuitname, ['global', 'broadcast']) && strpos((string)$circuitname, 'scan.') !== 0) {
                $options[] = ['caption' => $circuitname, 'value' => (string)$circuitname];
            }
        }

        $optionVaulue = json_encode($options, JSON_THROW_ON_ERROR);
        $this->UpdateFormField(self::PROP_CIRCUITNAME, 'options', $optionVaulue);
        $this->WriteAttributeString(self::ATTR_CIRCUITOPTIONLIST, $optionVaulue);
    }

    private function getPollPriorities(array $variableList): array
    {
        $ret = [];
        foreach ($variableList as $item) {
            if ((int)$item[self::FORM_ELEMENT_POLLPRIORITY] > 0) {
                $ret[$item[self::FORM_ELEMENT_MESSAGENAME]] = (int)$item[self::FORM_ELEMENT_POLLPRIORITY];
            }
        }
        return $ret;
    }

    private function publishPollPriorities(array $oldPollPriorities, array $newPollPriorities = []): void
    {
        $newItems        = array_diff($newPollPriorities, $oldPollPriorities);
        $deprecatedItems = array_diff($oldPollPriorities, $newPollPriorities);
        $changedItems    = array_diff($newPollPriorities, $newItems, $deprecatedItems);
        $this->SendDebug(
            __FUNCTION__,
            sprintf(
                'new: %s, deprecated: %s, changed: %s',
                json_encode($newItems, JSON_THROW_ON_ERROR),
                json_encode($deprecatedItems, JSON_THROW_ON_ERROR),
                json_encode($changedItems, JSON_THROW_ON_ERROR)
            ),
            0
        );
        foreach ($deprecatedItems as $messagename => $pollPriority) {
            $this->publish(
                sprintf('%s/%s/%s/get', MQTT_GROUP_TOPIC, strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $messagename),
                '?0'
            );
        }
        foreach (array_merge($newItems, $changedItems) as $messagename => $pollPriority) {
            $this->publish(
                sprintf('%s/%s/%s/get', MQTT_GROUP_TOPIC, strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $messagename),
                '?' . (int)$pollPriority
            );
        }
    }

    private function getCurrentValue(string $mqttTopic, string $messageId): ?string
    {
        set_time_limit(10);

        $url    = sprintf(
            'http://%s:%s/data/%s/%s?def&verbose&exact&required&maxage=600',
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT),
            $mqttTopic,
            $messageId
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
        return json_decode($result_json, true, 512, JSON_THROW_ON_ERROR);
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
        //wenn die Meldung kommentiert ist und es nur ein Feld in der Meldung gibt, dann wird dieser Kommentar genommen
        if (!empty($message['comment']) && ($this->getNumberOfFieldDefs($message['fielddefs']) === 1)) {
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

        if ($message['fielddefs'][$fieldId]['comment'] !== '') {
            if ($message['fielddefs'][$fieldId]['comment'] === 'Temperatur') {
                return sprintf('%s (%s)', $message['fielddefs'][$fieldId]['comment'], $message['fielddefs'][$fieldId]['name']);
            }
            return $message['fielddefs'][$fieldId]['comment'];
        }

        return $message['fielddefs'][$fieldId]['name'];
    }

    private function getFieldValue(
        string $messageId,
        array $fields,
        int $key,
        int $variableType,
        array $associations = [],
        bool $numericValues = false
    ) {
        if ($this->trace) {
            $this->SendDebug(
                __FUNCTION__,
                sprintf(
                    '%s[%s]: %s, %s, %s',
                    $messageId,
                    $key,
                    $variableType,
                    json_encode($fields, JSON_THROW_ON_ERROR),
                    json_encode($associations, JSON_THROW_ON_ERROR)
                ),
                0
            );
        }

        $fieldValues = array_values($fields); //den Key durch Index ersetzen

        if (!array_key_exists($key, $fieldValues)) {
            $this->SendDebug(
                __FUNCTION__,
                sprintf('key is not set: [%s]: %s', $key, json_encode($fieldValues, JSON_THROW_ON_ERROR)),
                0
            );
            return null;
        }

        if (!array_key_exists('value', $fieldValues[$key])) {
            $this->SendDebug(
                __FUNCTION__,
                sprintf('Value is not set: [%s]: %s', $key, json_encode($fieldValues[$key], JSON_THROW_ON_ERROR)),
                0
            );
            return null;
        }

        $value = $fieldValues[$key]['value'];

        if (!$numericValues && count($associations)) {
            if (is_string($fieldValues[$key]['value'])) {
                $value = $this->getValueOfAssoziation($fieldValues[$key]['value'], $associations);
                if ($value === null) {
                    $this->SendDebug(
                        __FUNCTION__,
                        sprintf(
                            'Value %s of field %s not defined in associations %s',
                            $fieldValues[$key]['value'],
                            $key,
                            json_encode($associations, JSON_THROW_ON_ERROR)
                        ),
                        0
                    );
                }
            } else {
                $value = $fieldValues[$key]['value'];
            }
        }


        if (!$numericValues) {
            switch ($variableType) {
                case VARIABLETYPE_BOOLEAN:
                    $ret = (bool)$value;
                    break;
                case VARIABLETYPE_INTEGER:
                    $ret = (int)$value;
                    break;
                case VARIABLETYPE_FLOAT:
                    $ret = (float)$value;
                    break;
                case VARIABLETYPE_STRING:
                    $ret = (string)$value;
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
            $this->SendDebug(__FUNCTION__, sprintf('Value: %s, Associations: %s', $value, json_encode($associations, JSON_THROW_ON_ERROR)), 0);
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
        foreach (json_decode($jsonConfigurationMessages, true, 512, JSON_THROW_ON_ERROR) as $message) {
            $variableName = '';

            if (count($message['fielddefs']) === 0) {
                //einige wenige messages haben keine fielddefs
                // z.B.: wi,,ioteststop,I/O Test stoppen,,,,01,,,,,,
                $this->SendDebug(__FUNCTION__, sprintf('%s: No fielddefs found of message %s', __FUNCTION__, $message['name']), 0);
                continue;
            }

            $identNames         = [];
            $identNamesExisting = [];
            foreach ($message['fielddefs'] as $fielddefkey => $fielddef) {
                $fieldLabel = $this->getFieldLabel($message, $fielddefkey);
                $fieldLabel = implode(
                    json_decode('["' . $fieldLabel . '"]', true, 512, JSON_THROW_ON_ERROR)
                ); //wandelt unicode escape Sequenzen wie in 'd.27 Zubeh\u00f6rrelais 1' in utf8 um
                if (($fielddef['type'] !== 'IGN') && ($fieldLabel !== '')) {
                    $variableName .= '/' . $fieldLabel;
                }

                $ident        = $this->getFieldIdentName($message, $fielddefkey);
                $identNames[] = $ident;
                if (@$this->GetIDForIdent($ident)) {
                    if ($this->isArchived($ident)) {
                        $identNamesExisting[] = $ident . '(A)';
                    } else {
                        $identNamesExisting[] = $ident;
                    }
                }
            }

            if (count($identNames) === 0) {
                trigger_error(sprintf('%s: No idents found of message %s', __FUNCTION__, $message['name']), E_USER_NOTICE);
            }

            //keep wird aus der gespeicherten Liste geholt
            $storedItem = $this->getItemOfStoredVariableList($message['name']);
            if ($storedItem !== null) {
                $keep         = $storedItem[self::FORM_ELEMENT_KEEP];
                $pollpriority = $storedItem[self::FORM_ELEMENT_POLLPRIORITY];
            } else {
                $keep         = false;
                $pollpriority = 0;
            }

            $elements[] = [
                self::FORM_ELEMENT_MESSAGENAME  => $message['name'],
                'variablename'                  => substr($variableName, 1),
                self::FORM_ELEMENT_IDENTNAMES   => implode('/', $identNames),
                'readable'                      => ($message['passive'] === false) ? self::OK_SIGN : '',
                'writable'                      => ($message['write'] === true) ? self::OK_SIGN : '',
                'value'                         => '',
                self::FORM_ELEMENT_KEEP         => $keep,
                self::FORM_ELEMENT_OBJECTIDENTS => implode('/', $identNamesExisting),
                self::FORM_ELEMENT_POLLPRIORITY => $pollpriority
            ];
        }
        return json_encode($elements, JSON_THROW_ON_ERROR);
    }

    private function getItemOfStoredVariableList(string $messagename): ?array
    {
        $storedVariableList = json_decode($this->ReadAttributeString(self::ATTR_VARIABLELIST), true, 3, JSON_THROW_ON_ERROR);

        foreach ($storedVariableList as $item) {
            if ($item[self::FORM_ELEMENT_MESSAGENAME] === $messagename) {
                return $item;
            }
        }
        return null;
    }

    private function RegisterVariablesOfMessage(array $configurationMessage): int
    {
        $countOfVariables = 0;

        foreach ($configurationMessage['fielddefs'] as $fielddefkey => $fielddef) {
            if ($this->trace) {
                $this->SendDebug(
                    __FUNCTION__,
                    sprintf(
                        'Message: %s, Field: %s: %s',
                        $configurationMessage['name'],
                        $fielddefkey,
                        json_encode($fielddef, JSON_THROW_ON_ERROR)
                    ),
                    0
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
                    $this->SendDebug('Values', json_encode($fielddef['values'], JSON_THROW_ON_ERROR), 0);
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
                        'Associations',
                        sprintf(
                            'Name: "%s", Assoziationen: %s',
                            $profileName,
                            json_encode($ass, JSON_THROW_ON_ERROR)
                        ),
                        0
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
                            $profileName,
                            '',
                            '',
                            $unit,
                            $TypeDef['MinValue'],
                            $TypeDef['MaxValue'],
                            $TypeDef['StepSize']
                        );
                        break;

                    case VARIABLETYPE_FLOAT:
                        $this->RegisterProfileFloat(
                            $profileName,
                            '',
                            '',
                            $fielddef['unit'],
                            $TypeDef['MinValue'],
                            $TypeDef['MaxValue'],
                            $TypeDef['StepSize'],
                            $TypeDef['Digits']
                        );
                }
            }

            switch ($variableTyp) {
                case VARIABLETYPE_BOOLEAN:
                    $id = $this->RegisterVariableBoolean($ident, $objectName, '~Switch');
                    if ($id > 0) {
                        $countOfVariables++;
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf('Boolean Variable angelegt. Ident: %s, Label: %s, Profil: %s', $ident, $objectName, '~Switch'),
                            0
                        );
                    } else {
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf(
                                'Boolean Variable konnte nicht angelegt werden. Ident: %s, Label: %s, Profil: %s',
                                $ident,
                                $objectName,
                                '~Switch'
                            ),
                            0
                        );
                    }
                    break;

                case VARIABLETYPE_STRING:
                    $id = $this->RegisterVariableString($ident, $objectName);
                    if ($id > 0) {
                        $countOfVariables++;
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf('String Variable angelegt. Ident: %s, Label: %s', $ident, $objectName),
                            0
                        );
                    } else {
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf('String Variable konnte nicht angelegt werden. Ident: %s, Label: %s', $ident, $objectName),
                            0
                        );
                    }
                    break;

                case VARIABLETYPE_INTEGER:
                    $id = $this->RegisterVariableInteger($ident, $objectName, $profileName);
                    if ($id > 0) {
                        $countOfVariables++;
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf('Integer Variable angelegt. Ident: %s, Label: %s', $ident, $profileName),
                            0
                        );
                    } else {
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf('Integer Variable konnte nicht angelegt werden. Ident: %s, Label: %s', $ident, $profileName),
                            0
                        );
                    }
                    break;

                case VARIABLETYPE_FLOAT:
                    $id = $this->RegisterVariableFloat($ident, $objectName, $profileName);
                    if ($id > 0) {
                        $countOfVariables++;
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf('Float Variable angelegt. Ident: %s, Label: %s', $ident, $profileName),
                            0
                        );
                    } else {
                        $this->SendDebug(
                            __FUNCTION__,
                            sprintf('Float Variable konnte nicht angelegt werden. Ident: %s, Label: %s', $ident, $profileName),
                            0
                        );
                    }
                    break;
            }

            if ($configurationMessage['write']) {
                $this->EnableAction($ident);
            }
        }
        return $countOfVariables;
    }

    private function checkGlobalMessage(string $topic, string $payload): void
    {
        if ($topic === 'ebusd/global/signal') {
            $this->SendDebug(__FUNCTION__, sprintf('%s: %s', $topic, $payload), 0);
            $this->WriteAttributeBoolean(self::ATTR_SIGNAL, filter_var($payload, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE));
            $this->SetInstanceStatus();
        }
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
                $this->SendDebug('--fielddef--: ', $fielddefkey . ':' . json_encode($fielddef, JSON_THROW_ON_ERROR), 0);
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
                    $this->SendDebug('Values', json_encode($fielddef['values'], JSON_THROW_ON_ERROR), 0);
                }
                $ass = [];
                foreach ($fielddef['values'] as $key => $value) {
                    $ass[] = [$key, $value, '', -1];
                }
                if ($this->trace) {
                    $this->SendDebug(
                        'Associations',
                        sprintf(
                            'Name: "%s", Suffix: "%s", Assoziationen: %s',
                            $profileName,
                            $fielddef['unit'],
                            json_encode($ass, JSON_THROW_ON_ERROR)
                        ),
                        0
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
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        //IP Prüfen
        if ($host === '') {
            $this->SetStatus(self::STATUS_INST_IP_IS_EMPTY);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'empty Host'), 0);
            return;
        }

        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID); //IP Adresse ist ungültig
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid IP'), 0);
            return;
        }

        //Circuit prüfen
        if ($circuitName === self::MODEL_GLOBAL_NAME) {
            $this->SetStatus(self::STATUS_INST_TOPIC_IS_INVALID);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'Wrong Circuit name (global)'), 0);
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->SetStatus(IS_INACTIVE);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'Parent not active'), 0);
            return;
        }

        //Verbindung prüfen und circuits holen
        $url    = sprintf(
            'http://%s:%s/data/%s',
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT),
            $circuitName
        );
        $result = $this->readURL($url);

        if (($result === null) || !array_key_exists(self::MODEL_GLOBAL_NAME, $result)
            || !filter_var(
                $result[self::MODEL_GLOBAL_NAME]['signal'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            )) {
            $this->SetStatus(IS_INACTIVE);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid connection'), 0);
            return;
        }


        if (!array_key_exists($circuitName, $result)) {
            $this->SetStatus(self::STATUS_INST_TOPIC_IS_INVALID);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid circuit name'), 0);
            return;
        }

        if (!$this->ReadAttributeBoolean(self::ATTR_SIGNAL)) {
            $this->SetStatus(IS_INACTIVE);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'no signal'), 0);
            return;
        }

        if ($this->GetStatus() !== IS_ACTIVE) {
            $this->SetStatus(IS_ACTIVE);
            $this->SendDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'active'), 0);

            //wenn die Instanz wieder auf aktiv gesetzt wird, dann werden die Pollprioritäten erneut gesetzt
            $pollPriorities = json_decode($this->ReadAttributeString(self::ATTR_POLLPRIORITIES), true, 512, JSON_THROW_ON_ERROR);
            $this->publishPollPriorities([], $pollPriorities);
        }
    }
}


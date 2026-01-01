<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

if (function_exists('IPSUtils_Include')) {
    IPSUtils_Include('IPSLogger.inc.php', 'IPSLibrary::app::core::IPSLogger');
}

require_once __DIR__ . '/../libs/eBUS_MQTT_Helper.php';


class ebusdMQTTDevice extends IPSModule
{
    use ebusd2MQTTHelper;

    private const PT_PUBLISH = 3; //Packet Type Publish
    private const QOS_0      = 0; //Quality of Service 0

    private const STATUS_INST_PORT_IS_INVALID  = 202;
    private const STATUS_INST_IP_IS_INVALID    = 204;
    private const STATUS_INST_TOPIC_IS_INVALID = 203;

    //property names
    private const PROP_HOST                             = 'Host';
    private const PROP_PORT                             = 'Port';
    private const PROP_CIRCUITNAME                      = 'CircuitName';
    private const PROP_UPDATEINTERVAL                   = 'UpdateInterval';
    private const PROP_WRITEDEBUGINFORMATIONTOIPSLOGGER = 'WriteDebugInformationToIPSLogger';

    //attribute names
    private const ATTR_EBUSD_CONFIGURATION_MESSAGES = 'ebusdConfigurationMessages';
    private const ATTR_VARIABLELIST                 = 'VariableList';
    private const ATTR_POLLPRIORITIES               = 'PollPriorities';
    private const ATTR_CIRCUITOPTIONLIST            = 'CircuitOptionList';
    private const ATTR_SIGNAL                       = 'GlobalSignal';
    private const ATTR_CHECKCONNECTIONTIMER         = 'CheckConnectionTimer';

    //timer names
    private const TIMER_REQUEST_ALL_VALUES = 'requestAllValues';
    private const TIMER_CHECK_CONNECTION   = 'checkConnection';

    //form element names
    private const FORM_LIST_VARIABLELIST     = 'VariableList';
    private const FORM_ELEMENT_READABLE      = 'readable';
    private const FORM_ELEMENT_POLLPRIORITY  = 'pollpriority';
    private const FORM_ELEMENT_MESSAGENAME   = 'messagename';
    private const FORM_ELEMENT_VARIABLENAMES = 'variablenames';
    private const FORM_ELEMENT_IDENTNAMES    = 'identnames';
    private const FORM_ELEMENT_READVALUES    = 'readvalues';
    private const FORM_ELEMENT_KEEP          = 'keep';
    private const FORM_ELEMENT_OBJECTIDENTS  = 'objectidents';

    private bool $trace               = false;

    private bool $testFunctionsActive = false; //button "Publish Poll Priorities" aktivieren

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
        'SIN'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -32767, 'MaxValue' => 32767, 'StepSize' => 1],
        'SIR'   => ['VariableType' => VARIABLETYPE_INTEGER, 'MinValue' => -32767, 'MaxValue' => 32767, 'StepSize' => 1],
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

    //ok-Zeichen für die Auswahlliste (siehe https://www.compart.com/de/unicode/)
    private const OK_SIGN = "\u{2714}";
    //Leerzeichen für Profile mit % im Suffix
    private const ZERO_WIDTH_SPACE = "\u{200B}";

    //global
    private const MODEL_GLOBAL_NAME  = 'global';
    private const EMPTY_OPTION_VALUE = ['caption' => '-', 'value' => ''];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent(self::MODULE_ID_MQTT_SERVER);

        $this->RegisterPropertyString(self::PROP_HOST, '');
        // We use String for Port to keep compatibility with existing instances.
        // Changing this to Integer would reset the value for users during an update.
        $this->RegisterPropertyString(self::PROP_PORT, '8080');
        $this->RegisterPropertyString(self::PROP_CIRCUITNAME, '');
        $this->RegisterPropertyInteger(self::PROP_UPDATEINTERVAL, 0);
        $this->RegisterPropertyBoolean(self::PROP_WRITEDEBUGINFORMATIONTOIPSLOGGER, false);


        $this->RegisterAttributeString(self::ATTR_VARIABLELIST, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_POLLPRIORITIES, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_CIRCUITOPTIONLIST, json_encode([self::EMPTY_OPTION_VALUE], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, json_encode([], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeBoolean(self::ATTR_SIGNAL, true);
        $this->RegisterAttributeInteger(self::ATTR_CHECKCONNECTIONTIMER, 0);

        $this->RegisterTimer(self::TIMER_REQUEST_ALL_VALUES, 0, 'IPS_RequestAction(' . $this->InstanceID . ', "timerRefreshAllMessages", "");');
        $this->RegisterTimer(self::TIMER_CHECK_CONNECTION, 0, 'IPS_RequestAction(' . $this->InstanceID . ', "timerCheckConnection", "");');

        //we will wait until the kernel is ready
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        // 1. Grundlegende Validierung der Eigenschaften
        $circuitName = $this->ReadPropertyString(self::PROP_CIRCUITNAME);
        if ($circuitName === '') {
            $this->SetStatus(self::STATUS_INST_TOPIC_IS_INVALID);
            $this->SetTimerInterval(self::TIMER_REQUEST_ALL_VALUES, 0);
            return;
        }

        // 2. Nachrichten-Registrierung (Wichtig für Status-Updates der Instanz-Hierarchie)
        $this->unregisterParentMessages();
        $this->registerParentMessages();

        // 3. Filter setzen (nur wenn ein Parent da ist)
        if ($this->HasActiveParent()) {
            $filter = sprintf('.*(ebusd\/%s|ebusd\/%s).*', strtolower($circuitName), self::MODEL_GLOBAL_NAME);
            if ($this->trace) {
                $this->logDebug('Filter', $filter);
            }
            // SetReceiveDataFilter prüft intern meist auf Änderungen, wir können es hier sicher aufrufen
            $this->SetReceiveDataFilter($filter);
        }

        // 4. Verbindung und Detail-Status prüfen
        // Dies setzt intern den Status (ACTIVE/INACTIVE/ERROR)
        $this->checkConnection();

        // 5. Abhängig vom resultierenden Status Timer und Prioritäten schalten
        $currentStatus = $this->GetStatus();
        if ($currentStatus === IS_ACTIVE) {
            $interval = $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL) * 60 * 1000;
            $this->SetTimerInterval(self::TIMER_REQUEST_ALL_VALUES, $interval);

            // Einmalig die Poll-Prioritäten pushen (verzögert, damit der Parent sicher bereit ist)
            $pollPriorities = $this->ReadAttributeString(self::ATTR_POLLPRIORITIES);
            $this->RegisterOnceTimer(
                'DeferredPollPriorities',
                sprintf(
                    'IPS_RequestAction(%d, "%s", %s);',
                    $this->InstanceID,
                    'publishPollPriorities',
                    var_export(json_encode(['old' => [], 'new' => json_decode($pollPriorities, true)], JSON_THROW_ON_ERROR), true)
                )
            );
        } else {
            // Wenn nicht ACTIVE, sollte der Refresh-Timer aus sein.
            // Der CheckConnection-Timer wird bereits in checkConnection() gesteuert.
            $this->SetTimerInterval(self::TIMER_REQUEST_ALL_VALUES, 0);
        }

        // 6. Summary setzen
        $this->SetSummary(
            sprintf(
                '%s:%s (%s)',
                $this->ReadPropertyString(self::PROP_HOST),
                $this->ReadPropertyString(self::PROP_PORT),
                $circuitName
            )
        );
    }

    private function unregisterParentMessages(): void
    {
        // Alle bisherigen Registrierungen für IM_CHANGESTATUS löschen, um Dubletten zu vermeiden
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $msgList) {
            if (in_array(IM_CHANGESTATUS, $msgList, true)) {
                $this->UnregisterMessage($senderID, IM_CHANGESTATUS);
            }
        }
    }

    private function registerParentMessages(): void
    {
        // 1. Parent (z.B. MQTT Client)
        $parentId = $this->GetParent($this->InstanceID);
        if ($parentId > 0 && IPS_InstanceExists($parentId)) {
            $this->RegisterMessage($parentId, IM_CHANGESTATUS);

            // 2. Parent des Parents (z.B. Client Socket / Splitter)
            $grandParentId = $this->GetParent($parentId);
            if ($grandParentId > 0 && IPS_InstanceExists($grandParentId)) {
                $this->RegisterMessage($grandParentId, IM_CHANGESTATUS);
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        $this->logDebug(
            __FUNCTION__,
            sprintf('SenderID: %s, Message: %s, Data: %s', $SenderID, $Message, json_encode($Data, JSON_THROW_ON_ERROR))
        );

        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        switch ($Message) {
            case IPS_KERNELMESSAGE: //the kernel status has changed
                if ($Data[0] === KR_READY) {
                    $this->ApplyChanges();
                }
                break;

            case IM_CHANGESTATUS: // the parent status has changed
                // $Data[0] ist der neue Status des Senders (Parent)
                // Wir führen ApplyChanges nur aus, wenn der Parent aktiv wurde
                // oder wenn wir aktuell nicht aktiv sind und eine Chance auf Besserung besteht.

                $newParentStatus = $Data[0];
                $currentStatus   = $this->GetStatus();

                // Logik: Nur neu konfigurieren, wenn der Parent nun bereit ist
                // oder wenn wir bisher wegen des Parents inaktiv waren.
                if ($newParentStatus === IS_ACTIVE || $currentStatus !== IS_ACTIVE) {
                    $this->logDebug(__FUNCTION__, 'Parent status changed to ' . $newParentStatus . '. Triggering ApplyChanges.');
                    $this->ApplyChanges();
                }
                break;
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        $this->logDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, json_encode($Value, JSON_THROW_ON_ERROR)));

        if (!$this->HasActiveParent()) {
            $this->checkConnection();
            $this->logDebug(__FUNCTION__, 'No active parent - ignored');
            return;
        }

        // Hilfsfunktion für JSON-Decoding von $Value
        $decodeValue = function () use ($Value) {
            return json_decode((string)$Value, true, 512, JSON_THROW_ON_ERROR);
        };

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

            case 'btnReadValues':
                $ret = $this->UpdateCurrentValues($decodeValue());
                $this->MsgBox($ret . ' Werte gelesen');
                return;

            case 'btnCreateUpdateVariables':
                $ret = $this->CreateAndUpdateVariables($decodeValue());
                $this->MsgBox($ret . ' Variablen angelegt/aktualisiert');
                return;

            case 'btnPublishPollPriorities':
                $pollPriorities = json_decode($this->ReadAttributeString(self::ATTR_POLLPRIORITIES), true, 512, JSON_THROW_ON_ERROR);
                $this->publishPollPriorities([], $pollPriorities);
                $this->MsgBox('OK');
                return;

            case 'timerCheckConnection':
                $this->checkConnection();
                return;

            case 'timerRefreshAllMessages':
                $this->requestAllValues();
                return;

            case 'publishPollPriorities':
                $priorities = $decodeValue();
                $this->publishPollPriorities($priorities['old'], $priorities['new']);
                return;

            case 'VariableList_onEdit':
                $parameter = json_decode($Value, true, 512, JSON_THROW_ON_ERROR);
                if ($parameter['readable'] !== self::OK_SIGN) {
                    $this->MsgBox(
                        sprintf(
                            'Die Variable "%s" ist nicht lesbar. Die Änderungen werden nicht gespeichert.',
                            $parameter['messagename'] ?? 'unbekannt'
                        )
                    );
                }
                return;
        }

        $topic   = sprintf('%s/%s/%s/set', MQTT_GROUP_TOPIC, $this->ReadPropertyString(self::PROP_CIRCUITNAME), $Ident);
        $payload = $this->getPayload($Ident, $Value);
        $this->publish($topic, $payload);
    }

    public function ReceiveData($JSONString): string
    {
        $this->logDebug(__FUNCTION__, $JSONString);

        //prüfen, ob CircuitName vorhanden
        $mqttTopicProperty = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        if (empty($mqttTopicProperty)) {
            return '';
        }
        $mqttTopicLower = strtolower($mqttTopicProperty);


        // prüfen, ob buffer korrektes JSON ist
        try {
            $Buffer = json_decode($JSONString, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }

        //prüfen, ob Topic und Payload vorhanden sind
        if (!isset($Buffer->Topic, $Buffer->Payload)) {
            return '';
        }

        //Globale Meldungen werden extra behandelt
        if (str_starts_with($Buffer->Topic, MQTT_GROUP_TOPIC . '/global/')) {
            $this->checkGlobalMessage($Buffer->Topic, $Buffer->Payload);
            return '';
        }

        //prüfen, ob der Topic korrekt ist
        $expectedPrefix = MQTT_GROUP_TOPIC . '/' . $mqttTopicLower . '/';
        if (!str_starts_with($Buffer->Topic, $expectedPrefix)) {
            return '';
        }

        $this->logDebug('MQTT Topic/Payload', sprintf('Topic: %s -- Payload: %s', $Buffer->Topic, $Buffer->Payload));

        // Payload dekodieren (ebusd > 3.4 sendet valides JSON, ältere Versionen evtl. nicht)
        try {
            $Payload = json_decode($Buffer->Payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $txtError = sprintf(
                'ERROR! (ebusd version issue?) - JSON Error (%s) at Topic "%s": %s, json: %s',
                json_last_error(),
                $Buffer->Topic,
                json_last_error_msg(),
                $Buffer->Payload
            );
            $this->logDebug(__FUNCTION__ . ' (ERROR)', $txtError);
            return '';
        }

        if ($Payload === null) {
            $this->logDebug(__FUNCTION__, 'Payload is null: ' . $Buffer->Payload);
            return '';
        }

        $messageId = str_replace(MQTT_GROUP_TOPIC . '/' . $mqttTopicLower . '/', '', $Buffer->Topic);
        if ($this->trace) {
            $this->logDebug('MQTT messageId', $messageId);
        }

        // ist die Konfiguration der Message bekannt?
        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true, 512, JSON_THROW_ON_ERROR);
        if (!isset($configurationMessages[$messageId])) {
            $this->logDebug(
                'MQTT messageId - not found',
                sprintf('%s, %s', $messageId, $this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES))
            );

            $this->LogMessage(sprintf('Message %s nicht in Konfiguration gefunden.', $messageId), KL_ERROR);
            return '';
        }

        // Prüfen, ob die Message zum Speichern markiert ist
        $variableList = json_decode($this->ReadAttributeString(self::ATTR_VARIABLELIST), true, 512, JSON_THROW_ON_ERROR);
        $keep         = false;
        foreach ($variableList as $entry) {
            if ($entry[self::FORM_ELEMENT_MESSAGENAME] === $messageId) {
                $keep = $entry[self::FORM_ELEMENT_KEEP] ?? false;
                break;
            }
        }

        if (!$keep) {
            $this->logDebug('MQTT messageId - skip', "Message '$messageId' is not marked to be stored");
            return '';
        }

        // Werte verarbeiten
        $messageDef = $configurationMessages[$messageId];
        foreach ($this->getFieldValues($messageDef, $Payload, false) as $value) {
            //wenn die Statusvariable existiert, dann wird sie geschrieben
            $variableId = @$this->GetIDForIdent($value['ident']);
            if ($variableId > 0) {
                $this->SetValue($value['ident'], $value['value']);
            }
        }

        return '';
    }

    public function GetConfigurationForm(): string
    {
        $variableList   = json_decode($this->ReadAttributeString(self::ATTR_VARIABLELIST), true, 512, JSON_THROW_ON_ERROR);
        $circuitOptions = json_decode($this->ReadAttributeString(self::ATTR_CIRCUITOPTIONLIST), true, 512, JSON_THROW_ON_ERROR);

        $isActive = ($this->GetStatus() === IS_ACTIVE);


        $Form                                                   =
            json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $Form['elements'][0]['items'][1]['items'][0]['options'] = $circuitOptions;
        $Form['actions'][1]['values']                           = $this->getUpdatedVariableList($variableList);
        $Form['actions'][1]['columns'][6]['edit']['enabled']    = $isActive;
        $Form['actions'][1]['columns'][7]['edit']['enabled']    = $isActive;
        $Form['actions'][2]['items'][0]['enabled']              = $isActive;
        $Form['actions'][2]['items'][1]['enabled']              = $isActive;
        $Form['actions'][2]['items'][2]['enabled']              = $isActive;
        $Form['actions'][2]['items'][3]['visible']              = $this->testFunctionsActive;

        if ($this->trace) {
            $this->logDebug(__FUNCTION__, json_encode($Form, JSON_THROW_ON_ERROR));
        }
        return json_encode($Form, JSON_THROW_ON_ERROR);
    }

    protected function SetValue($Ident, $Value): bool
    {
        $oldValue = $this->GetValue($Ident);

        $id = $this->GetIDForIdent($Ident);

        if (($oldValue === $Value) && (IPS_GetVariable($id)['VariableUpdated'] !== 0)) {
            $this->logDebug(__FUNCTION__, sprintf('%s: %s - not changed', $Ident, $Value));
            return true;
        }

        $this->logDebug(
            __FUNCTION__,
            sprintf('%s: old: %s (%s), new: %s (%s)', $Ident, $oldValue, gettype($oldValue), $Value, gettype($Value))
        );
        return parent::SetValue($Ident, $Value);
    }

    protected function SetStatus($Status): bool
    {
        $isActive = ($Status === IS_ACTIVE);
        $fields   = ['BtnReadConfiguration', 'BtnReadValues', 'BtnCreateUpdateVariables'];

        foreach ($fields as $field) {
            $this->UpdateFormField($field, 'enabled', $isActive);
        }

        return parent::SetStatus($Status);
    }

    //------------------------------------------------------------------------------------------------------------------------
    // my own public functions
    public function publish(string $topic, string $payload): void
    {
        // see https://docs.oasis-open.org/mqtt/mqtt/v5.0/os/mqtt-v5.0-os.html
        $Data = [
            'DataID'           => self::DATA_ID_MQTT_SERVER_TX,
            'PacketType'       => self::PT_PUBLISH,
            'QualityOfService' => self::QOS_0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => $payload
        ];

        $DataJSON = json_encode($Data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->logDebug(__FUNCTION__, sprintf('Call: %s', $DataJSON));

        $ret = $this->SendDataToParent($DataJSON);
        if ($ret === false) {
            $this->logDebug(__FUNCTION__, 'Error: SendDataToParent returned false');
        } else {
            $this->logDebug(__FUNCTION__, sprintf('Call: %s, Return: %s', $DataJSON, (string)$ret));
        }
    }


    private function UpdateCurrentValues(array $variableList): int
    {
        $mqttTopic = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        $readCounter        = 0;
        $progressBarCounter = 0;

        $formField = [];
        $this->UpdateFormField('ProgressBar', 'maximum', count($variableList));
        $this->UpdateFormField('ProgressBar', 'visible', true);
        foreach ($variableList as $entry) {
            $this->UpdateFormField('ProgressBar', 'current', $progressBarCounter++);
            $this->UpdateFormField('ProgressBar', 'caption', $entry[self::FORM_ELEMENT_MESSAGENAME]);
            //alle lesbaren Werte holen
            if ($entry[self::FORM_ELEMENT_READABLE] === self::OK_SIGN) {
                $readCounter++;
                $entry[self::FORM_ELEMENT_READVALUES] = (string)$this->getCurrentValue($mqttTopic, $entry[self::FORM_ELEMENT_MESSAGENAME]);
            }
            $formField[] = $entry;
        }

        $jsonFormField = json_encode($formField, JSON_THROW_ON_ERROR);
        $this->logDebug(__FUNCTION__, 'formField: ' . $jsonFormField);
        $this->UpdateFormField('ProgressBar', 'visible', false);
        $this->UpdateFormField(self::FORM_LIST_VARIABLELIST, 'values', $jsonFormField);
        return $readCounter;
    }

    private function CreateAndUpdateVariables(array $variableList): int
    {
        $configurationMessages = json_decode($this->ReadAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES), true, 512, JSON_THROW_ON_ERROR);
        $count                 = 0;
        foreach ($variableList as $item) {
            if (($item[self::FORM_ELEMENT_READABLE] === self::OK_SIGN) && $item[self::FORM_ELEMENT_KEEP]) {
                $count += $this->RegisterVariablesOfMessage($configurationMessages[$item[self::FORM_ELEMENT_MESSAGENAME]]);
            }
        }

        // Neue Liste mit aktualisierten Idents generieren
        $updatedList = $this->getUpdatedVariableList($variableList);

        // UI aktualisieren
        $this->UpdateFormField(
            self::FORM_LIST_VARIABLELIST,
            'values',
            json_encode($updatedList, JSON_THROW_ON_ERROR)
        );

        // Poll-Prioritäten verarbeiten
        $oldPollPriorities = json_decode($this->ReadAttributeString(self::ATTR_POLLPRIORITIES), true, 512, JSON_THROW_ON_ERROR);
        $newPollPriorities = $this->getPollPriorities($variableList);

        if ($oldPollPriorities !== $newPollPriorities) {
            $this->publishPollPriorities($oldPollPriorities, $newPollPriorities);
            $this->WriteAttributeString(self::ATTR_POLLPRIORITIES, json_encode($newPollPriorities, JSON_THROW_ON_ERROR));
        }

        $this->logDebug(__FUNCTION__, json_encode($variableList, JSON_THROW_ON_ERROR));

        // Persistierung der Liste
        $this->SaveVariableList($updatedList);

        return $count;
    }

    private function SaveVariableList(array $variableList): void
    {
        $cleanedList = array_map(static function (array $item) {
            $item[self::FORM_ELEMENT_READVALUES] = '';
            return $item;
        }, $variableList);

        $this->WriteAttributeString(
            self::ATTR_VARIABLELIST,
            json_encode($cleanedList, JSON_THROW_ON_ERROR)
        );
    }

    private function ReadConfiguration(): ?array
    {
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $port        = $this->ReadPropertyString(self::PROP_PORT);
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        if ($host === '' || $circuitName === '') {
            return null;
        }

        $url    = sprintf('http://%s:%s/data/%s/?def&verbose&exact&write', $host, $port, $circuitName);
        $result = $this->readURL($url);

        if ($result === null) {
            $this->logDebug(__FUNCTION__, 'No response from ebusd URL: ' . $url);
            return null;
        }

        if (!isset($result[$circuitName]['messages'])) {
            trigger_error(sprintf('Configuration for circuit \'%s\' not found (URL: %s)', $circuitName, $url));
            return null;
        }

        $configurationMessages = $result[$circuitName]['messages'];
        if (count($configurationMessages) === 1) {
            trigger_error('Unexpected count of messages: ' . count($configurationMessages));
            return null;
        }

        //ebusd Konfiguration aufbereiten und als Attribut speichern
        $configurationMessages = $this->selectAndPrepareConfigurationMessages($configurationMessages);
        ksort($configurationMessages);
        $this->logDebug(__FUNCTION__, 'configurationMessages: ' . json_encode($configurationMessages, JSON_THROW_ON_ERROR));
        $this->WriteAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, json_encode($configurationMessages, JSON_THROW_ON_ERROR));

        //Ausgabeliste aufbereiten und als Attribut speichern
        $variableList = $this->getVariableList(json_encode($configurationMessages, JSON_THROW_ON_ERROR));
        $this->UpdateFormField(self::FORM_LIST_VARIABLELIST, 'values', json_encode($variableList, JSON_THROW_ON_ERROR, 3));
        $this->SaveVariableList($variableList);

        return $configurationMessages;
    }

    private function requestAllValues(): void
    {
        $variableListJson = $this->ReadAttributeString(self::ATTR_VARIABLELIST);
        try {
            $variableList = json_decode($variableListJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logDebug(__FUNCTION__, 'Error decoding VariableList: ' . $e->getMessage());
            return;
        }

        if (!is_array($variableList) || empty($variableList)) {
            return;
        }

        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        $topicPrefix = sprintf('%s/%s', MQTT_GROUP_TOPIC, $circuitName);

        foreach ($variableList as $entry) {
            $keep     = $entry[self::FORM_ELEMENT_KEEP] ?? false;
            $readable = ($entry[self::FORM_ELEMENT_READABLE] ?? '') === self::OK_SIGN;

            if ($keep && $readable) {
                $topic = sprintf('%s/%s/get', $topicPrefix, $entry[self::FORM_ELEMENT_MESSAGENAME]);
                $this->publish($topic, '');
            }
        }
    }


    private function checkConnection(): void
    {
        $this->updateInstanceStatus();

        $status = $this->GetStatus();
        if ($this->trace) {
            $this->logDebug(__FUNCTION__, 'InstanceStatus: ' . $status);
        }

        if ($status === IS_ACTIVE) {
            // Wenn aktiv: Normalen Update-Timer setzen, Connection-Check-Timer stoppen
            $updateInterval = $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL) * 60 * 1000;
            $this->SetTimerInterval(self::TIMER_REQUEST_ALL_VALUES, $updateInterval);

            $checkConnectionTimer = 0;
        } else {
            // Wenn inaktiv: Update-Timer stoppen, Connection-Check-Timer mit Backoff starten
            $this->SetTimerInterval(self::TIMER_REQUEST_ALL_VALUES, 0);

            $currentRetry = $this->ReadAttributeInteger(self::ATTR_CHECKCONNECTIONTIMER);
            // Startwert 5 Sek (falls 0), dann verdoppeln bis max 180 Sek (3 Min)
            $checkConnectionTimer = min(max($currentRetry * 2, 5), 180);
        }

        $this->WriteAttributeInteger(self::ATTR_CHECKCONNECTIONTIMER, $checkConnectionTimer);

        if ($this->trace || $checkConnectionTimer > 0) {
            $this->logDebug(__FUNCTION__, sprintf('Next connection check in %s seconds', $checkConnectionTimer));
        }

        $this->SetTimerInterval(self::TIMER_CHECK_CONNECTION, $checkConnectionTimer * 1000);
    }

    private function getUpdatedVariableList(array $variableList): array
    {
        $ahID = $this->GetArchiveHandlerID();

        $variableListUpdated = [];
        foreach ($variableList as $item) {
            $identList     = [];
            $variableFound = false;

            if (!empty($item[self::FORM_ELEMENT_IDENTNAMES])) {
                foreach (explode('/', $item[self::FORM_ELEMENT_IDENTNAMES]) as $ident) {
                    $varID = @$this->GetIDForIdent($ident);

                    if ($varID > 0) {
                        $isArchived    = AC_GetLoggingStatus($ahID, $varID);
                        $identList[]   = $ident . ($isArchived ? '(A)' : '');
                        $variableFound = true;
                    } else {
                        $identList[] = '';
                    }
                }
            }

            $item[self::FORM_ELEMENT_OBJECTIDENTS] = $variableFound ? implode(', ', $identList) : '';

            // Wenn die Variable nicht lesbar ist, dann werden keep und pollpriority verworfen
            if ($item[self::FORM_ELEMENT_READABLE] !== self::OK_SIGN) {
                $item[self::FORM_ELEMENT_KEEP]         = false;
                $item[self::FORM_ELEMENT_POLLPRIORITY] = 0;
            }

            $variableListUpdated[] = $item;
        }
        return $variableListUpdated;
    }

    private const EXCLUDED_CIRCUIT_NAMES = ['global', 'broadcast'];
    private const SCANNER_PREFIX         = 'scan.';

    private function setCircuitOptions(): void
    {
        $url = sprintf(
            'http://%s:%s/data',
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT)
        );

        $result = $this->readURL($url);

        $options = [self::EMPTY_OPTION_VALUE];

        if (is_array($result)) {
            foreach ($result as $name => $circuit) {
                $name = (string)$name;

                if (in_array($name, self::EXCLUDED_CIRCUIT_NAMES, true)) {
                    continue;
                }

                if (str_starts_with($name, self::SCANNER_PREFIX)) {
                    continue;
                }

                $options[] = [
                    'caption' => $name,
                    'value'   => $name
                ];
            }
        }

        $optionValue = json_encode($options, JSON_THROW_ON_ERROR);

        $this->logDebug(__FUNCTION__, 'optionValues: ' . $optionValue);

        // UI und Attribut synchronisieren
        $this->UpdateFormField(self::PROP_CIRCUITNAME, 'options', $optionValue);
        $this->WriteAttributeString(self::ATTR_CIRCUITOPTIONLIST, $optionValue);
    }

    private function getPollPriorities(array $variableList): array
    {
        $ret = [];
        foreach ($variableList as $item) {
            $priority = (int)($item[self::FORM_ELEMENT_POLLPRIORITY] ?? 0);
            if ($priority > 0) {
                $ret[$item[self::FORM_ELEMENT_MESSAGENAME]] = $priority;
            }
        }
        return $ret;
    }

    private function publishPollPriorities(array $oldPollPriorities, array $newPollPriorities = []): void
    {
        // array_diff_assoc berücksichtigt auch die Keys (Messagenamen)
        $newItems        = array_diff_assoc($newPollPriorities, $oldPollPriorities);
        $deprecatedItems = array_diff_key($oldPollPriorities, $newPollPriorities);

        $this->logDebug(
            __FUNCTION__,
            sprintf(
                'new/changed: %s, deprecated: %s',
                json_encode($newItems, JSON_THROW_ON_ERROR),
                json_encode($deprecatedItems, JSON_THROW_ON_ERROR)
            )
        );

        // 1. Veraltete Einträge auf Prio 0 setzen
        foreach ($deprecatedItems as $messagename => $pollPriority) {
            $this->publish(
                sprintf('%s/%s/%s/get', MQTT_GROUP_TOPIC, strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $messagename),
                '?0'
            );
        }

        // 2. Neue oder geänderte Einträge senden
        foreach ($newItems as $messagename => $pollPriority) {
            $this->publish(
                sprintf('%s/%s/%s/get', MQTT_GROUP_TOPIC, strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)), $messagename),
                '?' . (int)$pollPriority
            );
        }
    }

    private function getCurrentValue(string $mqttTopic, string $messageId): ?string
    {
        $url = sprintf(
            'http://%s:%s/data/%s/%s?def&verbose&exact&required&maxage=600',
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT),
            $mqttTopic,
            $messageId
        );

        $result = $this->readURL($url);

        // Prüfung ob Ergebnis valide und die erwarteten Daten enthält
        if ($result === null || !isset($result[$mqttTopic]['messages'][$messageId])) {
            $this->logDebug(__FUNCTION__, sprintf('current values of message \'%s\' not found (URL: %s)', $messageId, $url));
            return null;
        }

        $message = $result[$mqttTopic]['messages'][$messageId];

        if (!isset($message['fields']) || !is_array($message['fields'])) {
            return '';
        }

        $values = [];
        foreach ($this->getFieldValues($message, $message['fields'], true) as $field) {
            $values[] = $field['value'];
        }

        return implode('/', $values);
    }

    private function readURL(string $url): ?array
    {
        $this->logDebug(__FUNCTION__, 'URL: ' . $url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // Schneller Abbruch, wenn Host nicht erreichbar

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

    private function getFieldIdentName(array $message, int $fieldId): string
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

    private function countRelevantFieldDefs(array $fieldDefs): int
    {
        return count(array_filter($fieldDefs, static fn($f) => ($f['type'] ?? '') !== 'IGN'));
    }


    private function getFieldLabel(array $message, int $fieldId): string
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

    private function getFieldValue(
        string $messageId,
        array $fields,
        int $key,
        int $variableType,
        array $associations = [],
        bool $numericValues = false
    ): mixed {
        if ($this->trace) {
            $this->logDebug(
                __FUNCTION__,
                sprintf(
                    '%s[%s]: %s, %s, %s',
                    $messageId,
                    $key,
                    $variableType,
                    json_encode($fields, JSON_THROW_ON_ERROR),
                    json_encode($associations, JSON_THROW_ON_ERROR)
                )
            );
        }

        // Sicherstellen, dass wir numerische Indizes haben
        $fieldValues = array_values($fields);

        if (!isset($fieldValues[$key])) {
            $this->logDebug(__FUNCTION__, sprintf('Key [%s] not set for message %s', $key, $messageId));
            return null;
        }

        if (!isset($fieldValues[$key]['value'])) {
            $this->logDebug(__FUNCTION__, sprintf('Value not set for key [%s] in message %s', $key, $messageId));
            return null;
        }

        $value = $fieldValues[$key]['value'];

        // Assoziationen auflösen (Mapping von String-Werten auf Integer-IDs)
        if (!$numericValues && is_string($value) && !empty($associations)) {
            $mappedValue = $this->resolveAssociationValue($value, $associations);
            if ($mappedValue === null) {
                $errorMsg = sprintf(
                    'Value \'%s\' of field \'%s\' (name: \'%s\') not defined in associations',
                    $value,
                    $key,
                    $fieldValues[$key]['name'] ?? 'unknown'
                );
                $this->logDebug(__FUNCTION__, $errorMsg . ' ' . json_encode($associations, JSON_THROW_ON_ERROR));
                // trigger_error optional behalten oder durch LogMessage ersetzen
            } else {
                $value = $mappedValue;
            }
        }

        if ($numericValues) {
            return $value;
        }

        // Typ-Konvertierung
        $ret = match ($variableType) {
            VARIABLETYPE_BOOLEAN => (bool)$value,
            VARIABLETYPE_INTEGER => (int)$value,
            VARIABLETYPE_FLOAT => (float)$value,
            VARIABLETYPE_STRING => (string)$value,
            default => null,
        };

        if ($ret === null && $variableType !== -1) { // -1 oder unbekannter Typ
            $this->LogMessage('Unexpected VariableType: ' . $variableType, KL_ERROR);
        }

        if ($this->trace) {
            $this->logDebug(__FUNCTION__, sprintf('return: %s', var_export($ret, true)));
        }

        return $ret;
    }

    private function resolveAssociationValue(string $value, array $associations): ?int
    {
        if ($this->trace) {
            $this->logDebug(__FUNCTION__, sprintf('Value: %s, Associations: %s', $value, json_encode($associations, JSON_THROW_ON_ERROR)));
        }

        foreach ($associations as $assValue) {
            if ($assValue[1] === $value) {
                return $assValue[0];
            }
        }
        return null;
    }

    private function getVariableList(string $jsonConfigurationMessages): array
    {
        $elements = [];
        foreach (json_decode($jsonConfigurationMessages, true, 512, JSON_THROW_ON_ERROR) as $message) {
            if (count($message['fielddefs']) === 0) {
                //einige wenige messages haben keine fielddefs
                // z.B.: wi,,ioteststop,I/O Test stoppen,,,,01,,,,,,
                $this->logDebug(__FUNCTION__, sprintf('%s: No fielddefs found of message %s', __FUNCTION__, $message['name']));
                continue;
            }

            $variableNames      = [];
            $identNames         = [];
            $identNamesExisting = [];
            foreach ($message['fielddefs'] as $fielddefkey => $fielddef) {
                if ($fielddef['type'] === 'IGN') {
                    continue;
                }
                $fieldLabel      = $this->getFieldLabel($message, $fielddefkey);
                $fieldLabel      = implode(
                    json_decode('["' . $fieldLabel . '"]', true, 512, JSON_THROW_ON_ERROR)
                ); //wandelt unicode escape Sequenzen wie in 'd.27 Zubeh\u00f6rrelais 1' in utf8 um
                $variableNames[] = $fieldLabel;

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
            $storedItem = $this->findStoredVariableItem($message['name']);
            if ($storedItem !== null) {
                $keep         = $storedItem[self::FORM_ELEMENT_KEEP];
                $pollpriority = $storedItem[self::FORM_ELEMENT_POLLPRIORITY];
            } else {
                $keep         = false;
                $pollpriority = 0;
            }

            $element = [
                self::FORM_ELEMENT_MESSAGENAME   => $message['name'],
                self::FORM_ELEMENT_VARIABLENAMES => implode('/', $variableNames),
                self::FORM_ELEMENT_IDENTNAMES    => implode('/', $identNames),
                self::FORM_ELEMENT_READABLE      => ($message['read'] === true) ? self::OK_SIGN : '',
                'writable'                       => ($message['write'] === true) ? self::OK_SIGN : '',
                self::FORM_ELEMENT_READVALUES    => '',
                self::FORM_ELEMENT_KEEP          => $keep,
                self::FORM_ELEMENT_OBJECTIDENTS  => implode('/', $identNamesExisting),
                self::FORM_ELEMENT_POLLPRIORITY  => $pollpriority
            ];
            if ($element[self::FORM_ELEMENT_READABLE] !== self::OK_SIGN) {
                $element['rowColor'] = '#DFDFDF';
            }
            $elements[] = $element;
        }
        return $elements;
    }

    /**
     * Sucht ein gespeichertes Item in der Variablenliste anhand des Messagenamens.
     *
     * @param string $messagename
     *
     * @return array|null
     */
    private function findStoredVariableItem(string $messagename): ?array
    {
        // Wir nutzen ein lokales Cache-Array, um mehrfaches Dekodieren pro Request zu vermeiden
        static $cachedList = null;

        if ($cachedList === null) {
            $json = $this->ReadAttributeString(self::ATTR_VARIABLELIST);
            try {
                $cachedList = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $cachedList = [];
            }
        }

        foreach ($cachedList as $item) {
            if (isset($item[self::FORM_ELEMENT_MESSAGENAME]) && $item[self::FORM_ELEMENT_MESSAGENAME] === $messagename) {
                return $item;
            }
        }

        return null;
    }

    private function RegisterVariablesOfMessage(array $configurationMessage): int
    {
        $countOfVariables = 0;
        foreach ($configurationMessage['fielddefs'] as $fielddefkey => $fielddef) {
            if ($fielddef['type'] === 'IGN') {
                continue;
            }

            if ($this->trace) {
                $this->logDebug(__FUNCTION__, sprintf('Field: %s: %s', $fielddefkey, json_encode($fielddef, JSON_THROW_ON_ERROR)));
            }

            $profileName = 'EBM.' . $configurationMessage['name'] . ($fielddef['name'] !== '' ? '.' . $fielddef['name'] : '');
            $ident       = $this->getFieldIdentName($configurationMessage, $fielddefkey);
            $objectName  = $this->getFieldLabel($configurationMessage, $fielddefkey);

            if ($ident === '' || $objectName === '') {
                continue;
            }

            $variableTyp = $this->getIPSVariableType($fielddef);
            $unit        = match ($fielddef['unit']) {
                '%' => self::ZERO_WIDTH_SPACE . '%',
                '' => '',
                default => ' ' . $fielddef['unit']
            };

            // Profil-Registrierung
            if (isset($fielddef['values'])) {
                $ass = [];
                foreach ($fielddef['values'] as $key => $value) {
                    $ass[] = [$key, $value . ($fielddef['unit'] !== '' ? ' ' . $fielddef['unit'] : ''), '', -1];
                }

                if ($variableTyp === VARIABLETYPE_INTEGER) {
                    $this->RegisterProfileIntegerEx($profileName, '', '', '', $ass);
                } elseif ($variableTyp === VARIABLETYPE_FLOAT) {
                    $this->RegisterProfileFloatEx($profileName, '', '', '', $ass);
                }
            } else {
                $TypeDef = self::DataTypes[$fielddef['type']];
                $divisor = $fielddef['divisor'] ?? 0;

                if ($variableTyp === VARIABLETYPE_INTEGER && $divisor <= 0) {
                    $this->RegisterProfileInteger($profileName, '', '', $unit, $TypeDef['MinValue'], $TypeDef['MaxValue'], $TypeDef['StepSize']);
                } else {
                    // Sowohl FLOAT als auch INTEGER mit Divisor werden zu FLOAT-Profilen
                    $div    = $divisor > 0 ? $divisor : 1;
                    $digits = $variableTyp === VARIABLETYPE_FLOAT ? ($divisor > 0 ? (int)log($divisor, 10) : $TypeDef['Digits']) : 0;

                    $this->RegisterProfileFloat(
                        $profileName,
                        '',
                        '',
                        $unit,
                        $TypeDef['MinValue'] / $div,
                        $TypeDef['MaxValue'] / $div,
                        $TypeDef['StepSize'] / $div,
                        $digits
                    );
                }
            }

            // Variablen-Registrierung
            $id        = 0;
            $typeLabel = '';

            switch ($variableTyp) {
                case VARIABLETYPE_BOOLEAN:
                    $id        = @$this->RegisterVariableBoolean($ident, $objectName, '~Switch');
                    $typeLabel = 'Boolean';
                    break;
                case VARIABLETYPE_STRING:
                    $id        = @$this->RegisterVariableString($ident, $objectName);
                    $typeLabel = 'String';
                    break;
                case VARIABLETYPE_INTEGER:
                    if (isset($fielddef['divisor']) && $fielddef['divisor'] > 0) {
                        $id        = @$this->RegisterVariableFloat($ident, $objectName, $profileName);
                        $typeLabel = 'Float (Divisor)';
                    } else {
                        $id        = @$this->RegisterVariableInteger($ident, $objectName, $profileName);
                        $typeLabel = 'Integer';
                    }
                    break;
                case VARIABLETYPE_FLOAT:
                    $id        = @$this->RegisterVariableFloat($ident, $objectName, $profileName);
                    $typeLabel = 'Float';
                    break;
            }

            if ($id > 0) {
                $countOfVariables++;
                $this->logDebug(__FUNCTION__, sprintf('%s Variable angelegt. Ident: %s, Label: %s', $typeLabel, $ident, $objectName));
            } else {
                trigger_error(sprintf('%s Variable konnte nicht angelegt werden. Ident: %s', $typeLabel, $ident), E_USER_WARNING);
            }

            if ($configurationMessage['write'] && ($this->countRelevantFieldDefs($configurationMessage['fielddefs']) === 1)) {
                $this->EnableAction($ident);
            }
        }
        return $countOfVariables;
    }

    private function checkGlobalMessage(string $topic, string $payload): void
    {
        if ($topic === 'ebusd/global/signal') {
            $this->logDebug(__FUNCTION__, sprintf('%s: %s', $topic, $payload));
            $newSignal = filter_var($payload, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;

            // Nur bei Änderung reagieren
            if ($newSignal !== $this->ReadAttributeBoolean(self::ATTR_SIGNAL)) {
                $this->WriteAttributeBoolean(self::ATTR_SIGNAL, $newSignal);
                $this->ApplyChanges();
            }
        }
    }

    private function selectAndPrepareConfigurationMessages(array $configurationMessages): array
    {
        $ret = [];
        // Vorab-Index für schreibbare Nachrichten erstellen (Performance-Optimierung)
        $writableMap = [];
        foreach ($configurationMessages as $msg) {
            if ($msg['write']) {
                $writableMap[$msg['name']] = true;
            }
        }

        foreach ($configurationMessages as $key => $message) {
            // Nachrichten mit Suffix '-w' (reine Schreib-Endpunkte) überspringen
            if (!str_contains($key, '-w')) {
                $name = $message['name'];

                // Eine Nachricht ist lesbar, wenn sie nicht nur zum Schreiben da ist ODER passiv empfangen werden kann
                $message['read'] = !$message['write'] || $message['passive'];

                // Eine Nachricht ist schreibbar, wenn sie selbst 'write' ist ODER es ein Gegenstück in der Map gibt
                $message['write'] = $message['write'] || isset($writableMap[$name]);

                $message['lastup'] = 0;
                $ret[$name]        = $message;
            }
        }
        return $ret;
    }


    private function getFieldValues(array $message, array $Payload, bool $numericValues = false): array
    {
        $ret          = [];
        $payloadIndex = 0;

        foreach ($message['fielddefs'] as $fielddefkey => $fielddef) {
            if ($this->trace) {
                $this->logDebug('--fielddef--: ', $fielddefkey . ':' . json_encode($fielddef, JSON_THROW_ON_ERROR));
            }

            if (($fielddef['type'] ?? '') === 'IGN') {
                continue;
            }

            // Der Index im Payload erhöht sich nur für Felder, die nicht IGN sind
            $currentIndex = $payloadIndex++;

            $ident = $this->getFieldIdentName($message, $fielddefkey);
            $label = $this->getFieldLabel($message, $fielddefkey);

            if ($ident === '' || $label === '') {
                continue;
            }

            $variableType = $this->getIPSVariableType($fielddef);
            $associations = [];

            if (isset($fielddef['values'])) {
                foreach ($fielddef['values'] as $key => $value) {
                    $associations[] = [$key, $value, '', -1];
                }

                if ($this->trace) {
                    $this->logDebug(
                        'Associations',
                        sprintf(
                            'Name: "EBM.%s.%s", Suffix: "%s", Assoziationen: %s',
                            $message['name'],
                            $fielddef['name'],
                            $fielddef['unit'] ?? '',
                            json_encode($associations, JSON_THROW_ON_ERROR)
                        )
                    );
                }
            }

            $value = $this->getFieldValue(
                $message['name'],
                $Payload,
                $currentIndex,
                $variableType,
                $associations,
                $numericValues
            );

            $ret[] = ['ident' => $ident, 'value' => $value];
        }

        return $ret;
    }

    private function getIPSVariableType(array $fielddef): int
    {
        $type = $fielddef['type'] ?? '';

        if (!isset(self::DataTypes[$type])) {
            trigger_error('Unsupported type: ' . $type, E_USER_ERROR);
        }

        // Ein Divisor erzwingt in der IPS-Logik immer einen Float,
        // um Nachkommastellen darzustellen.
        if (($fielddef['divisor'] ?? 0) > 0) {
            return VARIABLETYPE_FLOAT;
        }

        return self::DataTypes[$type]['VariableType'];
    }

    private function updateInstanceStatus(): void
    {
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $port        = (int)$this->ReadPropertyString(self::PROP_PORT);
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid IP'));
            return;
        }

        //Port Prüfen
        if ((int)$port < 1 || (int)$port > 65535 || !filter_var($port, FILTER_VALIDATE_INT)) {
            $this->SetStatus(self::STATUS_INST_PORT_IS_INVALID);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid Port'));
            return;
        }

        //Circuit prüfen
        if ($circuitName === self::MODEL_GLOBAL_NAME) {
            $this->SetStatus(self::STATUS_INST_TOPIC_IS_INVALID);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'Wrong Circuit name (global)'));
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->SetStatus(IS_INACTIVE);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'Parent not active'));
            return;
        }

        //Verbindung prüfen und circuits holen
        $url    = sprintf('http://%s:%d/data/%s', $host, $port, $circuitName);
        $result = $this->readURL($url);

        if ($result === null || !isset($result[self::MODEL_GLOBAL_NAME]['signal'])
            || !filter_var($result[self::MODEL_GLOBAL_NAME]['signal'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
            $this->SetStatus(IS_INACTIVE);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid connection'));
            return;
        }


        if (!array_key_exists($circuitName, $result)) {
            $this->SetStatus(self::STATUS_INST_TOPIC_IS_INVALID);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid circuit name'));
            return;
        }

        if (!$this->ReadAttributeBoolean(self::ATTR_SIGNAL)) {
            $this->SetStatus(IS_INACTIVE);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'no signal'));
            return;
        }

        $this->SetStatus(IS_ACTIVE);
        $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'active'));
    }

    private function logDebug(string $message, string $data): void
    {
        // Daten für SendDebug aufbereiten (Strings lassen, Rest zu JSON)
        $debugData = is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $this->SendDebug($message, $debugData, 0);

        if (function_exists('IPSLogger_Dbg') && $this->ReadPropertyBoolean(self::PROP_WRITEDEBUGINFORMATIONTOIPSLOGGER)) {
            IPSLogger_Dbg(__CLASS__ . '.' . IPS_GetObject($this->InstanceID)['ObjectName'] . '.' . $message, $debugData);
        }
    }
}


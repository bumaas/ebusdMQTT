<?php /** @noinspection AutoloadingIssuesInspection */

declare(strict_types=1);

if (function_exists('IPSUtils_Include')) {
    IPSUtils_Include('IPSLogger.inc.php', 'IPSLibrary::app::core::IPSLogger');
}

require_once __DIR__ . '/../libs/eBUS_MQTT_Helper.php';


class ebusdMQTTDevice extends IPSModuleStrict
{
    use ebusd2MQTTHelper;

    private const int PT_PUBLISH = 3; //Packet Type Publish
    private const int QOS_0      = 0; //Quality of Service 0

    private const int STATUS_INST_PORT_IS_INVALID  = 202;
    private const int STATUS_INST_IP_IS_INVALID    = 204;
    private const int STATUS_INST_TOPIC_IS_INVALID = 203;

    //property names
    private const string PROP_HOST                             = 'Host';
    private const string PROP_PORT                             = 'Port';
    private const string PROP_CIRCUITNAME                      = 'CircuitName';
    private const string PROP_UPDATEINTERVAL                   = 'UpdateInterval';
    private const string PROP_WRITEDEBUGINFORMATIONTOIPSLOGGER = 'WriteDebugInformationToIPSLogger';

    //attribute names
    private const string ATTR_EBUSD_CONFIGURATION_MESSAGES = 'ebusdConfigurationMessages';
    private const string ATTR_VARIABLELIST                 = 'VariableList';
    private const string ATTR_POLLPRIORITIES               = 'PollPriorities';
    private const string ATTR_CIRCUITOPTIONLIST            = 'CircuitOptionList';
    private const string ATTR_SIGNAL                       = 'GlobalSignal';
    private const string ATTR_CHECKCONNECTIONTIMER         = 'CheckConnectionTimer';

    //timer names
    private const string TIMER_REQUEST_ALL_VALUES = 'requestAllValues';
    private const string TIMER_CHECK_CONNECTION   = 'checkConnection';

    //form element names
    private const string FORM_LIST_VARIABLELIST     = 'VariableList';
    private const string FORM_ELEMENT_READABLE      = 'readable';
    private const string FORM_ELEMENT_POLLPRIORITY  = 'pollpriority';
    private const string FORM_ELEMENT_MESSAGENAME   = 'messagename';
    private const string FORM_ELEMENT_VARIABLENAMES = 'variablenames';
    private const string FORM_ELEMENT_IDENTNAMES    = 'identnames';
    private const string FORM_ELEMENT_READVALUES    = 'readvalues';
    private const string FORM_ELEMENT_WRITABLE      = 'writable';
    private const string FORM_ELEMENT_KEEP          = 'keep';
    private const string FORM_ELEMENT_OBJECTIDENTS  = 'objectidents';

    private bool $trace               = false;

    private bool $testFunctionsActive = false; //button "Publish Poll Priorities" aktivieren

    //ok-Zeichen für die Auswahlliste (siehe https://www.compart.com/de/unicode/)
    private const string OK_SIGN = "\u{2714}";
    //Leerzeichen für Profile mit % im Suffix
    private const string ZERO_WIDTH_SPACE = "\u{200B}";

    //global
    private const string MODEL_GLOBAL_NAME  = 'global';
    private const array  EMPTY_OPTION_VALUE = ['caption' => '-', 'value' => ''];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

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
                    var_export(json_encode(['old' => [], 'new' => json_decode($pollPriorities, true, 512, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR),
                               true)
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

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
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
                // Logik: Nur neu konfigurieren, wenn der Parent nun bereit ist
                // oder wenn wir bisher wegen des Parents inaktiv waren.
                $newParentStatus = (int)$Data[0];
                $currentStatus   = $this->GetStatus();

                if ($newParentStatus === IS_ACTIVE || $currentStatus !== IS_ACTIVE) {
                    $this->logDebug(__FUNCTION__, 'Parent status changed. Triggering ApplyChanges.');
                    $this->ApplyChanges();
                }
                break;
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->logDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, json_encode($Value, JSON_THROW_ON_ERROR)));

        if (!$this->HasActiveParent()) {
            $this->checkConnection();
            $this->logDebug(__FUNCTION__, 'No active parent - ignored');
            return;
        }

        // Hilfsfunktion für JSON-Decoding von $Value
        $decodeValue = static function () use ($Value) {
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
                $this->MsgBox($ret . ' Variablen neu angelegt');
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

            default:
                // Prüfen, ob die Variable zum Ident existiert bevor wir publishen
                if ($this->GetIDForIdent($Ident) === 0) {
                    $this->logDebug(__FUNCTION__, 'Unknown Ident: ' . $Ident);
                    return;
                }
                $topic   = sprintf('%s/%s/%s/set', MQTT_GROUP_TOPIC, $this->ReadPropertyString(self::PROP_CIRCUITNAME), $Ident);
                $payload = $this->getPayload($Ident, $Value);
                $this->publish($topic, $payload);
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->logDebug(__FUNCTION__, $JSONString);

        //wir prüfen, ob CircuitName vorhanden ist
        $mqttTopicProperty = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        if (empty($mqttTopicProperty)) {
            return '';
        }
        $mqttTopicLower = strtolower($mqttTopicProperty);


        //wir prüfen, ob buffer korrektes JSON ist
        try {
            $data = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '';
        }

        //wir prüfen, ob Topic und Payload vorhanden sind
        if (!isset($data['Topic'], $data['Payload'])) {
            return '';
        }

        $topic      = $data['Topic'];
        $payloadHex = $data['Payload'];

        if (!ctype_xdigit($payloadHex)) {
            $this->logDebug(__FUNCTION__, 'Payload is not a valid hex string: ' . $payloadHex);
            return '';
        }
        $payloadJson = hex2bin($payloadHex);


        //Globale Meldungen werden extra behandelt
        if (str_starts_with($topic, MQTT_GROUP_TOPIC . '/global/')) {
            $this->checkGlobalMessage($topic, $payloadJson);
            return '';
        }

        //prüfen, ob der Topic korrekt ist
        $expectedPrefix = MQTT_GROUP_TOPIC . '/' . $mqttTopicLower . '/';
        if (!str_starts_with($topic, $expectedPrefix)) {
            return '';
        }

        // Entfernt LF/CR und alle direkt darauf folgenden Leerzeichen (Einrückungen)
        $debugPayload = preg_replace('/[\r\n]+\s*/', '', $payloadJson);
        $this->logDebug('MQTT Topic/Payload', sprintf('Topic: %s -- Payload: %s', $topic, $debugPayload));

        // Payload dekodieren (ebusd > 3.4 sendet valides JSON, ältere Versionen evtl. nicht)
        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $txtError = sprintf(
                'ERROR! (ebusd version issue?) - JSON Error (%s) at Topic "%s": %s, json: %s',
                json_last_error(),
                $topic,
                json_last_error_msg(),
                $payloadJson
            );
            $this->logDebug(__FUNCTION__ . ' (ERROR)', $txtError);
            return '';
        }

        if ($payload === null) {
            $this->logDebug(__FUNCTION__, 'Payload is null: ' . $payloadJson);
            return '';
        }

        $messageId = str_replace(MQTT_GROUP_TOPIC . '/' . $mqttTopicLower . '/', '', $topic);
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

        $entry = array_find($variableList, static fn(array $e) => $e[self::FORM_ELEMENT_MESSAGENAME] === $messageId);
        $keep  = $entry[self::FORM_ELEMENT_KEEP] ?? false;

        if (!$keep) {
            $this->logDebug('MQTT messageId - skip', "Message '$messageId' is not marked to be stored");
            return '';
        }

        // Werte verarbeiten
        $messageDef = $configurationMessages[$messageId];
        foreach ($this->getFieldValues($messageDef, $payload) as $value) {
            //wenn die Statusvariable existiert, wird sie geschrieben
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

    protected function SetValue(string $Ident, mixed $Value): bool
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

    protected function SetStatus(int $Status): bool
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
            'Payload'          => bin2hex($payload)
        ];

        $DataJSON = json_encode($Data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->logDebug(__FUNCTION__, sprintf('Call: %s', $DataJSON));

        $ret = $this->SendDataToParent($DataJSON);
        $this->logDebug(__FUNCTION__, sprintf('Call: %s, Return: %s', $DataJSON, $ret));
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
            if (!is_array($variableList) || empty($variableList)) {
                return;
            }
        } catch (JsonException $e) {
            $this->logDebug(__FUNCTION__, 'Error decoding VariableList: ' . $e->getMessage());
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

            // Wenn die Variable nicht lesbar ist, werden keep und pollpriority verworfen
            if ($item[self::FORM_ELEMENT_READABLE] !== self::OK_SIGN) {
                $item[self::FORM_ELEMENT_KEEP]         = false;
                $item[self::FORM_ELEMENT_POLLPRIORITY] = 0;
            }

            $variableListUpdated[] = $item;
        }
        return $variableListUpdated;
    }

    private const array  EXCLUDED_CIRCUIT_NAMES = ['global', 'broadcast'];
    private const string SCANNER_PREFIX         = 'scan.';

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

        // Prüfung, ob Ergebnis valide und die erwarteten Daten enthält
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


    private function getFieldValue(
        string $messageId,
        array $fields,
        int $key,
        int $variableType,
        array $valueMap = [],
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
                    json_encode($valueMap, JSON_THROW_ON_ERROR)
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
        if (!$numericValues && is_string($value) && !empty($valueMap)) {
            $mappedValue = $this->resolveAssociationValue($value, $valueMap);
            if ($mappedValue === null) {
                $errorMsg = sprintf(
                    'Value \'%s\' of field \'%s\' (name: \'%s\') not defined in associations',
                    $value,
                    $key,
                    $fieldValues[$key]['name'] ?? 'unknown'
                );
                $this->logDebug(__FUNCTION__, $errorMsg . ' ' . json_encode($valueMap, JSON_THROW_ON_ERROR));
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


    private function getVariableList(string $jsonConfigurationMessages): array
    {
        $elements = [];
        $messages = json_decode($jsonConfigurationMessages, true, 512, JSON_THROW_ON_ERROR);

        foreach ($messages as $message) {
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
                trigger_error(sprintf('%s: No idents found of message %s', __FUNCTION__, $message['name']));
            }

            // Nutzt den effizienten statischen Cache in findStoredVariableItem
            $storedItem   = $this->findStoredVariableItem($message['name']);
            $keep         = $storedItem[self::FORM_ELEMENT_KEEP] ?? false;
            $pollPriority = $storedItem[self::FORM_ELEMENT_POLLPRIORITY] ?? 0;

            $element = [
                self::FORM_ELEMENT_MESSAGENAME   => $message['name'],
                self::FORM_ELEMENT_VARIABLENAMES => implode('/', $variableNames),
                self::FORM_ELEMENT_IDENTNAMES    => implode('/', $identNames),
                self::FORM_ELEMENT_READABLE      => ($message['read'] === true) ? self::OK_SIGN : '',
                self::FORM_ELEMENT_WRITABLE      => ($message['write'] === true) ? self::OK_SIGN : '',
                self::FORM_ELEMENT_READVALUES    => '',
                self::FORM_ELEMENT_KEEP          => $keep,
                self::FORM_ELEMENT_OBJECTIDENTS  => implode('/', $identNamesExisting),
                self::FORM_ELEMENT_POLLPRIORITY  => $pollPriority
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
     * Nutzt einen statischen Cache, um wiederholte JSON-Dekodierungen zu vermeiden.
     *
     * @param string $messagename Der Name der eBUS-Nachricht, nach der gesucht wird.
     *
     * @return array|null Das gefundene Item als Array oder null, wenn nichts gefunden wurde.
     * @throws \JsonException Wenn die JSON-Daten im Attribut ungültig sind.
     */
    private function findStoredVariableItem(string $messagename): ?array
    {
        static $indexedCache = null;
        static $lastCacheHash = '';

        // Aktuelle Liste aus den Instanz-Attributen lesen
        $json = $this->ReadAttributeString(self::ATTR_VARIABLELIST);

        // Hash erzeugen, um festzustellen, ob sich die Liste seit dem letzten Aufruf geändert hat
        $currentHash = md5($json);

        // Cache neu aufbauen, wenn er leer ist oder sich die Quelldaten geändert haben
        if ($indexedCache === null || $lastCacheHash !== $currentHash) {
            $list         = json_decode($json, true, 512, JSON_THROW_ON_ERROR) ? : [];
            $indexedCache = [];

            // Die Liste für schnelleren Zugriff über den Messagenamen indizieren
            foreach ($list as $item) {
                if (isset($item[self::FORM_ELEMENT_MESSAGENAME])) {
                    $indexedCache[$item[self::FORM_ELEMENT_MESSAGENAME]] = $item;
                }
            }

            // Hash für den nächsten Vergleich speichern
            $lastCacheHash = $currentHash;
        }

        // Das gesuchte Item aus dem indizierten Cache zurückgeben (oder null)
        return $indexedCache[$messagename] ?? null;
    }

    private function RegisterVariablesOfMessage(array $configurationMessage): int
    {
        $countOfVariables    = 0;
        $fieldDefs           = $configurationMessage['fielddefs'] ?? [];
        $relevantFieldsCount = $this->countRelevantFieldDefs($configurationMessage['fielddefs']);
        $isWritable          = $configurationMessage['write'] ?? false;

        foreach ($fieldDefs as $fielddefkey => $fielddef) {
            if (($fielddef['type'] ?? '') === 'IGN') {
                continue;
            }

            $ident      = $this->getFieldIdentName($configurationMessage, $fielddefkey);
            $objectName = $this->getFieldLabel($configurationMessage, $fielddefkey);

            if ($ident === '' || $objectName === '') {
                continue;
            }

            if ($this->trace) {
                $this->logDebug(__FUNCTION__, sprintf('Field: %s: %s', $fielddefkey, json_encode($fielddef, JSON_THROW_ON_ERROR)));
            }

            $variableType = $this->getIPSVariableType($fielddef);
            // Action nur erlauben, wenn die Nachricht schreibbar ist UND nur ein Feld existiert (Symcon Standard-Verhalten für einfache Variablen)
            $variableHasAction = $isWritable && ($relevantFieldsCount === 1);


            // Vorbereitung der Präsentations-Daten
            $presentation = $this->getVariablePresentation($fielddef, $variableType, $variableHasAction);

            // Variablen-Registrierung
            $created = $this->MaintainVariable($ident, $objectName, $variableType, $presentation, 0, true);

            if ($variableHasAction) {
                $this->EnableAction($ident);
            }

            if ($created) {
                $countOfVariables++;
                $typeLabel = match ($variableType) {
                    VARIABLETYPE_BOOLEAN => 'Boolean',
                    VARIABLETYPE_INTEGER => 'Integer',
                    VARIABLETYPE_FLOAT => 'Float',
                    VARIABLETYPE_STRING => 'String',
                    default => 'Unknown (' . $variableType . ')'
                };
                $this->logDebug(__FUNCTION__, sprintf('%s Variable neu angelegt. Ident: %s, Label: %s', $typeLabel, $ident, $objectName));
            }
        }
        return $countOfVariables;
    }

    private function getPresentationOptions(array $fielddef, int $variableType): array
    {
        if ($variableType === VARIABLETYPE_BOOLEAN) {
            return [
                [
                    'Value'              => false,
                    'Caption'            => 'Aus',
                    'IconValue'          => '',
                    'IconActive'         => false,
                    'ColorActive'        => false,
                    'ColorValue'         => -1,
                    'ContentColorActive' => false
                ],
                [
                    'Value'              => true,
                    'Caption'            => 'An',
                    'IconValue'          => '',
                    'IconActive'         => false,
                    'ColorActive'        => true,
                    'ColorValue'         => 1692672,
                    'ContentColorActive' => false
                ],
            ];
        }

        $options = [];
        if (isset($fielddef['values'])) {
            foreach ($fielddef['values'] as $key => $value) {
                $options[] = [
                    'Value'      => $key,
                    'Caption'    => $value . ($fielddef['unit'] !== '' ? ' ' . $fielddef['unit'] : ''),
                    'Icon'       => '',
                    'Color'      => -1,
                    'IconActive' => false,
                    'IconValue'  => ''
                ];
            }
        }
        return $options;
    }

    private function getPresentationIntervals(array $fielddef): array
    {
        $intervals = [];
        if (isset($fielddef['values'])) {
            foreach ($fielddef['values'] as $key => $value) {
                $intervals[] = [
                    'ColorDisplay'        => -1,
                    'ContentColorDisplay' => -1,
                    'IntervalMinValue'    => $key,
                    'IntervalMaxValue'    => $key + 1,
                    'ConstantActive'      => true,
                    'ConstantValue'       => $value . ($fielddef['unit'] !== '' ? ' ' . $fielddef['unit'] : ''),
                    'ConversionFactor'    => 1,
                    'IconActive'          => false,
                    'IconValue'           => '',
                    'PrefixActive'        => false,
                    'PrefixValue'         => '',
                    'SuffixActive'        => false,
                    'SuffixValue'         => '',
                    'DigitsActive'        => false,
                    'DigitsValue'         => 0,
                    'ColorActive'         => false,
                    'ColorValue'          => -1,
                    'ContentColorActive'  => false,
                    'ContentColorValue'   => -1
                ];
            }
        }
        return $intervals;
    }

    private function getVariablePresentation(array $fielddef, int $variableType, bool $hasAction): array
    {
        $suffix = match ($fielddef['unit']) {
            '%' => self::ZERO_WIDTH_SPACE . '%',
            '' => '',
            default => ' ' . $fielddef['unit']
        };

        // 1. Boolean Sonderfall
        if ($variableType === VARIABLETYPE_BOOLEAN) {
            $options = $this->getPresentationOptions($fielddef, $variableType);
            return array_filter([
                                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                    'OPTIONS'      => $options ? json_encode($options, JSON_THROW_ON_ERROR) : null
                                ]);
        }

        // 2. Zahlen (Integer / Float)
        if ($variableType === VARIABLETYPE_INTEGER || $variableType === VARIABLETYPE_FLOAT) {
            $typeDef = $this->getEbusDataTypeDefinitions()[$fielddef['type']];
            $div     = max(1, $fielddef['divisor'] ?? 0);
            $digits  = ($variableType === VARIABLETYPE_FLOAT && $div > 1)
                ? (int)round(log10($div))
                : ($typeDef['Digits'] ?? 0);

            if ($hasAction) {
                $options = $this->getPresentationOptions($fielddef, $variableType);

                // Enumeration (wenn feste Werte definiert sind)
                if (!empty($options)) {
                    return [
                        'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                        'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
                    ];
                }

                // Eingabefeld oder Slider
                if ($typeDef['MinValue'] === $typeDef['MaxValue']) {
                    return [
                        'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT,
                        'SUFFIX'       => $suffix,
                    ];
                }

                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'SUFFIX'       => $suffix,
                    'DIGITS'       => $digits,
                    'MIN'          => $typeDef['MinValue'] / $div,
                    'MAX'          => $typeDef['MaxValue'] / $div,
                    'STEP_SIZE'    => $typeDef['StepSize'] / $div,
                ];
            }

            // Nur Anzeige (keine Action)
            $intervals = $this->getPresentationIntervals($fielddef);
            if (!empty($intervals)) {
                return [
                    'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                    'INTERVALS'        => json_encode($intervals, JSON_THROW_ON_ERROR),
                    'INTERVALS_ACTIVE' => true
                ];
            }

            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => $suffix,
                'DIGITS'       => $digits,
            ];
        }

        // 3. Fallback für Strings und Unbekanntes
        $options = $hasAction ? $this->getPresentationOptions($fielddef, $variableType) : [];

        return array_filter([
                                'PRESENTATION' => $hasAction ? VARIABLE_PRESENTATION_VALUE_INPUT : VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                                'SUFFIX'       => $hasAction ? null : $suffix,
                                'OPTIONS'      => $options ? json_encode($options, JSON_THROW_ON_ERROR) : null
                            ]);
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


    private function getFieldValues(array $message, array $payload, bool $numericValues = false): array
    {
        $ret          = [];
        $payloadIndex = 0;

        foreach ($message['fielddefs'] as $fieldDefKey => $fielddef) {
            if ($this->trace) {
                $this->logDebug('--fielddef--: ', $fieldDefKey . ':' . json_encode($fielddef, JSON_THROW_ON_ERROR));
            }

            if (($fielddef['type'] ?? '') === 'IGN') {
                continue;
            }

            // Der Index im Payload erhöht sich nur für Felder, die nicht IGN sind
            $currentIndex = $payloadIndex++;

            $ident = $this->getFieldIdentName($message, $fieldDefKey);
            $label = $this->getFieldLabel($message, $fieldDefKey);

            if ($ident === '' || $label === '') {
                continue;
            }

            $variableType = $this->getIPSVariableType($fielddef);

            $valueMap = isset($fielddef['values'])
                ? array_map(null, array_keys($fielddef['values']), $fielddef['values'])
                : [];

            if ($this->trace && $valueMap) {
                    $this->logDebug(
                        'Associations',
                        sprintf(
                            'Name: "EBM.%s.%s", Suffix: "%s", Assoziationen: %s',
                            $message['name'],
                            $fielddef['name'],
                            $fielddef['unit'] ?? '',
                            json_encode($valueMap, JSON_THROW_ON_ERROR)
                        )
                    );
                }

            $value = $this->getFieldValue(
                $message['name'],
                $payload,
                $currentIndex,
                $variableType,
                $valueMap,
                $numericValues
            );

            $ret[] = ['ident' => $ident, 'value' => $value];
        }

        return $ret;
    }


    private function updateInstanceStatus(): void
    {
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $portString  = $this->ReadPropertyString(self::PROP_PORT);
        $port        = is_numeric($portString) ? (int)$portString : 0;
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $this->SetStatus(self::STATUS_INST_IP_IS_INVALID);
            $this->logDebug(__FUNCTION__, sprintf('Status: %s (%s)', $this->GetStatus(), 'invalid IP'));
            return;
        }

        //Port Prüfen
        if ($port < 1 || $port > 65535 || !filter_var($port, FILTER_VALIDATE_INT)) {
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

}


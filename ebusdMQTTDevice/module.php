<?php

/** @noinspection AutoloadingIssuesInspection */

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
    private const int STATUS_INST_NOT_REACHABLE    = 205;
    private const int STATUS_INST_NO_SIGNAL        = 206;
    private const int STATUS_INST_NO_CIRCUIT       = 207;
    private const int STATUS_INST_INTERVAL_INVALID = 208;
    private const int STATUS_INST_NO_MQTT_REPLY    = 209;

    // RequestAction-Idents, die das Modul selbst auslöst (Timer) — ohne aktiven Parent still übergehen
    private const array INTERNAL_IDENTS = ['timerCheckConnection', 'timerRefreshAllMessages', 'publishPollPriorities'];

    // EBM_ReadMessageValues: ebusd fragt ggf. den Bus ab — Obergrenze je Aufruf
    private const int MAX_MESSAGES_PER_READ = 20;

    // Schieberegler nur bis zu dieser Schrittzahl, sonst Eingabefeld
    private const int MAX_SLIDER_STEPS = 1000;

    // Selbsttest: so viele Einträge je Altlast-Hinweis, der Rest als „und N weitere"
    private const int MAX_LISTED_ITEMS = 10;

    // bereits per Log gemeldete Meldungen, die in der Konfiguration fehlen (je Laufzeit einmal)
    private const string BUFFER_REPORTED_UNKNOWN = 'ReportedUnknownMessages';

    // MQTT-Rückweg: '' = keine Anfrage offen, 'pending' = Anfragerunde gesendet, 'silent' = eine Runde blieb unbeantwortet
    private const string BUFFER_MQTT_REPLY = 'MqttReplyState';
    // Zeitpunkt der letzten Meldung von ebusd per MQTT (für den Selbsttest)
    private const string BUFFER_LAST_MQTT_RECEIVE = 'LastMqttReceive';

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

        $this->RegisterAttributeString(self::ATTR_VARIABLELIST, '[]');
        $this->RegisterAttributeString(self::ATTR_POLLPRIORITIES, '[]');
        $this->RegisterAttributeString(self::ATTR_CIRCUITOPTIONLIST, json_encode([self::EMPTY_OPTION_VALUE], JSON_THROW_ON_ERROR));
        $this->RegisterAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, '[]');
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
            $this->applyStatus(...$this->getPropertyError());
            $this->SetTimerInterval(self::TIMER_REQUEST_ALL_VALUES, 0);
            $this->SetTimerInterval(self::TIMER_CHECK_CONNECTION, 0); // ohne Schaltkreis gibt es nichts zu prüfen
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

        // 5. Bei aktiver Verbindung einmalig die Poll-Prioritäten pushen (verzögert,
        // damit der Parent sicher bereit ist); die Timer hat checkConnection() bereits gesetzt
        if ($this->GetStatus() === IS_ACTIVE) {
            $pollPriorities = $this->readAttributeArray(self::ATTR_POLLPRIORITIES);
            $this->RegisterOnceTimer(
                'DeferredPollPriorities',
                sprintf(
                    'IPS_RequestAction(%d, "%s", %s);',
                    $this->InstanceID,
                    'publishPollPriorities',
                    var_export(json_encode(['old' => [], 'new' => $pollPriorities], JSON_THROW_ON_ERROR), true)
                )
            );
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
        $this->logDebug(__FUNCTION__, sprintf('Ident: %s, Value: %s', $Ident, $this->shortenForDebug(json_encode($Value, JSON_THROW_ON_ERROR))));

        // Hilfsfunktion für JSON-Decoding von $Value
        $decodeValue = static function () use ($Value) {
            return json_decode((string)$Value, true, 512, JSON_THROW_ON_ERROR);
        };

        // Diese Aktionen brauchen nur HTTP zu ebusd, nicht den MQTT-Parent
        switch ($Ident) {
            case 'btnReadCircuits':
                // Formularwerte statt gespeicherter Properties: die Adresse soll vor dem Übernehmen prüfbar sein
                $formValues = $Value === '' ? [] : $decodeValue();
                $this->setCircuitOptions(
                    (string)($formValues['Host'] ?? $this->ReadPropertyString(self::PROP_HOST)),
                    (string)($formValues['Port'] ?? $this->ReadPropertyString(self::PROP_PORT)),
                    (string)($formValues['CircuitName'] ?? $this->ReadPropertyString(self::PROP_CIRCUITNAME))
                );
                return;

            case 'btnReadConfiguration':
                $ret = $this->ReadConfiguration();
                $this->MsgBox(is_string($ret) ? $ret : sprintf($this->Translate('%s entries found'), count($ret)));
                return;

            case 'VariableList_onEdit':
                $parameter = json_decode($Value, true, 512, JSON_THROW_ON_ERROR);
                if ($parameter['readable'] !== self::OK_SIGN) {
                    $this->MsgBox(
                        sprintf(
                            $this->Translate('The variable "%s" is not readable. The changes will not be saved.'),
                            $parameter['messagename'] ?? $this->Translate('unknown')
                        )
                    );
                }
                return;
        }

        if (!$this->HasActiveParent()) {
            $this->checkConnection();
            $this->logDebug(__FUNCTION__, 'No active parent - ignored');
            if (!in_array($Ident, self::INTERNAL_IDENTS, true)) {
                trigger_error(
                    sprintf(
                        $this->Translate('"%s" was not executed: the MQTT Server (parent instance) is not active. Check the MQTT Server instance and try again.'),
                        $Ident
                    ),
                    E_USER_WARNING
                );
            }
            return;
        }

        switch ($Ident) {
            case 'btnReadValues':
                $ret = $this->UpdateCurrentValues($decodeValue());
                $this->MsgBox(is_string($ret) ? $ret : sprintf($this->Translate('%s values read'), $ret));
                return;

            case 'btnCreateUpdateVariables':
                $ret = $this->CreateAndUpdateVariables($decodeValue());
                $this->MsgBox(sprintf($this->Translate('%s variables newly created'), $ret));
                return;

            case 'btnPublishPollPriorities':
                $pollPriorities = $this->readAttributeArray(self::ATTR_POLLPRIORITIES);
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

            default:
                $writable = $this->getWritableMessage($Ident, $Value);
                if ($writable === null) {
                    return; // Grund wurde bereits per trigger_error gemeldet
                }
                [$message, $fieldDef] = $writable;
                $this->publish($this->buildTopic($message['name'], 'set'), $this->getPayload($fieldDef, $Value));
        }
    }

    /**
     * Prüft einen Schreibwunsch aus RequestAction gegen die ebusd-Konfiguration und liefert die
     * zugehörige Meldung. Jeder Grund für eine Ablehnung kommt als trigger_error beim Aufrufer an
     * (Skript wie KI) — mit Art des Fehlers und nächstem Schritt, nichts wird publiziert.
     *
     * @return array{0: array, 1: array}|null [Meldung, Felddefinition des einzigen relevanten Feldes]
     */
    private function getWritableMessage(string $ident, mixed $value): ?array
    {
        foreach ($this->readAttributeArray(self::ATTR_EBUSD_CONFIGURATION_MESSAGES) as $message) {
            foreach ($message['fielddefs'] ?? [] as $key => $fieldDef) {
                if (($fieldDef['type'] ?? '') === 'IGN' || $this->getFieldIdentName($message, $key) !== $ident) {
                    continue;
                }

                if (!($message['write'] ?? false)) {
                    trigger_error(
                        sprintf($this->Translate('"%s" is read-only: ebusd does not allow writing the message "%s".'), $ident, $message['name']),
                        E_USER_WARNING
                    );
                    return null;
                }
                if ($this->countRelevantFieldDefs($message['fielddefs']) > 1) {
                    trigger_error(
                        sprintf(
                            $this->Translate('"%s" is one field of the message "%s" with several fields and cannot be written on its own. Write the whole message with EBM_publish.'),
                            $ident,
                            $message['name']
                        ),
                        E_USER_WARNING
                    );
                    return null;
                }

                $allowed = $this->getDisallowedValueHint($fieldDef, $value);
                if ($allowed !== '') {
                    trigger_error(
                        sprintf(
                            $this->Translate('Value "%s" for "%s" is not allowed (allowed: %s). Do not repeat with this value.'),
                            is_scalar($value) ? var_export($value, true) : json_encode($value),
                            $ident,
                            $allowed
                        ),
                        E_USER_WARNING
                    );
                    return null;
                }
                return [$message, $fieldDef];
            }
        }

        trigger_error(
            sprintf($this->Translate('"%s" is not a status variable of this instance. Use the ident of a writable status variable.'), $ident),
            E_USER_WARNING
        );
        return null;
    }

    /** Leerer Text = Wert zulässig, sonst die Beschreibung der erlaubten Werte */
    private function getDisallowedValueHint(array $fieldDef, mixed $value): string
    {
        $typeDef = $this->getEbusDataTypeDefinitions()[$fieldDef['type']] ?? null;
        if ($typeDef === null) {
            return sprintf($this->Translate('nothing, the eBUS type %s is not supported'), $fieldDef['type']);
        }

        switch ($typeDef['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                return (is_bool($value) || in_array($value, [0, 1, '0', '1'], true)) ? '' : 'true, false';

            case VARIABLETYPE_STRING:
                return is_scalar($value) ? '' : $this->Translate('a text');

            case VARIABLETYPE_INTEGER:
            case VARIABLETYPE_FLOAT:
                if (!empty($fieldDef['values'])) {
                    $choices = [];
                    foreach ($fieldDef['values'] as $key => $caption) {
                        $choices[] = $key . ' = ' . $caption;
                    }
                    $isChoice = is_numeric($value) && (float)$value === (float)(int)$value
                                && array_key_exists((int)$value, $fieldDef['values']);
                    return $isChoice ? '' : implode(', ', $choices);
                }
                if (!is_numeric($value)) {
                    return $this->Translate('a number');
                }
                // ohne Divisor ganzzahlig: ein Nachkommaanteil ginge unverändert auf den Bus
                if ($this->getIPSVariableType($fieldDef) === VARIABLETYPE_INTEGER && (float)$value !== (float)(int)$value) {
                    return $this->Translate('a whole number');
                }
                // immer prüfen, auch bei unüberschaubar großem Bereich (EXP) — die Grenze für den Schieberegler gilt hier nicht
                $range = $this->getValueRange($fieldDef);
                if ($range !== null && ((float)$value < $range['min'] || (float)$value > $range['max'])) {
                    return sprintf($this->Translate('%s to %s'), $range['min'], $range['max']);
                }
                return '';
        }
        return '';
    }

    public function ReceiveData(string $JSONString): string
    {
        if ($this->trace) {
            $this->logDebug(__FUNCTION__, $JSONString); // Rohpaket; Topic und Payload stehen unten dekodiert im Debug
        }

        //wir prüfen, ob CircuitName vorhanden ist
        $mqttTopicLower = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        if ($mqttTopicLower === '') {
            return '';
        }

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
            $this->mqttReplyReceived();
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

        // ebusd/<circuit>/<message>/set bzw. /get: Aufträge an ebusd (andere Clients oder das eigene Echo), keine Werte
        if (str_contains($messageId, '/')) {
            $this->logDebug('MQTT messageId - skip', 'not a value topic: ' . $messageId);
            return '';
        }
        $this->mqttReplyReceived();

        // ist die Konfiguration der Message bekannt?
        $configurationMessages = $this->readAttributeArray(self::ATTR_EBUSD_CONFIGURATION_MESSAGES);
        if (!isset($configurationMessages[$messageId])) {
            // nur die Meldung und die Größe der Konfiguration — nicht die ganze Konfiguration (bis 170 kB)
            $this->logDebug(
                'MQTT messageId - not found',
                sprintf('%s (configuration has %d messages)', $messageId, count($configurationMessages))
            );

            // Noch nicht eingelesen (Zeitfenster nach der Schaltkreiswahl): jede Meldung wäre „unbekannt" — kein Fehler.
            // Fehlt eine Meldung in einer eingelesenen Konfiguration, einmal je Meldung warnen, nicht bei jedem Empfang.
            if ($configurationMessages !== []) {
                $reported = json_decode($this->GetBuffer(self::BUFFER_REPORTED_UNKNOWN) ?: '[]', true, 512, JSON_THROW_ON_ERROR);
                if (!in_array($messageId, $reported, true)) {
                    $reported[] = $messageId;
                    $this->SetBuffer(self::BUFFER_REPORTED_UNKNOWN, json_encode($reported, JSON_THROW_ON_ERROR));
                    $this->LogMessage(
                        sprintf(
                            $this->Translate('ebusd reports the message "%s", which is not in the stored configuration. Read the configuration again ("Read Configuration" or EBM_UpdateConfiguration).'),
                            mb_substr($messageId, 0, 100)
                        ),
                        KL_WARNING
                    );
                }
            }
            return '';
        }

        // Prüfen, ob die Message zum Speichern markiert ist
        $entry = $this->findStoredVariableItem($messageId);
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
        $variableList   = $this->readAttributeArray(self::ATTR_VARIABLELIST);
        $circuitOptions = $this->readAttributeArray(self::ATTR_CIRCUITOPTIONLIST);

        $isActive = ($this->GetStatus() === IS_ACTIVE);

        $Form                                                   =
            json_decode(file_get_contents(__DIR__ . '/form.json'), true, 512, JSON_THROW_ON_ERROR);
        $Form['elements'][0]['items'][1]['items'][0]['options'] = $this->withCircuitOption(
            $circuitOptions,
            $this->ReadPropertyString(self::PROP_CIRCUITNAME)
        );
        $Form['actions'][1]['values']                           = $this->getUpdatedVariableList($variableList);
        $Form['actions'][1]['columns'][6]['edit']['enabled']    = $isActive;
        $Form['actions'][1]['columns'][7]['edit']['enabled']    = $isActive;
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
        $fields   = ['BtnReadValues', 'BtnCreateUpdateVariables']; // „Lese Konfiguration aus“ braucht nur HTTP und bleibt immer bedienbar

        foreach ($fields as $field) {
            $this->UpdateFormField($field, 'enabled', $isActive);
        }

        return parent::SetStatus($Status);
    }

    //------------------------------------------------------------------------------------------------------------------------
    // my own public functions

    /**
     * Probelauf ohne Wirkung: prüft MQTT-Parent, ebusd, Signal, Schaltkreis, Konfiguration und
     * Auswahl und liefert das Ergebnis als Text, jede Störung mit dem nächsten Schritt. Setzt
     * keinen Status, keinen Timer und kein Attribut und sendet nichts.
     */
    public function RunSelfTest(): string
    {
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $port        = $this->ReadPropertyString(self::PROP_PORT);
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        $problems    = 0;
        $lines       = [
            sprintf($this->Translate('Self-test of circuit "%s" at ebusd %s:%s (without effect on the instance)'), $circuitName, $host, $port),
            sprintf($this->Translate('Instance status: %d'), $this->GetStatus())
        ];
        $good = static function (string $text) use (&$lines): void {
            $lines[] = '✔ ' . $text;
        };
        $bad  = static function (string $text) use (&$lines, &$problems): void {
            $lines[] = '✘ ' . $text;
            $problems++;
        };

        if ($this->HasActiveParent()) {
            $good($this->Translate('The MQTT Server (parent instance) is active.'));
        } else {
            $bad($this->Translate('The MQTT Server (parent instance) is not active. The values of ebusd arrive via MQTT only - check the MQTT Server instance.'));
        }

        $propertyError = $this->getPropertyError();
        if ($propertyError !== null) {
            $bad($propertyError[2]); // ohne Abfrage — mit ungültiger Adresse käme nur ein irreführendes „antwortet nicht"
        } else {
            $url    = sprintf('http://%s:%s/data/%s', $host, $port, $circuitName);
            $result = $this->readURL($url);
            if ($result === null || !isset($result[self::MODEL_GLOBAL_NAME]['signal'])) {
                $bad(sprintf($this->Translate('ebusd does not answer at %s. Check host, port and the HTTP port of ebusd (--httpport).'), $url));
            } elseif (!filter_var($result[self::MODEL_GLOBAL_NAME]['signal'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
                $bad($this->Translate('ebusd reports no eBUS signal. Check the eBUS adapter and its connection to the bus.'));
            } elseif (!array_key_exists($circuitName, $result)) {
                $bad(sprintf($this->Translate('The circuit "%s" does not exist at ebusd. Determine the available circuits with "Read Circuits".'), $circuitName));
            } else {
                $good($this->Translate('ebusd answers, reports an eBUS signal and knows the circuit.'));
            }
        }

        if (!$this->ReadAttributeBoolean(self::ATTR_SIGNAL)) {
            $bad($this->Translate('The last eBUS signal reported via MQTT (ebusd/global/signal) was "no signal".'));
        }

        if ($this->GetBuffer(self::BUFFER_MQTT_REPLY) === 'silent') {
            $bad($this->getNoMqttReplyText());
        } elseif (($lastMqtt = (int)$this->GetBuffer(self::BUFFER_LAST_MQTT_RECEIVE)) > 0) {
            $good(sprintf($this->Translate('Last message from ebusd via MQTT: %s.'), date('d.m.Y H:i:s', $lastMqtt)));
        }

        $configurationMessages = $this->readAttributeArray(self::ATTR_EBUSD_CONFIGURATION_MESSAGES);
        if ($configurationMessages === []) {
            $bad($this->Translate('The configuration has not been read from ebusd yet. Read it with EBM_UpdateConfiguration.'));
        } else {
            $good(sprintf($this->Translate('Configuration read: %d messages.'), count($configurationMessages)));
        }

        $activeMessages = array_filter(
            $this->readAttributeArray(self::ATTR_VARIABLELIST),
            static fn(array $item): bool => ($item[self::FORM_ELEMENT_KEEP] ?? false) && ($item[self::FORM_ELEMENT_READABLE] ?? '') === self::OK_SIGN
        );
        if ($activeMessages === []) {
            $bad($this->Translate('No message is active. Find messages with EBM_FindMessages and activate them with EBM_SetMessageActive.'));
        } else {
            $good(sprintf($this->Translate('Active messages: %d.'), count($activeMessages)));
        }

        $variableCount = 0;
        $lastUpdate    = 0;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (IPS_VariableExists($childID)) {
                $variableCount++;
                $lastUpdate = max($lastUpdate, IPS_GetVariable($childID)['VariableUpdated']);
            }
        }
        if ($variableCount > 0) {
            $good(
                $lastUpdate > 0
                    ? sprintf($this->Translate('Status variables: %d, last update %s.'), $variableCount, date('d.m.Y H:i:s', $lastUpdate))
                    : sprintf($this->Translate('Status variables: %d, no value received yet.'), $variableCount)
            );
        }

        // Bei einer Störung hält checkConnection() den Abfrage-Timer an
        $updateInterval = $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL);
        if (!in_array($this->GetStatus(), [IS_ACTIVE, self::STATUS_INST_NO_MQTT_REPLY], true)) {
            $lines[] = '– ' . sprintf($this->Translate('While the instance status is %d, the module does not request any values.'), $this->GetStatus());
        } else {
            $good(
                $updateInterval > 0
                    ? sprintf($this->Translate('The active messages are requested every %d minute(s).'), $updateInterval)
                    : $this->Translate('Update interval 0: the module does not request values itself; they arrive only when ebusd publishes them.')
            );
        }

        foreach ($this->getLegacyHints($configurationMessages) as $hint) {
            $lines[] = '– ' . $hint;
        }

        $lines[] = $problems === 0 ? $this->Translate('Result: OK') : sprintf($this->Translate('Result: %d problem(s)'), $problems);
        return implode("\n", $lines);
    }

    /**
     * Hinweise auf Altlasten für den Selbsttest — nur benennen, nichts umbenennen oder löschen:
     * Variablen ohne Meldung in der ebusd-Konfiguration (z. B. Meldungen, die ebusd nicht mehr
     * kennt) und gleich benannte Variablen (ebusd beschreibt manche Werte in zwei Meldungen gleich).
     */
    private function getLegacyHints(array $configurationMessages): array
    {
        $knownIdents = [];
        foreach ($configurationMessages as $message) {
            foreach ($message['fielddefs'] ?? [] as $key => $fieldDef) {
                if (($fieldDef['type'] ?? '') !== 'IGN') {
                    $knownIdents[$this->getFieldIdentName($message, $key)] = true;
                }
            }
        }

        $orphans = [];
        $byName  = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (!IPS_VariableExists($childID)) {
                continue;
            }
            $object = IPS_GetObject($childID);
            $ident  = $object['ObjectIdent'];
            $label  = $ident !== '' ? $ident : sprintf('#%d "%s"', $childID, $object['ObjectName']);

            $byName[$object['ObjectName']][] = $label;
            if ($configurationMessages !== [] && !isset($knownIdents[$ident])) {
                $updated   = IPS_GetVariable($childID)['VariableUpdated'];
                $orphans[] = sprintf('%s (%s)', $label, $updated > 0 ? date('d.m.Y', $updated) : $this->Translate('no value yet')); // 0 wäre „01.01.1970“
            }
        }

        $hints = [];
        if ($orphans !== []) {
            $hints[] = sprintf(
                $this->Translate('Variables without a message in the ebusd configuration, probably obsolete (last update): %s'),
                $this->shortList($orphans)
            );
        }
        $duplicates = [];
        foreach ($byName as $name => $labels) {
            if (count($labels) > 1) {
                $duplicates[] = sprintf('"%s" (%s)', $name, implode(', ', $labels));
            }
        }
        if ($duplicates !== []) {
            $hints[] = sprintf($this->Translate('Variables with the same name: %s'), $this->shortList($duplicates));
        }
        return $hints;
    }

    private function shortList(array $items): string
    {
        $text = implode(', ', array_slice($items, 0, self::MAX_LISTED_ITEMS));
        if (count($items) > self::MAX_LISTED_ITEMS) {
            $text .= sprintf($this->Translate(' and %d more'), count($items) - self::MAX_LISTED_ITEMS);
        }
        return $text;
    }

    /**
     * Die Meldungen des Schaltkreises als JSON — dieselbe Information wie die Liste im Formular,
     * ohne Formatierung. $search filtert (Groß/Klein egal) nach Meldungsname oder Bezeichnung,
     * '' liefert alle.
     */
    public function FindMessages(string $search): string
    {
        $variableList = $this->getStoredVariableListOrWarn();
        if ($variableList === null) {
            return '';
        }

        $configurationMessages = $this->readAttributeArray(self::ATTR_EBUSD_CONFIGURATION_MESSAGES);
        $out                   = [];
        foreach ($this->filterVariableList($variableList, $search) as $item) {
            $name  = $item[self::FORM_ELEMENT_MESSAGENAME];
            $out[] = [
                'message'      => $name,
                'readable'     => ($item[self::FORM_ELEMENT_READABLE] ?? '') === self::OK_SIGN,
                'writable'     => ($item[self::FORM_ELEMENT_WRITABLE] ?? '') === self::OK_SIGN,
                'active'       => (bool)($item[self::FORM_ELEMENT_KEEP] ?? false),
                'pollPriority' => (int)($item[self::FORM_ELEMENT_POLLPRIORITY] ?? 0),
                'fields'       => isset($configurationMessages[$name]) ? $this->describeFields($configurationMessages[$name]) : []
            ];
        }
        return json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Je Feld einer Meldung: Ident, Bezeichnung, Typ, Einheit, erlaubte Werte (Wertetabelle) bzw.
     * Bereich — der Bereich nur, wenn er überschaubar ist (sonst ist es der technische Bereich des
     * eBUS-Typs, z. B. ±3·10³⁸) — und die ID der Variable, falls angelegt.
     */
    private function describeFields(array $message): array
    {
        $typeNames = [VARIABLETYPE_BOOLEAN => 'boolean', VARIABLETYPE_INTEGER => 'integer', VARIABLETYPE_FLOAT => 'float', VARIABLETYPE_STRING => 'string'];
        $fields    = [];
        foreach ($message['fielddefs'] ?? [] as $key => $fieldDef) {
            if (($fieldDef['type'] ?? '') === 'IGN') {
                continue;
            }
            $ident = $this->getFieldIdentName($message, $key);
            $known = isset($this->getEbusDataTypeDefinitions()[$fieldDef['type'] ?? '']); // getIPSVariableType kennt nur Typen der Tabelle
            $field = [
                'ident' => $ident,
                'label' => $this->getFieldLabel($message, $key),
                'type'  => $known ? ($typeNames[$this->getIPSVariableType($fieldDef)] ?? 'unknown') : 'unknown',
            ];
            if (($fieldDef['unit'] ?? '') !== '') {
                $field['unit'] = $fieldDef['unit'];
            }
            if (!empty($fieldDef['values'])) {
                $field['values'] = (object)$fieldDef['values'];
            } else {
                $range = $this->getValueRange($fieldDef);
                if ($range !== null && $range['steps'] <= self::MAX_SLIDER_STEPS) {
                    $field['min'] = $range['min'];
                    $field['max'] = $range['max'];
                }
            }
            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID > 0) {
                $field['variableID'] = $variableID;
            }
            $fields[] = $field;
        }
        return $fields;
    }

    /** Liest die Konfiguration des Schaltkreises von ebusd und speichert sie (Knopf „Lese Konfiguration aus"). */
    public function UpdateConfiguration(): string
    {
        $ret = $this->ReadConfiguration();
        if (is_string($ret)) {
            trigger_error($ret, E_USER_WARNING);
            return '';
        }
        return sprintf($this->Translate('%d messages read from ebusd. Find messages with EBM_FindMessages and activate them with EBM_SetMessageActive.'), count($ret));
    }

    /**
     * Bindet eine Meldung ein (Variablen anlegen, Auswahl speichern) oder aus. Ausgeschaltet
     * bleiben die Variablen samt Archiv erhalten; das Modul fragt sie nur nicht mehr ab.
     * $pollPriority 0..9 (0 = keine eigene Poll-Priorität) wird an ebusd gesendet, wenn sie sich ändert.
     */
    public function SetMessageActive(string $messageName, bool $active, int $pollPriority): string
    {
        $variableList = $this->getStoredVariableListOrWarn();
        if ($variableList === null) {
            return '';
        }

        if ($pollPriority < 0 || $pollPriority > 9) {
            trigger_error(
                sprintf($this->Translate('Poll priority %d is not valid (allowed: 0 to 9, 0 = no own poll priority). Do not repeat with this value.'), $pollPriority),
                E_USER_WARNING
            );
            return '';
        }

        $index = array_search($messageName, array_column($variableList, self::FORM_ELEMENT_MESSAGENAME), true);
        if ($index === false) {
            trigger_error(
                sprintf($this->Translate('"%s" is not a message of this circuit. Find the message name with EBM_FindMessages.'), $messageName),
                E_USER_WARNING
            );
            return '';
        }

        if ($active && ($variableList[$index][self::FORM_ELEMENT_READABLE] ?? '') !== self::OK_SIGN) {
            trigger_error(
                sprintf($this->Translate('The message "%s" is not readable and cannot be activated.'), $messageName),
                E_USER_WARNING
            );
            return '';
        }

        $variableList[$index][self::FORM_ELEMENT_KEEP]         = $active;
        $variableList[$index][self::FORM_ELEMENT_POLLPRIORITY] = $active ? $pollPriority : 0;

        $oldPollPriorities = $this->readAttributeArray(self::ATTR_POLLPRIORITIES);
        $newPollPriorities = $this->getPollPriorities($variableList);
        if ($oldPollPriorities !== $newPollPriorities && !$this->HasActiveParent()) {
            trigger_error(
                sprintf(
                    $this->Translate('"%s" was not changed: the new poll priority cannot be sent because the MQTT Server (parent instance) is not active. Check the MQTT Server instance and try again.'),
                    $messageName
                ),
                E_USER_WARNING
            );
            return '';
        }

        $created = 0;
        if ($active) {
            $created = $this->RegisterVariablesOfMessage($this->readAttributeArray(self::ATTR_EBUSD_CONFIGURATION_MESSAGES)[$messageName]);
        }
        if ($oldPollPriorities !== $newPollPriorities) {
            $this->publishPollPriorities($oldPollPriorities, $newPollPriorities);
            $this->writeAttributeArray(self::ATTR_POLLPRIORITIES, $newPollPriorities);
        }
        $this->SaveVariableList($this->getUpdatedVariableList($variableList));

        // Wert gleich anfordern (nur lesend) — sonst steht die neue Variable bis zur nächsten
        // Abfrage auf 0, und „Vorlauf 0 °C" sieht aus wie ein Messwert
        $requested = $active && $this->HasActiveParent();
        if ($requested) {
            $this->publish($this->buildTopic($messageName, 'get'), '');
        }

        if (!$active) {
            return sprintf($this->Translate('The message "%s" is no longer active. Its variables and their archive data are kept; the module no longer requests them.'), $messageName);
        }
        return sprintf(
            $this->Translate('The message "%s" is active: %d new variable(s), idents %s, poll priority %d.'),
            $messageName,
            $created,
            $variableList[$index][self::FORM_ELEMENT_IDENTNAMES],
            $pollPriority
        ) . ' ' . ($requested
                ? $this->Translate('The current value has been requested from ebusd and arrives within a few seconds.')
                : $this->Translate('The value arrives with the next request, because the MQTT Server is not active.'));
    }

    /**
     * Liest die aktuellen Werte lesbarer Meldungen bei ebusd (Knopf „Lese Werte") und liefert sie
     * als JSON (Meldung => Werte durch "/" getrennt, null = kein Wert). ebusd fragt dafür ggf. den
     * Bus ab, deshalb höchstens 20 Meldungen je Aufruf.
     */
    public function ReadMessageValues(string $search): string
    {
        $propertyError = $this->getPropertyError();
        if ($propertyError !== null) {
            trigger_error($propertyError[2], E_USER_WARNING); // ohne Abfrage — mit ungültiger Adresse käme nur ein irreführendes „antwortet nicht"
            return '';
        }

        $variableList = $this->getStoredVariableListOrWarn();
        if ($variableList === null) {
            return '';
        }

        $matches = array_filter(
            $this->filterVariableList($variableList, $search),
            static fn(array $item): bool => ($item[self::FORM_ELEMENT_READABLE] ?? '') === self::OK_SIGN
        );
        if ($matches === []) {
            trigger_error(
                sprintf($this->Translate('No readable message matches "%s". Find message names with EBM_FindMessages.'), $search),
                E_USER_WARNING
            );
            return '';
        }
        if (count($matches) > self::MAX_MESSAGES_PER_READ) {
            trigger_error(
                sprintf(
                    $this->Translate('The search "%s" matches %d readable messages; at most %d are read per call to spare the eBUS. Narrow the search.'),
                    $search,
                    count($matches),
                    self::MAX_MESSAGES_PER_READ
                ),
                E_USER_WARNING
            );
            return '';
        }

        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));
        $out         = [];
        foreach ($matches as $item) {
            $current = $this->getCurrentValueAndTime($circuitName, $item[self::FORM_ELEMENT_MESSAGENAME]);
            if ($current === null) {
                // nicht weiterfragen: jede weitere Meldung wartete erneut auf die Zeitüberschreitung
                trigger_error($this->getNoAnswerText($circuitName, $item[self::FORM_ELEMENT_MESSAGENAME]), E_USER_WARNING);
                return '';
            }
            [$value, $lastUpdate]                       = $current;
            $out[$item[self::FORM_ELEMENT_MESSAGENAME]] = ['value' => $value, 'lastUpdate' => $lastUpdate > 0 ? date('Y-m-d H:i:s', $lastUpdate) : null];
        }
        return json_encode($out, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Gespeicherte Meldungsliste; leer = Konfiguration noch nicht eingelesen (dann Warnung, null) */
    private function getStoredVariableListOrWarn(): ?array
    {
        $variableList = $this->readAttributeArray(self::ATTR_VARIABLELIST);
        if ($variableList === []) {
            trigger_error($this->Translate('The configuration has not been read from ebusd yet. Read it with EBM_UpdateConfiguration.'), E_USER_WARNING);
            return null;
        }
        return $variableList;
    }

    private function filterVariableList(array $variableList, string $search): array
    {
        if ($search === '') {
            return $variableList;
        }
        return array_values(array_filter(
            $variableList,
            static fn(array $item): bool => mb_stripos((string)$item[self::FORM_ELEMENT_MESSAGENAME], $search) !== false
                                            || mb_stripos((string)($item[self::FORM_ELEMENT_VARIABLENAMES] ?? ''), $search) !== false
        ));
    }

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

        $ret = $this->SendDataToParent(json_encode($Data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->logDebug(__FUNCTION__, sprintf('%s = %s%s', $topic, $this->shortenForDebug($payload), $ret !== '' ? ' (return: ' . $ret . ')' : ''));
    }

    /** Lange Werte (ganze Meldungslisten aus Formular-Knöpfen) im Debug kürzen */
    private function shortenForDebug(string $text): string
    {
        $max = 200;
        return strlen($text) <= $max ? $text : sprintf('%s… (%d characters)', substr($text, 0, $max), strlen($text));
    }

    /** @return int|string Anzahl gelesener Werte, oder der Grund, wenn ebusd nicht antwortet (dann bleibt die Liste unverändert) */
    private function UpdateCurrentValues(array $variableList): int|string
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
                $current = $this->getCurrentValueAndTime($mqttTopic, $entry[self::FORM_ELEMENT_MESSAGENAME]);
                if ($current === null) {
                    $this->UpdateFormField('ProgressBar', 'visible', false);
                    return $this->getNoAnswerText($mqttTopic, $entry[self::FORM_ELEMENT_MESSAGENAME]);
                }
                $readCounter++;
                $entry[self::FORM_ELEMENT_READVALUES] = (string)$current[0];
            }
            $formField[] = $entry;
        }

        $jsonFormField = json_encode($formField, JSON_THROW_ON_ERROR);
        $this->logDebug(__FUNCTION__, sprintf('%d messages, %d readable values read', count($formField), $readCounter));
        $this->UpdateFormField('ProgressBar', 'visible', false);
        $this->UpdateFormField(self::FORM_LIST_VARIABLELIST, 'values', $jsonFormField);
        return $readCounter;
    }

    private function CreateAndUpdateVariables(array $variableList): int
    {
        $configurationMessages = $this->readAttributeArray(self::ATTR_EBUSD_CONFIGURATION_MESSAGES);
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
        $oldPollPriorities = $this->readAttributeArray(self::ATTR_POLLPRIORITIES);
        $newPollPriorities = $this->getPollPriorities($variableList);

        if ($oldPollPriorities !== $newPollPriorities) {
            $this->publishPollPriorities($oldPollPriorities, $newPollPriorities);
            $this->writeAttributeArray(self::ATTR_POLLPRIORITIES, $newPollPriorities);
        }

        $this->logDebug(__FUNCTION__, sprintf('%d messages in the list, %d new variable(s)', count($variableList), $count));

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

        $this->writeAttributeArray(self::ATTR_VARIABLELIST, $cleanedList);
    }

    /**
     * Liest die Konfiguration des Schaltkreises von ebusd und speichert sie.
     *
     * @return array|string die aufbereiteten Meldungen, oder der Grund des Fehlschlags als Text
     */
    private function ReadConfiguration(): array|string
    {
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $port        = $this->ReadPropertyString(self::PROP_PORT);
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        if ($host === '' || $circuitName === '') {
            return $this->Translate('Host and circuit must be set and applied before the configuration can be read.');
        }

        $url    = sprintf('http://%s:%s/data/%s/?def&verbose&exact&write', $host, $port, $circuitName);
        $result = $this->readURL($url);

        if ($result === null) {
            $this->logDebug(__FUNCTION__, 'No response from ebusd URL: ' . $url);
            return sprintf($this->Translate('ebusd does not answer at %s. Check host, port and the HTTP port of ebusd (--httpport).'), $url);
        }

        if (!isset($result[$circuitName]['messages'])) {
            return sprintf(
                $this->Translate('ebusd has no configuration for the circuit "%s" (%s). Check the circuit with "Read Circuits".'),
                $circuitName,
                $url
            );
        }

        $configurationMessages = $result[$circuitName]['messages'];
        if (count($configurationMessages) === 1) {
            return sprintf(
                $this->Translate('ebusd returned only one message for the circuit "%s" - the configuration is probably still loading. Try again later.'),
                $circuitName
            );
        }

        //ebusd Konfiguration aufbereiten und als Attribut speichern
        $configurationMessages = $this->selectAndPrepareConfigurationMessages($configurationMessages);
        ksort($configurationMessages);
        $this->logDebug(__FUNCTION__, sprintf('%d messages read from %s', count($configurationMessages), $url));
        $this->WriteAttributeString(self::ATTR_EBUSD_CONFIGURATION_MESSAGES, json_encode($configurationMessages, JSON_THROW_ON_ERROR));

        //Ausgabeliste aufbereiten und als Attribut speichern
        $variableList = $this->getVariableList(json_encode($configurationMessages, JSON_THROW_ON_ERROR));
        $this->UpdateFormField(self::FORM_LIST_VARIABLELIST, 'values', json_encode($variableList, JSON_THROW_ON_ERROR, 3));
        $this->SaveVariableList($variableList);
        $this->SetBuffer(self::BUFFER_REPORTED_UNKNOWN, '[]'); // neue Konfiguration: fehlende Meldungen wieder melden

        return $configurationMessages;
    }

    private function requestAllValues(): void
    {
        try {
            $variableList = $this->readAttributeArray(self::ATTR_VARIABLELIST);
        } catch (JsonException $e) {
            $this->logDebug(__FUNCTION__, 'Error decoding VariableList: ' . $e->getMessage());
            return;
        }

        $topics = [];
        foreach ($variableList as $entry) {
            $keep     = $entry[self::FORM_ELEMENT_KEEP] ?? false;
            $readable = ($entry[self::FORM_ELEMENT_READABLE] ?? '') === self::OK_SIGN;

            if ($keep && $readable) {
                $topics[] = $this->buildTopic($entry[self::FORM_ELEMENT_MESSAGENAME], 'get');
            }
        }
        if ($topics === []) {
            return;
        }

        // Kam seit der letzten Runde nichts per MQTT zurück, erreicht ebusd den MQTT Server nicht.
        // Weiter anfragen: nur so kommt nach der Behebung wieder eine Antwort.
        $replyState = $this->GetBuffer(self::BUFFER_MQTT_REPLY);
        if ($replyState === 'pending') {
            $this->SetBuffer(self::BUFFER_MQTT_REPLY, 'silent');
            $this->updateInstanceStatus();
        } elseif ($replyState === '') {
            $this->SetBuffer(self::BUFFER_MQTT_REPLY, 'pending');
        }

        foreach ($topics as $topic) {
            $this->publish($topic, '');
        }
    }

    /**
     * Eine Meldung von ebusd kam per MQTT an. Nach einer unbeantworteten Runde (Status 209)
     * stellt sie den Status wieder her.
     */
    private function mqttReplyReceived(): void
    {
        $this->SetBuffer(self::BUFFER_LAST_MQTT_RECEIVE, (string)time());
        $wasSilent = $this->GetBuffer(self::BUFFER_MQTT_REPLY) === 'silent';
        $this->SetBuffer(self::BUFFER_MQTT_REPLY, '');
        if ($wasSilent && $this->GetStatus() === self::STATUS_INST_NO_MQTT_REPLY) {
            $this->updateInstanceStatus();
        }
    }

    private function getNoMqttReplyText(): string
    {
        return sprintf(
            $this->Translate('ebusd %s:%s answers via HTTP, but sent nothing via MQTT since the last value request of the update interval. Check the MQTT options of ebusd (--mqtthost, --mqttport, --mqttuser, --mqttpass) against the Server Socket and the MQTT Server instance in Symcon.'),
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT)
        );
    }

    private function checkConnection(): void
    {
        $this->updateInstanceStatus();

        $status = $this->GetStatus();
        if ($this->trace) {
            $this->logDebug(__FUNCTION__, 'InstanceStatus: ' . $status);
        }

        // Ohne MQTT-Antwort (209) weiter anfragen, sonst käme nach der Behebung nie wieder eine
        $requestValues  = in_array($status, [IS_ACTIVE, self::STATUS_INST_NO_MQTT_REPLY], true);
        $updateInterval = $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL) * 60 * 1000;
        $this->SetTimerInterval(self::TIMER_REQUEST_ALL_VALUES, $requestValues ? $updateInterval : 0);

        if ($status === IS_ACTIVE) {
            // Wenn aktiv: Connection-Check-Timer stoppen
            $checkConnectionTimer = 0;
        } else {
            // Bei einer Störung: Connection-Check-Timer mit Backoff starten
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
        $variableListUpdated = [];
        foreach ($variableList as $item) {
            $identList     = [];
            $variableFound = false;

            if (!empty($item[self::FORM_ELEMENT_IDENTNAMES])) {
                foreach (explode('/', $item[self::FORM_ELEMENT_IDENTNAMES]) as $ident) {
                    $varID = @$this->GetIDForIdent($ident);

                    if ($varID > 0) {
                        $identList[]   = $ident . ($this->isArchived($ident) ? '(A)' : '');
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

    private function setCircuitOptions(string $host, string $port, string $currentCircuit): void
    {
        $url = sprintf('http://%s:%s/data', $host, $port);

        $result = $this->readURL($url);

        // Nicht erreichbar: Liste unverändert lassen, sonst wird der gewählte Schaltkreis ungültig
        if ($result === null) {
            $this->MsgBox(sprintf($this->Translate('ebusd could not be reached at %s'), $url));
            return;
        }

        $options = [self::EMPTY_OPTION_VALUE];

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

        $options     = $this->withCircuitOption($options, $currentCircuit);
        $optionValue = json_encode($options, JSON_THROW_ON_ERROR);

        $this->logDebug(__FUNCTION__, 'optionValues: ' . $optionValue);

        // UI und Attribut synchronisieren
        $this->UpdateFormField(self::PROP_CIRCUITNAME, 'options', $optionValue);
        $this->WriteAttributeString(self::ATTR_CIRCUITOPTIONLIST, $optionValue);
    }

    private function withCircuitOption(array $options, string $circuitName): array
    {
        if ($circuitName === '' || in_array($circuitName, array_column($options, 'value'), true)) {
            return $options;
        }

        $options[] = [
            'caption' => $circuitName,
            'value'   => $circuitName
        ];
        return $options;
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

        // Veraltete Einträge werden auf Prio 0 zurückgesetzt, neue/geänderte auf ihren Wert
        $updates = array_fill_keys(array_keys($deprecatedItems), 0) + $newItems;
        foreach ($updates as $messagename => $pollPriority) {
            $this->publish($this->buildTopic((string)$messagename, 'get'), '?' . (int)$pollPriority);
        }
    }

    private function getValueURL(string $mqttTopic, string $messageId): string
    {
        return sprintf(
            'http://%s:%s/data/%s/%s?def&verbose&exact&required&maxage=600',
            $this->ReadPropertyString(self::PROP_HOST),
            $this->ReadPropertyString(self::PROP_PORT),
            $mqttTopic,
            $messageId
        );
    }

    private function getNoAnswerText(string $mqttTopic, string $messageId): string
    {
        return sprintf(
            $this->Translate('ebusd does not answer at %s. Check host, port and the HTTP port of ebusd (--httpport).'),
            $this->getValueURL($mqttTopic, $messageId)
        );
    }

    /** @return array{0: ?string, 1: int} Werte durch "/" getrennt (null = keine Antwort) und ebusds Zeitpunkt der letzten Aktualisierung (0 = nie) */
    /**
     * @return array{0: ?string, 1: int}|null [Wert, letzte Aktualisierung] — [null, 0], wenn ebusd ohne Wert antwortet
     *                                        (HTTP 200 mit nur dem global-Block); null, wenn ebusd gar nicht antwortet
     */
    private function getCurrentValueAndTime(string $mqttTopic, string $messageId): ?array
    {
        $url    = $this->getValueURL($mqttTopic, $messageId);
        $result = $this->readURL($url);
        if ($result === null) {
            return null;
        }

        // Prüfung, ob Ergebnis die erwarteten Daten enthält
        if (!isset($result[$mqttTopic]['messages'][$messageId])) {
            $this->logDebug(__FUNCTION__, sprintf('current values of message \'%s\' not found (URL: %s)', $messageId, $url));
            return [null, 0];
        }

        $message    = $result[$mqttTopic]['messages'][$messageId];
        $lastUpdate = (int)($message['lastup'] ?? 0);

        if (!isset($message['fields']) || !is_array($message['fields'])) {
            return ['', $lastUpdate];
        }

        $values = [];
        foreach ($this->getFieldValues($message, $message['fields'], true) as $field) {
            $values[] = $field['value'];
        }

        return [implode('/', $values), $lastUpdate];
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

            // MaintainAction statt EnableAction: nimmt eine Aktion auch zurück, wenn die Meldung nicht (mehr) schreibbar ist
            $this->MaintainAction($ident, $variableHasAction);

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
                    'Caption'            => $this->Translate('Off'),
                    'IconValue'          => '',
                    'IconActive'         => false,
                    'ColorActive'        => false,
                    'ColorValue'         => -1,
                    'ContentColorActive' => false
                ],
                [
                    'Value'              => true,
                    'Caption'            => $this->Translate('On'),
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

                // Eingabefeld oder Slider: ebusd liefert keine fachlichen Grenzen, nur den technischen
                // Bereich des Datentyps. Bei EXP (±3·10³⁸) oder UIN (0 … 65534) ist ein Schieberegler
                // unbedienbar — ein Schieberegler nur, wenn der Bereich überschaubar ist.
                $range = $this->getValueRange($fielddef);
                if ($range === null || $range['steps'] > self::MAX_SLIDER_STEPS) {
                    return [
                        'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT,
                        'SUFFIX'       => $suffix,
                    ];
                }

                return [
                    'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                    'SUFFIX'       => $suffix,
                    'DIGITS'       => $digits,
                    'MIN'          => $range['min'],
                    'MAX'          => $range['max'],
                    'STEP_SIZE'    => $range['step'],
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
        if (!$hasAction) {
            return array_filter([
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'SUFFIX'       => $suffix
            ]);
        }

        // Die Werteingabe kennt keine OPTIONS - mit fester Werteliste wird es eine Aufzählung.
        $options = $this->getPresentationOptions($fielddef, $variableType);
        if (!empty($options)) {
            return [
                'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS'      => json_encode($options, JSON_THROW_ON_ERROR)
            ];
        }

        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT
        ];
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

    /**
     * Prüft die Eigenschaften, die sich ohne Netz beurteilen lassen. Gemeinsam für Instanzstatus und
     * Selbsttest, damit beide mit denselben Worten urteilen.
     *
     * @return array{0: int, 1: string, 2: string}|null [Statuscode, Debug-Grund, Text mit nächstem Schritt] oder null = in Ordnung
     */
    private function getPropertyError(): ?array
    {
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $portString  = $this->ReadPropertyString(self::PROP_PORT);
        $port        = is_numeric($portString) ? (int)$portString : 0;
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        // zuerst: ohne Schaltkreis fragt die Verbindungsprüfung sonst /data/ ab und meldet 203 „Schaltkreis "" gibt es nicht"
        if ($circuitName === '') {
            return [
                self::STATUS_INST_NO_CIRCUIT,
                'no circuit selected',
                $this->Translate('No circuit selected. Determine the circuits with "Read Circuits" and select one.')
            ];
        }

        if (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return [
                self::STATUS_INST_IP_IS_INVALID,
                'invalid IP',
                sprintf($this->Translate('Host "%s" is not a valid IP address or host name. Correct the host in the instance configuration.'), $host)
            ];
        }

        if ($port < 1 || $port > 65535 || !filter_var($port, FILTER_VALIDATE_INT)) {
            return [
                self::STATUS_INST_PORT_IS_INVALID,
                'invalid Port',
                sprintf($this->Translate('Port "%s" is not valid (allowed: 1 to 65535). Correct the port in the instance configuration.'), $portString)
            ];
        }

        $updateInterval = $this->ReadPropertyInteger(self::PROP_UPDATEINTERVAL);
        if ($updateInterval < 0) {
            return [
                self::STATUS_INST_INTERVAL_INVALID,
                'invalid update interval',
                sprintf(
                    $this->Translate('Update interval %d is not valid (allowed: 0 = off, or a number of minutes). Correct the update interval in the instance configuration.'),
                    $updateInterval
                )
            ];
        }

        if ($circuitName === self::MODEL_GLOBAL_NAME) {
            return [
                self::STATUS_INST_TOPIC_IS_INVALID,
                'Wrong Circuit name (global)',
                $this->Translate('The circuit "global" cannot be used. Select the circuit of a device.')
            ];
        }
        return null;
    }

    private function updateInstanceStatus(): void
    {
        $host        = $this->ReadPropertyString(self::PROP_HOST);
        $port        = (int)$this->ReadPropertyString(self::PROP_PORT);
        $circuitName = strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME));

        $propertyError = $this->getPropertyError();
        if ($propertyError !== null) {
            $this->applyStatus(...$propertyError);
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->applyStatus(
                IS_INACTIVE,
                'Parent not active',
                $this->Translate('The MQTT Server (parent instance) is not active. The values of ebusd arrive via MQTT only - check the MQTT Server instance.')
            );
            return;
        }

        //Verbindung prüfen und circuits holen
        $url    = sprintf('http://%s:%d/data/%s', $host, $port, $circuitName);
        $result = $this->readURL($url);

        if ($result === null || !isset($result[self::MODEL_GLOBAL_NAME]['signal'])) {
            $this->applyStatus(
                self::STATUS_INST_NOT_REACHABLE,
                'invalid connection',
                sprintf(
                    $this->Translate('ebusd does not answer at %s. Check host and port and whether ebusd is running with its HTTP port enabled (--httpport). The connection is checked again automatically.'),
                    $url
                )
            );
            return;
        }

        $noSignalText = sprintf(
            $this->Translate('ebusd at %s:%d reports no eBUS signal. Check the eBUS adapter and its connection to the bus. The connection is checked again automatically.'),
            $host,
            $port
        );

        if (!filter_var($result[self::MODEL_GLOBAL_NAME]['signal'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
            $this->applyStatus(self::STATUS_INST_NO_SIGNAL, 'no signal (REST)', $noSignalText);
            return;
        }

        if (!array_key_exists($circuitName, $result)) {
            $this->applyStatus(
                self::STATUS_INST_TOPIC_IS_INVALID,
                'invalid circuit name',
                sprintf(
                    $this->Translate('The circuit "%s" does not exist at ebusd %s:%d. Determine the available circuits with "Read Circuits".'),
                    $circuitName,
                    $host,
                    $port
                )
            );
            return;
        }

        if (!$this->ReadAttributeBoolean(self::ATTR_SIGNAL)) {
            $this->applyStatus(self::STATUS_INST_NO_SIGNAL, 'no signal (MQTT)', $noSignalText);
            return;
        }

        if ($this->GetBuffer(self::BUFFER_MQTT_REPLY) === 'silent') {
            $this->applyStatus(self::STATUS_INST_NO_MQTT_REPLY, 'no reply via MQTT', $this->getNoMqttReplyText());
            return;
        }

        $this->applyStatus(IS_ACTIVE, 'active');
    }

    /**
     * Setzt den Instanzstatus. Über MCP sieht eine KI nur die Statuszahl, nicht den Text aus der
     * form.json — deshalb steht jeder Wechsel in einen Fehler einmal als Warnung mit Wert und
     * nächstem Schritt im Log, die Rückkehr nach 102 einmal als Meldung. Ein unveränderter Status
     * (die Verbindungsprüfung läuft zyklisch) schreibt nichts.
     */
    private function applyStatus(int $status, string $reason, string $logText = ''): void
    {
        $previous = $this->GetStatus();
        $this->SetStatus($status);
        $this->logDebug('updateInstanceStatus', sprintf('Status: %s (%s)', $status, $reason));

        if ($status === $previous) {
            return;
        }

        if ($status === IS_ACTIVE) {
            // nur nach einer Störung im Betrieb — nach einem Eingabe- oder Einrichtungsfehler
            // (202, 204, 207, 208) gab es keine Verbindung, die wieder funktionieren könnte
            if (in_array(
                $previous,
                [IS_INACTIVE, self::STATUS_INST_TOPIC_IS_INVALID, self::STATUS_INST_NOT_REACHABLE, self::STATUS_INST_NO_SIGNAL, self::STATUS_INST_NO_MQTT_REPLY],
                true
            )) {
                $this->LogMessage(
                    sprintf(
                        $this->Translate('Connection to ebusd %s:%s (circuit "%s") is working again.'),
                        $this->ReadPropertyString(self::PROP_HOST),
                        $this->ReadPropertyString(self::PROP_PORT),
                        $this->ReadPropertyString(self::PROP_CIRCUITNAME)
                    ),
                    KL_MESSAGE
                );
            }
            return;
        }

        if ($logText !== '') {
            $this->LogMessage($logText, KL_WARNING);
        }
    }

    /**
     * Baut den MQTT-Topic für eine Message dieses Schaltkreises, z. B. ebusd/hmu/SetMode/set.
     */
    private function buildTopic(string $messageId, string $suffix): string
    {
        return sprintf(
            '%s/%s/%s/%s',
            MQTT_GROUP_TOPIC,
            strtolower($this->ReadPropertyString(self::PROP_CIRCUITNAME)),
            $messageId,
            $suffix
        );
    }

    /**
     * Liest ein als JSON gespeichertes Instanz-Attribut als Array.
     */
    private function readAttributeArray(string $name): array
    {
        return json_decode($this->ReadAttributeString($name), true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeAttributeArray(string $name, array $value): void
    {
        $this->WriteAttributeString($name, json_encode($value, JSON_THROW_ON_ERROR));
    }

}


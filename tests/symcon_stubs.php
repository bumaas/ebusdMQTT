<?php

declare(strict_types=1);

/**
 * Minimaler IP-Symcon-Kernel-Stub für die Golden-File-Regressionstests.
 *
 * Die Konstantenwerte entsprechen dem offiziellen SDK (PhpStorm-Stub symcon.php).
 * Die Basisklasse IPSModuleStrict zeichnet alle relevanten Kernel-Aufrufe in
 * $recorded auf und beantwortet ReadProperty- und ReadAttribute-Aufrufe aus den
 * per RegisterProperty bzw. RegisterAttribute hinterlegten Defaults — damit
 * entspricht eine frisch erzeugte Instanz exakt dem Auslieferungszustand. Tests
 * können einzelne Werte per setPropertyForTest()/setAttributeForTest() überschreiben.
 */

const IPS_KERNELMESSAGE = 10100;
const KR_READY          = 10103;
const KL_ERROR          = 10205;
const IM_CHANGESTATUS   = 10505;
const IS_ACTIVE         = 102;
const IS_INACTIVE       = 104;

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;

const VARIABLE_PRESENTATION_VALUE_PRESENTATION = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
const VARIABLE_PRESENTATION_VALUE_INPUT        = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';
const VARIABLE_PRESENTATION_SLIDER             = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
const VARIABLE_PRESENTATION_ENUMERATION        = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';

function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

function IPS_InstanceExists(int $InstanceID): bool
{
    return false;
}

function IPS_GetInstance(int $InstanceID): array
{
    return ['ConnectionID' => 0];
}

function IPS_GetInstanceListByModuleID(string $ModuleID): array
{
    return [];
}

function IPS_GetVariable(int $VariableID): array
{
    return ['VariableUpdated' => 0];
}

function IPS_GetObject(int $ID): array
{
    return ['ObjectName' => 'Objekt#' . $ID];
}

function AC_GetLoggingStatus(int $InstanceID, int $VariableID): bool
{
    return false;
}

class IPSModuleStrict
{
    protected int $InstanceID;

    /** @var array<string, mixed> per RegisterProperty* hinterlegte Werte */
    private array $properties = [];

    /** @var array<string, mixed> per RegisterAttribute* hinterlegte Werte */
    private array $attributes = [];

    /** @var list<array> aufgezeichnete Kernel-Aufrufe */
    public array $recorded = [];

    public function __construct(int $InstanceID = 0)
    {
        $this->InstanceID = $InstanceID;
    }

    // ---- Test-Hilfen -------------------------------------------------------

    public function setPropertyForTest(string $Name, mixed $Value): void
    {
        $this->properties[$Name] = $Value;
    }

    public function setAttributeForTest(string $Name, mixed $Value): void
    {
        $this->attributes[$Name] = $Value;
    }

    public function resetRecorded(): void
    {
        $this->recorded = [];
    }

    private function record(string $event, mixed ...$args): void
    {
        $this->recorded[] = [$event, ...$args];
    }

    // ---- Properties --------------------------------------------------------

    protected function RegisterPropertyBoolean(string $Name, bool $DefaultValue): bool
    {
        $this->properties[$Name] = $DefaultValue;
        return true;
    }

    protected function RegisterPropertyInteger(string $Name, int $DefaultValue): bool
    {
        $this->properties[$Name] = $DefaultValue;
        return true;
    }

    protected function RegisterPropertyString(string $Name, string $DefaultValue): bool
    {
        $this->properties[$Name] = $DefaultValue;
        return true;
    }

    protected function ReadPropertyBoolean(string $Name): bool
    {
        return (bool)($this->properties[$Name] ?? false);
    }

    protected function ReadPropertyInteger(string $Name): int
    {
        return (int)($this->properties[$Name] ?? 0);
    }

    protected function ReadPropertyString(string $Name): string
    {
        return (string)($this->properties[$Name] ?? '');
    }

    // ---- Attribute ---------------------------------------------------------

    protected function RegisterAttributeBoolean(string $Name, bool $DefaultValue): bool
    {
        $this->attributes[$Name] = $DefaultValue;
        return true;
    }

    protected function RegisterAttributeInteger(string $Name, int $DefaultValue): bool
    {
        $this->attributes[$Name] = $DefaultValue;
        return true;
    }

    protected function RegisterAttributeString(string $Name, string $DefaultValue): bool
    {
        $this->attributes[$Name] = $DefaultValue;
        return true;
    }

    protected function ReadAttributeBoolean(string $Name): bool
    {
        return (bool)($this->attributes[$Name] ?? false);
    }

    protected function ReadAttributeInteger(string $Name): int
    {
        return (int)($this->attributes[$Name] ?? 0);
    }

    protected function ReadAttributeString(string $Name): string
    {
        return (string)($this->attributes[$Name] ?? '');
    }

    protected function WriteAttributeBoolean(string $Name, bool $Value): bool
    {
        $this->attributes[$Name] = $Value;
        return true;
    }

    protected function WriteAttributeInteger(string $Name, int $Value): bool
    {
        $this->attributes[$Name] = $Value;
        return true;
    }

    protected function WriteAttributeString(string $Name, string $Value): bool
    {
        $this->attributes[$Name] = $Value;
        return true;
    }

    // ---- Variablen ---------------------------------------------------------

    protected function MaintainVariable(string $Ident, string $Name, int $Type, array|string $PresentationOrProfile, int $Position, bool $Keep): bool
    {
        $this->record('MaintainVariable', $Ident, $Name, $Type, $PresentationOrProfile, $Position, $Keep);
        return true;
    }

    protected function GetIDForIdent(string $Ident): int
    {
        return 0;
    }

    protected function EnableAction(string $Ident): bool
    {
        $this->record('EnableAction', $Ident);
        return true;
    }

    protected function GetValue(string $Ident): mixed
    {
        return '';
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        $this->record('SetValue', $Ident, $Value);
        return true;
    }

    // ---- Kommunikation / Sonstiges ----------------------------------------

    protected function SendDataToParent(string $Data): string
    {
        $this->record('SendDataToParent', $Data);
        return '';
    }

    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        return true;
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->record('LogMessage', $Message, $Type);
        return true;
    }

    protected function SetStatus(int $Status): bool
    {
        $this->record('SetStatus', $Status);
        return true;
    }

    protected function GetStatus(): int
    {
        return IS_ACTIVE;
    }

    protected function SetSummary(string $Summary): bool
    {
        return true;
    }

    protected function HasActiveParent(): bool
    {
        return false;
    }

    protected function GetMessageList(): array
    {
        return [];
    }

    protected function RegisterMessage(int $SenderID, int $Message): bool
    {
        return true;
    }

    protected function UnregisterMessage(int $SenderID, int $Message): bool
    {
        return true;
    }

    protected function RegisterTimer(string $Name, int $Milliseconds, string $ScriptText): bool
    {
        return true;
    }

    protected function RegisterOnceTimer(string $Name, string $ScriptText): bool
    {
        return true;
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        return true;
    }

    protected function SetReceiveDataFilter(string $Filter): bool
    {
        return true;
    }

    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        return true;
    }

    public function Translate(string $Text): string
    {
        return $Text;
    }

    // ---- Framework-Hooks ---------------------------------------------------

    public function Create(): void
    {
    }

    public function Destroy(): void
    {
    }

    public function ApplyChanges(): void
    {
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
    }

    public function GetConfigurationForm(): string
    {
        return '';
    }
}

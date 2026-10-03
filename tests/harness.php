<?php

declare(strict_types=1);

/**
 * Gemeinsamer Testrahmen: bindet ebusdMQTTDevice an den offiziellen Kernel-Stub
 * (symcon/SymconStubs, Submodul tests/stubs, gepinnt). Der Kernel trägt Properties,
 * Attribute, Variablen, Timer und Status; der Harness zeichnet nur auf, was der Stub
 * nicht beobachtbar macht, und ersetzt die beiden Außenverbindungen:
 *
 *  - HTTP zur ebusd-REST-Schnittstelle: readURL() liefert Antworten aus $responses
 *  - MQTT zum Parent: SendDataToParent() wird nur aufgezeichnet; HasActiveParent()
 *    meldet $parentActive (der Stub kennt den MQTT Server nicht als Instanz)
 *
 * Einbinden mit require_once __DIR__ . '/harness.php'; Instanzen über neueInstanz().
 */

require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/ebusdMQTTDevice/module.php';

IPS\Kernel::reset();

final class ebusdMQTTHarness extends ebusdMQTTDevice
{
    public const MODULE_ID = '{0A243F27-C31D-A389-5357-B8D000901D78}'; // ebusdMQTTDevice/module.json

    /** @var list<array> aufgezeichnete Kernel-Aufrufe: [Name, Argumente …] */
    public array $recorded = [];

    /** @var array<string, array|null> URL => Antwort der ebusd-REST-Schnittstelle (fehlt/null = nicht erreichbar) */
    public array $responses = [];

    /** @var list<string> abgefragte URLs */
    public array $requestedUrls = [];

    /** @var list<array{string, string, mixed}> UpdateFormField-Aufrufe */
    public array $formUpdates = [];

    public bool $parentActive = false;

    public function id(): int
    {
        return $this->InstanceID;
    }

    public function resetRecorded(): void
    {
        $this->recorded = [];
    }

    /** Properties setzen und übernehmen — wie „Übernehmen" im Formular */
    public function konfigurieren(array $properties): void
    {
        foreach ($properties as $name => $value) {
            $this->SetProperty($name, $value);
        }
        $this->ApplyChanges();
    }

    public function attribute(string $Name): string
    {
        return $this->ReadAttributeString($Name);
    }

    public function attributSetzen(string $Name, string $Value): void
    {
        $this->WriteAttributeString($Name, $Value);
    }

    /** Variable unter der Instanz anlegen, die zu keiner ebusd-Meldung gehört (Altlast) */
    public function fremdeVariable(string $Ident, string $Name): int
    {
        parent::MaintainVariable($Ident, $Name, VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 0, true);
        return IPS_GetObjectIDByIdent($Ident, $this->InstanceID);
    }

    /** Private Modulmethode aufrufen (Registrierung, Ableitungen) */
    public function privat(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(ebusdMQTTDevice::class, $method))->invoke($this, ...$args);
    }

    /** @return list<array{topic: string, payload: string}> per SendDataToParent publizierte Nachrichten */
    public function publiziert(): array
    {
        $out = [];
        foreach ($this->recorded as $r) {
            if ($r[0] === 'SendDataToParent') {
                $d     = json_decode($r[1], true, 512, JSON_THROW_ON_ERROR);
                $out[] = ['topic' => $d['Topic'], 'payload' => hex2bin($d['Payload'])];
            }
        }
        return $out;
    }

    /* --- Außenverbindungen ------------------------------------------------- */

    protected function readURL(string $url): ?array
    {
        $this->requestedUrls[] = $url;
        return $this->responses[$url] ?? null;
    }

    protected function HasActiveParent(): bool
    {
        return $this->parentActive;
    }

    protected function SendDataToParent(string $Data): string
    {
        $this->recorded[] = ['SendDataToParent', $Data];
        return '';
    }

    /* --- Stub-Overrides: Signaturen exakt wie tests/stubs/ModuleStrictStubs.php --- */

    protected function getTime(): int
    {
        return time(); // RegisterTimer/SetTimerInterval brauchen eine Uhr
    }

    protected function MaintainVariable(string $Ident, string $Name, int $Type, string|array $ProfileOrPresentation, int $Position, bool $Keep): bool
    {
        $this->recorded[] = ['MaintainVariable', $Ident, $Name, $Type, $ProfileOrPresentation, $Position, $Keep];
        return parent::MaintainVariable($Ident, $Name, $Type, $ProfileOrPresentation, $Position, $Keep);
    }

    protected function EnableAction(string $Ident): bool
    {
        $this->recorded[] = ['EnableAction', $Ident];
        return parent::EnableAction($Ident);
    }

    protected function MaintainAction(string $Ident, bool $Keep): bool
    {
        $this->recorded[] = ['MaintainAction', $Ident, $Keep];
        return parent::MaintainAction($Ident, $Keep);
    }

    protected function SetValue(string $Ident, mixed $Value): bool
    {
        $this->recorded[] = ['SetValue', $Ident, $Value];
        return parent::SetValue($Ident, $Value); // typstreng: TypeError statt Cast
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->recorded[] = ['LogMessage', $Message, $Type];
        return parent::LogMessage($Message, $Type);
    }

    protected function SetStatus(int $Status): bool
    {
        $this->recorded[] = ['SetStatus', $Status];
        return parent::SetStatus($Status);
    }

    /** @var list<array{0: string, 1: string}> Debug-Ausgaben (Message, Data) — getrennt von $recorded, damit die Golden-Dateien unberührt bleiben */
    public array $debug = [];

    protected function SendDebug(string $Message, string $Data, int $Format): bool
    {
        $this->debug[] = [$Message, $Data];
        return parent::SendDebug($Message, $Data, $Format);
    }

    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        $this->formUpdates[] = [$Field, $Parameter, $Value];
        return parent::UpdateFormField($Field, $Parameter, $Value);
    }
}

/** Frische Instanz im Stub-Kernel (Create + ApplyChanges), danach konfiguriert */
function neueInstanz(array $properties = []): ebusdMQTTHarness
{
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => ebusdMQTTHarness::MODULE_ID,
        'ModuleName' => 'ebusdMQTTDevice',
        'ModuleType' => 3,
        'Class'      => ebusdMQTTHarness::class,
    ]);
    $h = IPS\InstanceManager::getInstanceInterface($id);
    if ($properties !== []) {
        $h->konfigurieren($properties);
    }
    $h->resetRecorded();
    return $h;
}

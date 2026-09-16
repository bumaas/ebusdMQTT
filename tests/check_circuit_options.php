<?php

declare(strict_types=1);

/**
 * Tests für den Knopf „Ermittle Schaltkreisnamen" (btnReadCircuits); dazu, dass er und
 * „Lese Konfiguration aus" (btnReadConfiguration) ohne aktiven MQTT-Parent laufen.
 *
 * Anlass: Forum t/51854/459 (ebusd auf neue Adresse umgezogen). Der Knopf fragte die
 * gespeicherte statt der eingetippten Adresse ab; die fehlgeschlagene Abfrage leerte
 * die Auswahlliste, der gespeicherte Schaltkreis war danach kein gültiger Wert mehr
 * und „Übernehmen" scheiterte mit „Aktueller Wert "430" ist nicht verfügbar".
 *
 * Fixture tests/fixtures/data_all.json: unveränderte Antwort von
 * http://rasp3keller:8081/data (eingefangen 16.09.2026).
 *
 * Aufruf: php tests/check_circuit_options.php
 */

$root = dirname(__DIR__);

require_once __DIR__ . '/symcon_stubs.php';
require_once $root . '/ebusdMQTTDevice/module.php';

final class CircuitOptionsHarness extends ebusdMQTTDevice
{
    /** @var array<string, array|null> URL => Antwort (null = nicht erreichbar) */
    public array $responses = [];

    /** @var list<string> abgefragte URLs */
    public array $requestedUrls = [];

    /** @var list<array{string, string, mixed}> UpdateFormField-Aufrufe */
    public array $formUpdates = [];

    protected function readURL(string $url): ?array
    {
        $this->requestedUrls[] = $url;
        return $this->responses[$url] ?? null;
    }

    public bool $parentActive = true;

    protected function HasActiveParent(): bool
    {
        return $this->parentActive;
    }

    protected function UpdateFormField(string $Field, string $Parameter, mixed $Value): bool
    {
        $this->formUpdates[] = [$Field, $Parameter, $Value];
        return true;
    }

    public function attribute(string $Name): string
    {
        return $this->ReadAttributeString($Name);
    }
}

$checks = 0;
$fails  = 0;

function check(bool $condition, string $label): void
{
    global $checks, $fails;
    $checks++;
    if ($condition) {
        echo "  ok    $label\n";
        return;
    }
    $fails++;
    echo "  FEHLER $label\n";
}

function optionValues(array $options): array
{
    return array_map(static fn(array $o): string => (string)$o['value'], $options);
}

function newHarness(string $host, string $port, string $circuit): CircuitOptionsHarness
{
    $h = new CircuitOptionsHarness(0);
    $h->Create();
    $h->setPropertyForTest('Host', $host);
    $h->setPropertyForTest('Port', $port);
    $h->setPropertyForTest('CircuitName', $circuit);
    return $h;
}

function clickPayload(string $host, string $port, string $circuit): string
{
    return json_encode(['Host' => $host, 'Port' => $port, 'CircuitName' => $circuit], JSON_THROW_ON_ERROR);
}

$dataAll = json_decode(file_get_contents(__DIR__ . '/fixtures/data_all.json'), true, 512, JSON_THROW_ON_ERROR);

// 0) Das Formular übergibt die eingetippten Werte an den Knopf
$form    = json_decode(file_get_contents($root . '/ebusdMQTTDevice/form.json'), true, 512, JSON_THROW_ON_ERROR);
$onClick = $form['elements'][0]['items'][1]['items'][1]['onClick'] ?? '';
echo "Formular:\n";
check(str_contains($onClick, '$Host') && str_contains($onClick, '$Port'), 'onClick übergibt $Host und $Port');

// 1) Eingetippte Adresse (noch nicht übernommen) wird abgefragt
echo "Eingetippte Adresse:\n";
$h            = newHarness('192.168.1.10', '8080', '700');
$h->responses = ['http://10.1.254.12:8081/data' => $dataAll];
$h->RequestAction('btnReadCircuits', clickPayload('10.1.254.12', '8081', '700'));
check($h->requestedUrls === ['http://10.1.254.12:8081/data'], 'fragt die eingetippte Adresse ab');
$options = json_decode($h->attribute('CircuitOptionList'), true, 512, JSON_THROW_ON_ERROR);
check(optionValues($options) === ['', '700', 'hmu'], 'Liste enthält 700 und hmu, ohne global/broadcast/scan.*');

// 2) ebusd nicht erreichbar: Liste bleibt, Meldung erscheint
echo "Nicht erreichbar:\n";
$h      = newHarness('192.168.1.10', '8080', '700');
$before = json_encode([['caption' => '-', 'value' => ''], ['caption' => '700', 'value' => '700'], ['caption' => 'hmu', 'value' => 'hmu']], JSON_THROW_ON_ERROR);
$h->setAttributeForTest('CircuitOptionList', $before);
$h->RequestAction('btnReadCircuits', clickPayload('10.1.254.12', '8081', '700'));
check($h->attribute('CircuitOptionList') === $before, 'gespeicherte Auswahlliste bleibt unverändert');
$optionUpdates = array_filter($h->formUpdates, static fn(array $u): bool => $u[0] === 'CircuitName');
check($optionUpdates === [], 'Auswahlfeld im Formular wird nicht angefasst');
$msgTexts = array_values(array_filter($h->formUpdates, static fn(array $u): bool => $u[0] === 'MsgText'));
check(
    count($msgTexts) === 1 && str_contains((string)$msgTexts[0][2], 'http://10.1.254.12:8081/data'),
    'Meldung nennt die abgefragte URL'
);

// 3) Gewählter Schaltkreis fehlt beim neuen ebusd: bleibt wählbar
echo "Schaltkreis fehlt in der Antwort:\n";
$h            = newHarness('192.168.1.10', '8080', '430');
$h->responses = ['http://10.1.254.12:8081/data' => $dataAll];
$h->RequestAction('btnReadCircuits', clickPayload('10.1.254.12', '8081', '430'));
$options = json_decode($h->attribute('CircuitOptionList'), true, 512, JSON_THROW_ON_ERROR);
check(in_array('430', optionValues($options), true), 'aktuell gewählter Schaltkreis bleibt in der Liste');

// 4) MQTT-Parent inaktiv: das Auslesen braucht nur HTTP und läuft trotzdem
echo "Parent inaktiv:\n";
$h               = newHarness('192.168.1.10', '8080', '700');
$h->parentActive = false;
$h->responses    = ['http://10.1.254.12:8081/data' => $dataAll];
$h->RequestAction('btnReadCircuits', clickPayload('10.1.254.12', '8081', '700'));
check($h->requestedUrls === ['http://10.1.254.12:8081/data'], 'fragt ebusd auch ohne aktiven Parent ab');
$options = json_decode($h->attribute('CircuitOptionList'), true, 512, JSON_THROW_ON_ERROR);
check(optionValues($options) === ['', '700', 'hmu'], 'Liste wird auch ohne aktiven Parent gefüllt');

$configUrl       = 'http://10.1.254.12:8081/data/hmu/?def&verbose&exact&write';
$h               = newHarness('10.1.254.12', '8081', 'hmu');
$h->parentActive = false;
$h->responses    = [$configUrl => json_decode(file_get_contents(__DIR__ . '/fixtures/config_hmu.json'), true, 512, JSON_THROW_ON_ERROR)];
$h->RequestAction('btnReadConfiguration', '');
check($h->requestedUrls === [$configUrl], 'liest die Konfiguration auch ohne aktiven Parent');
$messages = json_decode($h->attribute('ebusdConfigurationMessages'), true, 512, JSON_THROW_ON_ERROR);
check(is_array($messages) && count($messages) > 0, 'Konfiguration wird auch ohne aktiven Parent gespeichert');

// 5) Formular: gespeicherter Schaltkreis ist immer eine gültige Option
echo "Formularaufbau:\n";
$h = newHarness('192.168.1.10', '8080', '430');
$h->setAttributeForTest('CircuitOptionList', json_encode([['caption' => '-', 'value' => '']], JSON_THROW_ON_ERROR));
$formOut = json_decode($h->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
$values  = optionValues($formOut['elements'][0]['items'][1]['items'][0]['options']);
check(in_array('430', $values, true), 'gespeicherter Schaltkreis steht in den Optionen');
check(count($values) === count(array_unique($values)), 'keine doppelten Optionen');

$h = newHarness('192.168.1.10', '8080', '');
$formOut = json_decode($h->GetConfigurationForm(), true, 512, JSON_THROW_ON_ERROR);
check(optionValues($formOut['elements'][0]['items'][1]['items'][0]['options']) === [''], 'ohne Schaltkreis nur der Leereintrag');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

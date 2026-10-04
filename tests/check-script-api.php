<?php

declare(strict_types=1);

/**
 * Skript-API für alles, was bisher nur über Formular-Knöpfe ging (MCP-Tauglichkeit,
 * Regeln 5, 7, 9, 15).
 *
 * Eine KI über MCP sieht keine Popups und kein UpdateFormField; die Meldungsliste steckt in
 * einem bis zu 80 kB großen Formular. Ohne diese Funktionen war „Binde die Vorlauftemperatur
 * ein" per Skript nicht lösbar:
 *
 *  - EBM_RunSelfTest         Text, ohne jede Wirkung (kein Status, kein Attribut, kein Publish)
 *  - EBM_FindMessages      JSON, filterbar
 *  - EBM_UpdateConfiguration Text; Logik hinter „Lese Konfiguration aus"
 *  - EBM_SetMessageActive    Text; Häkchen „Aktiv" + „Variablen anlegen" für eine Meldung
 *  - EBM_ReadMessageValues   JSON; Logik hinter „Lese Werte", höchstens 20 Meldungen
 *
 * Fixtures: tests/fixtures/config_700.json (Regler VRC700, echte ebusd-Antwort).
 *
 * Aufruf: php tests/check-script-api.php
 */

require_once __DIR__ . '/harness.php';

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

const HOST       = '10.1.254.12';
const BASE       = 'http://10.1.254.12:8081/data';
const STATUS_URL = BASE . '/700';
const CONFIG_URL = BASE . '/700/?def&verbose&exact&write';

function konfig700(): array
{
    return json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** Konfigurierte, aktive Instanz; Konfiguration von ebusd ist noch NICHT eingelesen */
function instanz(): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [
        STATUS_URL => ['global' => ['signal' => 1], '700' => []],
        CONFIG_URL => konfig700(),
    ];
    $h->konfigurieren(['Host' => HOST, 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 5]);
    $h->resetRecorded();
    return $h;
}

/**
 * Ruft eine öffentliche Modulfunktion auf und sammelt, was beim Aufrufer ankommt.
 *
 * @return array{wert: mixed, fehler: list<string>}
 */
function rufe(ebusdMQTTHarness $h, string $methode, mixed ...$args): array
{
    $fehler = [];
    set_error_handler(static function (int $nr, string $text) use (&$fehler): bool {
        if (!(error_reporting() & $nr)) {
            return false;
        }
        $fehler[] = $text;
        return true;
    });
    $wert = null;
    try {
        $wert = $h->$methode(...$args);
    } catch (Throwable $e) {
        $fehler[] = get_class($e) . ': ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
    return ['wert' => $wert, 'fehler' => $fehler];
}

function status(ebusdMQTTHarness $h): int
{
    return IPS_GetInstance($h->id())['InstanceStatus'];
}

/** Zustand, der sich durch eine Funktion ohne Wirkung nicht ändern darf */
function zustand(ebusdMQTTHarness $h): array
{
    return [
        status($h),
        $h->attribute('ebusdConfigurationMessages'),
        $h->attribute('VariableList'),
        $h->attribute('PollPriorities'),
        IPS_GetChildrenIDs($h->id()),
    ];
}

function publiziertOhneGet(ebusdMQTTHarness $h): array
{
    return $h->publiziert();
}

// --- UpdateConfiguration --------------------------------------------------------

echo "EBM_UpdateConfiguration:\n";
$h = instanz();
$r = rufe($h, 'UpdateConfiguration');
check($r['fehler'] === [], 'kein Fehler (' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
check(is_string($r['wert']) && str_contains($r['wert'], '250'), 'Rückgabe nennt die Anzahl der Meldungen (' . var_export($r['wert'], true) . ')');
check(count(json_decode($h->attribute('ebusdConfigurationMessages'), true)) === 250, 'Konfiguration ist gespeichert');

$h2            = instanz();
$h2->responses = [STATUS_URL => ['global' => ['signal' => 1], '700' => []]];
$r             = rufe($h2, 'UpdateConfiguration');
check(count($r['fehler']) === 1 && str_contains($r['fehler'][0], CONFIG_URL), 'ebusd antwortet nicht: Fehler nennt die URL (' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');

// --- FindMessages ---------------------------------------------------------------

echo "EBM_FindMessages:\n";
$r = rufe(instanz(), 'FindMessages', '');
check(count($r['fehler']) === 1 && str_contains($r['fehler'][0], 'EBM_UpdateConfiguration'), 'ohne eingelesene Konfiguration: Fehler verweist auf EBM_UpdateConfiguration');

$r     = rufe($h, 'FindMessages', '');
$liste = json_decode((string)$r['wert'], true);
check($r['fehler'] === [] && is_array($liste) && count($liste) === 250, 'leerer Suchtext liefert alle 250 Meldungen');
$r     = rufe($h, 'FindMessages', 'heizkurve');
$liste = json_decode((string)$r['wert'], true);
$namen = array_column($liste ?? [], 'message');
check(in_array('AdaptHeatCurve', $namen, true) && in_array('Hc1HeatCurve', $namen, true), 'Suche „heizkurve" findet über die Bezeichnung (Groß/Klein egal)');
check(count($namen) < 20, 'Suche schränkt ein (' . count($namen) . ' Treffer)');
$eintrag = $liste[array_search('AdaptHeatCurve', $namen, true)] ?? [];
check(
    ($eintrag['readable'] ?? null) === true && ($eintrag['writable'] ?? null) === true && ($eintrag['active'] ?? null) === false
    && ($eintrag['pollPriority'] ?? null) === 0 && ($eintrag['fields'][0]['ident'] ?? null) === 'AdaptHeatCurve' && !isset($eintrag['fields'][0]['variableID']),
    'Eintrag: lesbar/schreibbar/aktiv/Poll-Priorität/Ident, noch ohne Variable (' . json_encode($eintrag, JSON_UNESCAPED_UNICODE) . ')'
);
check(($eintrag['fields'][0]['label'] ?? null) === 'Adaptive Heizkurve', 'Eintrag trägt die Bezeichnung');

// --- SetMessageActive -------------------------------------------------------------

echo "EBM_SetMessageActive:\n";
$r = rufe($h, 'SetMessageActive', 'Hc1FlowTemp', true, 0);
check($r['fehler'] === [], 'Einschalten ohne Fehler (' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
$vid = @IPS_GetObjectIDByIdent('Hc1FlowTemp', $h->id());
check($vid !== false && $vid > 0, 'Variable Hc1FlowTemp ist angelegt');
check(is_string($r['wert']) && str_contains($r['wert'], 'Hc1FlowTemp'), 'Rückgabe nennt die Meldung');
$eintrag = json_decode((string)rufe($h, 'FindMessages', 'Hc1FlowTemp')['wert'], true)[0] ?? [];
check(($eintrag['active'] ?? null) === true && ($eintrag['fields'][0]['variableID'] ?? null) === $vid, 'Meldungsliste zeigt die Meldung als aktiv mit Variablen-ID');

$h->resetRecorded();
$r = rufe($h, 'SetMessageActive', 'Hc1FlowTemp', true, 3);
check($r['fehler'] === [] && in_array(['topic' => 'ebusd/700/Hc1FlowTemp/get', 'payload' => '?3'], $h->publiziert(), true), 'neue Poll-Priorität geht an ebusd (' . json_encode($h->publiziert()) . ')');
check(json_decode($h->attribute('PollPriorities'), true) === ['Hc1FlowTemp' => 3], 'Poll-Priorität ist gespeichert');

$h->resetRecorded();
$r = rufe($h, 'SetMessageActive', 'Hc1FlowTemp', false, 0);
check($r['fehler'] === [], 'Ausschalten ohne Fehler');
check(@IPS_GetObjectIDByIdent('Hc1FlowTemp', $h->id()) === $vid, 'Variable bleibt beim Ausschalten erhalten (Archivdaten)');
check($h->publiziert() === [['topic' => 'ebusd/700/Hc1FlowTemp/get', 'payload' => '?0']], 'Poll-Priorität wird bei ebusd zurückgesetzt');
$eintrag = json_decode((string)rufe($h, 'FindMessages', 'Hc1FlowTemp')['wert'], true)[0] ?? [];
check(($eintrag['active'] ?? null) === false, 'Meldungsliste zeigt die Meldung als nicht aktiv');

echo "EBM_SetMessageActive, Ablehnungen:\n";
foreach ([
    ['GibtEsNicht', true, 0, ['GibtEsNicht', 'EBM_FindMessages'], 'unbekannte Meldung'],
    ['Hc1FlowTemp', true, 10, ['10', '0', '9'], 'Poll-Priorität außerhalb 0-9'],
] as [$meldung, $aktiv, $prio, $woerter, $fall]) {
    $vorher = zustand($h);
    $h->resetRecorded();
    $r = rufe($h, 'SetMessageActive', $meldung, $aktiv, $prio);
    check(count($r['fehler']) === 1, "$fall: genau ein Fehler (" . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
    foreach ($woerter as $wort) {
        check(isset($r['fehler'][0]) && str_contains($r['fehler'][0], $wort), "$fall: Fehler nennt „{$wort}\"");
    }
    check(zustand($h) === $vorher && $h->publiziert() === [], "$fall: nichts verändert, nichts gesendet");
}
$config     = json_decode($h->attribute('ebusdConfigurationMessages'), true) ?: [];
$nichtLesbar = (string)array_key_first(array_filter($config, static fn(array $m): bool => $m['read'] === false));
$r          = rufe($h, 'SetMessageActive', $nichtLesbar, true, 0);
check(count($r['fehler']) === 1 && str_contains($r['fehler'][0], $nichtLesbar), "nicht lesbare Meldung ($nichtLesbar) wird abgelehnt");
$h->parentActive = false;
$vorher          = zustand($h);
$r               = rufe($h, 'SetMessageActive', 'Hc1FlowTemp', true, 2);
check(count($r['fehler']) === 1 && str_contains($r['fehler'][0], 'MQTT'), 'Poll-Priorität ohne MQTT-Parent: Fehler nennt MQTT');
check(json_decode($h->attribute('PollPriorities'), true) === [], 'ohne Parent wird die Priorität nicht gespeichert');
$h->parentActive = true;

// --- ReadMessageValues ------------------------------------------------------------

echo "EBM_ReadMessageValues:\n";
$h->responses[BASE . '/700/AdaptHeatCurve?def&verbose&exact&required&maxage=600'] = [
    '700' => ['messages' => ['AdaptHeatCurve' => konfig700()['700']['messages']['AdaptHeatCurve']]],
];
$r     = rufe($h, 'ReadMessageValues', 'AdaptHeatCurve');
$werte = json_decode((string)$r['wert'], true);
check($r['fehler'] === [] && is_array($werte) && array_key_exists('AdaptHeatCurve', $werte), 'liefert JSON mit dem Wert der Meldung (' . var_export($r['wert'], true) . ')');
$r = rufe($h, 'ReadMessageValues', '');
check(count($r['fehler']) === 1 && str_contains($r['fehler'][0], '20'), 'mehr als 20 Treffer: Fehler nennt die Grenze');
check($r['wert'] === null || $r['wert'] === '', 'mehr als 20 Treffer: kein Ergebnis');

// --- RunSelfTest ------------------------------------------------------------------

echo "EBM_RunSelfTest:\n";
$h->responses[STATUS_URL] = ['global' => ['signal' => 1], '700' => []];
rufe($h, 'SetMessageActive', 'Hc1FlowTemp', true, 0);
$vorher = zustand($h);
$h->resetRecorded();
$r = rufe($h, 'RunSelfTest');
check($r['fehler'] === [] && is_string($r['wert']), 'Gutzustand: Text ohne Fehler');
check(str_contains((string)$r['wert'], '250') && str_contains((string)$r['wert'], '1'), 'nennt Meldungen und aktive Meldungen');
check(!str_contains((string)$r['wert'], '✘'), 'Gutzustand: kein ✘ (' . $r['wert'] . ')');
check(zustand($h) === $vorher && $h->publiziert() === [], 'Gutzustand: keine Wirkung (Status, Attribute, Variablen, MQTT)');

foreach ([
    'ebusd nicht erreichbar' => [static function (ebusdMQTTHarness $h): void { unset($h->responses[STATUS_URL]); }, STATUS_URL],
    'kein eBUS-Signal'       => [static function (ebusdMQTTHarness $h): void { $h->responses[STATUS_URL] = ['global' => ['signal' => 0], '700' => []]; }, 'signal'],
    'MQTT-Parent inaktiv'    => [static function (ebusdMQTTHarness $h): void { $h->parentActive = false; }, 'MQTT'],
] as $fall => [$stoerung, $stichwort]) {
    $s = instanz();
    rufe($s, 'UpdateConfiguration');
    $stoerung($s);
    $vorher = zustand($s);
    $s->resetRecorded();
    $r = rufe($s, 'RunSelfTest');
    check($r['fehler'] === [] && str_contains((string)$r['wert'], '✘') && str_contains((string)$r['wert'], $stichwort), "$fall: ✘ mit „{$stichwort}\"");
    check(zustand($s) === $vorher && $s->publiziert() === [], "$fall: keine Wirkung, auch kein Statuswechsel");
}

$s = instanz();
$r = rufe($s, 'RunSelfTest');
check(str_contains((string)$r['wert'], '✘') && str_contains((string)$r['wert'], 'EBM_UpdateConfiguration'), 'Konfiguration nicht eingelesen: ✘ mit Verweis auf EBM_UpdateConfiguration');
check(str_contains((string)$r['wert'], 'EBM_SetMessageActive'), 'keine aktive Meldung: Verweis auf EBM_SetMessageActive');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

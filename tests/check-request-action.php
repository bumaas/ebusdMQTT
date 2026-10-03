<?php

declare(strict_types=1);

/**
 * RequestAction meldet jeden Fehlschlag (MCP-Tauglichkeit, Regeln 8, 14, 16, 2).
 *
 * RequestAction ist void; nur ein trigger_error kommt beim Aufrufer an — bei einer KI über
 * MCP genauso wie bei einem Skript. Bisher wurde still ignoriert: Schreiben ohne aktiven
 * MQTT-Parent, ein unbekannter Ident, ein Wert außerhalb der Wertetabelle oder des
 * Wertebereichs — und ein Wert für eine nur lesbare Meldung ging ungeprüft auf den eBUS.
 * Dazu: Das Aktualisierungsintervall hatte keine Untergrenze.
 *
 * Fixture: tests/fixtures/config_700.json (Regler VRC700, echte ebusd-Antwort).
 *
 * Aufruf: php tests/check-request-action.php
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
const STATUS_URL = 'http://10.1.254.12:8081/data/700';
const CONFIG_URL = 'http://10.1.254.12:8081/data/700/?def&verbose&exact&write';

/** Meldungen, deren Variablen der Test anlegt */
const MELDUNGEN = [
    'AdaptHeatCurve',       // schreibbar, Wertetabelle 0=nein, 1=ja
    'FrostOverRideTime',    // schreibbar, Ganzzahl 0..65534
    'Hc1HeatCurve',         // schreibbar, Kommazahl
    'DisplayedOutsideTemp', // nur lesbar
    'ccTimer.Friday',       // schreibbar, aber sechs Felder (Idents ccTimer_Friday_1 …)
];

function instanz(): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [
        STATUS_URL => ['global' => ['signal' => 1], '700' => []],
        CONFIG_URL => json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR),
    ];
    $h->konfigurieren(['Host' => HOST, 'Port' => '8081', 'CircuitName' => '700']);
    $h->RequestAction('btnReadConfiguration', '');
    $config = json_decode($h->attribute('ebusdConfigurationMessages'), true, 512, JSON_THROW_ON_ERROR);
    foreach (MELDUNGEN as $name) {
        $h->privat('RegisterVariablesOfMessage', $config[$name]);
    }
    $h->resetRecorded();
    return $h;
}

/**
 * Führt RequestAction aus und sammelt, was beim Aufrufer ankommt.
 *
 * @return array{fehler: list<string>, publiziert: list<array{topic: string, payload: string}>}
 */
function aktion(ebusdMQTTHarness $h, string $ident, mixed $wert): array
{
    $h->resetRecorded();
    $fehler = [];
    set_error_handler(static function (int $nr, string $text) use (&$fehler): bool {
        if (!(error_reporting() & $nr)) {
            return false; // mit @ unterdrückt - kommt beim Aufrufer nicht an
        }
        if ($nr &(E_USER_WARNING | E_USER_ERROR | E_USER_NOTICE | E_WARNING | E_NOTICE)) {
            $fehler[] = $text;
            return true;
        }
        return false;
    });
    try {
        $h->RequestAction($ident, $wert);
    } catch (Throwable $e) {
        $fehler[] = get_class($e) . ': ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
    return ['fehler' => $fehler, 'publiziert' => $h->publiziert()];
}

function pruefeAbgelehnt(array $r, array $stichwoerter, string $fall): void
{
    check(count($r['fehler']) === 1, "$fall: genau eine Fehlermeldung (" . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
    check(!isset($r['fehler'][0]) || !str_contains($r['fehler'][0], 'Exception'), "$fall: Klartext, keine Rohausnahme");
    foreach ($stichwoerter as $wort) {
        check(isset($r['fehler'][0]) && str_contains($r['fehler'][0], (string)$wort), "$fall: Meldung nennt „{$wort}\"");
    }
    check($r['publiziert'] === [], "$fall: nichts auf den Bus geschickt");
}

function pruefeGesendet(array $r, string $topic, string $payload, string $fall): void
{
    check($r['fehler'] === [], "$fall: kein Fehler (" . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
    check($r['publiziert'] === [['topic' => $topic, 'payload' => $payload]], "$fall: publiziert $topic = $payload (" . json_encode($r['publiziert']) . ')');
}

$h = instanz();

echo "Gültige Werte:\n";
pruefeGesendet(aktion($h, 'AdaptHeatCurve', 1), 'ebusd/700/AdaptHeatCurve/set', '1', 'Wertetabelle');
pruefeGesendet(aktion($h, 'FrostOverRideTime', 4), 'ebusd/700/FrostOverRideTime/set', '4', 'Ganzzahl');
$r = aktion($h, 'Hc1HeatCurve', 0.5);
check($r['fehler'] === [] && count($r['publiziert']) === 1, 'Kommazahl wird publiziert');

echo "Ungültige Werte:\n";
pruefeAbgelehnt(aktion($h, 'AdaptHeatCurve', 5), ['5', 'nein', 'ja'], 'Wert außerhalb der Wertetabelle');
pruefeAbgelehnt(aktion($h, 'FrostOverRideTime', 70000), ['70000', '65534'], 'Ganzzahl außerhalb des Bereichs');
pruefeAbgelehnt(aktion($h, 'FrostOverRideTime', 'viel'), ['viel'], 'Text statt Zahl');
pruefeAbgelehnt(aktion($h, 'Hc1HeatCurve', 'steil'), ['steil'], 'Text statt Kommazahl');

echo "Nicht schreibbar:\n";
pruefeAbgelehnt(aktion($h, 'DisplayedOutsideTemp', 12.5), ['DisplayedOutsideTemp', 'read'], 'Lesewert');
pruefeAbgelehnt(aktion($h, 'ccTimer_Friday_1', '06:00'), ['ccTimer_Friday_1', 'EBM_publish'], 'Mehrfeld-Meldung');
pruefeAbgelehnt(aktion($h, 'GibtEsNicht', 1), ['GibtEsNicht'], 'Unbekannter Ident');

echo "MQTT-Parent inaktiv:\n";
$h->parentActive = false;
pruefeAbgelehnt(aktion($h, 'AdaptHeatCurve', 1), ['MQTT'], 'Schreiben ohne Parent');
pruefeAbgelehnt(aktion($h, 'btnPublishPollPriorities', ''), ['MQTT'], 'Knopf ohne Parent');
$r = aktion($h, 'timerCheckConnection', '');
check($r['fehler'] === [], 'interner Timer meldet keinen Fehler');
$r = aktion($h, 'timerRefreshAllMessages', '');
check($r['fehler'] === [], 'interner Aktualisierungstimer meldet keinen Fehler');
$h->parentActive = true;

echo "Aktionen nur an schreibbaren Variablen:\n";
$h = instanz();
check(IPS_GetVariable(IPS_GetObjectIDByIdent('AdaptHeatCurve', $h->id()))['VariableAction'] === $h->id(), 'schreibbare Variable hat eine Aktion');
check(IPS_GetVariable(IPS_GetObjectIDByIdent('DisplayedOutsideTemp', $h->id()))['VariableAction'] === 0, 'Lesewert bekommt bei der Registrierung keine Aktion');

echo "Aktualisierungsintervall:\n";
$h = instanz();
$h->resetRecorded();
$h->konfigurieren(['UpdateInterval' => -5]);
check(IPS_GetInstance($h->id())['InstanceStatus'] === 208, 'negatives Intervall: Status 208');
$logs = array_values(array_filter($h->recorded, static fn(array $r): bool => $r[0] === 'LogMessage'));
check(count($logs) === 1 && $logs[0][2] === KL_WARNING && str_contains($logs[0][1], '-5'), 'genau eine Warnung, die den Wert nennt');
$form    = json_decode(file_get_contents(dirname(__DIR__) . '/ebusdMQTTDevice/form.json'), true, 512, JSON_THROW_ON_ERROR);
$spinner = $form['elements'][0]['items'][2];
check($spinner['name'] === 'UpdateInterval' && ($spinner['minimum'] ?? null) === 0, 'Formular: UpdateInterval hat minimum 0 wie die Prüfung im Modul');
check(in_array(208, array_column($form['status'], 'code'), true), 'Formular: Status 208 hat einen Text');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

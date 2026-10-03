<?php

declare(strict_types=1);

/**
 * Instanzstatus und Log-Klartexte (MCP-Tauglichkeit, Regeln 3, 4 und 16).
 *
 * Über MCP sieht eine KI nur die Statuszahl, nicht den Text aus der form.json — und ein
 * stummes 104 findet kein Fehlerfilter. Deshalb hat jede Fehlerursache einen eigenen Code
 * mit Text, jeder Wechsel in einen Fehler steht einmal als Warnung mit Wert und nächstem
 * Schritt im Log, die Behebung einmal als Meldung. Ein gleichbleibender Zustand (die
 * Verbindungsprüfung läuft alle paar Minuten) erzeugt keine weiteren Einträge.
 *
 * Anlass: Lesetest am nuc (03.10.2026) — die Instanz für den abgebauten Schaltkreis „bai"
 * stand auf 203, fragte alle 3 Minuten erfolglos bei ebusd an, und das Log enthielt den
 * ganzen Tag keinen einzigen Eintrag dazu.
 *
 * Aufruf: php tests/check-status-log.php
 */

require_once __DIR__ . '/harness.php';

set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false;
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_WARNING | E_NOTICE)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }
    return false;
});

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

const HOST = '10.1.254.12';
const PORT = '8081';
const URL  = 'http://10.1.254.12:8081/data/hmu';

function antwort(bool $signal = true, bool $mitSchaltkreis = true): array
{
    $r = ['global' => ['signal' => $signal ? 1 : 0]];
    if ($mitSchaltkreis) {
        $r['hmu'] = ['messages' => []];
    }
    return $r;
}

/** Instanz mit aktivem Parent und erreichbarem ebusd; konfiguriert, aber noch nicht übernommen */
function instanz(array $properties = []): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [URL => antwort()];
    foreach ($properties + ['Host' => HOST, 'Port' => PORT, 'CircuitName' => 'hmu'] as $name => $value) {
        $h->SetProperty($name, $value);
    }
    return $h;
}

function status(ebusdMQTTHarness $h): int
{
    return IPS_GetInstance($h->id())['InstanceStatus'];
}

/** @return list<array{0: string, 1: int}> LogMessage-Aufrufe seit dem letzten resetRecorded() */
function logs(ebusdMQTTHarness $h): array
{
    $logs = [];
    foreach ($h->recorded as $r) {
        if ($r[0] === 'LogMessage') {
            $logs[] = [$r[1], $r[2]];
        }
    }
    return $logs;
}

/** Genau eine Warnung, die alle Stichwörter enthält */
function pruefeWarnung(ebusdMQTTHarness $h, array $stichwoerter, string $fall): void
{
    $logs = logs($h);
    check(count($logs) === 1 && $logs[0][1] === KL_WARNING, "$fall: genau eine Warnung im Log (" . json_encode($logs, JSON_UNESCAPED_UNICODE) . ')');
    foreach ($stichwoerter as $wort) {
        check(isset($logs[0]) && str_contains($logs[0][0], $wort), "$fall: Warnung nennt „{$wort}\"");
    }
}

function uebernehmen(ebusdMQTTHarness $h): void
{
    $h->resetRecorded();
    $h->ApplyChanges();
}

function pruefen(ebusdMQTTHarness $h): void
{
    $h->resetRecorded();
    $h->RequestAction('timerCheckConnection', '');
}

// --- Fehlerursachen: Code und Log-Text --------------------------------------

echo "Host ungültig:\n";
$h = instanz(['Host' => 'kein host!']);
uebernehmen($h);
check(status($h) === 204, 'Status 204');
pruefeWarnung($h, ['kein host!'], 'Host');

echo "Port ungültig:\n";
$h = instanz(['Port' => '99999']);
uebernehmen($h);
check(status($h) === 202, 'Status 202');
pruefeWarnung($h, ['99999'], 'Port');

echo "Kein Schaltkreis gewählt:\n";
$h = instanz(); // eine frische Instanz steht schon auf „kein Schaltkreis" - Wechsel aus dem Betrieb prüfen
uebernehmen($h);
$h->SetProperty('CircuitName', '');
uebernehmen($h);
check(status($h) === 207, 'Status 207 (nicht 203 „ungültig")');
pruefeWarnung($h, ['Read Circuits'], 'Kein Schaltkreis');

echo "ebusd nicht erreichbar:\n";
$h            = instanz();
$h->responses = [];
uebernehmen($h);
check(status($h) === 205, 'Status 205 (nicht stumm 104)');
pruefeWarnung($h, [URL], 'Nicht erreichbar');

echo "Kein eBUS-Signal:\n";
$h            = instanz();
$h->responses = [URL => antwort(false)];
uebernehmen($h);
check(status($h) === 206, 'Status 206 (nicht stumm 104)');
pruefeWarnung($h, ['signal'], 'Kein Signal');

echo "Schaltkreis bei ebusd nicht vorhanden:\n";
$h            = instanz();
$h->responses = [URL => antwort(true, false)];
uebernehmen($h);
check(status($h) === 203, 'Status 203');
pruefeWarnung($h, ['hmu', HOST . ':' . PORT], 'Schaltkreis fehlt');

echo "MQTT-Parent inaktiv:\n";
$h = instanz();
uebernehmen($h);
check(status($h) === IS_ACTIVE, 'Ausgangslage aktiv');
$h->parentActive = false;
pruefen($h);
check(status($h) === IS_INACTIVE, 'Status 104');
pruefeWarnung($h, ['MQTT'], 'Parent inaktiv');

// --- Kein Log-Rauschen, Behebung wird gemeldet -------------------------------

echo "Gleichbleibender Fehler:\n";
$h            = instanz();
$h->responses = [];
uebernehmen($h);
pruefen($h);
check(status($h) === 205, 'Status bleibt 205');
check(logs($h) === [], 'zweite Prüfung schreibt nichts ins Log');

echo "Behebung:\n";
$h->responses = [URL => antwort()];
pruefen($h);
check(status($h) === IS_ACTIVE, 'Status wieder 102');
$logs = logs($h);
check(count($logs) === 1 && $logs[0][1] === KL_MESSAGE, 'genau eine Meldung (KL_MESSAGE) zur Behebung');
pruefen($h);
check(logs($h) === [], 'weiterer Lauf im Gutzustand schreibt nichts');

// --- form.json: jeder Code hat einen Text ------------------------------------

echo "Statusliste im Formular:\n";
$form   = json_decode(file_get_contents(dirname(__DIR__) . '/ebusdMQTTDevice/form.json'), true, 512, JSON_THROW_ON_ERROR);
$codes  = array_column($form['status'], 'caption', 'code');
foreach ([202, 203, 204, 205, 206, 207] as $code) {
    check(isset($codes[$code]) && $codes[$code] !== '', "Code $code hat einen Text");
}

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

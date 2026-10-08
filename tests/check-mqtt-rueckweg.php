<?php

declare(strict_types=1);

/**
 * MQTT-Rückweg: ebusd antwortet per HTTP, aber per MQTT kommt nichts an.
 *
 * Anlass: PN froema (Forum t/144582, 08.10.2026). ebusd lief, „Lese aktuelle Werte“ (HTTP)
 * zeigte die Werte, die Instanz stand auf 102, aber die Variablen wurden nie aktualisiert:
 * Das Intervall fragt per MQTT an, und ebusd erreichte den MQTT Server nicht. Das Modul
 * meldete davon nichts.
 *
 * Erwartet:
 *  - Bleibt nach einer Anfragerunde des Intervalls bis zur nächsten Runde jede Antwort per MQTT
 *    aus, geht die Instanz auf 209 und schreibt genau eine Warnung mit nächstem Schritt.
 *  - Das eigene Echo der Anfrage (…/get) zählt nicht als Antwort, ein Wert oder eine globale
 *    Meldung von ebusd schon.
 *  - In 209 fragt das Intervall weiter an (sonst gäbe es nie wieder eine Antwort), die
 *    Verbindungsprüfung kippt den Status nicht zurück auf 102, und es gibt kein Log-Rauschen.
 *  - Die erste Antwort per MQTT stellt 102 her und meldet das einmal.
 *  - Der Selbsttest nennt den fehlenden Rückweg.
 *
 * Aufruf: php tests/check-mqtt-rueckweg.php
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

const BASE       = 'http://10.1.254.12:8081/data';
const STATUS_URL = BASE . '/700';
const CONFIG_URL = BASE . '/700/?def&verbose&exact&write';

function instanz(): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [
        STATUS_URL => ['global' => ['signal' => 1], '700' => []],
        CONFIG_URL => json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR),
    ];
    $h->konfigurieren(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 2]);
    $h->UpdateConfiguration();
    $h->SetMessageActive('Hc1FlowTemp', true, 0);
    $h->resetRecorded();
    return $h;
}

function status(ebusdMQTTHarness $h): int
{
    return IPS_GetInstance($h->id())['InstanceStatus'];
}

function logs(ebusdMQTTHarness $h): array
{
    return array_values(array_map(
        static fn(array $r): array => [$r[1], $r[2]],
        array_filter($h->recorded, static fn(array $r): bool => $r[0] === 'LogMessage')
    ));
}

/** Eine Anfragerunde des Intervalls; liefert die publizierten Topics */
function runde(ebusdMQTTHarness $h): array
{
    $h->resetRecorded();
    $h->RequestAction('timerRefreshAllMessages', '');
    return array_column($h->publiziert(), 'topic');
}

function pruefen(ebusdMQTTHarness $h): void
{
    $h->resetRecorded();
    $h->RequestAction('timerCheckConnection', '');
}

function empfang(ebusdMQTTHarness $h, string $topic, string $payload = '{"0":{"value":45.5}}'): void
{
    $h->ReceiveData(json_encode([
        'DataID'  => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}',
        'Topic'   => $topic,
        'Payload' => bin2hex($payload),
    ], JSON_THROW_ON_ERROR));
}

echo "Antwort kommt per MQTT:\n";
$h = instanz();
check(runde($h) === ['ebusd/700/Hc1FlowTemp/get'], 'erste Runde fragt die aktive Meldung an');
empfang($h, 'ebusd/700/Hc1FlowTemp');
runde($h);
check(status($h) === IS_ACTIVE, 'mit Antwort zwischen den Runden bleibt 102');
check(logs($h) === [], 'kein Log-Eintrag');

echo "Keine Antwort per MQTT:\n";
$h = instanz();
runde($h);
check(status($h) === IS_ACTIVE, 'nach der ersten Runde noch 102 (Antwort kann noch kommen)');
$topics = runde($h);
check(status($h) === 209, 'zweite Runde ohne Antwort dazwischen: Status 209 (ist ' . status($h) . ')');
$l = logs($h);
check(count($l) === 1 && $l[0][1] === KL_WARNING, 'genau eine Warnung (' . json_encode($l, JSON_UNESCAPED_UNICODE) . ')');
check(isset($l[0]) && str_contains($l[0][0], 'MQTT') && str_contains($l[0][0], '--mqtthost'), 'Warnung nennt MQTT und die ebusd-Optionen');
check($topics === ['ebusd/700/Hc1FlowTemp/get'], 'die Runde fragt trotzdem an');

echo "Störung hält an:\n";
check(runde($h) === ['ebusd/700/Hc1FlowTemp/get'], 'in 209 fragt das Intervall weiter an');
check(status($h) === 209 && logs($h) === [], 'Status bleibt 209, kein weiterer Log-Eintrag');
pruefen($h);
check(status($h) === 209, 'Verbindungsprüfung (HTTP in Ordnung) kippt nicht zurück auf 102');
check(logs($h) === [], 'Verbindungsprüfung schreibt nichts');

echo "Selbsttest:\n";
$text = $h->RunSelfTest();
check(str_contains($text, '✘') && str_contains($text, 'MQTT') && str_contains($text, '--mqtthost'), 'Selbsttest nennt den fehlenden MQTT-Rückweg');

echo "Behebung:\n";
$h->resetRecorded();
empfang($h, 'ebusd/700/Hc1FlowTemp');
check(status($h) === IS_ACTIVE, 'erste Antwort per MQTT: wieder 102');
$l = logs($h);
check(count($l) === 1 && $l[0][1] === KL_MESSAGE, 'genau eine Meldung zur Behebung (' . json_encode($l, JSON_UNESCAPED_UNICODE) . ')');
runde($h);
check(status($h) === IS_ACTIVE && logs($h) === [], 'nächste Runde: 102, kein Log');
check(!str_contains($h->RunSelfTest(), '--mqtthost'), 'Selbsttest meldet den Rückweg nicht mehr');

echo "Eigenes Echo zählt nicht:\n";
$h = instanz();
runde($h);
empfang($h, 'ebusd/700/Hc1FlowTemp/get', '');
runde($h);
check(status($h) === 209, 'nur das Echo …/get dazwischen: 209');

echo "Globale Meldung von ebusd zählt:\n";
$h = instanz();
runde($h);
empfang($h, 'ebusd/global/signal', 'true');
runde($h);
check(status($h) === IS_ACTIVE, 'ebusd/global/… dazwischen: 102');

echo "Statusliste im Formular:\n";
$form  = json_decode(file_get_contents(dirname(__DIR__) . '/ebusdMQTTDevice/form.json'), true, 512, JSON_THROW_ON_ERROR);
$codes = array_column($form['status'], 'caption', 'code');
check(isset($codes[209]) && $codes[209] !== '', 'Code 209 hat einen Text');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

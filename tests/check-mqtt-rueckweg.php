<?php

declare(strict_types=1);

/**
 * MQTT-Rückweg: ebusd antwortet per HTTP, aber per MQTT kommt nichts an.
 *
 * Anlass: Verdacht aus der PN froema (Forum t/144582, 08.10.2026). „Lese aktuelle Werte“ (HTTP)
 * zeigte die Werte, die Variablen schienen still zu stehen, die Instanz stand auf 102. Ein
 * fehlender MQTT-Rückweg war es dort am Ende nicht, die Werte kamen an und änderten sich nur
 * nicht (siehe check-setvalue-update.php). Erreicht ebusd den MQTT Server aber wirklich nicht,
 * fragt das Intervall ins Leere, und das Modul meldete davon nichts.
 *
 * ebusd sendet, solange es mit dem Broker verbunden ist, alle ~15 s ebusd/global/uptime
 * (src/ebusd/mqtthandler.cpp, Zweig „now > lastTaskRun+15“). Ein gescheitertes Lesen sendet
 * dagegen nichts. Erkannt wird deshalb Stille: nichts von ebusd länger als 120 s. Kam von diesem
 * ebusd noch nie uptime (Topic ohne %name), kommen nur die Antworten des Intervalls; dann gilt
 * max(120 s, 2 × Aktualisierungsintervall), sonst spränge der Status zwischen 102 und 209.
 *
 * Erwartet:
 *  - Stille über der Grenze: Status 209 und genau eine Warnung mit nächstem Schritt, auch bei
 *    Intervall 0. Unter der Grenze bleibt 102.
 *  - Das eigene Echo der Anfrage (…/get) zählt nicht, uptime und Werte schon.
 *  - In 209 fragt das Intervall weiter an, die Verbindungsprüfung kippt nicht auf 102, kein
 *    Log-Rauschen. Die erste Meldung von ebusd stellt 102 her und meldet das einmal.
 *  - In 102 läuft die Verbindungsprüfung alle 60 s weiter (vorher stand sie in 102 still).
 *  - 205 (ebusd antwortet nicht) und 206 (kein eBUS-Signal, z. B. Heizung aus) gehen vor 209.
 *  - Die Beobachtung beginnt neu, wenn der Parent wieder aktiv wird oder das Ziel wechselt.
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

const HOST       = '10.1.254.12';
const BASE       = 'http://' . HOST . ':8081/data';
const STATUS_URL = BASE . '/700';
const CONFIG_URL = BASE . '/700/?def&verbose&exact&write';
const T0         = 1_791_500_000;

function instanz(int $intervall = 2): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->uhr          = T0;
    $h->parentActive = true;
    $h->responses    = [
        STATUS_URL => ['global' => ['signal' => 1], '700' => []],
        CONFIG_URL => json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR),
    ];
    $h->konfigurieren(['Host' => HOST, 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => $intervall]);
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

/** Zeit vergeht, dann läuft die Verbindungsprüfung (Timer) */
function nach(ebusdMQTTHarness $h, int $sekunden): void
{
    $h->uhr += $sekunden;
    $h->resetRecorded();
    $h->RequestAction('timerCheckConnection', '');
}

/** Eine Anfragerunde des Intervalls; liefert die publizierten Topics */
function runde(ebusdMQTTHarness $h): array
{
    $h->resetRecorded();
    $h->RequestAction('timerRefreshAllMessages', '');
    return array_column($h->publiziert(), 'topic');
}

function empfang(ebusdMQTTHarness $h, string $topic, string $payload): void
{
    $h->ReceiveData(json_encode([
        'DataID'  => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}',
        'Topic'   => $topic,
        'Payload' => bin2hex($payload),
    ], JSON_THROW_ON_ERROR));
}

function uptime(ebusdMQTTHarness $h): void
{
    empfang($h, 'ebusd/global/uptime', (string)($h->uhr - T0 + 3600));
}

function timerMs(ebusdMQTTHarness $h, string $ident): int
{
    return $h->privat('GetTimerInterval', $ident);
}

echo "uptime kommt regelmäßig:\n";
$h = instanz();
for ($t = 0; $t < 600; $t += 15) {
    $h->uhr = T0 + $t;
    uptime($h);
}
nach($h, 15);
check(status($h) === IS_ACTIVE, 'nach 10 min mit uptime alle 15 s: 102');
check(logs($h) === [], 'kein Log-Eintrag');

echo "Wächter in 102:\n";
$h = instanz();
check(timerMs($h, 'checkConnection') === 60_000, 'Verbindungsprüfung läuft in 102 alle 60 s (ist ' . timerMs($h, 'checkConnection') . ' ms)');
nach($h, 60);
$gesetzt = array_values(array_filter($h->recorded, static fn(array $r): bool => $r[0] === 'SetTimerInterval'));
check($gesetzt === [], 'Prüfung ohne Zustandswechsel setzt keinen Timer neu, der Anfrage-Countdown läuft weiter (' . json_encode($gesetzt) . ')');
$h->responses[STATUS_URL] = null;
nach($h, 60);
check(status($h) === 205, 'ebusd fällt in 102 aus: die nächste Prüfung meldet 205 (ist ' . status($h) . ')');

echo "uptime bleibt aus:\n";
$h = instanz();
uptime($h);
nach($h, 119);
check(status($h) === IS_ACTIVE, '119 s still: noch 102');
nach($h, 2);
check(status($h) === 209, '121 s still: Status 209 (ist ' . status($h) . ')');
$l = logs($h);
check(count($l) === 1 && $l[0][1] === KL_WARNING, 'genau eine Warnung (' . json_encode($l, JSON_UNESCAPED_UNICODE) . ')');
check(isset($l[0]) && str_contains($l[0][0], 'MQTT') && str_contains($l[0][0], '--mqtthost'), 'Warnung nennt MQTT und die ebusd-Optionen');

echo "Störung hält an:\n";
check(runde($h) === ['ebusd/700/Hc1FlowTemp/get'], 'in 209 fragt das Intervall weiter an');
check(timerMs($h, 'requestAllValues') === 120_000, 'Anfrage-Timer läuft in 209 weiter');
nach($h, 180);
check(status($h) === 209, 'Verbindungsprüfung (HTTP in Ordnung) kippt nicht zurück auf 102');
check(logs($h) === [], 'kein weiterer Log-Eintrag');

echo "Selbsttest:\n";
$text = $h->RunSelfTest();
check(str_contains($text, '✘') && str_contains($text, 'MQTT') && str_contains($text, '--mqtthost'), 'Selbsttest nennt den fehlenden MQTT-Rückweg');

echo "Behebung:\n";
$h->resetRecorded();
uptime($h);
check(status($h) === IS_ACTIVE, 'erste Meldung per MQTT: wieder 102');
$l = logs($h);
check(count($l) === 1 && $l[0][1] === KL_MESSAGE, 'genau eine Meldung zur Behebung (' . json_encode($l, JSON_UNESCAPED_UNICODE) . ')');
nach($h, 60);
check(status($h) === IS_ACTIVE && logs($h) === [], 'nächste Prüfung: 102, kein Log');
check(!str_contains($h->RunSelfTest(), '--mqtthost'), 'Selbsttest meldet den Rückweg nicht mehr');

echo "Eigenes Echo zählt nicht, ein Wert schon:\n";
$h = instanz();
uptime($h);
$h->uhr += 100;
empfang($h, 'ebusd/700/Hc1FlowTemp/get', '');
nach($h, 30);
check(status($h) === 209, 'nur das Echo …/get: nach 130 s 209');
$h = instanz();
uptime($h);
$h->uhr += 100;
empfang($h, 'ebusd/700/Hc1FlowTemp', '{"tempv":{"value":48.5}}');
nach($h, 30);
check(status($h) === IS_ACTIVE, 'ein Wert nach 100 s: 30 s später 102');

echo "Intervall 0:\n";
$h = instanz(0);
check(timerMs($h, 'requestAllValues') === 0, 'kein Anfrage-Timer');
nach($h, 121);
check(status($h) === 209, 'Erkennung auch ohne Intervall (ist ' . status($h) . ')');

echo "Topic ohne uptime:\n";
$h = instanz();
nach($h, 200);
check(status($h) === IS_ACTIVE, 'nie uptime, Intervall 2 min: nach 200 s noch 102 (Grenze 4 min)');
nach($h, 41);
check(status($h) === 209, 'über 4 min still: 209');
$h = instanz();
for ($i = 1; $i <= 5; $i++) {
    $h->uhr += 120;
    runde($h);
    empfang($h, 'ebusd/700/Hc1FlowTemp', '{"tempv":{"value":48.5}}');
    nach($h, 60);
}
check(status($h) === IS_ACTIVE && logs($h) === [], 'nie uptime, aber Antworten in jeder Runde: 102 ohne Log');
$h = instanz(10);
nach($h, 900);
check(status($h) === IS_ACTIVE, '15 min still bei Intervall 10 min: 102 (Grenze 20 min)');
nach($h, 301);
check(status($h) === 209, 'über 20 min still: 209');

echo "Vorrang von 205 und 206:\n";
$h = instanz();
uptime($h);
$h->responses[STATUS_URL] = null;
nach($h, 200);
check(status($h) === 205, 'ebusd antwortet nicht per HTTP: 205 statt 209 (ist ' . status($h) . ')');
$h = instanz();
uptime($h);
$h->responses[STATUS_URL] = ['global' => ['signal' => 0], '700' => []];
nach($h, 200);
check(status($h) === 206, 'Heizung aus, kein eBUS-Signal: 206 statt 209 (ist ' . status($h) . ')');

echo "Parent war inaktiv:\n";
$h = instanz();
uptime($h);
$h->parentActive = false;
nach($h, 3600);
check(status($h) === IS_INACTIVE, 'Parent inaktiv: 104');
$h->parentActive = true;
nach($h, 5);
check(status($h) === IS_ACTIVE, 'Parent wieder aktiv: 102, die Beobachtung beginnt neu');
nach($h, 121);
check(status($h) === 209, '121 s nach dem Neubeginn ohne Meldung: 209');

echo "Ziel wechselt:\n";
$h = instanz();
uptime($h);
nach($h, 121);
check(status($h) === 209, 'altes Ziel: 209');
$h->responses['http://10.1.254.13:8081/data/700'] = ['global' => ['signal' => 1], '700' => []];
$h->resetRecorded();
$h->konfigurieren(['Host' => '10.1.254.13']);
check(status($h) === IS_ACTIVE, 'neuer Host: 102, die Beobachtung beginnt neu (ist ' . status($h) . ')');

echo "Statusliste im Formular:\n";
$form  = json_decode(file_get_contents(dirname(__DIR__) . '/ebusdMQTTDevice/form.json'), true, 512, JSON_THROW_ON_ERROR);
$codes = array_column($form['status'], 'caption', 'code');
check(isset($codes[209]) && $codes[209] !== '', 'Code 209 hat einen Text');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

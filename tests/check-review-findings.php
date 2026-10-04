<?php

declare(strict_types=1);

/**
 * Befunde aus dem Code-Review vom 04.10.2026 (Diff Beta 1.3 #28 bis 1.4 build 38):
 *
 *  1. Leerer Schaltkreis: Nur ApplyChanges kannte Status 207. Lief danach die
 *     Verbindungsprüfung (Timer, RequestAction ohne Parent), fragte sie /data/ ab und setzte
 *     203 „Schaltkreis "" gibt es nicht" bzw. 104 — samt Warnung, und beim nächsten
 *     ApplyChanges wieder 207: der Status pendelte.
 *  2. EBM_FindMessages endete mit einem TypeError, sobald die Konfiguration einen eBUS-Typ
 *     enthält, den die Typtabelle nicht kennt (getIPSVariableType liefert dann null).
 *  4. Schreibprüfung: Kommazahlen für ganzzahlige Typen ohne Divisor gingen unverändert
 *     auf den Bus ('21.7').
 *  5. UCH hatte als Höchstwert 256; gültig ist 0…254 (0xFF ist der Ersatzwert,
 *     ebusd-Wiki 4.3 „Builtin data types").
 *  6. Topics mit Unterpfad (ebusd/<circuit>/<msg>/set von anderen Clients oder als Echo)
 *     galten als unbekannte Meldung „<msg>/set" und lösten eine Warnung aus, die zum
 *     Neueinlesen der Konfiguration rät — was nichts hilft.
 *
 * Fixtures: tests/fixtures/config_700.json und data_all.json (echte ebusd-Antworten).
 * Zu 2: Ein Typ außerhalb der Tabelle kommt in keinem Mitschnitt vor (alle Schaltkreise des
 * ebusd am nuc geprüft). Deshalb trägt die echte Meldung BankHolidayEndPeriod hier den Typ
 * BDZ (BCD-Datum mit Wochentag, laut ebusd-Wiki ein eingebauter Typ) statt HDA:3 — sonst
 * ist sie unverändert.
 *
 * Aufruf: php tests/check-review-findings.php
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

function instanz(?array $konfig = null): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [STATUS_URL => ['global' => ['signal' => 1], '700' => []], CONFIG_URL => $konfig ?? konfig700()];
    $h->konfigurieren(['Host' => HOST, 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 10]);
    $h->UpdateConfiguration();
    $h->resetRecorded();
    return $h;
}

function status(ebusdMQTTHarness $h): int
{
    return IPS_GetInstance($h->id())['InstanceStatus'];
}

function logs(ebusdMQTTHarness $h): array
{
    return array_values(array_filter($h->recorded, static fn(array $r): bool => $r[0] === 'LogMessage'));
}

/**
 * Führt $aufruf aus und sammelt, was beim Aufrufer ankommt (Fehlermeldungen, Ausnahmen).
 *
 * @return array{ergebnis: mixed, fehler: list<string>}
 */
function mitFehlern(callable $aufruf): array
{
    $fehler = [];
    set_error_handler(static function (int $nr, string $text) use (&$fehler): bool {
        if (!(error_reporting() & $nr)) {
            return false;
        }
        $fehler[] = $text;
        return true;
    });
    $ergebnis = null;
    try {
        $ergebnis = $aufruf();
    } catch (Throwable $e) {
        $fehler[] = get_class($e) . ': ' . $e->getMessage();
    } finally {
        restore_error_handler();
    }
    return ['ergebnis' => $ergebnis, 'fehler' => $fehler];
}

function paket(string $topic, string $payload): string
{
    return json_encode([
        'DataID'  => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}',
        'Topic'   => $topic,
        'Payload' => bin2hex($payload),
    ], JSON_THROW_ON_ERROR);
}

// --- 1. Leerer Schaltkreis bleibt bei 207 -----------------------------------------

echo "Leerer Schaltkreis:\n";
$h               = neueInstanz();
$h->parentActive = true;
$h->konfigurieren(['Host' => HOST, 'Port' => '8081', 'CircuitName' => '700']); // ebusd antwortet nicht: 205
check(status($h) === 205, 'Ausgangslage: ebusd nicht erreichbar (205)');
$h->konfigurieren(['CircuitName' => '']);
check(status($h) === 207, 'Schaltkreis geleert: Status 207');

$h->resetRecorded();
$h->requestedUrls = [];
$h->responses     = [BASE . '/' => json_decode(file_get_contents(__DIR__ . '/fixtures/data_all.json'), true, 512, JSON_THROW_ON_ERROR)];
$h->RequestAction('timerCheckConnection', '');
check(status($h) === 207, 'Verbindungsprüfung mit aktivem Parent: bleibt 207 (Status ' . status($h) . ')');
check(logs($h) === [], 'keine weitere Warnung (' . json_encode(array_column(logs($h), 1), JSON_UNESCAPED_UNICODE) . ')');
check($h->requestedUrls === [], 'fragt ebusd ohne Schaltkreis nicht ab');

$h->parentActive = false;
$h->RequestAction('timerCheckConnection', '');
check(status($h) === 207, 'Verbindungsprüfung ohne Parent: bleibt 207 (Status ' . status($h) . ')');
check(logs($h) === [], 'auch dann keine Warnung');

$test = $h->RunSelfTest();
check(substr_count($test, 'circuit') >= 1 && str_contains($test, 'Read Circuits'), 'Selbsttest nennt den fehlenden Schaltkreis');

// --- 2. FindMessages mit einem eBUS-Typ außerhalb der Tabelle ----------------------

echo "Unbekannter eBUS-Typ:\n";
$konfig = konfig700();
foreach ($konfig['700']['messages']['BankHolidayEndPeriod']['fielddefs'] as $i => $fd) {
    if ($fd['type'] === 'HDA:3') {
        $konfig['700']['messages']['BankHolidayEndPeriod']['fielddefs'][$i]['type'] = 'BDZ';
    }
}
$h = instanz($konfig);
$r = mitFehlern(static fn() => $h->FindMessages('BankHolidayEndPeriod'));
check($r['fehler'] === [], 'kein Fehler beim Aufrufer (' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
$treffer = is_string($r['ergebnis']) ? json_decode($r['ergebnis'], true) : null;
$felder  = array_merge(...array_column($treffer ?? [], 'fields'));
check($felder !== [] && in_array('unknown', array_column($felder, 'type'), true), 'Feld mit Typ BDZ erscheint als type "unknown"');
$r = mitFehlern(static fn() => $h->FindMessages(''));
check($r['fehler'] === [] && is_array(json_decode((string)$r['ergebnis'], true)), 'Suche über alle Meldungen liefert JSON');

// --- 4./5. Schreibprüfung für UCH ohne Divisor (Hc1Status) -----------------------

echo "Schreibprüfung UCH:\n";
$h      = instanz();
$felder = json_decode($h->FindMessages('Hc1Status'), true, 512, JSON_THROW_ON_ERROR)[0]['fields'] ?? [];
$ident  = $felder[0]['ident'] ?? '';
check($ident !== '', "Ident der Meldung Hc1Status: $ident");
check(($felder[0]['max'] ?? null) === 254, 'FindMessages nennt Höchstwert 254 (' . json_encode($felder[0] ?? null) . ')');

$senden = static function (mixed $wert) use ($h, $ident): array {
    $h->resetRecorded();
    $r = mitFehlern(static fn() => $h->RequestAction($ident, $wert));
    return [$r['fehler'], $h->publiziert()];
};
$topic = 'ebusd/700/Hc1Status/set';

[$fehler, $pub] = $senden(21);
check($fehler === [] && $pub === [['topic' => $topic, 'payload' => '21']], 'ganze Zahl 21 wird publiziert');
[$fehler, $pub] = $senden(21.0);
check($fehler === [] && $pub === [['topic' => $topic, 'payload' => '21']], '21.0 gilt als ganze Zahl');
[$fehler, $pub] = $senden(21.7);
check(count($fehler) === 1 && $pub === [] && str_contains($fehler[0] ?? '', 'a whole number'), '21.7 wird abgelehnt, nichts publiziert (' . json_encode([$fehler, $pub], JSON_UNESCAPED_UNICODE) . ')');
[$fehler, $pub] = $senden('21.7');
check(count($fehler) === 1 && $pub === [] && str_contains($fehler[0] ?? '', 'a whole number'), "'21.7' als Text ebenso");
[$fehler, $pub] = $senden(254);
check($fehler === [] && count($pub) === 1, '254 wird publiziert');
foreach ([255, 256] as $wert) {
    [$fehler, $pub] = $senden($wert);
    check(count($fehler) === 1 && $pub === [] && str_contains($fehler[0] ?? '', '254'), "$wert wird abgelehnt, Meldung nennt 254");
}

// --- 6. Topics mit Unterpfad -------------------------------------------------------

echo "Topics mit Unterpfad:\n";
$h = instanz();
$h->ReceiveData(paket('ebusd/700/Hc1Status/set', '3'));
$h->ReceiveData(paket('ebusd/700/AdaptHeatCurve/get', '{"0":{"value":1}}'));
check(logs($h) === [], 'kein Log für …/set und …/get (' . json_encode(array_column(logs($h), 1), JSON_UNESCAPED_UNICODE) . ')');
$h->resetRecorded();
$h->ReceiveData(paket('ebusd/700/GibtEsNicht', '{"0":{"value":1}}'));
check(count(logs($h)) === 1 && str_contains(logs($h)[0][1], 'GibtEsNicht'), 'Gegenprobe: echte unbekannte Meldung warnt weiterhin');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * eBUS-Datentypen: Typtabelle gegen ebusd 26.1, unbekannter Typ ohne Abbruch.
 *
 * Anlass: Durchsicht der ebusd-Changelogs 24.1 bis 26.1 (27.10.2026). ebusd 25.1 bringt neue
 * Zahlentypen ohne Ersatzwert (U1L, S1L, U2L, U2B, … bis S4B). Kennt die Typtabelle des Moduls
 * einen Typ nicht, lieferte getIPSVariableType() null bei Rückgabetyp int: TypeError. Das brach
 * die Registrierung der Variablen ab und jeden Empfang der betroffenen Meldung per MQTT. Schon
 * unter ebusd 23.3 fehlten BCD:1, HCD:4, BDA:4, BDZ und DTM.
 *
 * Erwartet:
 *  1. Jeder eingebaute Typ von ebusd 26.1 außer IGN steht in der Typtabelle. Die Liste stammt aus
 *     src/lib/ebus/datatype.cpp (Tag 26.1, DataTypeList::DataTypeList); BI0 … BI7 stehen dort als
 *     Schleife.
 *  2. Wertebereiche der neuen Typen laut Quelltext (ohne Ersatzwert reicht der Bereich bis zur
 *     Grenze): U1L 0…255, S1L −128…127, U2L/U2B 0…65535, S2L/S2B −32768…32767. Geprüft über die
 *     Schreibprüfung (RequestAction) und EBM_FindMessages.
 *  3. Unbekannter Typ bei der Registrierung: kein Fehler beim Aufrufer, die übrigen Felder der
 *     Meldung werden angelegt, genau eine Warnung nennt Meldung, Feld und Typ.
 *  4. Unbekannter Typ beim Empfang: kein Fehler, die übrigen Felder bekommen ihren Wert (die
 *     Zuordnung im Payload verschiebt sich nicht), kein weiterer Log-Eintrag.
 *
 * Fixtures: tests/fixtures/config_hmu.json und config_700.json (echte ebusd-Antworten). Die
 * neuen Typen kommen in keinem Mitschnitt vor, weder an der eigenen Anlage noch in den neuen
 * Konfigurationsdateien für hmu und 700 (ebus.github.io, 26.10.2026). Deshalb trägt je ein
 * Feld einer echten Meldung einen anderen Typ, sonst sind die Meldungen unverändert:
 * hmu/State.CurrentCompressor (D1B) wird zum unbekannten Typ „XYZ“, 700/Hc1Status und
 * Hc1Status-w (UCH) zum jeweils geprüften neuen Typ. Die MQTT-Payload für State entsteht aus deren echten „fields“.
 *
 * Aufruf: php tests/check-ebus-types.php
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

const HOST = '10.1.254.12';
const BASE = 'http://10.1.254.12:8081/data';

/** eingebaute Typen von ebusd 26.1 (datatype.cpp), ohne IGN */
const EBUSD_26_1_TYPES = [
    'STR', 'NTS', 'HEX', 'BDA', 'BDA:3', 'BDA:4', 'BDZ', 'HDA', 'HDA:3', 'DAY', 'DTM',
    'BTI', 'HTI', 'VTI', 'BTM', 'HTM', 'VTM', 'MIN', 'TTM', 'TTH', 'TTQ',
    'BDY', 'HDY', 'BCD', 'BCD:1', 'BCD:2', 'BCD:3', 'BCD:4', 'HCD', 'HCD:1', 'HCD:2', 'HCD:3', 'HCD:4',
    'PIN', 'UCH', 'U1L', 'SCH', 'S1L', 'D1B', 'D1C', 'D2B', 'D2C', 'FLT', 'FLR', 'EXP', 'EXR',
    'UIN', 'UIR', 'U2L', 'U2B', 'SIN', 'SIR', 'S2L', 'S2B',
    'U3N', 'U3R', 'U3L', 'U3B', 'S3N', 'S3R', 'S3L', 'S3B',
    'ULG', 'ULR', 'U4L', 'U4B', 'SLG', 'SLR', 'S4L', 'S4B',
    'BI0', 'BI1', 'BI2', 'BI3', 'BI4', 'BI5', 'BI6', 'BI7',
];

function konfig(string $circuit): array
{
    return json_decode(file_get_contents(__DIR__ . "/fixtures/config_$circuit.json"), true, 512, JSON_THROW_ON_ERROR);
}

/** Feldtyp einer Meldung in der Konfiguration ersetzen (IGN-Felder heißen oft ebenfalls leer und bleiben) */
function mitTyp(array $konfig, string $circuit, string $message, string $field, string $type): array
{
    foreach ($konfig[$circuit]['messages'][$message]['fielddefs'] as $i => $fd) {
        if ($fd['name'] === $field && $fd['type'] !== 'IGN') {
            $konfig[$circuit]['messages'][$message]['fielddefs'][$i]['type'] = $type;
        }
    }
    return $konfig;
}

function instanz(string $circuit, array $konfig): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [
        BASE . "/$circuit"                           => ['global' => ['signal' => 1], $circuit => []],
        BASE . "/$circuit/?def&verbose&exact&write" => $konfig,
    ];
    $h->konfigurieren(['Host' => HOST, 'Port' => '8081', 'CircuitName' => $circuit, 'UpdateInterval' => 10]);
    $h->UpdateConfiguration();
    $h->resetRecorded();
    return $h;
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

// --- 1. Vollständigkeit der Typtabelle --------------------------------------------

echo "Typtabelle gegen ebusd 26.1:\n";
$h      = instanz('700', konfig('700'));
$tabelle = $h->privat('getEbusDataTypeDefinitions');
$fehlt  = array_values(array_diff(EBUSD_26_1_TYPES, array_keys($tabelle)));
check($fehlt === [], 'alle eingebauten Typen bekannt (fehlen: ' . implode(' ', $fehlt) . ')');
foreach (['BDA:4', 'BDZ', 'DTM'] as $typ) {
    check(($tabelle[$typ]['VariableType'] ?? null) === VARIABLETYPE_STRING, "$typ ist Text");
}
foreach (['BCD:1', 'HCD:4', 'U1L', 'S1L', 'U2L', 'U2B', 'S2L', 'S2B', 'U3L', 'U3B', 'S3L', 'S3B', 'U4L', 'U4B', 'S4L', 'S4B'] as $typ) {
    check(($tabelle[$typ]['VariableType'] ?? null) === VARIABLETYPE_INTEGER, "$typ ist ganzzahlig");
}

// --- 2. Wertebereiche der neuen Typen ---------------------------------------------

$bereiche = [
    'U1L' => [0, 255],
    'S1L' => [-128, 127],
    'U2L' => [0, 65535],
    'U2B' => [0, 65535],
    'S2L' => [-32768, 32767],
    'S2B' => [-32768, 32767],
    'U3L' => [0, 16_777_215],
    'S3B' => [-8_388_608, 8_388_607],
];
foreach ($bereiche as $typ => [$min, $max]) {
    echo "Schreibprüfung $typ:\n";
    $h      = instanz('700', mitTyp(mitTyp(konfig('700'), '700', 'Hc1Status', '', $typ), '700', 'Hc1Status-w', '', $typ));
    $r      = mitFehlern(static fn() => $h->FindMessages('Hc1Status'));
    $felder = json_decode((string)$r['ergebnis'], true)[0]['fields'] ?? [];
    $ident  = $felder[0]['ident'] ?? '';
    check($r['fehler'] === [] && ($felder[0]['type'] ?? '') === 'integer', 'FindMessages: Typ integer (' . json_encode([$r['fehler'], $felder[0] ?? null], JSON_UNESCAPED_UNICODE) . ')');
    if ($max - $min <= 1000) {
        check(($felder[0]['min'] ?? null) === $min && ($felder[0]['max'] ?? null) === $max, "FindMessages nennt $min bis $max");
    }

    foreach ([$min => true, $max => true, $min - 1 => false, $max + 1 => false] as $wert => $erlaubt) {
        $h->resetRecorded();
        $r   = mitFehlern(static fn() => $h->RequestAction($ident, $wert));
        $pub = $h->publiziert();
        if ($erlaubt) {
            check($r['fehler'] === [] && $pub === [['topic' => 'ebusd/700/Hc1Status/set', 'payload' => (string)$wert]], "$wert wird publiziert (" . json_encode([$r['fehler'], $pub], JSON_UNESCAPED_UNICODE) . ')');
        } else {
            check(count($r['fehler']) === 1 && $pub === [], "$wert wird abgelehnt, nichts publiziert");
        }
    }
}

// --- 3. Unbekannter Typ bei der Registrierung -------------------------------------

echo "Unbekannter Typ bei der Registrierung:\n";
$konfig = mitTyp(konfig('hmu'), 'hmu', 'State', 'CurrentCompressor', 'XYZ');
$h      = instanz('hmu', $konfig);
$felder = json_decode($h->FindMessages('State'), true, 512, JSON_THROW_ON_ERROR)[0]['fields'] ?? [];
$idents = array_column($felder, 'type', 'ident');
check(count($idents) === 4 && in_array('unknown', $idents, true), 'FindMessages: vier Felder, eines mit Typ unknown (' . json_encode($idents) . ')');
$unbekannt = (string)array_search('unknown', $idents, true);

$r = mitFehlern(static fn() => $h->SetMessageActive('State', true, 0));
check($r['fehler'] === [], 'kein Fehler beim Aufrufer (' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
foreach ($idents as $ident => $typ) {
    $id = @IPS_GetObjectIDByIdent($ident, $h->id());
    if ($ident === $unbekannt) {
        check($id === false, "keine Variable für $ident");
    } else {
        check(is_int($id) && $id > 0, "Variable $ident angelegt");
    }
}
$warnungen = array_values(array_filter(logs($h), static fn(array $l): bool => $l[2] === KL_WARNING));
$text      = (string)($warnungen[0][1] ?? '');
check(count($warnungen) === 1, 'genau eine Warnung (' . json_encode(array_column(logs($h), 1), JSON_UNESCAPED_UNICODE) . ')');
check(str_contains($text, 'State') && str_contains($text, 'CurrentCompressor') && str_contains($text, 'XYZ'), 'Warnung nennt Meldung, Feld und Typ');

// --- 4. Unbekannter Typ beim Empfang ----------------------------------------------

echo "Unbekannter Typ beim Empfang:\n";
$h->resetRecorded();
$payload = $konfig['hmu']['messages']['State']['fields'];
$r       = mitFehlern(static fn() => $h->ReceiveData(json_encode([
    'DataID'  => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}',
    'Topic'   => 'ebusd/hmu/State',
    'Payload' => bin2hex(json_encode($payload, JSON_THROW_ON_ERROR)),
], JSON_THROW_ON_ERROR)));
check($r['fehler'] === [], 'kein Fehler beim Empfang (' . json_encode($r['fehler'], JSON_UNESCAPED_UNICODE) . ')');
foreach ($felder as $feld) {
    if ($feld['ident'] === $unbekannt) {
        continue;
    }
    $id = @IPS_GetObjectIDByIdent($feld['ident'], $h->id());
    check(is_int($id) && IPS_GetVariable($id)['VariableUpdated'] > 0, "{$feld['ident']} hat einen Wert bekommen");
}
$energyIdent = $felder[1]['ident'] ?? ''; // zweites Feld der Meldung: energy (UIN, Divisor 10)
$energyID    = $energyIdent !== '' ? @IPS_GetObjectIDByIdent($energyIdent, $h->id()) : false;
check(is_int($energyID) && GetValue($energyID) === 2.5, "Feld energy trägt 2.5 aus der Payload, keine Verschiebung ($energyIdent)");
check(logs($h) === [], 'kein Log beim Empfang (' . json_encode(array_column(logs($h), 1), JSON_UNESCAPED_UNICODE) . ')');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * Altlasten und Darstellungsbereiche (MCP-Tauglichkeit, Regel 14).
 *
 * Erhebung am nuc (03.10.2026):
 *  - 26 schreibbare Zahlen hatten einen Schieberegler über den technischen Bereich des
 *    eBUS-Typs: EXP −3·10³⁸ … 3·10³⁸ (Heizkurve, Speichersolltemperatur …), UIN 0 … 65534
 *    (Pumpenstatus, Frostschutz-Verzögerung …). ebusd liefert keine fachlichen Grenzen; 13 davon
 *    waren von Hand mit eigenen Profilen überdeckt. Ein Schieberegler gibt es deshalb nur noch,
 *    wenn der Typbereich höchstens 1000 Schritte umfasst, sonst ein Eingabefeld mit Einheit.
 *  - Variablen ohne Meldung in der ebusd-Konfiguration (F02, F10 … in hmu, zuletzt 2022
 *    aktualisiert) und gleich benannte Variablen (zweimal „Rücklauftemperatur" aus ReturnTemp
 *    und Status01) waren für eine KI nicht als Altlast erkennbar. Der Selbsttest nennt sie
 *    jetzt — als Hinweis, ohne etwas umzubenennen oder zu löschen.
 *
 * Aufruf: php tests/check-legacy.php
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

const STATUS_URL = 'http://10.1.254.12:8081/data/700';
const CONFIG_URL = 'http://10.1.254.12:8081/data/700/?def&verbose&exact&write';

function instanz(): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [
        STATUS_URL => ['global' => ['signal' => 1], '700' => []],
        CONFIG_URL => json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR),
    ];
    $h->konfigurieren(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 5]);
    $h->UpdateConfiguration();
    return $h;
}

function darstellung(ebusdMQTTHarness $h, string $ident): array
{
    return IPS_GetVariable(IPS_GetObjectIDByIdent($ident, $h->id()))['VariablePresentation'];
}

$h = instanz();
foreach (['Hc1HeatCurve', 'HwcTempDesired', 'FrostOverRideTime', 'Hc1PumpStatus', 'Hc1Status', 'AdaptHeatCurve', 'Hc1FlowTemp'] as $meldung) {
    $h->SetMessageActive($meldung, true, 0);
}

echo "Darstellung schreibbarer Zahlen:\n";
$p = darstellung($h, 'HwcTempDesired');
check($p['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_INPUT, 'EXP (Speichersolltemperatur): Eingabefeld statt Schieberegler ±3·10³⁸');
check(($p['SUFFIX'] ?? '') === ' °C' && !isset($p['MIN']) && !isset($p['MAX']), 'EXP: Einheit als Suffix, keine Pseudo-Grenzen (' . json_encode($p, JSON_UNESCAPED_UNICODE) . ')');
check(darstellung($h, 'Hc1HeatCurve')['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_INPUT, 'EXP ohne Einheit (Heizkurve): Eingabefeld');
$p = darstellung($h, 'FrostOverRideTime');
check($p['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_INPUT && ($p['SUFFIX'] ?? '') === ' h', 'UIN (Frostschutz-Verzögerung): Eingabefeld mit „ h" statt 0 … 65534');
check(darstellung($h, 'Hc1PumpStatus')['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_INPUT, 'UIN (Pumpenstatus): Eingabefeld');
$p = darstellung($h, 'Hc1Status');
check($p['PRESENTATION'] === VARIABLE_PRESENTATION_SLIDER && $p['MIN'] === 0 && $p['MAX'] === 254, 'UCH (0 … 254): Schieberegler bleibt');
check(darstellung($h, 'AdaptHeatCurve')['PRESENTATION'] === VARIABLE_PRESENTATION_ENUMERATION, 'Wertetabelle: Auswahl bleibt');
check(darstellung($h, 'Hc1FlowTemp')['PRESENTATION'] === VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'nur lesbar: Wertanzeige bleibt');

echo "Selbsttest ohne Altlasten:\n";
$text = $h->RunSelfTest();
check(!str_contains($text, 'obsolete') && !str_contains($text, 'same name'), 'keine Altlast-Zeilen, wenn es keine Altlasten gibt');

echo "Selbsttest mit Altlasten:\n";
$name = IPS_GetName(IPS_GetObjectIDByIdent('Hc1FlowTemp', $h->id()));
$h->fremdeVariable('F10', 'F10');
$h->fremdeVariable('F02', $name); // heißt wie eine echte Variable
$vorher = $h->RunSelfTest();
check(str_contains($vorher, 'F10') && str_contains($vorher, 'F02') && str_contains($vorher, 'obsolete'), 'nennt Variablen ohne Meldung in der Konfiguration');
check(str_contains($vorher, 'same name') && str_contains($vorher, $name) && str_contains($vorher, 'Hc1FlowTemp') && str_contains($vorher, 'F02'), 'nennt gleich benannte Variablen samt Idents');
check(str_contains($vorher, 'Result: OK'), 'Altlasten zählen nicht als Problem (Ergebnis bleibt OK)');
check(@IPS_GetObjectIDByIdent('F10', $h->id()) > 0 && IPS_GetName(IPS_GetObjectIDByIdent('F02', $h->id())) === $name, 'nichts gelöscht, nichts umbenannt');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

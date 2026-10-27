<?php

declare(strict_types=1);

/**
 * Unterparameter der Darstellungen: Jeder Eintrag in INTERVALS (Wertanzeige) und OPTIONS
 * (Wertanzeige, Aufzählung) setzt nur Felder, die Symcon für diese Darstellung kennt.
 *
 * Anlass: Gegenprobe am nuc (27.10.2026, Symcon 9.1 rust-796c023): EBM_SetMessageActive für
 * RunDataStatuscode (UIN mit Werteliste, nur lesbar) scheiterte mit „Entry in parameter
 * INTERVALS includes unknown sub-parameters“; die Variable stand danach ohne Darstellung da.
 * Die Intervalle setzten ColorDisplay und ContentColorDisplay, die Aufzählung Icon. Ältere
 * Variablen tragen dieselben Felder noch, angelegt vor dieser Prüfung im Kernel.
 * check_presentations.php prüft nur die oberste Ebene der Darstellung.
 *
 * Geprüft wird jede Variable, die die Registrierung für die echten Konfigurationen von hmu
 * und 700 anlegt (tests/fixtures/config_hmu.json, config_700.json), also alle Darstellungen
 * mit Werteliste: Intervalle bei nur lesbaren Meldungen, Aufzählung bei schreibbaren.
 *
 * Die erlaubten Felder stammen aus IPS_GetPresentation(<GUID>)['groups'][…]
 * ['presentationParameters'][<Liste>]['parameters'], abgefragt am 27.10.2026 am nuc.
 *
 * Aufruf: php tests/check-presentation-subparams.php
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

const ERLAUBT = [
    VARIABLE_PRESENTATION_VALUE_PRESENTATION => [
        'INTERVALS' => [
            'ColorActive', 'ColorValue', 'ConstantActive', 'ConstantValue', 'ContentColorActive', 'ContentColorValue',
            'ConversionFactor', 'DigitsActive', 'DigitsValue', 'IconActive', 'IconValue', 'IntervalMaxValue',
            'IntervalMinValue', 'PrefixActive', 'PrefixValue', 'SuffixActive', 'SuffixValue',
        ],
        'OPTIONS'   => ['Caption', 'ColorActive', 'ColorValue', 'ContentColorActive', 'ContentColorValue', 'IconActive', 'IconValue', 'Value'],
    ],
    VARIABLE_PRESENTATION_ENUMERATION        => [
        'OPTIONS' => ['Caption', 'Color', 'IconActive', 'IconValue', 'Value'],
    ],
];

const BASE = 'http://10.1.254.12:8081/data';

foreach (['hmu', '700'] as $circuit) {
    echo "Schaltkreis $circuit:\n";
    $konfig          = json_decode(file_get_contents(__DIR__ . "/fixtures/config_$circuit.json"), true, 512, JSON_THROW_ON_ERROR);
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [
        BASE . "/$circuit"                           => ['global' => ['signal' => 1], $circuit => []],
        BASE . "/$circuit/?def&verbose&exact&write" => $konfig,
    ];
    $h->konfigurieren(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => $circuit, 'UpdateInterval' => 10]);
    $h->UpdateConfiguration();
    $h->resetRecorded();

    $attr     = (new ReflectionClassConstant(ebusdMQTTDevice::class, 'ATTR_EBUSD_CONFIGURATION_MESSAGES'))->getValue();
    $meldungen = $h->privat('readAttributeArray', $attr);
    foreach ($meldungen as $meldung) {
        $h->privat('RegisterVariablesOfMessage', $meldung);
    }

    $geprueft = ['INTERVALS' => 0, 'OPTIONS' => 0];
    $fremd    = [];
    foreach ($h->recorded as $r) {
        if ($r[0] !== 'MaintainVariable' || !is_array($r[4])) {
            continue;
        }
        $darstellung = $r[4]['PRESENTATION'] ?? '';
        foreach (ERLAUBT[$darstellung] ?? [] as $liste => $felder) {
            if (!isset($r[4][$liste])) {
                continue;
            }
            $geprueft[$liste]++;
            foreach (json_decode($r[4][$liste], true, 512, JSON_THROW_ON_ERROR) as $eintrag) {
                foreach (array_diff(array_keys($eintrag), $felder) as $feld) {
                    $fremd[$feld][] = $r[1];
                }
            }
        }
    }
    check($geprueft['INTERVALS'] > 0 && $geprueft['OPTIONS'] > 0, sprintf('Darstellungen mit Intervallen (%d) und Optionen (%d) gefunden', $geprueft['INTERVALS'], $geprueft['OPTIONS']));
    $text = [];
    foreach ($fremd as $feld => $idents) {
        $idents = array_unique($idents);
        $text[] = sprintf('%s (%d Variablen, z. B. %s)', $feld, count($idents), reset($idents));
    }
    check($fremd === [], 'nur bekannte Unterparameter' . ($text === [] ? '' : ': unbekannt ' . implode(', ', $text)));
}

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

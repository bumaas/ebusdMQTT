<?php

declare(strict_types=1);

/**
 * Suffix der Darstellungen: vor jeder Einheit steht ein normales Leerzeichen, auch vor „%“.
 *
 * Anlass: Am nuc (27.10.2026) zeigten Prozentwerte „45%“, alle anderen Einheiten „45 °C“. Das
 * Modul setzte vor „%“ seit build 5 (2020) ein Leerzeichen ohne Breite (U+200B), weil Symcon bei
 * Profilen einen Suffix „%“ oder „ %“ als Anteil am Bereich darstellt. Gegenprobe am nuc
 * (Symcon 9.1 rust-796c023, Wert 128, Bereich 0 bis 255): Profil „ %“ ergibt „50 %“, die
 * Darstellungen Wertanzeige, Schieberegler und Werteingabe mit „ %“ ergeben „128 %“. Das Modul
 * legt nur noch Darstellungen an, die Umgehung ist also überflüssig.
 *
 * Geprüft wird jede Variable, die die Registrierung für die echten Konfigurationen von hmu und
 * 700 anlegt (tests/fixtures/config_hmu.json, config_700.json).
 *
 * Aufruf: php tests/check-suffix.php
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

    $attr      = (new ReflectionClassConstant(ebusdMQTTDevice::class, 'ATTR_EBUSD_CONFIGURATION_MESSAGES'))->getValue();
    $meldungen = $h->privat('readAttributeArray', $attr);
    foreach ($meldungen as $meldung) {
        $h->privat('RegisterVariablesOfMessage', $meldung);
    }

    $mitSuffix = 0;
    $prozent   = 0;
    $falsch    = [];
    foreach ($h->recorded as $r) {
        if ($r[0] !== 'MaintainVariable' || !is_array($r[4]) || ($r[4]['SUFFIX'] ?? '') === '') {
            continue;
        }
        $suffix = $r[4]['SUFFIX'];
        $mitSuffix++;
        if (str_ends_with($suffix, '%')) {
            $prozent++;
        }
        if (preg_match('/^ \S/u', $suffix) !== 1 || str_contains($suffix, "\u{200B}")) {
            $falsch[$r[1]] = json_encode($suffix, JSON_UNESCAPED_UNICODE);
        }
    }
    check($mitSuffix > 0, "Variablen mit Einheit gefunden ($mitSuffix)");
    if ($circuit === 'hmu') {
        check($prozent > 0, "Variablen mit Einheit % gefunden ($prozent)");
    }
    $beispiele = array_slice($falsch, 0, 3, true);
    check($falsch === [], 'jede Einheit mit einem normalen Leerzeichen davor' . ($falsch === [] ? '' : sprintf(': %d abweichend, z. B. %s', count($falsch), implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($beispiele), $beispiele)))));
}

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

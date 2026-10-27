<?php

declare(strict_types=1);

/**
 * Anzahl „neu angelegter“ Variablen: zählt nur Variablen, die es vorher nicht gab.
 *
 * Anlass: Am nuc meldete „Erzeuge/Aktualisiere Variablen“ an der hmu-Instanz „79 Variablen neu
 * angelegt“, angelegt wurden 2 (27.10.2026). Das Modul zählte jedes true von MaintainVariable.
 * Laut Doku liefert MaintainVariable bei IPSModuleStrict, „ob die Variable erstellt wurde“; der
 * Kernel 9.1 rust-796c023 liefert aber auch für eine bestehende, unveränderte Variable true
 * (Gegenprobe: EBM_SetMessageActive für die schon aktive Meldung RunDataStatuscode meldete zweimal
 * hintereinander „1 neue Variable(n)“, die Zahl der Variablen blieb 80). Ein Testmodul am selben
 * Kernel bestätigt das für MaintainVariable mit und ohne Darstellung; RegisterVariableInteger
 * verhält sich korrekt (erst true, dann false).
 *
 * Die Harness stellt dieses Verhalten mit maintainVariableImmerTrue nach. Erwartet, mit und ohne:
 *  - EBM_SetMessageActive: beim ersten Aktivieren 1 neue Variable, beim zweiten 0.
 *  - „Erzeuge/Aktualisiere Variablen“ (CreateAndUpdateVariables) für eine schon angelegte
 *    Meldung: 0.
 *
 * Fixture: tests/fixtures/config_700.json (echte ebusd-Antwort), Meldung Hc1FlowTemp.
 *
 * Aufruf: php tests/check-created-count.php
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

foreach ([false => 'Stub wie Doku', true => 'Kernel am nuc (immer true)'] as $immerTrue => $titel) {
    echo "$titel:\n";
    $h                            = neueInstanz();
    $h->parentActive              = true;
    $h->maintainVariableImmerTrue = (bool)$immerTrue;
    $h->responses                 = [
        BASE . '/700'                           => ['global' => ['signal' => 1], '700' => []],
        BASE . '/700/?def&verbose&exact&write' => json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR),
    ];
    $h->konfigurieren(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 10]);
    $h->UpdateConfiguration();

    $text = $h->SetMessageActive('Hc1FlowTemp', true, 0);
    check(str_contains($text, ': 1 new variable(s)'), "erstes Aktivieren: 1 neue Variable ($text)");
    $text = $h->SetMessageActive('Hc1FlowTemp', true, 0);
    check(str_contains($text, ': 0 new variable(s)'), "zweites Aktivieren: 0 neue Variablen ($text)");

    $liste = $h->privat('getStoredVariableListOrWarn');
    $n     = $h->privat('CreateAndUpdateVariables', $liste);
    check($n === 0, "Erzeuge/Aktualisiere Variablen bei bestehender Variable: 0 (ist $n)");
}

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

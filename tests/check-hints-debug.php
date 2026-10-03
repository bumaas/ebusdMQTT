<?php

declare(strict_types=1);

/**
 * Hinweise im Formular und knappes Debug (MCP-Tauglichkeit, Regeln 6, 10, 1 und 5).
 *
 *  - Regel 6: Jede öffentliche Skriptfunktion hat einen unsichtbaren Formular-Hinweis
 *    (visible: false) mit Signatur, Zweck und Rückgabe — die KI liest das Formular über MCP,
 *    in der Konsole erscheint davon nichts. EBM_publish wird als ungeprüftes Schreiben auf
 *    den eBUS gekennzeichnet.
 *  - Regel 1: „Aktiv" und „Poll-Priorität" der Meldungsliste sind sichtbar erklärt.
 *  - Regel 10: Debug nennt Ergebnisse statt Rohdaten — keine Zeile mit der ganzen
 *    Meldungsliste oder Konfiguration (bis 80 kB), ein Publish ergibt eine Debug-Zeile.
 *  - Regel 5: EBM_RunSelfTest behauptete bei einer gestörten Instanz (bai am nuc, Status 203)
 *    „Die aktiven Meldungen werden alle 1 Minute(n) abgefragt" — das Modul hält den
 *    Abfrage-Timer bei einer Störung aber an.
 *
 * Aufruf: php tests/check-hints-debug.php
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

const MAX_DEBUG = 1000;

$root   = dirname(__DIR__);
$form   = json_decode(file_get_contents($root . '/ebusdMQTTDevice/form.json'), true, 512, JSON_THROW_ON_ERROR);
$source = file_get_contents($root . '/ebusdMQTTDevice/module.php');

/** alle Label-Elemente des Formulars, rekursiv */
function labels(array $elemente): array
{
    $out = [];
    foreach ($elemente as $e) {
        if (($e['type'] ?? '') === 'Label') {
            $out[] = $e;
        }
        foreach (['items'] as $k) {
            if (isset($e[$k]) && is_array($e[$k])) {
                $out = array_merge($out, labels($e[$k]));
            }
        }
    }
    return $out;
}

$alleLabels     = labels(array_merge($form['elements'], $form['actions']));
$unsichtbar     = array_values(array_filter($alleLabels, static fn(array $l): bool => ($l['visible'] ?? true) === false));
$sichtbar       = array_values(array_filter($alleLabels, static fn(array $l): bool => ($l['visible'] ?? true) !== false));
$hinweisTexte   = array_column($unsichtbar, 'caption');

// --- Regel 6: Hinweis je öffentlicher Funktion -----------------------------------

echo "Unsichtbare Hinweise je Skriptfunktion:\n";
preg_match_all('/public function (\w+)\(/', $source, $treffer);
$rueckrufe  = ['create', 'applychanges', 'messagesink', 'requestaction', 'receivedata', 'getconfigurationform', 'destroy'];
$funktionen = array_values(array_filter($treffer[1], static fn(string $f): bool => !in_array(strtolower($f), $rueckrufe, true)));
check(count($funktionen) >= 6, 'öffentliche Funktionen gefunden: ' . implode(', ', $funktionen));
foreach ($funktionen as $f) {
    $passend = array_values(array_filter($hinweisTexte, static fn(string $t): bool => str_contains($t, 'EBM_' . $f . '(')));
    check(count($passend) === 1, "EBM_$f: genau ein unsichtbarer Hinweis");
    check(isset($passend[0]) && preg_match('/\):\s*(string|void|bool|int)/', $passend[0]) === 1, "EBM_$f: Hinweis nennt den Rückgabetyp");
}
$publish = array_values(array_filter($hinweisTexte, static fn(string $t): bool => str_contains($t, 'EBM_publish(')))[0] ?? '';
check(str_contains($publish, 'without any check') && str_contains($publish, 'eBUS'), 'EBM_publish: als ungeprüftes Schreiben auf den eBUS gekennzeichnet');
check(count(array_filter($hinweisTexte, static fn(string $t): bool => str_contains($t, 'RequestAction'))) >= 1, 'Hinweis erklärt das Schreiben von Statusvariablen per RequestAction');

// --- Regel 1: sichtbare Erklärung der Meldungsliste ------------------------------

echo "Sichtbare Erklärung der Meldungsliste:\n";
$erklaerung = array_values(array_filter(array_column($sichtbar, 'caption'), static fn(string $t): bool => str_contains($t, 'Poll priority')))[0] ?? '';
check($erklaerung !== '', 'sichtbares Label erklärt die Poll-Priorität');
check(str_contains($erklaerung, '0') && str_contains($erklaerung, '9') && str_contains($erklaerung, 'Active'), 'nennt den Bereich 0-9 und die Spalte „Active"');
check($form['actions'][1]['type'] === 'List' && $form['actions'][2]['type'] === 'RowLayout', 'Liste und Knopfzeile stehen weiter an Index 1 und 2 (GetConfigurationForm greift per Index zu)');

// --- Regel 5: Selbsttest bei Störung ---------------------------------------------

echo "Selbsttest bei Störung:\n";
$cfg = 'http://10.1.254.12:8081/data/700/?def&verbose&exact&write';
$h   = neueInstanz();
$h->parentActive = true;
$h->responses    = [
    'http://10.1.254.12:8081/data/700' => ['global' => ['signal' => 1], '700' => []],
    $cfg                               => json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR),
];
$h->konfigurieren(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 1]);
$h->UpdateConfiguration();
$text = $h->RunSelfTest();
check(str_contains($text, '1 minute'), 'aktive Instanz: nennt das Abfrageintervall');
$h->responses = [$cfg => $h->responses[$cfg]]; // ebusd antwortet nicht mehr
$h->RequestAction('timerCheckConnection', '');
check(IPS_GetInstance($h->id())['InstanceStatus'] === 205, 'Instanz steht in der Störung (205)');
$text = $h->RunSelfTest();
check(!str_contains($text, '1 minute'), 'gestörte Instanz: behauptet keine laufende Abfrage');
check(str_contains($text, 'not request'), 'gestörte Instanz: sagt, dass nichts abgefragt wird');

// --- Regel 10: knappes Debug -----------------------------------------------------

echo "Debug:\n";
$h->responses['http://10.1.254.12:8081/data/700'] = ['global' => ['signal' => 1], '700' => []];
$h->RequestAction('timerCheckConnection', '');
$h->debug = [];
$h->UpdateConfiguration();
$laengste = max(array_map(static fn(array $d): int => strlen($d[1]), $h->debug ?: [['', '']]));
check($laengste < MAX_DEBUG, "Konfiguration lesen: längste Debug-Zeile $laengste Zeichen (< " . MAX_DEBUG . ')');

$liste    = $h->attribute('VariableList');
$h->debug = [];
$h->RequestAction('btnCreateUpdateVariables', $liste);
$laengste = max(array_map(static fn(array $d): int => strlen($d[1]), $h->debug ?: [['', '']]));
check(strlen($liste) > 20000, 'Testliste ist groß (' . strlen($liste) . ' Zeichen)');
check($laengste < MAX_DEBUG, "Knopf mit ganzer Liste: längste Debug-Zeile $laengste Zeichen (< " . MAX_DEBUG . ')');

$h->debug = [];
$h->publish('ebusd/700/Test/set', '1');
$zeilen = array_values(array_filter($h->debug, static fn(array $d): bool => $d[0] === 'publish'));
check(count($zeilen) === 1, 'ein Publish ergibt genau eine Debug-Zeile (' . count($zeilen) . ')');
check(isset($zeilen[0]) && str_contains($zeilen[0][1], 'ebusd/700/Test/set') && !str_contains($zeilen[0][1], 'DataID'), 'Debug-Zeile nennt Topic und Wert, nicht das Rohpaket');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

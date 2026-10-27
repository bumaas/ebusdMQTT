<?php

declare(strict_types=1);

/**
 * „Ermittle Schaltkreisnamen" mit der neuen ebusd-Standardkonfiguration (ebus.github.io).
 *
 * Anlass: Nach dem Umstieg am nuc (27.10.2026) bot die Auswahlliste „Broadcast", „Scan.08",
 * „Scan.15" und „Scan.76" an. Die neue Konfiguration schreibt diese gemeinsamen Schaltkreise
 * groß, die alte klein; der Filter für global/broadcast/scan.* verglich genau. Wählbar waren
 * sie trotzdem nicht sinnvoll: Gerätedaten stehen in den Geräteschaltkreisen, und das Modul
 * schreibt den gewählten Namen intern klein.
 *
 * Erwartet: Die Liste enthält genau die Geräteschaltkreise 700, hmu und vwzio. Für die alte
 * Antwort (fixtures/data_all.json) gilt weiterhin 700 und hmu.
 *
 * Fixture tests/fixtures/data_all_2026-10-27.json: unveränderte Antwort von
 * http://rasp3keller:8081/data mit ebusd 26.1 und der Konfiguration eBUS/ebus.github.io.
 *
 * Aufruf: php tests/check-circuit-case.php
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

function optionen(string $fixture): array
{
    $h               = neueInstanz(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => 'hmu']);
    $h->parentActive = true;
    $h->responses    = ['http://10.1.254.12:8081/data' => json_decode(file_get_contents(__DIR__ . "/fixtures/$fixture"), true, 512, JSON_THROW_ON_ERROR)];
    $h->RequestAction('btnReadCircuits', json_encode(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => 'hmu'], JSON_THROW_ON_ERROR));
    $liste = json_decode($h->attribute('CircuitOptionList'), true, 512, JSON_THROW_ON_ERROR);
    return array_map(static fn(array $o): string => (string)$o['value'], $liste);
}

echo "Neue Standardkonfiguration:\n";
$werte = optionen('data_all_2026-10-27.json');
check($werte === ['', '700', 'hmu', 'vwzio'], 'Liste enthält genau 700, hmu und vwzio (' . json_encode($werte) . ')');
check(array_filter($werte, static fn(string $w): bool => preg_match('/^(broadcast|global|scan\.)/i', $w) === 1) === [], 'kein Broadcast, global oder Scan.* in beliebiger Schreibweise');

echo "Alte Konfiguration:\n";
$werte = optionen('data_all.json');
check($werte === ['', '700', 'hmu'], 'Liste wie bisher: 700 und hmu (' . json_encode($werte) . ')');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

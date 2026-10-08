<?php

declare(strict_types=1);

/**
 * Ein unveränderter Wert von ebusd frischt die Variable auf („Letzte Aktualisierung“).
 *
 * Anlass: PN froema (Forum t/144582, 08.10.2026). ebusd beantwortete die Anfragen per MQTT,
 * die Werte änderten sich nur nicht. Der SetValue-Override des Moduls überging gleiche Werte,
 * deshalb blieb VariableUpdated stehen, und die Variablen sahen aus, als käme nichts an.
 * Symcon unterscheidet selbst zwischen VariableUpdated (jeder Wert) und VariableChanged
 * (nur geänderte Werte).
 *
 * Erwartet:
 *  - Derselbe Wert ein zweites Mal: VariableUpdated rückt vor, VariableChanged bleibt.
 *  - Ein anderer Wert: beide rücken vor.
 *
 * Die MQTT-Payload entsteht aus dem Feld „tempv“ der echten Konfiguration
 * (fixtures/config_700.json, Meldung Hc1FlowTemp).
 *
 * Aufruf: php tests/check-setvalue-update.php
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

$config = json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR);
$field  = $config['700']['messages']['Hc1FlowTemp']['fields']['tempv'];

$h               = neueInstanz();
$h->parentActive = true;
$h->responses    = [
    STATUS_URL => ['global' => ['signal' => 1], '700' => []],
    CONFIG_URL => $config,
];
$h->konfigurieren(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 2]);
$h->UpdateConfiguration();
$h->SetMessageActive('Hc1FlowTemp', true, 0);

function empfang(ebusdMQTTHarness $h, array $field, float $value): void
{
    $field['value'] = $value;
    $h->ReceiveData(json_encode([
        'DataID'  => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}',
        'Topic'   => 'ebusd/700/Hc1FlowTemp',
        'Payload' => bin2hex(json_encode(['tempv' => $field], JSON_THROW_ON_ERROR)),
    ], JSON_THROW_ON_ERROR));
}

/** Zeitstempel der Variable im Stub zurückdrehen, damit ein neuer Schreibvorgang sichtbar wird */
function zurueckdrehen(int $varID, int $timestamp): void
{
    $prop = new ReflectionProperty(IPS\VariableManager::class, 'variables');
    $vars = $prop->getValue();
    $vars[$varID]['VariableUpdated'] = $timestamp;
    $vars[$varID]['VariableChanged'] = $timestamp;
    $prop->setValue(null, $vars);
}

$alt = 1000;

echo "Erster Wert:\n";
empfang($h, $field, (float)$field['value']);
$varID = IPS_GetObjectIDByIdent('Hc1FlowTemp', $h->id());
check(GetValue($varID) === (float)$field['value'], 'Variable trägt den Wert ' . $field['value']);

echo "Derselbe Wert noch einmal:\n";
zurueckdrehen($varID, $alt);
empfang($h, $field, (float)$field['value']);
$v = IPS_GetVariable($varID);
check($v['VariableUpdated'] > $alt, 'VariableUpdated rückt vor (ist ' . $v['VariableUpdated'] . ')');
check($v['VariableChanged'] === $alt, 'VariableChanged bleibt (ist ' . $v['VariableChanged'] . ')');

echo "Ein anderer Wert:\n";
zurueckdrehen($varID, $alt);
empfang($h, $field, (float)$field['value'] + 1.0);
$v = IPS_GetVariable($varID);
check(GetValue($varID) === (float)$field['value'] + 1.0, 'Variable trägt den neuen Wert');
check($v['VariableUpdated'] > $alt && $v['VariableChanged'] > $alt, 'VariableUpdated und VariableChanged rücken vor');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * Befunde aus dem Blindtest vom 04.10.2026 (frischer Agent, nur MCP, Testinstanz am nuc;
 * Bericht E:\Desktop\Smart Home\Eigenes\nuc\checks\2026-10-04_ebusdmqtt-blindtest-ergebnis.md):
 *
 *  1. Selbsttest bei ungültigem Port (Status 202) meldete „ebusd antwortet nicht unter …:80801"
 *     statt „Port ungültig", und „letzte Aktualisierung 01.01.1970" für nie befüllte Variablen.
 *  2. Nach EBM_SetMessageActive stand die neue Variable bis zur nächsten MQTT-Meldung auf 0 —
 *     eine KI liest „Vorlauf 0 °C" als Messwert.
 *  3. Im Zeitfenster zwischen „Schaltkreis gewählt" und „Konfiguration eingelesen" wurde jede
 *     eingehende Meldung zum ERROR („Message tariffTimer.Saturday nicht in Konfiguration
 *     gefunden", halb übersetzt, ohne nächsten Schritt). Fehlt eine Meldung wirklich, soll das
 *     einmal als Warnung mit nächstem Schritt erscheinen, nicht bei jedem Empfang.
 *  4. EBM_FindMessages nannte keine Einheiten, Wertetabellen oder Bereiche (die Testperson wich
 *     auf eigene Profile des Anwenders aus), EBM_ReadMessageValues kein Alter der Werte.
 *
 * Aufruf: php tests/check-blindtest-findings.php
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

function konfig700(): array
{
    return json_decode(file_get_contents(__DIR__ . '/fixtures/config_700.json'), true, 512, JSON_THROW_ON_ERROR);
}

function instanz(bool $konfigurationLesen = true): ebusdMQTTHarness
{
    $h               = neueInstanz();
    $h->parentActive = true;
    $h->responses    = [STATUS_URL => ['global' => ['signal' => 1], '700' => []], CONFIG_URL => konfig700()];
    $h->konfigurieren(['Host' => '10.1.254.12', 'Port' => '8081', 'CircuitName' => '700', 'UpdateInterval' => 10]);
    if ($konfigurationLesen) {
        $h->UpdateConfiguration();
    }
    $h->resetRecorded();
    return $h;
}

function logs(ebusdMQTTHarness $h): array
{
    return array_values(array_filter($h->recorded, static fn(array $r): bool => $r[0] === 'LogMessage'));
}

function paket(string $meldung): string
{
    return json_encode([
        'DataID'  => '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}',
        'Topic'   => 'ebusd/700/' . $meldung,
        'Payload' => bin2hex('{"0":{"value":1}}'),
    ], JSON_THROW_ON_ERROR);
}

// --- 1. Selbsttest bei ungültiger Konfiguration ----------------------------------

echo "Selbsttest bei ungültigem Port/Host:\n";
foreach (['Port' => '80801', 'Host' => 'kein host!'] as $eigenschaft => $wert) {
    $h = instanz();
    $h->konfigurieren([$eigenschaft => $wert]);
    $h->requestedUrls = [];
    $text             = $h->RunSelfTest();
    check(str_contains($text, '✘') && str_contains($text, $wert) && str_contains($text, $eigenschaft), "$eigenschaft „{$wert}\": Selbsttest nennt $eigenschaft und Wert");
    check(!str_contains($text, 'does not answer'), "$eigenschaft ungültig: keine irreführende „ebusd antwortet nicht\"-Zeile");
    check($h->requestedUrls === [], "$eigenschaft ungültig: keine HTTP-Abfrage mit ungültiger Adresse");
}

echo "Selbsttest mit nie befüllter Variable:\n";
$h = instanz();
$h->SetMessageActive('Hc1FlowTemp', true, 0);
$text = $h->RunSelfTest();
check(!str_contains($text, '1970'), 'kein Datum 01.01.1970');
check(str_contains($text, 'no value'), 'sagt, dass noch kein Wert empfangen wurde');

// --- 2. SetMessageActive fordert den Wert an --------------------------------------

echo "EBM_SetMessageActive fordert den Wert an:\n";
$h = instanz();
$h->SetMessageActive('Hc1FlowTemp', true, 0);
check($h->publiziert() === [['topic' => 'ebusd/700/Hc1FlowTemp/get', 'payload' => '']], 'nach dem Einschalten wird der Wert bei ebusd angefordert (' . json_encode($h->publiziert()) . ')');
$h->resetRecorded();
$h->SetMessageActive('Hc1FlowTemp', false, 0);
check($h->publiziert() === [], 'beim Ausschalten wird nichts angefordert');
$h->resetRecorded();
$h->parentActive = false;
$text            = $h->SetMessageActive('DisplayedOutsideTemp', true, 0);
check($h->publiziert() === [] && str_contains($text, 'DisplayedOutsideTemp'), 'ohne MQTT-Parent: Variable angelegt, nichts gesendet');

// --- 3. Unbekannte Meldungen --------------------------------------------------------

echo "Eingehende Meldung vor dem Einlesen der Konfiguration:\n";
$h = instanz(false);
$h->ReceiveData(paket('tariffTimer.Saturday'));
check(logs($h) === [], 'kein Log-Eintrag, solange die Konfiguration noch nicht eingelesen ist (' . json_encode(logs($h), JSON_UNESCAPED_UNICODE) . ')');

echo "Eingehende Meldung, die in der Konfiguration fehlt:\n";
$h = instanz();
$h->ReceiveData(paket('GibtEsNicht'));
$h->ReceiveData(paket('GibtEsNicht'));
$l = logs($h);
check(count($l) === 1 && $l[0][2] === KL_WARNING, 'genau eine Warnung, auch bei wiederholtem Empfang (' . json_encode($l, JSON_UNESCAPED_UNICODE) . ')');
check(isset($l[0]) && str_contains($l[0][1], 'GibtEsNicht') && str_contains($l[0][1], 'EBM_UpdateConfiguration'), 'Warnung nennt die Meldung und den nächsten Schritt');
$h->ReceiveData(paket('AuchNicht'));
check(count(logs($h)) === 2, 'eine andere fehlende Meldung wird ebenfalls einmal gemeldet');
$h->UpdateConfiguration();
$h->resetRecorded();
$h->ReceiveData(paket('GibtEsNicht'));
check(count(logs($h)) === 1, 'nach erneutem Einlesen wird eine weiterhin fehlende Meldung wieder einmal gemeldet');
$h->resetRecorded();
$h->ReceiveData(paket('Hc1FlowTemp'));
check(logs($h) === [], 'bekannte Meldung: kein Log-Eintrag');

// --- 4. FindMessages mit Feldbeschreibung, ReadMessageValues mit Alter -------------

echo "EBM_FindMessages beschreibt die Felder:\n";
$h = instanz();
$h->SetMessageActive('Hc1FlowTemp', true, 0);
$liste = array_column(json_decode($h->FindMessages(''), true, 512, JSON_THROW_ON_ERROR), null, 'message');
$f     = $liste['AdaptHeatCurve']['fields'][0] ?? [];
check(($f['ident'] ?? '') === 'AdaptHeatCurve' && ($f['label'] ?? '') === 'Adaptive Heizkurve' && ($f['type'] ?? '') === 'integer', 'Feld: Ident, Bezeichnung, Typ (' . json_encode($f, JSON_UNESCAPED_UNICODE) . ')');
check(($f['values'] ?? null) === ['0' => 'nein', '1' => 'ja'], 'Wertetabelle als erlaubte Werte');
$f = $liste['HwcTempDesired']['fields'][0] ?? [];
check(($f['unit'] ?? '') === '°C' && !isset($f['min']) && !isset($f['max']), 'Einheit; kein Pseudo-Bereich bei EXP (' . json_encode($f, JSON_UNESCAPED_UNICODE) . ')');
$f = $liste['Hc1Status']['fields'][0] ?? [];
check(($f['min'] ?? null) === 0 && ($f['max'] ?? null) === 254, 'überschaubarer Bereich wird genannt (UCH 0 … 254)');
$f = $liste['Hc1FlowTemp']['fields'][0] ?? [];
check(($f['variableID'] ?? 0) === IPS_GetObjectIDByIdent('Hc1FlowTemp', $h->id()), 'Feld nennt die ID der angelegten Variable');
check(!isset($liste['DisplayedOutsideTemp']['fields'][0]['variableID']), 'ohne Variable keine ID');

echo "EBM_ReadMessageValues nennt das Alter:\n";
$antwort = konfig700()['700']['messages']['AdaptHeatCurve'];
$h->responses[BASE . '/700/AdaptHeatCurve?def&verbose&exact&required&maxage=600'] = ['700' => ['messages' => ['AdaptHeatCurve' => $antwort]]];
$werte = json_decode($h->ReadMessageValues('AdaptHeatCurve'), true, 512, JSON_THROW_ON_ERROR);
check(isset($werte['AdaptHeatCurve']['value']) && ($werte['AdaptHeatCurve']['lastUpdate'] ?? '') === date('Y-m-d H:i:s', $antwort['lastup']), 'Wert und Zeitpunkt der letzten Aktualisierung (' . json_encode($werte, JSON_UNESCAPED_UNICODE) . ')');
$antwort['lastup'] = 0;
$h->responses[BASE . '/700/AdaptHeatCurve?def&verbose&exact&required&maxage=600'] = ['700' => ['messages' => ['AdaptHeatCurve' => $antwort]]];
$werte = json_decode($h->ReadMessageValues('AdaptHeatCurve'), true, 512, JSON_THROW_ON_ERROR);
check(is_array($werte['AdaptHeatCurve'] ?? null) && array_key_exists('lastUpdate', $werte['AdaptHeatCurve']) && $werte['AdaptHeatCurve']['lastUpdate'] === null, 'nie aktualisiert: lastUpdate null');

echo "\n$checks Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);

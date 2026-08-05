<?php

declare(strict_types=1);

/**
 * Golden-File-Regressionstests für ebusdMQTT.
 *
 * Friert das Verhalten der zentralen Verarbeitungskette auf Basis echter
 * ebusd-REST-Antworten ein (tests/fixtures/config_<circuit>.json, eingefangen
 * von der Live-Installation; Schaltkreise: hmu = Vaillant Wärmepumpe,
 * 700 = Vaillant Regler VRC700).
 *
 * Bereiche je Schaltkreis:
 *  - prepared:     read/write-Ableitung (selectAndPrepareConfigurationMessages)
 *  - variablelist: Formularliste (getVariableList inkl. Ident-/Label-Ableitung)
 *  - registration: MaintainVariable-/EnableAction-Aufrufe samt Presentations
 *                  (RegisterVariablesOfMessage)
 *  - values:       Werte-Dekodierung (getFieldValues) mit den Live-Feldwerten
 *  - payloads:     Publish-Format (getPayload) für schreibbare Messages
 *
 * Läuft ohne IP-Symcon-Kernel und ohne Netzwerk (tests/symcon_stubs.php).
 *
 * Aufruf:  php tests/golden_regression.php            -> Vergleich gegen tests/golden/ (CI)
 *          php tests/golden_regression.php --update   -> Golden-Dateien neu schreiben
 */

$root      = dirname(__DIR__);
$goldenDir = __DIR__ . '/golden';
$update    = in_array('--update', $argv, true);

require_once __DIR__ . '/symcon_stubs.php';
require_once $root . '/ebusdMQTTDevice/module.php';

// trigger_error-Meldungen einsammeln statt ausgeben — der Fehlerkanal ist Teil
// des eingefrorenen Verhaltens
$GLOBALS['capturedErrors'] = [];
set_error_handler(static function (int $errno, string $errstr): bool {
    if (!(error_reporting() & $errno)) {
        return false; // per @ unterdrückt
    }
    $GLOBALS['capturedErrors'][] = $errstr;
    return true;
});

function takeErrors(): array
{
    $errors                    = $GLOBALS['capturedErrors'];
    $GLOBALS['capturedErrors'] = [];
    return $errors;
}

function canonical(mixed $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

function digest(mixed $data): string
{
    return hash('sha256', canonical($data));
}

function pretty(mixed $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

function invokePrivate(object $obj, string $method, mixed ...$args): mixed
{
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);
    return $m->invoke($obj, ...$args);
}

// ---- Harness ---------------------------------------------------------------

const CIRCUITS = ['hmu', '700'];

function newHarness(string $circuit): ebusdMQTTDevice
{
    $harness = new ebusdMQTTDevice(0);
    $harness->Create();
    $harness->setPropertyForTest('Host', 'ebusd.test');
    $harness->setPropertyForTest('CircuitName', $circuit);
    $harness->resetRecorded();
    takeErrors();
    return $harness;
}

// ---- Bereiche --------------------------------------------------------------

function buildFiles(): array
{
    $files = [];

    foreach (CIRCUITS as $circuit) {
        $fixtureFile = __DIR__ . '/fixtures/config_' . $circuit . '.json';
        $fixture     = json_decode(file_get_contents($fixtureFile), true, 512, JSON_THROW_ON_ERROR);
        $rawMessages = $fixture[$circuit]['messages'];
        $harness     = newHarness($circuit);

        // prepared: read/write-Ableitung (wie in ReadConfiguration inkl. ksort)
        takeErrors();
        $prepared = invokePrivate($harness, 'selectAndPrepareConfigurationMessages', $rawMessages);
        ksort($prepared);
        $flags = [];
        foreach ($prepared as $name => $message) {
            $flags[$name] = [
                'read'    => $message['read'],
                'write'   => $message['write'],
                'passive' => $message['passive']
            ];
        }
        $files['prepared_' . $circuit . '.json'] = [
            'count'  => count($prepared),
            'sha256' => digest($prepared),
            'flags'  => $flags,
            'errors' => takeErrors()
        ];

        // Attribut wie im Betrieb setzen (getPayload liest daraus)
        $preparedJson = json_encode($prepared, JSON_THROW_ON_ERROR);
        $harness->setAttributeForTest('ebusdConfigurationMessages', $preparedJson);

        // variablelist: Formularliste inkl. Ident-/Label-Ableitung
        takeErrors();
        $variableList = invokePrivate($harness, 'getVariableList', $preparedJson);
        $files['variablelist_' . $circuit . '.json'] = [
            'count'  => count($variableList),
            'list'   => $variableList,
            'errors' => takeErrors()
        ];

        // registration: Variablen-Registrierung samt Presentations
        $registration = [];
        foreach ($prepared as $name => $message) {
            $harness->resetRecorded();
            takeErrors();
            $entry = [];
            try {
                $entry['created'] = invokePrivate($harness, 'RegisterVariablesOfMessage', $message);
            } catch (Throwable $e) {
                $entry['exception'] = get_class($e) . ': ' . $e->getMessage();
            }
            $entry['events'] = $harness->recorded;
            $errors          = takeErrors();
            if ($errors !== []) {
                $entry['errors'] = $errors;
            }
            $registration[$name] = $entry;
        }
        $files['registration_' . $circuit . '.json'] = $registration;

        // values: Werte-Dekodierung mit den Live-Feldwerten der REST-Antwort
        // (gleiche Struktur wie die MQTT-Payloads: Feldname -> {value: ...})
        $values = [];
        foreach ($prepared as $name => $message) {
            if (empty($message['fields']) || !is_array($message['fields'])) {
                continue;
            }
            takeErrors();
            $entry = [
                'typed'   => invokePrivate($harness, 'getFieldValues', $message, $message['fields'], false),
                'numeric' => invokePrivate($harness, 'getFieldValues', $message, $message['fields'], true)
            ];
            $errors = takeErrors();
            if ($errors !== []) {
                $entry['errors'] = $errors;
            }
            $values[$name] = $entry;
        }
        $files['values_' . $circuit . '.json'] = $values;

        // payloads: Publish-Format für schreibbare Messages, Beispielwert je Feldtyp
        $samples = [
            VARIABLETYPE_BOOLEAN => true,
            VARIABLETYPE_INTEGER => 21,
            VARIABLETYPE_FLOAT   => 21.5,
            VARIABLETYPE_STRING  => 'sample'
        ];
        $payloads = [];
        foreach ($prepared as $name => $message) {
            if (($message['write'] ?? false) !== true) {
                continue;
            }
            $fieldDef = null;
            foreach ($message['fielddefs'] ?? [] as $candidate) {
                if (($candidate['type'] ?? '') !== 'IGN') {
                    $fieldDef = $candidate;
                    break;
                }
            }
            takeErrors();
            $entry = [];
            try {
                $variableType     = $fieldDef === null ? -1 : invokePrivate($harness, 'getIPSVariableType', $fieldDef);
                $sample           = $samples[$variableType] ?? 'sample';
                $entry['sample']  = $sample;
                $entry['payload'] = invokePrivate($harness, 'getPayload', $name, $sample);
            } catch (Throwable $e) {
                $entry['exception'] = get_class($e) . ': ' . $e->getMessage();
            }
            $errors = takeErrors();
            if ($errors !== []) {
                $entry['errors'] = $errors;
            }
            $payloads[$name] = $entry;
        }
        $files['payloads_' . $circuit . '.json'] = $payloads;
    }

    return $files;
}

// ---- Lauf ------------------------------------------------------------------

$files = buildFiles();

// JSON-Roundtrip-Normalisierung: json_encode macht z. B. aus float 21.0 die
// JSON-Zahl 21, die beim Einlesen der Golden-Datei als int zurückkommt.
// Vergleich und Schreiben arbeiten deshalb einheitlich auf dekodierten Werten.
$files = json_decode(canonical($files), true, 512, JSON_THROW_ON_ERROR);

if ($update) {
    if (!is_dir($goldenDir) && !mkdir($goldenDir) && !is_dir($goldenDir)) {
        fwrite(STDERR, "Kann $goldenDir nicht anlegen.\n");
        exit(1);
    }
    foreach ($files as $name => $data) {
        file_put_contents($goldenDir . '/' . $name, pretty($data));
    }
    echo 'Golden-Dateien aktualisiert: ' . count($files) . " Dateien in tests/golden/.\n";
    exit(0);
}

$fail = false;
foreach ($files as $name => $actual) {
    $path = $goldenDir . '/' . $name;
    echo "==== $name ====\n";
    if (!is_file($path)) {
        echo "GOLDEN-DATEI FEHLT\n\n";
        $fail = true;
        continue;
    }
    $golden = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($golden === $actual) {
        echo 'OK (' . count($actual) . " Einträge)\n\n";
        continue;
    }
    $fail = true;
    foreach ($actual as $key => $value) {
        if (!array_key_exists($key, $golden)) {
            echo "  NEU (nicht in Golden-Datei): $key\n";
        } elseif ($golden[$key] !== $value) {
            echo "  ABWEICHUNG: $key\n";
            echo '    erwartet: ' . canonical($golden[$key]) . "\n";
            echo '    erhalten: ' . canonical($value) . "\n";
        }
    }
    foreach (array_keys($golden) as $key) {
        if (!array_key_exists($key, $actual)) {
            echo "  FEHLT (in Golden-Datei, aber nicht mehr vorhanden): $key\n";
        }
    }
    echo "\n";
}

if ($fail) {
    fwrite(STDERR, "FEHLER: Verhalten weicht von den Golden-Dateien ab (siehe oben).\n");
    fwrite(STDERR, "Falls die Abweichung beabsichtigt ist: php tests/golden_regression.php --update\n");
    exit(1);
}

echo "OK: Alle Bereiche unverändert.\n";

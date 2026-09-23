<?php

declare(strict_types=1);

/**
 * Hält die Doku beim Code (Sperrklinke).
 *
 * Aus Modulen, Formularen und öffentlichen Funktionen wird abgeleitet, was in der Doku stehen
 * muss (README.md, README.en.md, docs/**.md):
 *   - jedes Modul (module.json name, Ordnername oder alias) ist genannt;
 *   - jedes Formularfeld aus form.json (Elemente mit "name") ist beschrieben: Name in Backticks,
 *     englische Beschriftung oder deren deutsche Übersetzung aus locale.json;
 *   - jede öffentliche Skriptfunktion PREFIX_Methode aus module.php steht in der Doku
 *     (Symcon-Rückrufe und Formularknöpfe mit onClick/onChange ausgenommen);
 *   - kein „IP-Symcon" außerhalb von Links (Produktname seit 2024 „Symcon").
 *
 * Lücken, die es bei der Einführung schon gab, stehen in tests/readme-bekannt.json. Rot wird der
 * Test nur bei NEUEN Lücken - und bei bekannten, die inzwischen geschlossen sind: Die sind aus der
 * Datei zu streichen, damit die Liste nur schrumpfen kann.
 *
 * Was sich nicht ableiten lässt (Verhaltensänderungen, Migrationshinweise), prüft der Test nicht.
 *
 * Aufruf: php tests/check-readme.php                    Prüfung
 *         php tests/check-readme.php --bekannt-schreiben heutige Lücken als bekannt ablegen
 *
 * Vorlage: ~/.claude/skills/symcon-testsuite/vorlagen/check-readme.php - Änderungen dort pflegen.
 */

const RUECKRUFE = [
    'create', 'destroy', 'applychanges', 'receivedata', 'forwarddata', 'requestaction', 'messagesink',
    'getconfigurationform', 'getcompatibleparents', 'getconfigurationforparent', '__construct', '__destruct',
    'translate', 'processhookdata', 'getvisualizationtile', 'migrate',
];

$wurzel  = dirname(__DIR__);
$bekannt = __DIR__ . '/readme-bekannt.json';

/** Formularfelder (Elemente mit "name"), rekursiv über items/columns/popup. */
function formularfelder(array $elemente): array
{
    $felder = [];
    foreach ($elemente as $e) {
        if (!is_array($e)) {
            continue;
        }
        if (isset($e['name']) && is_string($e['name']) && !in_array($e['type'] ?? '', ['Label', 'Button', 'Image'], true)) {
            $felder[] = ['name' => $e['name'], 'caption' => is_string($e['caption'] ?? null) ? $e['caption'] : ''];
        }
        foreach (['items', 'columns'] as $k) {
            if (isset($e[$k]) && is_array($e[$k])) {
                $felder = array_merge($felder, formularfelder($e[$k]));
            }
        }
        if (isset($e['popup']['items']) && is_array($e['popup']['items'])) {
            $felder = array_merge($felder, formularfelder($e['popup']['items']));
        }
    }
    return $felder;
}

// Doku zusammentragen
$doku = '';
foreach (['README.md', 'readme.md', 'Readme.md', 'README.en.md'] as $datei) {
    if (is_file("$wurzel/$datei")) {
        $doku .= "\n" . file_get_contents("$wurzel/$datei");
    }
}
if (is_dir("$wurzel/docs")) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$wurzel/docs", FilesystemIterator::SKIP_DOTS)) as $datei) {
        if (str_ends_with($datei->getFilename(), '.md')) {
            $doku .= "\n" . file_get_contents($datei->getPathname());
        }
    }
}
$ohneLinks = preg_replace(['#\]\([^)]*\)#', '#https?://\S+#', '#<[^>]+>#'], ['](…)', '', ''], $doku);
$klein     = mb_strtolower($ohneLinks);

// Lücken ermitteln
$luecken = [];
foreach (glob("$wurzel/*/module.json") ?: [] as $moduljson) {
    $ordner = dirname($moduljson);
    $modul  = json_decode(file_get_contents($moduljson), true, 512, JSON_THROW_ON_ERROR);
    $name   = $modul['name'] ?? basename($ordner);

    $genannt = false;
    foreach (array_merge([$name, basename($ordner)], $modul['aliases'] ?? []) as $n) {
        if ($n !== '' && str_contains($klein, mb_strtolower($n))) {
            $genannt = true;
        }
    }
    if (!$genannt) {
        $luecken[] = "modul:$name";
    }

    $locale = is_file("$ordner/locale.json")
        ? (json_decode(file_get_contents("$ordner/locale.json"), true, 512, JSON_THROW_ON_ERROR)['translations']['de'] ?? [])
        : [];
    $form = is_file("$ordner/form.json") ? json_decode(file_get_contents("$ordner/form.json"), true, 512, JSON_THROW_ON_ERROR) : [];
    foreach (formularfelder($form['elements'] ?? []) as $feld) {
        $kandidaten = ['`' . $feld['name'] . '`'];
        if ($feld['caption'] !== '') {
            $kandidaten[] = rtrim($feld['caption'], ': ');
            if (isset($locale[$feld['caption']])) {
                $kandidaten[] = rtrim($locale[$feld['caption']], ': ');
            }
        }
        $beschrieben = false;
        foreach ($kandidaten as $k) {
            if (mb_strlen($k) > 2 && str_contains($klein, mb_strtolower($k))) {
                $beschrieben = true;
            }
        }
        if (!$beschrieben) {
            $luecken[] = "feld:$name:{$feld['name']}";
        }
    }

    $prefix = $modul['prefix'] ?? '';
    if ($prefix !== '' && is_file("$ordner/module.php")) {
        $quelle     = file_get_contents("$ordner/module.php");
        $formQuelle = (is_file("$ordner/form.json") ? file_get_contents("$ordner/form.json") : '') . $quelle;
        preg_match_all('/^\s*public\s+function\s+(\w+)\s*\(/m', $quelle, $treffer);
        foreach (array_unique($treffer[1]) as $methode) {
            $funktion = $prefix . '_' . $methode;
            if (in_array(strtolower($methode), RUECKRUFE, true)) {
                continue;
            }
            if (preg_match('/(onClick|onChange)["\']?\s*[:=]>?\s*["\'][^"\']*' . preg_quote($funktion, '/') . '\b/', $formQuelle) === 1) {
                continue; // Formularknopf, keine Skript-API
            }
            if (!str_contains($ohneLinks, $funktion)) {
                $luecken[] = "funktion:$funktion";
            }
        }
    }
}
sort($luecken);

if (in_array('--bekannt-schreiben', $argv, true)) {
    file_put_contents($bekannt, json_encode([
        'hinweis' => 'Doku-Lücken bei Einführung von tests/check-readme.php. Nur streichen, nie ergänzen - neue Felder und Funktionen gehören in die Doku.',
        'offen'   => $luecken,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    printf("%d bekannte Lücken nach %s geschrieben\n", count($luecken), basename($bekannt));
    exit(0);
}

// Prüfen
$pruefungen = 0;
$fehler     = 0;
function pruefe(bool $ok, string $text): void
{
    global $pruefungen, $fehler;
    $pruefungen++;
    if (!$ok) {
        $fehler++;
    }
    echo ($ok ? '  ok   ' : '  FEHL ') . $text . "\n";
}

$offen = is_file($bekannt) ? (json_decode(file_get_contents($bekannt), true, 512, JSON_THROW_ON_ERROR)['offen'] ?? []) : [];

pruefe(trim($doku) !== '', 'Doku vorhanden (README.md)');
// Code (Pfade, Zitate) zählt nicht: ```-Blöcke und `…` heraus
$ipSymcon = substr_count(preg_replace(['/```.*?```/s', '/`[^`\n]*`/'], '', $ohneLinks), 'IP-Symcon');
pruefe($ipSymcon === 0, "kein „IP-Symcon“ in der Doku ($ipSymcon)");

$neu = array_values(array_diff($luecken, $offen));
pruefe($neu === [], 'keine neuen Doku-Lücken' . ($neu !== [] ? ': ' . implode(', ', $neu) : ''));
$erledigt = array_values(array_diff($offen, $luecken));
pruefe($erledigt === [], 'readme-bekannt.json aktuell' . ($erledigt !== [] ? ' - geschlossen, bitte streichen: ' . implode(', ', $erledigt) : ''));
echo '  (noch ' . count($offen) . " bekannte Lücken)\n";

echo "\n$pruefungen Prüfungen, $fehler Fehler\n";
exit($fehler === 0 ? 0 : 1);

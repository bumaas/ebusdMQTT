<?php

declare(strict_types=1);

/**
 * Prüft, dass jede Darstellung im Quelltext nur Parameter setzt, die es in dieser Darstellung
 * auch gibt. Symcon validiert das ab 9.1 selbst und wirft sonst Fehler (Hinweis Niels,
 * Forum-PN t/144401 vom 16.09.2026).
 *
 * Gesucht werden Array-Literale mit dem Schlüssel 'PRESENTATION'. Dessen Wert wird aufgelöst
 * (VARIABLE_PRESENTATION_*-Konstante, GUID-String, Klassenkonstante `X::PRESENTATION` aus dem
 * Repo oder ein Ternär aus solchen); geprüft werden die Schlüssel auf derselben Ebene — bei
 * einem Ternär gegen jede der möglichen Darstellungen. Nicht auflösbare Werte werden nur als
 * Hinweis gemeldet.
 *
 * Die Parameterlisten stammen aus IPS_GetPresentation(<GUID>) (presentationParameters sowie
 * die Parameter aus Formular und Vorlagen), abgefragt am 16.09.2026 auf Symcon 9.1.
 *
 * Exit-Code 1 bei unbekannten Parametern (für die CI), sonst 0.
 * Aufruf: php tests/check_presentations.php
 */

const PRESENTATION_PARAMETERS = [
    'VARIABLE_PRESENTATION_LEGACY'             => ['PROFILE'],
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => [
        'COLOR', 'CONTENT_COLOR', 'DECIMAL_SEPARATOR', 'DIGITS', 'DISPLAY_TYPE', 'ICON', 'INTERVALS',
        'INTERVALS_ACTIVE', 'MAX', 'MIN', 'MULTILINE', 'OPTIONS', 'PERCENTAGE', 'PREFIX', 'PREVIEW_STYLE',
        'SHOW_PREVIEW', 'SUFFIX', 'THOUSANDS_SEPARATOR', 'USAGE_TYPE',
    ],
    'VARIABLE_PRESENTATION_VALUE_INPUT'        => ['MULTILINE', 'PREFIX', 'SUFFIX'],
    'VARIABLE_PRESENTATION_SLIDER'             => [
        'CUSTOM_GRADIENT', 'DECIMAL_SEPARATOR', 'DIGITS', 'GRADIENT_TYPE', 'ICON', 'INTERVALS', 'INTERVALS_ACTIVE',
        'MAX', 'MIN', 'PERCENTAGE', 'PREFIX', 'STEP_SIZE', 'SUFFIX', 'THOUSANDS_SEPARATOR', 'USAGE_TYPE',
    ],
    'VARIABLE_PRESENTATION_WEB_CONTENT'        => ['HTML_TYPE', 'PADDING'],
    'VARIABLE_PRESENTATION_COLOR'              => ['COLOR_CURVE', 'COLOR_SPACE', 'ENCODING', 'PRESET_VALUES', 'SELECTION'],
    'VARIABLE_PRESENTATION_DATE_TIME'          => ['DATE', 'DAY_OF_THE_WEEK', 'MONTH_TEXT', 'TIME'],
    'VARIABLE_PRESENTATION_SWITCH'             => [
        'GLOW_COLOR', 'GLOW_INTENSITY', 'ICON_FALSE', 'ICON_TRUE', 'USAGE_TYPE', 'USE_ICON_FALSE',
    ],
    'VARIABLE_PRESENTATION_SHUTTER'            => [
        'CLOSE_INSIDE_VALUE', 'MAX_ROTATION_INSIDE', 'MAX_ROTATION_OUTSIDE', 'OPEN_OUTSIDE_VALUE', 'SUN_POSITION',
        'USAGE_TYPE',
    ],
    'VARIABLE_PRESENTATION_ENUMERATION'        => ['DISPLAY', 'ICON', 'LAYOUT', 'OPTIONS'],
    'VARIABLE_PRESENTATION_PLAYBACK'           => [],
    'VARIABLE_PRESENTATION_DURATION'           => ['COUNTDOWN_TYPE', 'FORMAT', 'MILLISECONDS'],
    'VARIABLE_PRESENTATION_TEXT_BOX'           => [],
];

const PRESENTATION_GUIDS = [
    '{4153A8D4-5C33-C65F-C1F3-7B61AAF99B1C}' => 'VARIABLE_PRESENTATION_LEGACY',
    '{3319437D-7CDE-699D-750A-3C6A3841FA75}' => 'VARIABLE_PRESENTATION_VALUE_PRESENTATION',
    '{6F477326-1683-A2FD-D2E7-477F366ECB62}' => 'VARIABLE_PRESENTATION_VALUE_INPUT',
    '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}' => 'VARIABLE_PRESENTATION_SLIDER',
    '{9DE1D610-5106-97FB-714D-1AADEDF8377A}' => 'VARIABLE_PRESENTATION_WEB_CONTENT',
    '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}' => 'VARIABLE_PRESENTATION_COLOR',
    '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}' => 'VARIABLE_PRESENTATION_DATE_TIME',
    '{60AE6B26-B3E2-BDB1-A3A1-BE232940664B}' => 'VARIABLE_PRESENTATION_SWITCH',
    '{6075FC22-69AF-B110-3749-C24138883082}' => 'VARIABLE_PRESENTATION_SHUTTER',
    '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}' => 'VARIABLE_PRESENTATION_ENUMERATION',
    '{2F0FF5B0-FC86-117B-DDAA-2D2D33C3F8AC}' => 'VARIABLE_PRESENTATION_PLAYBACK',
    '{08A6AF76-394E-D354-48D5-BFC690488E4E}' => 'VARIABLE_PRESENTATION_DURATION',
    '{56696857-92B2-1780-16B8-EB6F09D4AEF7}' => 'VARIABLE_PRESENTATION_TEXT_BOX',
];

$root  = dirname(__DIR__);
$files = [];
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_ends_with($path, '.php') && !preg_match('#/(\.git|\.style|tests|vendor|node_modules)/#', substr($path, strlen($root)))) {
        $files[$path] = token_get_all(file_get_contents($path));
    }
}

/** Liefert den Index des nächsten Tokens, das kein Leerraum/Kommentar ist. */
function nextToken(array $tokens, int $i): int
{
    $n = count($tokens);
    while ($i < $n && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $i++;
    }
    return $i;
}

function tokenText(mixed $token): string
{
    return is_array($token) ? $token[1] : $token;
}

// Klassenkonstanten der Form `const [string] PRESENTATION = VARIABLE_PRESENTATION_X;` einsammeln
$classConstants = [];
foreach ($files as $tokens) {
    $className = null;
    $n         = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            $j = nextToken($tokens, $i + 1);
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                $className = $tokens[$j][1];
            }
        }
        if ($className !== null && is_array($tokens[$i]) && $tokens[$i][0] === T_CONST) {
            $name = '';
            $value = '';
            for ($j = $i + 1; $j < $n && tokenText($tokens[$j]) !== ';'; $j++) {
                if (tokenText($tokens[$j]) === '=') {
                    for ($k = $j + 1; $k < $n && tokenText($tokens[$k]) !== ';'; $k++) {
                        $value .= trim(tokenText($tokens[$k]));
                    }
                    break;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = $tokens[$j][1];
                }
            }
            $classConstants[$className . '::' . $name] = trim($value, "\\'\"");
        }
    }
}

/** Löst einen Ausdruck zu einer Liste von Darstellungs-Konstantennamen auf; null = nicht auflösbar. */
function resolvePresentations(string $expression, array $classConstants): ?array
{
    $candidates = str_contains($expression, '?')
        ? preg_split('/[?:](?!:)/', preg_replace('/^[^?]*\?/', '', $expression))
        : [$expression];
    $result = [];
    foreach ($candidates as $candidate) {
        $candidate = trim($candidate, " \t\n\r\\'\"");
        if (preg_match('/^\\\\?([A-Za-z_]\w*)::(\w+)$/', $candidate, $m)) {
            $candidate = $classConstants[$m[1] . '::' . $m[2]] ?? $candidate;
        }
        if (isset(PRESENTATION_GUIDS[strtoupper($candidate)])) {
            $candidate = PRESENTATION_GUIDS[strtoupper($candidate)];
        }
        if (!isset(PRESENTATION_PARAMETERS[$candidate])) {
            return null;
        }
        $result[] = $candidate;
    }
    return array_values(array_unique($result));
}

$checked  = 0;
$errors   = 0;
$warnings = 0;
foreach ($files as $path => $tokens) {
    $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
    $n        = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || trim($token[1], "'\"") !== 'PRESENTATION') {
            continue;
        }
        $arrow = nextToken($tokens, $i + 1);
        if (!is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_DOUBLE_ARROW) {
            continue;
        }

        // Wert bis zum Komma bzw. zur schließenden Klammer auf gleicher Ebene
        $expression = '';
        $depth      = 0;
        for ($k = $arrow + 1; $k < $n; $k++) {
            $text = tokenText($tokens[$k]);
            if (in_array($text, ['[', '(', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [']', ')', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                break;
            }
            $expression .= trim($text);
        }

        // öffnende Klammer des umgebenden Arrays
        $depth = 0;
        for ($start = $i - 1; $start >= 0; $start--) {
            $text = tokenText($tokens[$start]);
            if ($text === ']' || $text === ')') {
                $depth++;
            } elseif ($text === '[' || $text === '(') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
        }

        // Schlüssel auf derselben Ebene
        $keys  = [];
        $depth = 0;
        for ($e = $start + 1; $e < $n; $e++) {
            $text = tokenText($tokens[$e]);
            if ($text === '[' || $text === '(') {
                $depth++;
            } elseif ($text === ']' || $text === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && is_array($tokens[$e]) && $tokens[$e][0] === T_CONSTANT_ENCAPSED_STRING) {
                $after = nextToken($tokens, $e + 1);
                if (is_array($tokens[$after]) && $tokens[$after][0] === T_DOUBLE_ARROW) {
                    $keys[] = trim($tokens[$e][1], "'\"");
                }
            }
        }

        $checked++;
        $presentations = resolvePresentations($expression, $classConstants);
        if ($presentations === null) {
            printf("HINWEIS %s:%d: Darstellung nicht auflösbar (%s)\n", $relative, $token[2], $expression);
            $warnings++;
            continue;
        }
        foreach ($presentations as $presentation) {
            $unknown = array_diff($keys, ['PRESENTATION'], PRESENTATION_PARAMETERS[$presentation]);
            if ($unknown !== []) {
                printf("FEHLER %s:%d (%s): Parameter gibt es nicht: %s\n", $relative, $token[2], $presentation, implode(', ', $unknown));
                $errors++;
            }
        }
    }
}

printf("%d Darstellungen geprüft, %d fehlerhaft, %d nicht auflösbar\n", $checked, $errors, $warnings);
exit($errors > 0 ? 1 : 0);

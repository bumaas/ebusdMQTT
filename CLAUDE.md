# ebusdMQTT — Projekt-Hinweise

Symcon-Modul zur Anbindung von [ebusd](https://github.com/john30/ebusd) (eBUS-Daemon für
Heizungs-/Lüftungs-/Solaranlagen, z. B. Vaillant) über den Symcon-eigenen MQTT Server.

## Struktur

- `ebusdMQTTDevice/module.php` — einziges Modul (`ebusdMQTTDevice extends IPSModuleStrict`);
  Prefix `EBM`, Parent ist der MQTT Server (TX-GUID `{043EA491-…}`)
- `libs/eBUS_MQTT_Helper.php` — Trait `ebusd2MQTTHelper`: eBUS-Datentyp-Definitionen,
  HTTP-Zugriff auf die ebusd-REST-Schnittstelle (`readURL`), Ident-/Label-Ableitung,
  Payload-Aufbau, Debug-Logging
- `ebusdMQTTDevice/form.json` — Konfigurationsformular; englische Captions dienen als
  Übersetzungsschlüssel
- `ebusdMQTTDevice/locale.json` — deutsche Übersetzungen (Schlüssel müssen exakt den
  form.json-Texten entsprechen; Prüfung: `php tests/check_locale.php`)
- `library.json` (Repo-Wurzel) — Version, Build, Datum (Build-Konvention siehe globale CLAUDE.md)

## Funktionsweise (Kurzfassung)

- Die Konfiguration (verfügbare Meldungen je Schaltkreis) wird per HTTP von der
  ebusd-REST-Schnittstelle gelesen (`ReadConfiguration` → `http://<Host>:<Port>/data/…`);
  der Anwender wählt in der Formularliste (`VariableList`-Attribut) die einzubindenden
  Meldungen und Poll-Prioritäten aus.
- Laufende Werte kommen per MQTT (`ebusd/<circuit>/<message>`) über `ReceiveData` herein;
  Schreiben geht per Publish auf `…/set`. Poll-Prioritäten werden ebenfalls per MQTT
  publiziert.
- Statusvariablen werden mit modernen Presentations angelegt
  (`VARIABLE_PRESENTATION_*`, siehe `RegisterVariablesOfMessage`); Min/Max/Digits/Suffix
  kommen aus den eBUS-Datentyp-Definitionen des Traits.
- Zwei Timer: `requestAllValues` (Update-Intervall) und `checkConnection`
  (Signal-Überwachung, Instanzstatus).

## Tests

- `php tests/check_locale.php` — Übersetzungs-Vollständigkeit (form.json, module.php,
  `libs/*.php`)
- `php tests/golden_regression.php` — Golden-File-Regressionstests der Verarbeitungskette
  (read/write-Ableitung, Formularliste, Variablen-Registrierung samt Presentations,
  Werte-Dekodierung, Publish-Payloads) auf Basis echter ebusd-REST-Fixtures
  (`tests/fixtures/`, Schaltkreise hmu = Wärmepumpe, 700 = Regler VRC700).
  Läuft ohne Netz auf dem Kernel-Stub (siehe unten). Bei **beabsichtigten**
  Verhaltensänderungen: `--update` und den Golden-Diff im Commit reviewen.
  Neue Fixtures einfangen: `http://<ebusd-host>:8081/data/<circuit>/?def&verbose&exact&write`.
- `php tests/check_presentations.php` — jede Darstellung im Quelltext setzt nur Parameter,
  die es in dieser Darstellung gibt (Symcon 9.1 validiert das selbst und wirft sonst
  Fehler); Parameterlisten aus `IPS_GetPresentation` vom 16.09.2026.
- `php tests/check_circuit_options.php` — Knöpfe „Ermittle Schaltkreisnamen" und „Lese
  Konfiguration aus": fragen die eingetippte Adresse ab und laufen ohne aktiven MQTT-Parent
  (Anlass Forum `t/51854/459`; Fixture `tests/fixtures/data_all.json`, echte
  `/data`-Antwort). Läuft ebenfalls auf dem Kernel-Stub.
- `php tests/check-status-log.php` — Instanzstatus und Log-Klartexte (MCP-Tauglichkeit,
  Regeln 3/4/16): eigener Code je Störung (205 nicht erreichbar, 206 kein Signal,
  207 kein Schaltkreis), genau eine Warnung je Wechsel in einen Fehler, eine Meldung bei
  Behebung, kein Rauschen bei gleichbleibendem Zustand (`applyStatus`). Die CI führt alle
  `tests/check-*.php` aus.
- `php tests/check-request-action.php` — RequestAction meldet jeden Fehlschlag per
  `trigger_error` und publiziert dann nichts (Regel 8): Wert außerhalb Wertetabelle/Bereich,
  nur lesbar, Mehrfeld-Meldung (→ `EBM_publish`), unbekannter Ident, Parent inaktiv (interne
  Timer-Idents bleiben still). Dazu: Aktionen nur an schreibbaren Einfeld-Meldungen
  (`MaintainAction` bei der Registrierung) und `UpdateInterval` < 0 → Status 208. Bei `--update` der Golden-Dateien: Registrierung
  zeichnet `MaintainAction` statt `EnableAction` auf.
- `php tests/check-script-api.php` — Skript-API für alles hinter den Formular-Knöpfen
  (Regeln 5/7/9/15): `EBM_RunSelfTest` (Text, nachweislich ohne Wirkung), `EBM_FindMessages`
  (JSON, Suchfilter; `GetMessageList` ist als Kernel-Methode belegt), `EBM_UpdateConfiguration`,
  `EBM_SetMessageActive` (ausschalten lässt Variablen stehen), `EBM_ReadMessageValues`
  (höchstens 20 Meldungen). Knöpfe und Funktionen teilen sich die Logik
  (`ReadConfiguration` liefert bei Fehlschlag den Grund als Text).
- `php tests/check-hints-debug.php` — Regel 6: jede öffentliche Funktion hat genau einen
  unsichtbaren Formular-Hinweis (`visible: false`, am Ende von `actions`) mit Signatur und
  Rückgabetyp; Regel 1: sichtbares Label zu „Aktiv“/Poll-Priorität; Regel 10: keine
  Debug-Zeile ≥ 1000 Zeichen (auch nicht beim Empfang einer unbekannten Meldung), ein Publish = eine Zeile; Selbsttest behauptet bei Störung
  keine laufende Abfrage. **Neue öffentliche Funktion ⇒ Hinweis in `form.json` +
  `locale.json` nachtragen.** `actions[1]` (Liste) und `actions[2]` (Knöpfe) nicht
  verschieben — `GetConfigurationForm` greift per Index zu.
- `php tests/check-legacy.php` — Regel 14: schreibbare Zahlen ohne Wertetabelle bekommen
  einen Schieberegler nur bei ≤ 1000 Schritten im Typbereich (`MAX_SLIDER_STEPS`), sonst ein
  Eingabefeld mit Einheit (EXP ±3·10³⁸, UIN 0…65534 waren unbedienbar); der Selbsttest nennt
  Variablen ohne Meldung in der Konfiguration und gleich benannte Variablen, ohne etwas
  umzubenennen oder zu löschen. Erhebung am nuc 03.10.2026: die „Legacy-Darstellungen“ sind
  fast alle **eigene** Darstellungen des Anwenders (`EBM.*_my`) über moderner
  Modul-Darstellung — das Modul fasst `VariableCustomPresentation` nie an.
- `php tests/check-blindtest-findings.php` — Befunde des Blindtests vom 04.10.2026 (frischer
  Agent nur über MCP, Bericht `E:\Desktop\Smart Home\Eigenes\nuc\checks\2026-10-04_ebusdmqtt-blindtest-ergebnis.md`):
  Selbsttest prüft Eigenschaften vor der HTTP-Abfrage (`getPropertyError`, gemeinsam mit
  dem Instanzstatus) und sagt „noch kein Wert“ statt 01.01.1970; `EBM_SetMessageActive`
  fordert den Wert gleich an; unbekannte Meldung bei leerer Konfiguration kein Log, sonst
  eine Warnung je Meldung (Buffer `ReportedUnknownMessages`, beim Einlesen geleert);
  `EBM_FindMessages` mit `fields` (Typ, Einheit, `values`, `min`/`max` nur bei ≤ 1000
  Schritten, `variableID`), `EBM_ReadMessageValues` mit `lastUpdate`.
- `php tests/check-review-findings.php` — Befunde aus dem Code-Review vom 04.10.2026:
  leerer Schaltkreis bleibt bei jeder Verbindungsprüfung 207 (steht in `getPropertyError`,
  keine Abfrage von `/data/`); `EBM_FindMessages` meldet einen eBUS-Typ außerhalb der
  Typtabelle als `unknown` statt mit TypeError abzubrechen; ganzzahlige Typen ohne Divisor
  lehnen Kommazahlen ab; UCH reicht bis 254 (0xFF = Ersatzwert, ebusd-Wiki 4.3); Topics mit
  Unterpfad (`…/<msg>/set`, `…/get`) werden still übersprungen; `EBM_ReadMessageValues` prüft
  erst die Eigenschaften und bricht wie der Knopf „Lese aktuelle Werte“ bei der ersten fehlenden
  HTTP-Antwort mit Warnung ab (`getCurrentValueAndTime` liefert dann `null`, „antwortet ohne
  Wert“ dagegen `[null, 0]` — echter Mitschnitt `fixtures/value_700_errorhistory.json`);
  „Lese Konfiguration aus“ ist in jedem Status bedienbar (nur HTTP), die übrigen Knöpfe nur
  bei 102 (`GetConfigurationForm` und `SetStatus`). **Zeitstempel aus `VariableUpdated` nie
  ungeprüft formatieren** — 0 heißt „noch kein Wert“, nicht 01.01.1970 (Selbsttest, beide Stellen).
  „Verbindung funktioniert wieder“ (`applyStatus`) nur nach Betriebsstörungen 104/203/205/206/209,
  nicht nach Eingabe-/Einrichtungsfehlern 202/204/207/208.
- `php tests/check-mqtt-rueckweg.php` — Status 209, wenn ebusd per HTTP antwortet, per MQTT aber
  nichts zurückkommt (Verdacht aus der PN froema, Forum t/144582, 08.10.2026; dort war die Ursache am
  Ende der `SetValue`-Override, siehe unten, die Erkennung bleibt trotzdem sinnvoll). ebusd sendet
  `ebusd/global/uptime` alle ~15 s, 209 heißt also „gar nichts von ebusd per MQTT“. Buffer `MqttReplyState`: `requestAllValues` setzt `pending`,
  findet die nächste Runde noch `pending`, wird es `silent` → 209 mit einer Warnung. Jede Meldung
  von ebusd (Wert oder `ebusd/global/…`) setzt zurück und stellt 102 her; das eigene Echo `…/get`
  zählt nicht. In 209 läuft das Intervall weiter (sonst käme nie wieder eine Antwort),
  `updateInstanceStatus` bleibt bei 209, solange `silent`. Bei Intervall 0 gibt es keine Erkennung.
- `php tests/check-setvalue-update.php` — ein unveränderter Wert von ebusd rückt `VariableUpdated`
  vor, `VariableChanged` bleibt. Das Modul hat **keinen eigenen `SetValue`-Override** mehr: Der alte
  übersprang gleiche Werte, die Variablen sahen dann aus wie stehengeblieben (Anlass PN froema,
  t/144582, 08.10.2026; die Werte kamen per MQTT, sie änderten sich nur nicht).
- **Wertebereich eines Zahlenfeldes nur über `getValueRange()`** (Trait): Schreibprüfung,
  `EBM_FindMessages` und Darstellung nutzen dieselbe Berechnung; die Grenze
  `MAX_SLIDER_STEPS` wenden nur FindMessages und Darstellung an, die Schreibprüfung nie.
  `getPayload()` bekommt die Felddefinition von `getWritableMessage()`, dekodiert also nicht neu. **Die Typtabelle im Trait ist
  zugleich Schreibprüfung** — Grenzen dort aus dem ebusd-Wiki übernehmen, nicht schätzen.
- **Kernel-Stub:** offizieller `symcon/SymconStubs` als Submodul `tests/stubs`, gepinnt auf
  `bf2950f` (nie `submodule update --remote`; nach dem Klonen `git submodule update --init`).
  `tests/harness.php` bindet das Modul daran (`neueInstanz()`), zeichnet
  `MaintainVariable`/`EnableAction`/`SetValue`/`SetStatus`/`LogMessage`/`SendDataToParent`
  auf und ersetzt die Außenverbindungen: HTTP (`readURL` → `$responses`) und den
  MQTT-Parent (`HasActiveParent` → `$parentActive`, `SendDataToParent` nur aufgezeichnet).

## Texte pflegen

Bei Änderungen an Formulartexten immer synchron halten:

1. `form.json` — englischer Text (zugleich Übersetzungsschlüssel)
2. `locale.json` — deutscher Text unter exakt diesem Schlüssel
3. `README.md` — falls die Stelle dort ebenfalls dokumentiert ist

CI (`.github/workflows/check.yml`, PHP 8.4) prüft PHP-Syntax, Code-Stil (php-cs-fixer
`--dry-run` gegen das Regelwerk im Submodul `.style` = `bumaas/SymconStylePHP`, gepinnt;
lokal per `modul_build.php`), JSON-Validität,
Übersetzungs-Vollständigkeit, Darstellungsparameter (`check_presentations.php`), die
Golden-Regressionstests und die Schaltkreis-Auswahl (`check_circuit_options.php`).

## Support-Kontext

Fehlerberichte kommen aus dem Symcon-Forum. Fixes gehen als Beta über `master` raus
(Store-Konvention siehe globale CLAUDE.md); Antworttexte fürs Forum auf Deutsch.

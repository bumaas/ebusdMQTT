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
  Läuft ohne Kernel/Netz (`tests/symcon_stubs.php`). Bei **beabsichtigten**
  Verhaltensänderungen: `--update` und den Golden-Diff im Commit reviewen.
  Neue Fixtures einfangen: `http://<ebusd-host>:8081/data/<circuit>/?def&verbose&exact&write`.
- `php tests/check_presentations.php` — jede Darstellung im Quelltext setzt nur Parameter,
  die es in dieser Darstellung gibt (Symcon 9.1 validiert das selbst und wirft sonst
  Fehler); Parameterlisten aus `IPS_GetPresentation` vom 16.09.2026.
- `php tests/check_circuit_options.php` — Knöpfe „Ermittle Schaltkreisnamen" und „Lese
  Konfiguration aus": fragen die eingetippte Adresse ab und laufen ohne aktiven MQTT-Parent
  (Anlass Forum `t/51854/459`; Fixture `tests/fixtures/data_all.json`, echte
  `/data`-Antwort). Läuft ebenfalls auf `tests/symcon_stubs.php`.

## Texte pflegen

Bei Änderungen an Formulartexten immer synchron halten:

1. `form.json` — englischer Text (zugleich Übersetzungsschlüssel)
2. `locale.json` — deutscher Text unter exakt diesem Schlüssel
3. `README.md` — falls die Stelle dort ebenfalls dokumentiert ist

CI (`.github/workflows/check.yml`, PHP 8.4) prüft PHP-Syntax, JSON-Validität,
Übersetzungs-Vollständigkeit, Darstellungsparameter (`check_presentations.php`), die
Golden-Regressionstests und die Schaltkreis-Auswahl (`check_circuit_options.php`).

## Support-Kontext

Fehlerberichte kommen aus dem Symcon-Forum. Fixes gehen als Beta über `master` raus
(Store-Konvention siehe globale CLAUDE.md); Antworttexte fürs Forum auf Deutsch.

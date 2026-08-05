# ebusdMQTT — Projekt-Hinweise

IP-Symcon-Modul zur Anbindung von [ebusd](https://github.com/john30/ebusd) (eBUS-Daemon für
Heizungs-/Lüftungs-/Solaranlagen, z. B. Vaillant) über den IPS-eigenen MQTT Server.

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

## Bekannte Altlasten / Kandidaten

- Die `RegisterProfile*`-Funktionen im Trait sind **tot** (kein Aufruf aus `module.php`,
  Presentations sind längst umgesetzt) — bei Gelegenheit entfernen.
- Meldungstexte an den Anwender (`MsgBox('… Werte gelesen')` u. a.) stehen **deutsch und
  ohne `Translate()`** direkt im PHP-Code; sauber wäre englischer Text + `Translate` +
  locale-Schlüssel. Bei Umstellung `tests/check_locale.php` erneut laufen lassen
  (es sammelt `Translate('…')`-Texte automatisch ein).

## Texte pflegen

Bei Änderungen an Formulartexten immer synchron halten:

1. `form.json` — englischer Text (zugleich Übersetzungsschlüssel)
2. `locale.json` — deutscher Text unter exakt diesem Schlüssel
3. `README.md` — falls die Stelle dort ebenfalls dokumentiert ist

CI (`.github/workflows/check.yml`) prüft PHP-Syntax, JSON-Validität und die
Übersetzungs-Vollständigkeit.

## Support-Kontext

Fehlerberichte kommen aus dem Symcon-Forum. Fixes gehen als Beta über `master` raus
(Store-Konvention siehe globale CLAUDE.md); Antworttexte fürs Forum auf Deutsch.

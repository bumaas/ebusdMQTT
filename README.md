[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-5.3%20%3E-blue.svg)
[![Donate](https://img.shields.io/badge/Donate-Paypal-009cde.svg)](https://www.paypal.me/bumaas)
[![Checks](https://github.com/bumaas/ebusdMQTT/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/ebusdMQTT/actions/workflows/check.yml)


# ebusdMQTT
   Anbindung von https://github.com/john30/ebusd an Symcon.
 
   ## Inhaltverzeichnis
   1. [Funktionsumfang](#1-funktionsumfang)
   2. [Voraussetzungen](#2-voraussetzungen)
   3. [Installation](#3-installation)
   4. [Konfiguration](#4-konfiguration)
   5. [Einbindung ins Webfront](#5-einbindung-ins-webfront)
   6. [Schreiben von Werten](#6-schreiben-von-werten)
   7. [Funktionsreferenz](#7-funktionsreferenz)
   8. [Anhang](#8-anhang)
    
## 1. Funktionsumfang

Das Modul dient zur Einbindung von eBUS Geräten in Symcon. eBUS ('Energie Bus') ist ein Bussystem, das von verschiedenen Herstellern von Heizungs-, Lüftungs- und Solaranlagen genutzt wird.

Die Anbindung erfolgt über den Kommunikationsdienst **ebusd** in Verbindung mit einem [geeigneten Hardwareadapter](https://github.com/john30/ebusd/wiki/6.-Hardware).

Über das Modul werden die von ebusd zur Verfügung gestellten Parameter zum Auslesen und Schreiben in Symcon als Statusvariablen eingebunden. Die Auswahl der einzubindenden Parameter wird vom Anwender festgelegt.

  

 
## 2. Voraussetzungen

* Hardware Adapter zur Verbindung mit dem eBUS
* lauffähiger eBUS Daemon (ebusd (ab V3.4)) mit entsprechender Hardwareanbindung (siehe auch [Installationskurzanleitung ebusd](docs/de/InstallEbusdREADME.md))
* mindestens IPS Version 5.3
* MQTT Server (IPS built-in Modul) 


## 3. Installation
Füge im "Module Control" (Kern Instanzen->Modules) die URL 
```
https://github.com/bumaas/ebusdMQTT.git
```
hinzu.

Danach ist es möglich ein neues _ebusd MQTT Device_ zu erstellen:<br><br>
![Instanz erstellen](imgs/InstanzErstellen.png?raw=true "Instanz erstellen")
<br><br>Falls noch keine übergeordnete MQTT Server Instanz existiert, wird automatisch eine angelegt:<br><br>
![MQTT Server Instanz erstellen](imgs/InstanzErstellenMQTTServer.png?raw=true "MQTT Server Instanz erstellen")
<br><br>Auch eine Server Socket Instanz wird automatisch angelegt, wenn noch keine existiert:<br><br>
![MQTT Server Instanz erstellen](imgs/InstanzErstellenServerSocket.png?raw=true "Server Socket Instanz erstellen")
<br><br>

## 4. Konfiguration
Für jedes erkannte Gerät/Schaltkreis muss eine Instanz angelegt werden..<br><br>
![Instanz konfigurieren](imgs/InstanzKonfigurieren.png?raw=true "Instanz konfigurieren")
-  Host:<br>
Adresse unter der der ebusd Dienst erreichbar ist. Hierbei kann es sich um eine IP Adresse oder einen Hostnamen handeln.  

- Port:<br>
Portnummer auf dem der ebusd Dienst http-Anfragen entgegennimmt.

- Schaltkreis Name:<br>
Der Name des Schaltkreises unter dem das Gerät in ebusd geführt wird ('Circuit'). Beispiele sind 'bai', '700' etc. Über den Button "Ermittle Schaltkreis Namen" wird die Auswahl der zur verfügung stehenden Schaltkreise ermittelt.

- Aktualisierungsintervall:<br>
Intervall in Minuten, in dem alle Statusvariablen durch Anfragen an den eBUS aktualisiert werden (0 = keine Aktualisierung, negative Werte sind unzulässig). Je nach Anzahl der Statusvariablen kann die Abfrage den eBUS erheblich belasten. Das Intervall sollte nicht zu klein gewählt werden.

- Debug Informationen werden zusätzlich in das Logfile der IPSLibrary geschrieben:<br>
Schreibt die Debug-Meldungen des Moduls zusätzlich in das Logfile der IPSLibrary (`IPSLogger`). Nur wirksam, wenn die IPSLibrary installiert ist; sonst ohne Funktion. Für die Fehlersuche genügt in der Regel die Debug-Ausgabe der Instanz.

Nachdem die Einstellungen gespeichert wurden, kann im Aktionsbereich die Konfiguration gelesen werden und die anzulegenden Statusvariablen können ausgewählt werden. „Lese Konfiguration aus“ braucht nur die HTTP-Verbindung zu ebusd und ist deshalb auch bedienbar, wenn der MQTT Server noch nicht aktiv ist oder ebusd kein eBUS-Signal meldet; „Lese aktuelle Werte“ und „Speichere Änderungen“ erst bei aktiver Instanz.
In der Liste der Statusvariablen markiert ein **(A)** hinter dem Ident, dass für diese Variable die Archivierung im Query-Logger (Archive Handler) aktiv ist.

Das Modul überwacht zudem die Verbindung zu ebusd und dessen globales Signal und prüft sie bei einer Störung automatisch erneut. Jede Störung hat einen eigenen Instanzstatus:

| Status | Bedeutung | Was tun |
|---|---|---|
| 104 | Der MQTT Server (übergeordnete Instanz) ist nicht aktiv | MQTT-Server-Instanz prüfen |
| 202 | Port ungültig | Port korrigieren (1 bis 65535) |
| 203 | Schaltkreis ungültig oder bei ebusd nicht vorhanden | mit „Ermittle Schaltkreis Namen“ neu auswählen |
| 204 | Host ungültig | Host korrigieren |
| 205 | ebusd ist nicht erreichbar | Host, Port und HTTP-Port von ebusd (`--httpport`) prüfen |
| 206 | ebusd meldet kein eBUS-Signal (z. B. Adapter getrennt) | eBUS-Adapter und Verbindung zum Bus prüfen |
| 207 | Kein Schaltkreis ausgewählt | Schaltkreis auswählen |
| 208 | Aktualisierungsintervall ungültig (negativ) | 0 (aus) oder eine Anzahl Minuten eintragen |

Jeder Wechsel in eine Störung steht einmal als Warnung mit Ursache und nächstem Schritt im Meldungsprotokoll, die Behebung einmal als Meldung. Eine anhaltende Störung wiederholt sich dort nicht.

Bei Bedarf kann für eine Statusvariable eine Poll Priorität angegeben werden, die von ebusd verwendet werden soll. Die Poll Prioriät besagt, in welchem Intervallzyklus eine Meldung von ebusd gepollt werden soll.
Meldungen mit Priorität 1 werden in jedem Pollzyklus abgefragt, Meldungen mit Priorität 2 werden in jedem zweiten Zyklus abgefragt usw.. Die Pollpriorität kann gesetzt werden, wenn das Abfrageintervall, das im Minutenbereich liegt, für einzelne Meldungen nicht fein genug ist.

## 5. Einbindung ins Webfront
Alle Statusvariablen sind für eine Anzeige und (sofern vom ebusd ein Schreiben unterstützt wird) zum Ändern im Webfront vorbereitet. Sie haben alle eine Darstellung, die der ebusd Definition entspricht.
Zur Verwendung in der Visu sollten sie jedoch überprüft werden. ebusd liefert keine fachlichen Grenzen, nur den technischen Bereich des eBUS-Datentyps. Schreibbare Zahlen bekommen deshalb nur dann einen Schieberegler, wenn dieser Bereich überschaubar ist (höchstens 1000 Schritte, z. B. 0 bis 100 %); sonst – etwa bei Temperaturen im Typ EXP mit ±3·10³⁸ – ein Eingabefeld mit Einheit. Wer einen Schieberegler mit anlagenspezifischen Grenzen möchte, legt dafür eine eigene Darstellung an; das Modul überschreibt sie nicht.

Der Selbsttest (`EBM_RunSelfTest`) nennt außerdem Variablen, zu denen es in der ebusd-Konfiguration keine Meldung mehr gibt (vermutlich veraltet), und gleich benannte Variablen – ebusd beschreibt manche Werte in zwei Meldungen gleich. Das Modul benennt dabei nichts um und löscht nichts.

Besonderheit:

Schreibbare Mehrfachfelder können nicht direkt aus der Visu heraus geändert werden. Sie lassen sich aber über EBM_publish schreiben.

Beispiel:
```php
EBM_publish(47111, 'ebusd/700/hwctimer.monday/set', '07:00;22:00;00:00;00:00;00:00;00:00');
```
 

## 6. Schreiben von Werten
Sofern die Statusvariablen ein Schreiben zulassen, können die Werte direkt über das Webfront oder per Skript über [RequestAction](https://www.symcon.de/service/dokumentation/befehlsreferenz/variablenzugriff/requestaction/) verändert werden.

Schaltbar sind nur Variablen, deren Meldung ebusd schreiben lässt und die aus einem einzigen Feld besteht. Ein Schreibwunsch wird vor dem Senden geprüft und sonst mit einer Fehlermeldung abgelehnt, die Grund und nächsten Schritt nennt — es wird dann nichts auf den eBUS geschickt:

- Wert außerhalb der Wertetabelle oder des Wertebereichs (die erlaubten Werte stehen in der Meldung),
- nur lesbare Meldung,
- Feld einer Meldung mit mehreren Feldern (diese schreibt man als Ganzes mit `EBM_publish`),
- unbekannter Ident,
- MQTT Server (übergeordnete Instanz) nicht aktiv.

## 7. Funktionsreferenz

Alles, was im Formular über Knöpfe geht, geht auch per Skript. Fehlschläge kommen als Warnung mit Grund und nächstem Schritt.

```php
EBM_RunSelfTest(int $InstanceID): string
```
Prüft ohne jede Wirkung auf die Instanz, ob alles funktioniert: MQTT Server aktiv, ebusd erreichbar, eBUS-Signal, Schaltkreis vorhanden, Konfiguration eingelesen, aktive Meldungen, letzte Aktualisierung. Liefert einen Text, jede Störung mit dem nächsten Schritt.

```php
EBM_FindMessages(int $InstanceID, string $search): string
```
Liefert die Meldungen des Schaltkreises als JSON – dieselbe Information wie die Liste im Formular: Meldung, lesbar, schreibbar, aktiv, Poll-Priorität und je Feld Ident, Bezeichnung, Typ, Einheit, erlaubte Werte (`values`) bzw. Bereich (`min`/`max`, nur wo er sinnvoll ist) und die ID der Variable, falls sie schon angelegt ist. `$search` filtert nach Meldungsname oder Bezeichnung (Groß-/Kleinschreibung egal), `''` liefert alle.

```php
EBM_UpdateConfiguration(int $InstanceID): string
```
Liest die Konfiguration des Schaltkreises von ebusd und speichert sie (wie der Knopf „Lese Konfiguration aus“). Liefert die Anzahl der gelesenen Meldungen.

```php
EBM_SetMessageActive(int $InstanceID, string $messageName, bool $active, int $pollPriority): string
```
Bindet eine Meldung ein (`true`: Variablen anlegen, Auswahl speichern, aktuellen Wert gleich bei ebusd anfordern) oder aus (`false`) – wie das Häkchen „Aktiv“ plus „Variablen anlegen/aktualisieren“. Ausgeschaltet bleiben die Variablen samt Archivdaten erhalten; das Modul fragt sie nur nicht mehr ab. `$pollPriority` 0 bis 9 (0 = keine eigene Poll-Priorität) wird an ebusd gesendet, wenn sie sich ändert.

```php
EBM_ReadMessageValues(int $InstanceID, string $search): string
```
Liest die aktuellen Werte der lesbaren Meldungen, die zu `$search` passen, bei ebusd (wie der Knopf „Lese aktuelle Werte“) und liefert sie als JSON: Meldung → `value` (Werte durch „/“ getrennt, `null` = kein Wert) und `lastUpdate` (Zeitpunkt der letzten Aktualisierung bei ebusd, `null` = nie). Weil ebusd dafür den Bus abfragen kann, höchstens 20 Meldungen je Aufruf. Antwortet ebusd nicht, bricht die Funktion bei der ersten Meldung mit einer Warnung ab, die die abgefragte URL nennt, und liefert einen leeren Text; ebenso der Knopf „Lese aktuelle Werte“, der dann die Liste unverändert lässt.

Beispiel: Vorlauftemperatur einbinden
```php
$id = 12345; // Instanz-ID des „ebusd MQTT Device“
echo EBM_FindMessages($id, 'Vorlauf');           // Meldungsnamen suchen, z. B. Hc1FlowTemp
echo EBM_ReadMessageValues($id, 'Hc1FlowTemp');  // aktuellen Wert ansehen
echo EBM_SetMessageActive($id, 'Hc1FlowTemp', true, 0);
```

```php
EBM_publish(int $InstanceID, string $topic, string $payload): void
```
Published den Wert $payload zum $topic. Kann für "Sonderthemen" genutzt werden, siehe [MQTT client Beschreibung](https://github.com/john30/ebusd/wiki/3.3.-MQTT-client).
Ein Beispiel zum Schreiben eines Topics:
``` php
// der Wert des Parameters 'FlowsetHCMax' des Schaltkreises 'bai' wird auf 75 gesetzt

$InstanceID = 12345;                                  // die Instanz ID des "ebusd MQTT Device"
$topic      = 'ebusd/<Schaltkreis>/<Parameter>/set';  // <Schaltkreis> und <Parameter> sind entsprechend zu ersetzen ('ebusd/bai/FlowsetHCMax')
$payload    = '75';                                   // der Wert ist als String zu übergeben

EBM_publish($InstanceID, $topic, $payload);
```

## 8. Anhang

###  GUIDs der Module

|                Modul                |     Typ      |                  GUID                  |
| :---------------------------------: | :----------: | :------------------------------------: |
|        ebusd MQTT Device         |    Device    | {0A243F27-C31D-A389-5357-B8D000901D78} |

### Spenden  
  
  Die Nutzung des Moduls ist kostenfrei. Niemand sollte sich verpflichtet fühlen, aber wenn das Modul gefällt, dann freue ich mich über eine Spende.

<a href="https://www.paypal.me/bumaas" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a>

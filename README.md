[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-5.3%20%3E-blue.svg)
[![Donate](https://img.shields.io/badge/Donate-Paypal-009cde.svg)](https://www.paypal.me/bumaas)


# ebusdMQTT
   Anbindung von https://github.com/john30/ebusd an IP-Symcon.
 
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

Das Modul dient zur Einbindung von eBUS Geräten in IP-Symcon. eBUS ('Energie Bus') ist ein Bussystem, das von verschiedenen Herstellern von Heizungs-, Lüftungs- und Solaranlagen genutzt wird.

Die Anbindung erfolgt über den Kommunikationsdienst **ebusd** in Verbindung mit einem [geeigneten Hardwareadapter](https://github.com/john30/ebusd/wiki/6.-Hardware).

Über das Modul werden die von ebusd zur Verfügung gestellten Parameter zum Auslesen und Schreiben in IP-Symcon als Statusvariablen eingebunden. Die Auswahl der einzubindenden Parameter wird vom Anwender festgelegt.

  

 
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
Intervall in dem alle Statusvariablen durch Anfragen an den eBUS aktualisiert werden (0 = keine Aktualisierung). Je nach Anzahl der Statusvariablen kann die Abfrage den eBUS erheblich belasten. Das Intervall sollte nicht zu klein gewählt werden.

Nachdem die Einstellungen gespeichert wurden, kann im Aktionsbereich die Konfiguration gelesen werden und die anzulegenden Statusvariablen können ausgewählt werden.
In der Liste der Statusvariablen markiert ein **(A)** hinter dem Ident, dass für diese Variable die Archivierung im Query-Logger (Archive Handler) aktiv ist.

Das Modul überwacht zudem das globale Signal des ebusd. Geht das Signal verloren (z.B. Hardware-Trennung), wird die Instanz automatisch auf *Inaktiv* gesetzt.

Bei Bedarf kann für eine Statusvariable eine Poll Priorität angegeben werden, die von ebusd verwendet werden soll. Die Poll Prioriät besagt, in welchem Intervallzyklus eine Meldung von ebusd gepollt werden soll.
Meldungen mit Priorität 1 werden in jedem Pollzyklus abgefragt, Meldungen mit Priorität 2 werden in jedem zweiten Zyklus abgefragt usw.. Die Pollpriorität kann gesetzt werden, wenn das Abfrageintervall, das im Minutenbereich liegt, für einzelne Meldungen nicht fein genug ist.

## 5. Einbindung ins Webfront
Alle Statusvariablen sind für eine Anzeige und (sofern vom ebusd ein Schreiben unterstützt wird) zum Ändern im Webfront vorbereitet. Sie haben alle eine Darstellung, die der ebusd Definition entspricht.
Zur Verwendung in der Visu sollten sie jedoch überprüft werden. Insbesondere der Wertebereich (min/max) ist zu kontrollieren und auf reelle bzw. anlagenspezifische Werte zu setzen.

Besonderheit:

Schreibbare Mehrfachfelder können nicht direkt aus der Visu heraus geändert werden. Sie lassen sich aber über EBM_publish schreiben.

Beispiel:
```php
EBM_publish(47111, 'ebusd/700/hwctimer.monday/set', '07:00;22:00;00:00;00:00;00:00;00:00');
```
 

## 6. Schreiben von Werten
Sofern die Statusvariablen ein Schreiben zulassen, können die Werte direkt über das Webfront oder per Skript über [RequestAction](https://www.symcon.de/service/dokumentation/befehlsreferenz/variablenzugriff/requestaction/) verändert werden.

## 7. Funktionsreferenz

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

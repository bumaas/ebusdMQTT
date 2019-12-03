[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-5.3%20%3E-blue.svg)
[![StyleCI](https://styleci.io/repos/175384837/shield?style=flat)](https://styleci.io/repos/175384837)

# ebusdMQTT
   Anbindung von https://github.com/john30/ebusd an IP-Symcon.
 
   ## Inhaltverzeichnis
   1. [Funktionsumfang](#1-funktionsumfang)
   2. [Voraussetzungen](#2-voraussetzungen)
   3. [Installation](#3-installation)
   4. [Konfiguration](#4-konfiguration)
   5. [Funktionsreferenz](#5-funktionsreferenz)
    
## 1. Funktionsumfang

Das Modul dient zur Einbindung von eBUS Geräten in IP-Symcon. eBUS ('Energie Bus') ist ein Bussystem, das von verschiedenen Herstellern von Heizungs-, Lüftungs- und Solaranlagen genutzt wird.

Die Anbindung erfolgt über den Kommunikationsdienst **ebusd**.

Über das Modul werden die von ebusd zur Verfügung gestellten Parameter zum Auslesen und Schreiben in IP-Symcon als Statusvariablen eingebunden. Die Auswahl der einzubindenden Parameter wird vom Anwender festgelegt.

  

 
## 2. Voraussetzungen

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
**todo: "Optimale Polleinstellungen/Maximallast Bus. Bedeutung Intervall? Für Werte lesen muss der Wert <> 0 sein"**

Wenn die Einstellungen geändert werden, müssen sie erst gespeichert werden, bevor im Konfigurationsbereich die Konfiguration gelesen und die Statusvariablen angelegt werden können.

## 4. Funktionsreferenz

```php
EBM_publish(string $topic, string $payload): void
```
Published den Wert $payload zum $topic.




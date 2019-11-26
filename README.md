[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-5.3%20%3E-blue.svg)
[![StyleCI](https://styleci.io/repos/175384837/shield?style=flat)](https://styleci.io/repos/175384837)

# ebusdMQTT
   Anbindung von https://github.com/john30/ebusd an IP-Symcon.
 
   ## Inhaltverzeichnis
   1. [Voraussetzungen](#1-voraussetzungen)
   2. [Enthaltene Module](#2-enthaltene-module)
   3. [Installation](#3-installation)
   4. [Konfiguration in IP-Symcon](#4-konfiguration-in-ip-symcon)
   5. [Spenden](#5-spenden)
   6. [Lizenz](#6-lizenz)
   
## 1. Funktionsumfang

Das Modul dient zur Einbindung von eBUS Geräten in IP-Symcon. eBUS ('Energie Bus') ist ein Bussystem, das von verschiedenen Herstellern von Heizungs-, Lüftungs- und Solaranlagen genutzt wird.

Die Anbindung erfolgt über den Kommunikationsdienst **ebusd**.

Über das Modul werden die von ebusd zur Verfügung gestellten Parameter zum Auslesen und Schreiben in IP-Symcon als Statusvariablen eingebunden. Die Auswahl der einzubindenden Parameter wird vom Anwender festgelegt.

  

 
## 2. Voraussetzungen

* lauffähiger eBUS Daemon (ebusd (ab V3.4)) mit entsprechender Hardwareanbindung 
* mindestens IPS Version 5.3
* MQTT Server (IPS built-in Modul) 


## 3. Installation
### 3.1 Installation ebusd
Zur Installation des eBUS Daemon siehe https://github.com/john30/ebusd/wiki

Es gibt zahlreiche Wege, ebusd zu installieren. Ich fasse hier einmal zusammen, wie sich ebusd auf einem Raspberry Pi 1 Mod.B unter Jessie installieren lässt.

- je nach eingesetzter Hardware und installiertem Betriebssystem ist das passende Packet von hier zu installieren: https://github.com/john30/ebusd/releases
Tipp: die Hardware und die OS Version findet man heraus mit 
cat /etc/os-release
und
uname -a

Wichtig: es ist ein Paket mit **MQTT Support** zu wählen!

Das passende Paket ist herunterzuladen, z.B.:
```
wget https://github.com/john30/ebusd/releases/download/v3.4/ebusd-3.4_armhf-jessie_mqtt1.deb
```

und zu installieren:
```
sudo dpkg -i ebusd-3.4_armhf-jessie_mqtt1.deb
```


<br>
Zum Abschluss der Installation erfolgen folgende Hinweise, die auszuführen sind:

```
1. Edit /etc/default/ebusd
   (especially if your device is not /dev/ttyUSB0)
2. Start the daemon with 'systemctl start ebusd'
3. Check the log file /var/log/ebusd.log
4. Make the daemon autostart with 'systemctl enable ebusd'
```

zu 1.): Es empfiehlt sich mit nur drei Einstellungen zu beginnen:
```
EBUSD_OPTS="--device /dev/ttyebus --scanconfig --configpath=http://ebusd.eu/config/"
```

Der erste Parameter besagt, wo der Buskoppler angeschlossen ist.
Beispiele: 

- /dev/ttyebus (aufgesteckt und über ttyebus Treiber angesprochen)
- tcp:10.0.0.25:5000 (über Ethernet verbunden)

Der zweite Parameter besagt, dass beim Starten des Daemon der eBUS nach Geräten abgesucht werden soll. Der dritte Parameter beinhaltet den Pfad zu den Konfigurationsdeteien.

zu 3.)
Im Logfile muss erkennbar sein, dass er den Adapter gefunden hat und dass ein automatischer Scan durchgeführt wurde. Wenn es beim Scan zu Timeouts kommt, kann versucht werden, mit der Option --receivetimeout=100000 das Limit zu erhöhen. 


Wenn diese Dinge geschafft sind, ist im nächsten Schritt zu prüfen, ob ebusd die angeschlossenen eBUS Geräte findet.

Das wird überprüft mit 'ebusctl i'. Hier ein Auszug:

```
version:  ebusd 3.4.v3.3-51-g57eae05
signal: acquired
address 03: master #11
address 08: slave #11, scanned "MF=Vaillant;ID=BAI00;SW=0603;HW=9102", loaded "vaillant/bai.0010015600.inc" ([PROD='0010014917']), "vaillant/08.bai.csv"
address 10: master #2
address 15: slave #2, scanned "MF=Vaillant;ID=70000;SW=0419;HW=4603", loaded "vaillant/15.700.csv"
address 31: master #8, ebusd
address 36: slave #8, ebusd
```
Man sieht, welche Version installiert ist, ob die Verbindung zum ebus Adapter steht ("acquired") und welche Geräte gefunden wurden. Dabei hat jedes Gerät einen Schaltkreis Name (ID), dessen ersten drei Stellen relevant sind (hier: BAI und 700).
Und - ganz wichtig - welche Konfigurationsdateien geladen wurden (hier u.a. vaillant/15.700.csv für meine MultiMATIC VRC 700/4).

Über den scan Befehl (z.B. ebusctl scan 15) lässt sich zusätzlich die Produkt ID des Gerätes anzeigen (hier: 0020218357):

```
15;Vaillant;70000;0419;4603;21;17;09;0020218357;0082;015122;N7
```

An dieser Stelle kann man einen Blick in die Konfigurationsdatei werfen und dann versuchen, über ebusctl einen Wert auszulesen oder zu schreiben.
Die Konfigurationsdatei findet sich unter https://github.com/john30/ebusd-configuration/tree/master/ebusd-2.1.x/de/vaillant 

Dort findest sich als Beispiel der Eintrag zur Heizkurve des ersten Heizkreise:
```
r;w,,Hc1HeatCurve,HeatCurve Heizkreis 1,,,,0F00,,,EXP,,,heating curve of Hc1
```
Die wichtigsten Informationen daraus: r=Lesbar, w=schreibbar, Hc1HeatCurve = Name des Parameters.

Mit Hilfe dieser Informationen lassen sich die Daten über ebusctl auslesen bzw. schreiben
```
pi@raspberrypi:~ $ ebusctl
localhost: r -c 700 Hc1HeatCurve
0.58

localhost: w -c 700 Hc1HeatCurve 0.5
done

localhost: r -c 700 Hc1HeatCurve
0.5
```

Soweit zur Installation und zum Einstieg in ebusd.

Für die Integration von ebusd in IP-Symcon werden die Daten von ebusd über http und MQTT zur Vefügung gestellt. Dazu sind die Konfigurationsparameter in der /etc/default/ebusd um folgende Optionen zu erweitern:
```
 --pollinterval 5  --accesslevel=* --httpport=8080 --mqtthost=<IP> --mqttport=<Port> --mqttuser <USER> --mqttpass <PASSWORT> --mqttjson
```
- \<IP> - IP-Adresse des IP-Symcon Systems
- \<PORT> - die in der MQTT Server Instanz eingetragene Portnummer
- \<USER> - der in der MQTT Server Instanz eingetragene Benutzername
- \<PASSWORT> - das in der MQTT Server Instanz eingetragene Passwort

Eine nähere Beschreibung der Optionen findet sich hier: https://github.com/john30/ebusd/wiki/2.-Run  

### 3.2 Installation in IP-Symcon

Füge im "Module Control" (Kern Instanzen->Modules) die URL 
```
https://github.com/bumaas/ebusdMQTT.git
```
hinzu.

Danach ist es möglich ein neues ebusd MQTT Device zu erstellen:

![Instanz erstellen](imgs/InstanzErstellen.png?raw=true "Instanz erstellen")
<br>Falls noch keine MQTT Server Instanz existiert, wird automatisch eine angelegt:
![MQTT Server Instanz erstellen](imgs/InstanzErstellenMQTTServer.png?raw=true "MQTT Server Instanz erstellen")
<br>Auch eine Server Socket Instanz wird automatisch angelegt, wenn noch keine existiert:
![MQTT Server Instanz erstellen](imgs/InstanzErstellenServerSocket.png?raw=true "Server Socket Instanz erstellen")


### Konfiguration
![Instanz konfigurieren](imgs/InstanzKonfigurieren.png?raw=true "Instanz konfigurieren")
-  Host:  
Adresse unter der der ebusd Dienst erreichbar ist. Hierbei kann es sich um eine IP oder einen Hostnamen handeln.  
Wenn die Einstellungen gespeichert werden, wird überprüft ob die Adresse erreichbar ist.
- Port:  
Portnummer auf dem der ebusd Dienst http-Anfragen entgegennimmt.

- Schaltkreis Name: 
Der Name des Schaltkreises unter dem das Gerät in ebusd geführt wird ('Circuit').
 
- Aktualisierungsintervall:  
Intervall in dem alle Statusvariablen durch Anfragen an den eBUS aktualisiert werden (0 = keine Aktualisierung). Je nach Anzahl der Statusvariablen kann die Abfrage den eBUS belasten. Das Intervall sollte nicht zu klein gewählt werden.





ebusdMQTT:
```
https://github.com/bumaas/ebusdMQTT.git
```

## 4. Konfiguration in IP-Symcon



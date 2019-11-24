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
   
## 1. Voraussetzungen

* lauffähiger eBUS Daemon (ebusd) mit entsprechender Hardwareanbindung 
* mindestens IPS Version 5.3
* MQTT Server (IPS built-in Modul) 


## 2. Enthaltene Module


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
wget https://github.com/john30/ebusd/releases/download/v3.4/ebusd-3.4_armhf-jessie_mqtt1.deb

und zu installieren:
sudo dpkg -i --force-overwrite ebusd-3.4_armhf-jessie_mqtt1.deb

<br>
Nach der Installation sind die folgenden Punkte zu erledigen:

1. Edit /etc/default/ebusd
   (especially if your device is not /dev/ttyUSB0)
2. Start the daemon with 'systemctl start ebusd'
3. Check the log file /var/log/ebusd.log
4. Make the daemon autostart with 'systemctl enable ebusd'

zu 1.): Es empfiehlt sich, mit nur zwei Einstellungen zu beginnen:
EBUSD_OPTS="--device /dev/ttyebus --scanconfig"

Der erste Parameter besagt, wo der Buskoppler angeschlossen ist.
Beispiele: 
/dev/ttyebus (aufgesteckt und über ttyebus Treiber angesprochen)
tcp:10.0.0.25:5000 (über Ethernet verbunden)

Der zweite Parameter besagt, dass beim Starten des Daemon der eBUS nach Geräten abgesucht werden soll

zu 3.)
Im Logfile muss erkennbar sein, dass er den Adapter gefunden hat und dass ein automatischer Scan durchgeführt wurde


Wenn diese Dinge geschafft sind, ist im nächsten Schritt zu prüfen, ob ebusd die angeschlossenen eBUS Geräte findet.

Das wird überprüft mit ebusctl i:

Die der Ausgabe sind folgende Informationen wichtig:

```
version: ebusd 3.3.v3.3
signal: acquired
address 03: master #11
address 08: slave #11, scanned "MF=Vaillant;ID=**BAI**00;SW=0603;HW=9102", loaded "vaillant/bai.0010015600.inc" ([PROD='0010014917']), "vaillant/08.bai.csv"
address 10: master #2
address 15: slave #2, scanned "MF=Vaillant;ID=70000;SW=0419;HW=4603", loaded "vaillant/15.700.csv"
address 31: master #8, ebusd
address 36: slave #8, ebusd
```
Man sieht, welche Version installiert ist, ob die Verbindung zum ebus Adapter steht (acquired) und welche Geräte gefunden wurden. Und - ganz wichtig - welche Konfigurationsdateien geladen wurden.

Über den scan Befehl (z.B. ebusctl scan 08) lässt sich zusätzlich die Produkt ID des Gerätes anzeigen (hier: 0010014917):

```
08;Vaillant;BAI00;0603;9102;21;17;35;0010014917;0001;005342;N3
```

An dieser Stelle kann man einen Blick in die Konfigurationsdatei werfen und versuchen, über ebusctl mit der read Option einen Wert auszulesen.


to be continued ...

<br><br><br>
todo:
EBUSD_OPTS="-d /dev/ttyebus --scanconfig --configpath /home/pi/server/ebusd-configuration/ebusd-2.1.x/de --receivetimeout=100000 --pollinterval 5  --accesslevel=* --httpport=8080 --mqtthost=HOST --mqttport=1024 --mqttuser USER --mqttpass PASSWORT --mqttjson --mqtttopic=ebusd/%circuit/%name --mqttlog"

ebusdMQTT:
```
https://github.com/bumaas/ebusdMQTT.git
```

## 4. Konfiguration in IP-Symcon



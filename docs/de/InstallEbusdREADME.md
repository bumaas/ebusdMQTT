# Installationskurzanleitung ebusd
 
   ## Inhaltverzeichnis
   1. [Installation des richtigen Pakets](#1-installation-des-richtigen-pakets)
   2. [Abschluss der Installation](#2-abschluss-der-installation)
   3. [Überprüfung der Konfiguration](#3-berprfung-der-konfiguration)
   4. [Mit ebusctl Daten lesen und schreiben](#4-mit-ebusctl-daten-lesen-und-schreiben)
   5. [Über http ebusd Daten abfragen](#5-ber-http-ebusd-daten-abfragen)
   6. [IP-Symcon relevante Konfigurationsparameter](#6-ip-symcon-relevante-konfigurationsparameter)
    
Die detaillierte Installationsbeschreibung des eBUS Daemon ist im [ebusd Wiki](https://github.com/john30/ebusd/wiki) zu finden.

Es gibt zahlreiche Wege, ebusd zu installieren. Da die Installation nicht ganz trivial ist, fasse ich hier einmal zusammen, wie sich ebusd auf einem Raspberry Pi 1 Mod.B unter Buster installieren lässt.

## 1. Installation des richtigen Pakets

Je nach eingesetzter Hardware und installiertem Betriebssystem ist das passende Packet von der Seite [ebusd releases](https://github.com/john30/ebusd/releases) zu installieren.

Tipp: die Architektur (amd64, armhf oder i386) und die OS Version findet man heraus mit 
dpkg --print-architecture
und
cat /etc/os-release

Wichtig: es ist ein Paket mit **MQTT Support** zu wählen!

Wenn das passende Paket (z.B. ebusd-22.4_armv7-buster_mqtt1.deb) gefunden ist, ist es herunterzuladen und zu installieren.:
```
wget https://github.com/john30/ebusd/releases/download/v22.4/ebusd-22.4_armv7-buster_mqtt1.deb
```
```
sudo dpkg -i ebusd-22.4_armv7-buster_mqtt1.deb
```
Falls es bei der Installation des ebusd Paketes zu einer Fehlermeldung kommen sollte wie:

```
dpkg: Abhängigkeitsprobleme verhindern Konfiguration von ebusd:
ebusd hängt ab von libmosquitto1; aber:
Paket libmosquitto1 ist nicht installiert.
```

Dann ist vorab die passende libmosquitto[0/1] zu installieren:
```
sudo apt-get update
sudo apt-get install libmosquitto1
```


## 2. Abschluss der Installation

Zum Abschluss der Installation erfolgen folgende Hinweise, die auszuführen sind:

```
1. Edit /etc/default/ebusd
   (especially if your device is not /dev/ttyUSB0)
2. Start the daemon with 'systemctl start ebusd'
3. Check the log file /var/log/ebusd.log
4. Make the daemon autostart with 'systemctl enable ebusd'
```

zu 1.) Es empfiehlt sich mit nur vier Einstellungen zu beginnen:
```
EBUSD_OPTS="--device=/dev/ttyebus --scanconfig --configpath=http://ebusd.eu/config/ --accesslevel=*"
```

Der erste Parameter besagt, um welchen Typen es sich beim Buskoppler handelt und wo er angeschlossen ist.
Beispiele: 

- --device=/dev/ttyebus (aufgesteckt und über [ttyebus](https://github.com/ebus/ttyebus) Treiber angesprochen)
- --device=192.168.2.20:5000 (über Ethernet und TCP verbunden - die Adresse ist ein Beispiel. Bei dem LAN-Gateway von Esera werden die Daten (Ip-Adresse, Port und Operation-Mode 'TCP-Server') im configtool gesetzt.)
- --device=ens:/dev/ttyUSB0 (enhanced high speed Adapter an USB0)

Der zweite Parameter besagt, dass beim Starten des Daemon der eBUS nach Geräten abgesucht werden soll.

Der dritte Parameter beinhaltet den Pfad zu den Konfigurationsdeteien. Im Beispiel werden die aktuellen Konfigurationsdateien beim Start von der Webadresse http://ebusd.eu/config/ geholt.

Der vierte Parameter besagt, dass es keine Zugriffsbeschränkungen geben soll.

Alternativ bietet sich an, die benötigten Dateien lokal zu speichern und den Parameter auf den lokalen Pfad zu setzen.

Dazu wählt man sich ein passendes Verzeichnis aus (z.B. /home/pi/) und führt in dem Verzeichnis den folgenden Befehl aus:
```
git clone https://github.com/john30/ebusd-configuration.git
```
Dadurch werden die Konfigurationsdateien im Unterverzeichnis /home/pi/ebusd-configuration gespeichert, so dass die Einstellung für configpath auf das lokale Verzeichnis umgestellt werden kann:
```
--configpath=/home/pi/ebusd-configuration/ebusd-2.1.x/de"
```


<br>

<br>
zu 3.)
Im Logfile /var/log/ebusd.log muss erkennbar sein, dass der Adapter gefunden wurde und dass ein automatischer Scan durchgeführt wurde. Wenn es beim Scan zu Timeouts kommt, kann versucht werden, mit der zusätzlichen Option --receivetimeout=100000 das Limit zu erhöhen. 

Ein erfolgreicher Star sieht so aus:
```
2022-11-11 17:08:44.010 [main notice] ebusd 22.4.v22.4 started with auto scan on device /dev/ttyebus
2022-11-11 17:08:44.029 [bus notice] bus started with own address 31/36
2022-11-11 17:08:44.033 [mqtt notice] connection established
2022-11-11 17:08:44.040 [bus notice] signal acquired
2022-11-11 17:08:46.003 [bus notice] new master 71, master count 2
2022-11-11 17:08:46.062 [bus notice] new master 03, master count 3
```

Tipp: wenn der Dienst neu gestartet werden soll, geht das am einfachsten über
```
sudo systemctl restart ebusd
```

### Installation als Docker Container

Wenn man ebusd als Docker laufen lässt, empfiehlt es sich diesen mit Bridge und eigener IP zu erstellen, da sonst Port-Konflikte (8080) auftreten können.
```
ebusd -f --scanconfig --port=8888 --device=<IP LANGateway>:<Port LANGateway>
```

### 3. Überprüfung der Konfiguration
Wenn diese Dinge geschafft sind, ist im nächsten Schritt zu prüfen, ob ebusd die angeschlossenen eBUS Geräte korrekt findet.

Das wird überprüft mit 'ebusctl i'. Hier ein Auszug:

```
version: ebusd 22.4.v22.4
...
signal: acquired
...
address 03: master #11
address 08: slave #11, scanned "MF=Vaillant;ID=BAI00;SW=0603;HW=9102", loaded "vaillant/bai.0010015600.inc" ([PROD='0010014917']), "vaillant/08.bai.csv"
address 10: master #2
address 15: slave #2, scanned "MF=Vaillant;ID=70000;SW=0419;HW=4603", loaded "vaillant/15.700.csv"
address 31: master #8, ebusd
address 36: slave #8, ebusd
```
Man sieht, welche Version installiert ist, ob die Verbindung zum ebus Adapter steht ("acquired") und welche Geräte gefunden wurden. Dabei hat jedes Gerät einen Schaltkreis Namen (ID), dessen ersten drei Stellen relevant sind (hier: **BAI** und **700**).

Auch wird angezeigt - ganz wichtig - welche Konfigurationsdateien geladen wurden (hier z.B. "vaillant/15.700.csv" für eine verbaute MultiMATIC VRC 700/4).

Über den scan Befehl (z.B. ebusctl scan 15) lässt sich zusätzlich die Produkt ID des Gerätes anzeigen (hier: 0020218357):

```
15;Vaillant;70000;0419;4603;21;17;09;0020218357;0082;015122;N7
```

## 4. Mit ebusctl Daten lesen und schreiben
An dieser Stelle sollte man einen Blick in die Konfigurationsdatei werfen und dann versuchen, über ebusctl einen Wert auszulesen oder zu schreiben.
Die Konfigurationsdatei findet sich unter https://github.com/john30/ebusd-configuration/tree/master/ebusd-2.1.x/de/vaillant 

Darin findet man als Beispiel den Eintrag zur Heizkurve des ersten Heizkreises:
```
r;w,,Hc1HeatCurve,HeatCurve Heizkreis 1,,,,0F00,,,EXP,,,heating curve of Hc1
```
Die wichtigsten Informationen daraus: r=lesbar, w=schreibbar, Hc1HeatCurve = Name des Parameters.

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
Die Beschreibung aller Befehle findet sich im Kapitel [3.1 TCP client commands](https://github.com/john30/ebusd/wiki/3.1.-TCP-client-commands). 

## 5. Über http ebusd Daten abfragen

Als letztes sollte man prüfen, ob sich in einem Browser die ebusd Daten abfragen lassen:

```
http://raspberrypi:8080/data
```

Es sollten die Daten der erkannten Geräte geliefert werden:

```
{
 "700": {
  "messages": {   "AdaptHeatCurve": {
    "name": "AdaptHeatCurve",
    "passive": false,
    "write": false,
    "lastup": 1646136520,
    "zz": 21,
    "fields": {
     "yesno": {"value": "nein"}
    }
   },
   "BankHolidayEndPeriod": {
    "name": "BankHolidayEndPeriod",
    "passive": false,
    "write": false,
    "lastup": 1643318788,
    "zz": 21,
    "fields": {
     "hto": {"value": "01.01.2015"}
    }
   },
   "BankHolidayStartPeriod": {
    "name": "BankHolidayStartPeriod",
    "passive": false,
    "write": false,
    "lastup": 1643318788,
    "zz": 21,
    "fields": {
     "hfrom": {"value": "01.01.2015"}
    }
   }, ...
```

Standardmäßig horcht der ebusd Service auf den Port 8080. Ist der Port bereits anderweitig belegt, dann ist in der ebusd Konfiguration ein alternativer Port zu setzen.

Soweit zur Installation und zum Einstieg in ebusd.

## 6. IP-Symcon relevante Konfigurationsparameter
Für die Integration von ebusd in IP-Symcon werden die Daten von ebusd über http und MQTT zur Vefügung gestellt. Dazu sind die Konfigurationsparameter in der _/etc/default/ebusd_ um folgende Optionen zu erweitern:
```
 --pollinterval 5  --accesslevel=* --httpport=8080 --mqtthost=<IP> --mqttport=<Port> --mqttuser=<USER> --mqttpass=<PASSWORT> --mqttjson
```
- \<IP> - IP-Adresse des IP-Symcon Systems
- \<PORT> - die im Server Socket der MQTT Server Instanz eingetragene Portnummer
- \<USER> - der in der MQTT Server Instanz eingetragene Benutzername
- \<PASSWORT> - das in der MQTT Server Instanz eingetragene Passwort

Eine nähere Beschreibung der Optionen findet sich im Kapitel [2. Run](https://github.com/john30/ebusd/wiki/2.-Run) des Wikis. Die MQTT Parameter müssen den Werten entsprechen, die auf IP-Symcon Seite in der MQTT Server Instanz gesetzt wurden. 

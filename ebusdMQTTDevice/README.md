# IPS-Z2MDevice
   Anbindung von www.zigbee2mqtt.io an IP-Symcon.
     
   ## Inhaltverzeichnis
   1. [Konfiguration](#1-konfiguration)
   2. [Funktionen](#2-funktionen)
   
   ## 1. Konfiguration
   
   Feld | Beschreibung
   ------------ | -------------
   MQTT Topic | Hier wird das Topic vom Device eingetragen.
   Philips HUE Beleuchtungsstärke | Muss ausgewählt werden, wenn es sich um einen HUE Bewegungsmelder handelt.
   
   ## 2. Funktionen
   
   **Z2M_SwitchMode($InstanceID, $Value)**\
   Mit dieser Funktion ist es möglich das Gerät ein- bzw. auszuschalten.
   ```php
   Z2M_SwitchMode(25537, true) //Einschalten;
   Z2M_SwitchMode(25537,false) //Ausschalten;
   ```
   
   **Z2M_setDimmer($InstanceID, $Value)**\
   Mit dieser Funktion ist es möglich das Gerät zu dimmen.
   ```php
   Z2M_setDimmer(25537,50) //auf 50% dimmen;
   ```
   
   **2M_setSensitivity($InstanceID, $Value)**\
   Mit dieser Funktion ist es möglich die Empfindlichkeit einzustellen.
   ```php
   2M_setSensitivity(25537,1) //1 = Medium, 2 = Low, 3 = High
   ```
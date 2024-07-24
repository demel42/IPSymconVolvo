[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Volvo bietet für bestimmte Modelle eine Kommunikation via Internet/Volvo-Cloud an: ([Volvo Cars API](https://developer.volvocars.com)), diese steht nur für bestimmte Modelle zur Vefüdung ([siehe hier](https://developer.volvocars.com/apis/connected-vehicle/v2/overview/#availability)).

Realisiert ist der Datenabruf alle verfügbarer Daaten; Steuerungsfunktionen des Fahrzeugs sind nicht implementiert.
## 2. Voraussetzungen

- IP-Symcon ab Version 6.0

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *Volvo* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/Volvo.git` installiert werden.

### b. Einrichtung in IPS

## 4. Funktionsreferenz

es gibt keine auslösbaren Funktionen

## 5. Konfiguration

### a. VolvoIO

#### Einstellungen

| Eigenschaft             | Typ     | Standardwert | Beschreibung |
| :---------------------- | :------ | :----------- | :----------- |
| Instanz deaktivieren    | boolean | false        | Instanz temporär deaktivieren |
|                         |         |              | |
| Verbindugstyp           | integer | 0            | Auswahl der Art der Verbindung (**OAuth** oder **Developer**) |
|                         |         |              | |
| - nur bei _Developer_ - |         |              | |
| Benutzername            | string  |              | Volvo-Konto: Benutzerkennung |
| Passwort                | string  |              | Volvo-Konto: Passwort |
| VCC API Key             | string  |              | Schlüssel der Volvo-API Application |

#### Aktionen

| Bezeichnung   | Beschreibung |
| :------------ | :----------- |
| Zugang prüfen | Prüft, ob die Angaben korrekt sind |

### b. VolvoConfig

keine Einstellungen

### c. VolvoVehicle

#### Einstellungen

| Eigenschaft               | Typ     | Standardwert | Beschreibung |
| :------------------------ | :------ | :----------- | :----------- |
| Instanz deaktivieren      | boolean | false        | Instanz temporär deaktivieren |
|                           |         |              | |
| Aktualisierungsintervall  | integer | 0            | Aktualisierungsintervall in Sekunden |

#### Aktionen

| Bezeichnung                | Beschreibung |
| :------------------------- | :----------- |

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Boolean<br>
Volvo.Failure

* Integer<br>
Volvo.Altitude,
Volvo.CentralLockState,
Volvo.ChargingState,
Volvo.ConnectionState,
Volvo.DoorState,
Volvo.EngineState,
Volvo.Heading,
Volvo.Mileage,
Volvo.Minutes,
Volvo.TyreState,
Volvo.WindowState

* Float<br>
Volvo.BatteryCapacity,
Volvo.BatteryChargeLevel,
Volvo.Distance,
Volvo.EnergyConsumption,
Volvo.FuelAmount,
Volvo.FuelConsumption,
Volvo.Location,
Volvo.Range,
Volvo.Speed

## 6. Anhang

### GUIDs
- Modul: `{2563E223-0FDC-DE40-C61F-4EB9A2638993}`
- Instanzen:
  - VolvoIO: `{E730BFFA-6E1F-F615-D1B3-4D43A13B7285}`
  - VolvoConfig: `{4905DEEC-2A8D-74B6-138E-020C46999674}`
  - VolvoVehicle: `{6C6B7979-37AA-69B7-2E19-7E10D92A97E3}`
- Nachrichten:
    - `{76557D1D-4782-3FBA-81C8-78494D4B6908}`: an VolvoConfig, VolvoDevice
    - `{83DF672B-CA66-5372-A632-E9A5406332A7}`: an VolvoIO

### Quellen

## 7. Versions-Historie

- 0.9 @ 24.07.2024 11:56
  - Initiale Version

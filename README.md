# WorkTime Lite für Kimai

Kleine, kostenlose Arbeitszeit-Erweiterung für [Kimai](https://www.kimai.org) (ab 2.73) für alle, die ihre eigenen Stunden für den Arbeitgeber sauber mitschreiben wollen.

## Funktionen

- **Arbeitsvertrag erweitert** (Profil → Arbeitsvertrag): erster/letzter Arbeitstag, Urlaubstage pro Jahr, Wert eines Abwesenheitstages (automatisch *Wochenstunden ÷ Arbeitstage*, z. B. 39 h ÷ 5 = 7:48, oder fester Wert), Bundesland für Feiertage, Startsaldo.
- **Abwesenheiten** (Arbeitszeiten → Abwesenheit): Urlaub, Krank, Überstundenabbau, Sonstiges – auch halbe Tage und Zeiträume. Wochenenden, Feiertage und gesperrte Monate werden übersprungen. Resturlaub und Krankheitstage auf einen Blick.
- **Feiertage** aller Bundesländer (plus AT/CH), optional Mariä Himmelfahrt.
- **Arbeitszeitkonto**: Überstunden gesamt seit dem ersten Arbeitstag, Urlaub/Krank werden mit dem Abwesenheitswert verrechnet.
- **Monatsabschluss** (⋯-Menü bzw. Rechtsklick auf die Monatszeile): Vorschau des Stundenzettels, Monat sperren, per E-Mail senden, in einer Ablage speichern (Server-Ordner oder WebDAV, z. B. Nextcloud) und herunterladen – in einem Rutsch. Entsperren ebenfalls über das Menü.
- **Eigene Felder** für Zeiteinträge (z. B. `Regie | ja/nein`, `Fahrzeug | auswahl: Crafter, Caddy`, `Tätigkeitsbericht | langtext`) – im Formular und als Spalte in Exportvorlagen.
- **Schnell anlegen**: Kunde, Projekt oder Tätigkeit direkt im Zeiteintrag tippen → „+ „Name“ anlegen“.
- **Bequeme Zeiteingabe**: `7`, `7.00`, `7,00`, `700` → `07:00`. Optional in Dauer-Feldern `7.30` → 7 h 30 min.

## Installation

```bash
cd /opt/kimai/var/plugins          # im Kimai-Container
git clone https://github.com/Jedrimos/kimai-worktime-lite.git WorkTimeLiteBundle
cd /opt/kimai && bin/console kimai:reload --env=prod
```

Danach unter **System → Einstellungen → WorkTime Lite** die Projekte/Tätigkeiten für Urlaub, Krank usw. sowie E-Mail und Ablage einstellen.

Für den E-Mail-Versand muss Kimai einen Mailserver haben (`MAILER_URL`, `MAILER_FROM`).

## Lizenz

MIT

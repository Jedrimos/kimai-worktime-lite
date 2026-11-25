# Handwerker Toolbox für Kimai

Monteur-App für [Kimai](https://www.kimai.org) (ab 2.73): Baustelle, Zeiten und Papierkram vom Handy aus – aufgeräumt, große Knöpfe, alles einzeln abschaltbar.

## Baustelle

- **Heute** (Startseite): Start/Feierabend, Baustelle von heute mit Navi, Anrufen, Zugang/Schlüssel, Restarbeiten, Mitnehmen-Liste, Kacheln für offenes Material, Bus-Lager und Werkzeug, Überstunden-Ampel.
- **Baustellen-Übersicht**: Kunde → Projekt, Status (geplant/läuft/fertig/abgerechnet), Adresse, Kontakt, eigene Stunden, wer mit dabei war.
- **Zettel**: Aufmaß, Materialbestellung und Notizen mit beliebig vielen Positionen – häufigste Artikel als Schnellwahl, Vorlagen („Steckdose komplett“ × 5), PDF mit Überschrift.
- **Bestellliste** über alle Baustellen nach Großhändler, Status offen → bestellt → geliefert.
- **Fotos** pro Baustelle (auch Zählerstand und Typenschild), landen im Baustellenbericht.
- **Noch zu machen**: Restarbeiten pro Baustelle und gesamt.
- **Stromkreisliste** pro Verteiler mit PDF.
- **Werkzeug-Tracker** und **Bus-Lager** (knapp → Bestellliste).
- **Mit wem**: Kollegen/Azubis beim Zeiteintrag antippen, Übersicht und PDF pro Person (z. B. fürs Berichtsheft).
- **Diktieren** per Mikrofon bei Textfeldern.

## Arbeitszeit

- **Arbeitsvertrag erweitert**: erster/letzter Arbeitstag, Urlaubstage (anteilig), Wert eines Abwesenheitstages (z. B. 39 h ÷ 5 = 7:48), Bundesland für Feiertage, Startsaldo.
- **Abwesenheiten**: Urlaub, Krank, Überstundenabbau, Sonstiges – auch halbe Tage und Zeiträume.
- **Arbeitszeitkonto** und Dashboard-Widget.
- **Monatsabschluss** mit Check (fehlende Tage, Pausen, Überschneidungen), PDF-Vorschau, Sperren, E-Mail (eigener SMTP) und Ablage (Server-Ordner/WebDAV).
- **Bequeme Eingabe**: `7`, `7.00`, `700` → `07:00`; neuer Eintrag beginnt am Ende des letzten; Knöpfe „Standardtag“ und „Wie letzter Tag“.
- **Eigene Felder** (z. B. Regie ja/nein), **Schnell anlegen** von Kunde/Projekt/Tätigkeit, **Meine Woche** (Zoll-Nachweis), **Steuer-Hilfe** (Verpflegungstage, Arbeitstage, CSV).
- **Handy-Leiste** unten: Heute · Baustellen · + Zeit · Zeiten · Mehr.

## Installation

```bash
cd /opt/kimai/var/plugins          # im Kimai-Container
git clone https://github.com/Jedrimos/kimai-worktime-lite.git WorkTimeLiteBundle
cd /opt/kimai && bin/console kimai:reload --env=prod
```

Der Ordnername `WorkTimeLiteBundle` bleibt aus technischen Gründen so. Danach unter **System → Einstellungen → Handwerker Toolbox** einstellen. Die Tabellen für Baustellen-Daten legt das Plugin beim ersten Aufruf selbst an.

## Lizenz

MIT

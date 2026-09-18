# Ticketdetails

Die Ticketansicht zeigt oben das Kürzel, darunter Titel und Beschreibung. Es folgen
verknüpfte Tickets, Anhänge, der aufklappbare Verlauf und zuletzt die Kommentare.
Die Eingabe für einen neuen Kommentar bleibt am unteren Rand sichtbar. Auf dem Desktop
steht rechts die Metadatenleiste; auf kleinen Bildschirmen folgt sie der Beschreibung.
Dieselbe Ansicht funktioniert auf der eigenen Ticket-URL und im Board-Drawer. Die gesamte
Ticketkarte öffnet das Ticket, einschließlich Beschreibung und freier Fläche. Das Kartenmenü
bleibt unabhängig bedienbar, auch direkt nach einer Drag-Geste. Der native Titellink
unterstützt weiterhin Tastatur, Kontextmenü und Öffnen in einem neuen Tab.

## Neues Ticket im Modal

**+ Neues Ticket** im Board öffnet die rechte Ticketleiste. Sie rendert dasselbe
`app/app/views/ticket.phtml` und dieselben Feld-Komponenten wie die Detailansicht:
Titel und Quill-Beschreibung links, Spalte, Verantwortliche und weitere Metadaten rechts.
Startdatum, Fälligkeit, Schätzung, erfasste Minuten, Labels und Farbe stehen bereits beim
Anlegen zur Verfügung. Auf kleinen Bildschirmen folgt die Sidebar der Beschreibung.

Die Eingaben sind ein gemeinsamer Entwurf. Auch die Spaltenauswahl speichert hier erst mit
**Ticket erstellen**. Die Aktion bleibt am unteren Modalrand erreichbar. Nach Erfolg
zeigt das Modal das gespeicherte Ticket mit seiner eigenen URL und Inline-Bearbeitung;
Kommentare, Anhänge und Verknüpfungen sind dann verfügbar. Das Board zeigt den bestehenden
Hinweis zum Laden des aktualisierten Stands.

Abbrechen, Schließen, Escape, ein Klick auf den Hintergrund und Browser-Zurück fragen bei
geänderten Eingaben nach, ob sie verworfen werden sollen. **Weiterbearbeiten** behält den Entwurf. Während der Speicherung
bleibt das Modal geöffnet; wiederholtes Absenden wird blockiert. Fehler und Versionskonflikte
behalten die Eingaben. Ist das Anlegen bereits bestätigt und scheitert nur das Nachladen,
führt der angebotene Link zum erstellten Ticket; es wird kein zweites angelegt.

`GET /projects/{project}/tickets/new?fragment=1` liefert das gemeinsame Formularfragment
und prüft das Schreibrecht im Projekt. Die normale URL rendert weiterhin eine vollständige
Seite. Ohne JavaScript bleibt ein natives POST-Formular mit Klartext-Beschreibung nutzbar;
auch bei nicht geladenem Quill kann dieser Klartext gespeichert werden. Der bestehende
`TicketService::create()` übernimmt Validierung, CSRF wird vom Form-Plugin geprüft, die
Boardrevision schützt vor veralteten Schreibzugriffen. Es gibt keine zusätzliche Speicherlogik.

## Lesen und bearbeiten

Titel, Beschreibung und änderbare Metadaten erscheinen zunächst als normaler Inhalt.
Klicken oder mit Tab fokussieren und Enter/Leertaste drücken öffnet die passende Eingabe.
Titel, Beschreibung, Datum, Farbe, Zeit und Labels speichern automatisch nach
700 ms Schreibpause. Auswahlen wie Spalte, Verantwortliche, Status, Priorität und Swimlane
speichern sofort. Unveränderte Werte lösen keine Anfrage aus. Ein zentraler Status zeigt ausstehende,
erfolgreiche oder fehlgeschlagene Speicherung; einzelne Felder brauchen keine Speicherbuttons.

Beim Verlassen des Feldes wird gespeichert und die Leseansicht wiederhergestellt. Enter
im Titel oder Cmd/Strg + Enter übernimmt ebenfalls. Escape verwirft nur Änderungen seit
der zuletzt bestätigten Speicherung; bereits automatisch gespeicherte Werte bleiben bestehen.
Die Eingaben übernehmen Schriftgröße und Zeilenhöhe der Leseansicht. Das Titelfeld wächst
mit dem Text, der Beschreibungseditor und seine Werkzeugleiste bleiben kompakt.

Es ist immer nur ein Metadatenfeld geöffnet. Während des Speicherns kann man weiterschreiben;
Editor, Auswahl und Cursor bleiben erhalten. Weitere Änderungen werden anschließend mit der
aktuellen Version gesendet. Auch andere Ticketaktionen werden der Reihe nach verarbeitet.
Ein parallel geschriebener Kommentar bleibt erhalten und wird ausschließlich explizit gesendet.
Beim Schließen – auch per Hintergrundklick – wartet der Drawer auf die Speicherung eines
offenen Feldes. Ein Kommentarentwurf
oder eine fehlgeschlagene Speicherung kann weiterbearbeitet oder ausdrücklich verworfen werden.

Ungültige Werte und Verbindungsfehler bleiben als Entwurf mit Fehlermeldung und Wiederholungsaktion
sichtbar. Versionskonflikte erfordern das Laden des aktuellen Stands; veraltete Änderungen werden
nicht automatisch wiederholt. Wenn eine Änderung gespeichert wurde, aber das Nachladen scheitert,
verlangt die Oberfläche ebenfalls einen Reload und sendet die Änderung nicht erneut. Lesende
Rollen bekommen keine Bearbeitungssteuerung. Archivierte Tickets behalten ihre bestehende
Wiederherstellungsaktion.

Die Beschreibung verwendet lokal ausgeliefertes **Quill 2.0.3** (BSD-3-Clause):
Überschriften 1–3, Fett, Kursiv, Unterstreichen, Durchstreichen, Listen, Zitate, Codeblöcke,
Links und Entfernen der Formatierung. Texte werden beim Einfügen und Speichern auf die
unterstützten Formate begrenzt. Der serverseitige Symfony HTML Sanitizer erlaubt nur die
benötigten Elemente und Links mit http/https/mailto beziehungsweise relative Links.
HTML-Attribute für Skripte, Styles, Bilder, Frames und eingebettete Medien sind nicht erlaubt.
Die Beschreibung kann höchstens 50.000 Textzeichen bzw. 100.000 HTML-Zeichen enthalten.
Die zusätzliche Klartextspalte hält die Volltextsuche und bestehende Integrationen nutzbar.
Vorhandene Beschreibungen bleiben Klartext, bis sie über den Editor gespeichert werden.

## Rechte Seitenleiste

Die Sidebar hat dezente Ecken mit 6 px Radius und kompakte, typgerechte Eingaben.

Spalte und Verantwortliche nutzen dasselbe durchsuchbare Dropdown. Bereits der erste Klick
auf den gelesenen Wert öffnet alle Einträge. Bei Spalten wird genau eine Zeile gewählt und
das Menü anschließend geschlossen. Personen erscheinen ebenso als Namenzeilen mit Avatar;
ein dezenter Haken kennzeichnet die Auswahl. Erneutes Klicken entfernt eine Person, weitere
Namen können ergänzt werden. **Nicht zugewiesen** entfernt alle Zuweisungen. Die geschlossene
Anzeige zeigt den ersten Namen und beispielsweise **+1**; die vollständige Auswahl bleibt
im Menü sichtbar. Mehrfachzuweisung ist weiterhin möglich.

Die Suche filtert nur die Liste und speichert keine Ticketänderung. Pfeiltasten navigieren,
Enter wählt, Escape schließt das Menü; Tab oder ein Klick außerhalb verlässt es ebenfalls.
Beim Anlegen bleiben auch diese Auswahlen bis **Ticket erstellen** Teil des Entwurfs.
Die Liste bleibt auf schmalen Bildschirmen im sichtbaren Bereich und öffnet bei Platzmangel
nach oben. `ticket/choice.phtml` und `ticket-choice.js` erweitern die vorhandenen nativen
Formularwerte; Endpunkte, NAF-Validierung, CSRF und Revisionsprüfung bleiben bestehen.
Die native [Popover API](https://developer.mozilla.org/en-US/docs/Web/API/Popover_API/Using)
hält das Menü über dem Dialog. Ohne diese API wird es fest positioniert; ohne JavaScript
bleiben beim Anlegen Select und Checkboxen als Formular-Fallback vorhanden.

| Bereich | Werte |
| --- | --- |
| Oben | Boardspalte, danach Verantwortliche (mehrere möglich) |
| Details | Offen/geschlossen, Priorität, Swimlane, Labels, Akzentfarbe |
| Planung und Zeit | Startdatum, Fälligkeit, geschätzte und erfasste Minuten |
| Informationen | Ersteller, Projekt, erstellt/geändert/geschlossen/archiviert, Version |

Das Punktemenü oben rechts führt außerdem in ein anderes Projekt. Angeboten wird nur, wo die
Person schreiben darf; das Ticket bekommt dort eine neue Nummer und damit ein neues Kürzel.
Kommentare, Anhänge, Verlauf und erfasste Zeit kommen mit — laufende Uhren werden vorher
beendet, damit die Arbeit noch gebucht wird. Labels, Verknüpfungen und Zuständige ohne Zugriff
im Zielprojekt bleiben zurück, weil sie dort nichts bezeichnen würden. Zuständig ist
`TicketService::transfer()`; die alte Adresse antwortet danach mit 404.

Spaltenwechsel nutzen `TicketService::move()` einschließlich Abschlussstatus und Positionierung.
Metadaten nutzen `TicketService::update()` als Teiländerung innerhalb der bestehenden
Projekttransaktion. Nicht übermittelte Attribute und Zuordnungen bleiben erhalten.
`version` und `board_revision` sind weiterhin erforderlich. Statuswechsel verwenden den
bestehenden State-Service. Systemwerte wie Ersteller und Änderungsdatum sind schreibgeschützt.

Die Felder der rechten Leiste und die Widgets zwischen Beschreibung und Kommentaren sind
registrierte Beiträge. Die vier Gruppen — Status, Details, Planung & Zeit, Informationen —
und die drei mittleren Bereiche — Verknüpfungen, Anhänge, Verlauf — stehen in derselben
Registry, die auch ein installiertes Paket benutzt; ihre Reihenfolge, ihre Darstellung und
ihr Vorhandensein sind damit bestimmbar. Titel, Beschreibung und Kommentare bleiben davon
ausgenommen: sie sind keine Beiträge und lassen sich über die Registries weder ersetzen
noch entfernen.

Ein beigetragenes Feld speichert seinen Wert in `ticket_metadata`, eine Zeile je Schlüssel,
und wird in derselben Transaktion, unter derselben Projektsperre und mit derselben
Versionsprüfung geschrieben wie die Kernfelder. Version und Boardrevision steigen einmal je
akzeptierter Änderung, auch wenn nur Metadaten betroffen sind. Die Einzelheiten stehen in
[Plugin-Erweiterbarkeit](Plugin-Erweiterbarkeit.md#ticket-metadaten).

Die Zeiterfassung ist eine manuell änderbare **Gesamtsumme in Minuten**; sie ist keine
Stoppuhr und kein personenbezogenes Buchungsjournal. Die Darstellung rechnet in Stunden
und Minuten um. Bei vorhandener Schätzung erscheinen Fortschritt und verbleibende Zeit
bzw. eine Überschreitung. Das Startdatum darf nicht nach der Fälligkeit liegen.

## Verknüpfungen und Kommentare

**Ticket verknüpfen** erwartet die Nummer eines anderen Tickets desselben Projekts,
z. B. `42` für `NAF-42`. Eine Verknüpfung erscheint auf beiden Seiten; doppelte und
Selbstverknüpfungen werden verhindert. Das Entfernen betrifft nur die Beziehung.

`@` im Kommentarfeld öffnet die Auswahl aktiver Projektmitglieder. Pfeiltasten navigieren,
Enter oder Tab übernimmt und Escape schließt die Auswahl. Handles werden aus den vorhandenen
Anzeigenamen abgeleitet (`Anna Schmid` → `@anna.schmid`); bei gleichen Namen kommt die
Benutzer-ID als Suffix hinzu. Es gibt dafür keine zweite Benutzerverwaltung. Bei einer
späteren Namensänderung bleibt der bereits gespeicherte Kommentartext unverändert.

**Antworten** fügt das Handle vor den vorhandenen Entwurf ein und zeigt den Antwortbezug
oberhalb des Feldes. Der Kommentar speichert zusätzlich die konkrete Eltern-ID. Antworten
werden unter dem beantworteten Kommentar angezeigt, auch bei Antworten auf Antworten.
Nach dem Löschen eines Elternkommentars bleiben seine Antworten unter einem Platzhalter
sichtbar; der gelöschte Text wird nicht ausgeliefert. Fremde Projekt-/Ticket-IDs und bereits
gelöschte Antwortziele werden abgewiesen. Die bestehenden Kommentarrechte, Versionsprüfung,
Aktivität und projektbezogenen Benachrichtigungseinstellungen bleiben maßgeblich.
Cmd/Strg + Enter sendet. Eine offene Antwort lässt sich über das × im Antwortbezug lösen.

## Installation und Datenmodell

Im Source-Modus vom Projektverzeichnis aus:

```sh
bin/dev-composer install --no-interaction
make backup
make migrate
make restart-background
```

Nach einer Änderung des Manifests vor dem ersten Install die lokale Entwicklungslockdatei
mit `bin/dev-composer update symfony/html-sanitizer --with-dependencies --no-interaction`
aktualisieren. Quill liegt einschließlich Lizenz unter `app/public/assets/vendor/quill/`;
Browser laden keine Editor-Dateien von einem CDN. `symfony/html-sanitizer:^7.4` und `ext-dom`
sind explizite Composer-Voraussetzungen und unterstützen die PHP-8.3-Untergrenze der App.

Die NAF-Migration `M202609160001TicketDetails` ergänzt `description_html`, `start_date`,
`estimate_minutes`, `spent_minutes`, `comments.parent_id` sowie `ticket_links` mit
projektgebundenen Fremdschlüsseln. `M202609160002RichTextStorage` verbreitert unter MariaDB
die Beschreibungsfelder auf MEDIUMTEXT, damit die Zeichengrenzen auch Unicode und HTML
abdecken. PostgreSQLs TEXT braucht keine Verbreiterung. Die Readiness prüft beide Migrationen.
Ein Down der zweiten Migration setzt die alte kleinere Grenze voraus und kann bei danach
angelegten großen Beschreibungen abgelehnt werden; nicht als Betriebs-Rollback verwenden.

## Verifikation

- Neueste Dropdown-Runde: `make test-http` besteht mit 60 MariaDB-Service-/Migrationsprüfungen
  und 79 HTTPS-Prüfungen. Die neuen Regressionen prüfen die gemeinsamen Auswahlen in beiden
  Ansichten, das Speichern mehrerer Personen und das vollständige Aufheben der Zuweisung.
- `make test-postgres`: vorherige 60 Service-/Migrationsprüfungen unter PostgreSQL;
  die anschließenden Modal-/Auto-Save-Änderungen betreffen weder Services noch Schema.
- `bin/style check` besteht vollständig; JavaScript-Syntax und `git diff --check` sind geprüft. Composer wurde bei der Editorintegration validiert.
- Reale lokale App: Ticketseite und identische Editor-/UI-Assets über CA-validiertes HTTPS,
  temporäre Demo-Sitzung danach abgemeldet, Readiness auf Schema `202609160002`.
- Browser: echte NAF-Templates und echte Assets mit synthetischen Daten; Desktop, Drawer,
  Hell/Dunkel, 390 × 844 und 320 × 568. Formatierung, Escape, unmittelbare Spaltenauswahl,
  Entwurferhalt, simulierte Konflikt-/Erfolgsantworten, Erwähnungen und Antwort-Payload geprüft.
- Erstellen-Modal: Öffnen vom Board-Einstieg, Formatierung im gemeinsamen Editor, genau ein
  POST beim Anlegen, Übergang zur Detailansicht, Entwurferhalt bei Konflikt, Weiterbearbeiten,
  Verwerfen und Browser-Zurück sowie feste Aktionen auf 390 und 320 Pixel Breite geprüft.
- Auto-Save: Schriftgrößen in Lese-/Bearbeitungsansicht, mobiles Titelfeld, Formatierungen,
  sofortige Auswahl, Entwurferhalt bei verzögerten Antworten, aufeinanderfolgende Versionen,
  Speicherung beim Schließen und keine weiteren POSTs nach einem 409 geprüft. Klicks auf
  Beschreibung/freie Kartenfläche, Tastatur und erster Menüklick nach Drag sind geprüft.
  Escape setzt den Speicherstatus zurück; ein Antwortentwurf kann während einer laufenden
  Metadatenspeicherung begonnen werden und behält Text und Fokus.
- Gemeinsames Dropdown: erster Klick, Suche, Tastaturauswahl, mehrere markierte Personen,
  Abwahl, kompakte Anzeige, 409 bei parallelem Spaltenwechsel und beide Menüs im Erstellen-
  Modal geprüft. Bei 390 und 320 Pixeln bleiben die Listen innerhalb des Viewports; ein
  dabei gefundener Fehler im Resize-Handler wurde korrigiert und erneut geprüft.

Die Browser-Vorschau verwendet Antwortattrappen und verändert keine Anwendungsdaten.
Persistenz und Rechte sind davon unabhängig über die echte HTTPS-Testinstanz geprüft.
Der interne Browser vertraut der lokalen CA nicht; die native Firefox-Sicht lieferte
keinen verlässlichen aktuellen Seiteninhalt. Die Zertifikatsprüfung wurde nicht umgangen.
Der Production-/Candidate-Snapshot wurde in dieser UI-Runde nicht neu gebaut.

Die folgende reine JavaScript-Ergänzung für Hintergrundklicks wurde mit echten Templates
und synthetischen Daten geprüft: leere Ticketanlage, Entwurfschutz, Verwerfungsdialog,
verzögertes Auto-Save, Verschieben, Projektanlage, Profil und persönliche Einstellungen.
Nur der oberste Dialog schließt; Innenklicks und Ziehen von innen nach außen werden ignoriert.
`bin/style check`, JavaScript-Syntax und `git diff --check` bestehen. Datenbank-/HTTPS-Suites
wurden für diese Ergänzung nicht erneut ausgeführt, da Speicher-Endpunkte unverändert sind.

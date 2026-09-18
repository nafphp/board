# Nafinity – Implementierung und Abnahme

Stand: 16. September 2026. Der Prototyp liegt unter
`/Users/flo/PhpStormProjects/nafinity` und läuft auf **https://localhost** (Port 443).
Er verwendet echte Daten, lokale NAF-Quellen und projektgebundene Rechte.

## Einstieg

Demo-Passwort: `Nafinity-Demo-2026!`.

| Konto | Zugriff |
|---|---|
| alice@example.test | Owner des Projekts Nafinity |
| bob@example.test | Owner des getrennten Projekts Studio Nord |
| viewer@example.test | Lesender Zugriff auf Nafinity |

Die im Browser angelegte Karte „Showcase-Abnahme im Browser“ hat eine eigene Ticket-URL,
wurde über das Verschiebemenü nach Review bewegt und enthält eine private Testdatei.
Die Demo enthält keine echten Kundendaten.

## Ticketansicht mit Inline-Bearbeitung

Die Ticketseite und der Board-Drawer zeigen Kürzel, Titel und formatierbare Beschreibung
als normalen Inhalt. Klicken öffnet die passende Eingabe. Die rechte Seitenleiste bündelt
Spalte und Verantwortliche, Details, Planung/Zeiterfassung und Systemdaten. Verknüpfte
Tickets, Anhänge und Verlauf stehen vor den Kommentaren; deren Eingabe bleibt sichtbar.
Antworten setzen das Handle ein und werden über die gespeicherte Eltern-ID eingeordnet.

Quill 2.0.3 wird lokal ausgeliefert, Symfony HTML Sanitizer bereinigt die Beschreibung.
NAFs Services, ORM, Projekt-Policies, Form/CSRF, Migrationen und Ereignisse bleiben die
Grundlage. Teiländerungen behalten unberührte Daten, alte Versionen werden zurückgewiesen.
Beide neuen Migrationen sind nach Backup `work/backups/20260916T193244Z` im Source-Betrieb
angewendet; Worker und Ticker wurden neu gestartet, Readiness ist grün.

60 MariaDB-, 60 PostgreSQL- und 73 HTTPS-Prüfungen bestehen. Stilprüfung, Composer-Validierung
und die lesende Kontrolle der tatsächlichen lokalen Ticketseite/Assets sind grün.
Die synthetische Browser-Vorschau bestätigt Desktop, Drawer, Hell/Dunkel, kleine Viewports,
Formatierung, Konflikte, Entwurferhalt und Antworten. Sie ersetzt keine direkte Browser-
Abnahme des HTTPS-Backends; diese ist durch CA-Vertrauen bzw. die native Browseransicht begrenzt.
[Ticket-Details.md](Ticket-Details.md) beschreibt Bedienung, Datenmodell, Tests und Grenzen.

Die Ticketerstellung verwendet nun dasselbe Ticket- und Feldtemplate in einem nativen
Dialog. Rich Text und Metadaten werden zusammen über den bestehenden Create-Service
angelegt. Danach erscheint die Detailansicht im selben Modal. Der feste Aktionsbereich,
Abbruch mit Entwurfschutz und die direkte URL mit nativem Formular-Fallback sind geprüft.
Die Ergänzung wurde erneut mit 60 MariaDB- und jetzt 73 HTTPS-Prüfungen sowie Desktop-/Mobile-
Browserchecks gegen synthetische Template-Daten verifiziert. Datenmodell und Paket-APIs
bleiben dabei unverändert; die vorherigen PostgreSQL-Prüfungen wurden nicht erneut benötigt.

Die anschließende Bedienungsrunde ergänzt Auto-Save nach 700 ms Schreibpause, sofortige
Einzelauswahl und einen zentralen Speicherstatus. Anfragen werden mit aktualisierten Revisionen
nacheinander gesendet; laufende Eingaben und Kommentare bleiben erhalten. Erstellung und
Kommentare behalten ihre ausdrückliche Absendeaktion. Die ganze Karte öffnet das Ticket,
während Menü und Drag-Geste getrennt funktionieren. Sidebar und Eingaben haben dezente Ecken,
Titel und Beschreibung behalten beim Bearbeiten ihre Schriftgröße und Zeilenhöhe.
60 MariaDB- und 76 HTTPS-Prüfungen sind erfolgreich. Synthetische Browserchecks decken zusätzlich
verzögerte Antworten, weitere Eingaben während der Speicherung, 409, Schließen mit Auto-Save,
Kartenklicks und mobile Darstellung ab. Details und Grenzen stehen im Ticketleitfaden.

Spalte und Zuständigkeiten verwenden nun ein gemeinsames Dropdown, das beim ersten Klick
alle Namen anbietet. Suche, Tastatursteuerung, Avatare und dezente Auswahlhaken ersetzen die
bisherige zweistufige Select-/Checkbox-Bedienung. Die Zuständigkeit bleibt eine Mehrfachauswahl;
der erste Name mit einem zusätzlichen Zähler hält die Leseansicht kompakt. Auswahl und Abwahl
speichern sofort, beim Erstellen bleiben sie im Entwurf. Native Formwerte und bestehende
NAF-Endpunkte bleiben erhalten. 60 MariaDB- und 79 HTTPS-Prüfungen sowie die vollständige
Stilprüfung sind erfolgreich; Browserchecks umfassen Desktop, Modal, 390/320 Pixel und
Versionskonflikte mit synthetischen Template-Daten.

Alle nativen Modale verwenden für Hintergrundklicks jetzt den zentralen Schließweg in
`app.js`. Er nutzt `requestClose()` und damit die vorhandenen Cancel-Handler; ältere Browser
bekommen denselben abbrechbaren Cancel-Ablauf als Fallback. Profil und Einstellungen behalten
ihre Animationen und Aufräumlogik, Tickets behalten Auto-Save und Entwurfschutz. Nur ein auf
dem Hintergrund begonnener und beendeter Klick schließt den obersten Dialog. Innenklicks und
Ziehen von innen nach außen tun dies nicht. Mit synthetischen NAF-Templates sind Ticketanlage,
Entwurfschutz, gestapelte Dialoge, verzögertes Auto-Save, Verschieben, Projektanlage, Profil
und persönliche Einstellungen geprüft. Vollständige Stilprüfung und JS-Syntax sind erfolgreich.


## Ticketnummern je Projekt

Die Adresse eines Tickets trägt jetzt Projektkürzel und laufende Nummer: `/projects/1/tickets/NAF-3`.
Die Nummer zählt innerhalb des Projekts hoch, ein neues Projekt beginnt wieder bei 1. Dafür war
keine neue Zählung nötig: `boards.next_number` vergibt die Nummern seit dem ersten Schema und
`UNIQUE(project_id, number)` sichert sie ab. Die Zeilen-ID bleibt Primärschlüssel und interner
Verweis, steht aber in keiner Adresse mehr.

`M202609160003ProjectTicketKey` ergänzt `projects.ticket_key`. Die Spalte heißt nicht `key`, weil
das in MySQL ein reserviertes Wort ist und in jeder Anweisung — je Treiber unterschiedlich — hätte
maskiert werden müssen. Bestehende Projekte erhalten ihr Kürzel aus dem Namen (Nafinity → `NAF`,
Studio Nord → `STU`), gleiche Anfänge bekommen eine Ziffer angehängt. Beim Anlegen und in den
Projekteinstellungen ist das Kürzel änderbar; erlaubt sind ein bis sechs Buchstaben oder Ziffern,
damit es ohne Maskierung in eine Adresse passt.

`TicketService::resolve()` übersetzt die Referenz in die Zeilen-ID und weist eine nackte Zahl
bewusst ab: Adressen trugen früher die Zeilen-ID, und würde `/tickets/3` weiter angenommen,
öffnete ein altes Lesezeichen still ein anderes Ticket statt zu scheitern. Ein fremdes Kürzel
wird ebenso abgewiesen, weil die Auflösung immer gegen das Projekt der Adresse läuft.

Geprüft mit 60 MariaDB-, 60 PostgreSQL-, 79 HTTPS- und 25 Profilprüfungen, den Worker- und
AI-Checks sowie der vollständigen Stilprüfung. Am laufenden Entwicklungssystem liefern `NAF-3`
und `naf-3` die Seite, `3`, `NAF-999` und das fremde `STU-1` je 404. Ein neu angelegtes Projekt
begann erwartungsgemäß bei `WEB-1`, während Projekt 1 bei 10 stand; diese Prüfdaten wurden danach
wieder entfernt. `/health/ready` meldet Schema `202609160003`.


## Schätzung je Projekt und Zeiterfassung

Komplexität und Story-Points sind dieselbe Zahl auf zwei Skalen, deshalb gibt es ein Feld
und nicht zwei: `tickets.estimate_points` trägt den Wert, `projects.estimation_scale` sagt,
wie er zu lesen ist — `none`, `complexity` (1–5) oder `points` (1, 2, 3, 5, 8, 13, 21). Das
„nur eine Skala gleichzeitig“ ist damit strukturell und keine Regel, die die Oberfläche
durchsetzen müsste. Über jeder Spalte steht die Summe der sichtbaren Schätzungen; sie wird
beim Verschieben aus den Karten neu gerechnet, ohne den Server zu fragen. Bei aktiven Filtern
beschreibt sie die gefilterte Ansicht, worauf der bestehende Hinweis über dem Board zeigt.

Ein Skalenwechsel verändert keine Zahl. Werte, die die neue Skala nicht anbietet, bleiben
stehen, sind auf Karte und Ticket markiert und bleiben im Auswahlfeld wählbar, damit das
Öffnen eines Tickets sie nicht still verwirft. Die Projekteinstellungen nennen ihre Anzahl
und bieten einmalig an, sie auf den nächstgelegenen angebotenen Wert abzubilden; bei
Gleichstand gewinnt der kleinere. Das Abbilden passiert nur auf Aufforderung.

Die Zeiterfassung liegt in `ticket_timers` je Projekt, Ticket und Person. Gezählt wird aus
einem gespeicherten Startzeitpunkt, nicht im Browser: ein geschlossener Tab, ein Reload oder
ein schlafendes Gerät können damit weder Minuten verlieren noch erfinden. Eine Person zählt
eine Sache — ein Start beendet den laufenden Lauf, auch in einem anderen Projekt. Mehrere
Personen erfassen unabhängig auf dasselbe Ticket; ihre Minuten addieren sich in `spent_minutes`.

Ein Lauf führt zwei Zahlen, und das aus einem Grund, den erst die Benutzung zeigte. Gebucht
werden volle Minuten; die Sekunden darunter bleiben als Rest am Lauf und zählen beim nächsten
Mal mit, sonst würden wiederholte kurze Einheiten weggerundet. Zeigte die Uhr diesen Rest,
sprang sie beim Pausieren von 1:30 auf 0:30 zurück — buchhalterisch richtig, als Uhr
unbrauchbar. Was angezeigt wird, ist deshalb eine eigene, ausschließlich wachsende Zahl:
Pausieren friert sie ein, Fortsetzen zählt von dort weiter, und erst Beenden beginnt eine
neue Sitzung bei null. Die gebuchten Minuten liegen damit immer weniger als eine Minute
hinter der Uhr und nie davor. Ein Prüffall geht genau diesen Ablauf durch.

Der Timer ist eine Zeile der Planungsliste wie jede andere: links seine Beschriftung, rechts
die Bedienung. Ein Aufklappbereich stand vorher davor und war die unruhigste Stelle der
Ansicht; als gewöhnliche Zeile braucht er weder Animation noch Zustand und steht neben
Startdatum, Fälligkeit und Erfasst, wohin er gehört.

Bedient wird der Timer über eine einzige Schaltfläche, und die steht in der Zeile `Erfasst`
hinter der gebuchten Summe. Eine eigene Timer-Zeile gibt es nicht mehr; die laufende Uhr
gehört neben die Zahl, die sie verändert. Die Schaltfläche trägt beides: Läuft sie, zeigt sie
die Uhr, und sie sagt, was ein Druck täte, wenn der Zeiger ankommt und für 1,2 Sekunden nach
jeder Zustandsänderung. Der Weg zurück zur Uhr fragt den Zeiger bewusst nicht: Nach einem
Klick steht er noch auf der Schaltfläche, und darauf zu warten, dass er sie verlässt, ließ das
Symbol so lange stehen, wie die Hand ruhte. Sie wächst dafür in der Breite, und die Kontur wird an der
Schaltfläche gemessen, wie sie gerade ist, statt an festen Maßen.

Ein Tippen startet und pausiert, langes Drücken beendet. Doppelklick wäre hier falsch — der
erste Klick müsste rund eine Viertelsekunde abwarten, ob ein zweiter folgt, und Start und
Pause würden träge. Die ersten 160 ms eines Drucks passiert nichts; erst danach schließt sich
in 620 ms eine Kontur. Damit bleibt ein normaler Klick vollkommen still. Die Kontur trägt die
Farbe der Schaltfläche selbst, nicht die Warnfarbe, und liest sich so als Aufladen statt als
Alarm. Außerhalb des Haltens ist sie ganz ausgeblendet: eine runde Strichkappe zeichnet sonst
auch bei Strichlänge null noch einen Punkt. Leertaste und Enter halten ebenso.

Pausieren hält die Uhr an und schreibt nichts; erst Beenden bucht, was sie zeigt. Sonst hätte
Beenden keine eigene Bedeutung. Die Folge davon ist auszusprechen: Zeit, die nie beendet wird,
erreicht das Ticket nicht. Sie ist nicht verloren — sie wartet am Lauf und steht beim nächsten
Besuch wieder auf der Uhr —, aber sie zählt eben noch nicht. Der Rest unter einer Minute bleibt
über das Beenden hinaus liegen, damit mehrere kurze Sitzungen nicht weggerundet werden.

Ein Druck auf einen laufenden Timer hält die Uhr sofort an und nicht erst am Ende der Geste.
Sonst würden die 780 ms, die das Halten dauert, mitgebucht. Losgelassen bleibt sie pausiert —
das ist ohnehin, was ein Tippen bedeutet — und wer weiterhält, macht daraus ein Beenden, bei
dem nichts mehr hinzukommt. Ein Prüfdurchlauf im Browser zeigt genau diese Folge: `start`,
`pause` beim Druck, `stop` am Ende des Haltens, und dazwischen keine weitere Anfrage.

Was der Server nach einem Lauf zurückgibt, wird auch angezeigt, statt bis zum nächsten Laden
zu warten. Die Zeile `Erfasst` übernimmt die neue Summe in derselben Schreibweise, die das Feld
annimmt, und die Zahl selbst leuchtet einmal auf — die Schrift, nicht der Hintergrund — das ist das Signal, dass gebucht wurde. Es erscheint also
beim Beenden und nicht beim Pausieren. Bucht ein Lauf nichts, weil er unter einer Minute blieb,
leuchtet auch nichts; ein Signal ohne Anlass wäre eine Lüge. Während das Feld bearbeitet wird,
tritt die Schaltfläche zur Seite: Die Zeile wird dabei zum Stapel, und ein Knopf neben einem
zweizeiligen Formular hilft niemandem. Der grüne Punkt auf der Karte und die Marke in der Kopfleiste werden ebenso
mitgeführt: Startet eine Erfassung, erscheinen sie sofort, endet sie, verschwinden sie.

Dauern werden so geschrieben, wie man sie sagt: `2h 40m`, `2h30`, `40m`, `45min`, `1:30`,
`1,5h` oder eine nackte `90` für Minuten. Eine nackte Dezimalzahl bleibt abgewiesen, weil
„1.5“ allein weder Minuten noch Stunden sagt; mit Einheit ist `1.5h` eindeutig. Angezeigt
wird dieselbe Schreibweise, die das Feld annimmt, sodass Gelesenes unverändert wieder
eingetippt werden kann.

Timer-Schreibzugriffe tragen bewusst keine Ticketversion. Alles andere im Projekt nutzt
optimistische Sperren, aber wer eine Stunde erfasst hat, hielte beim Pausieren längst eine
veraltete Version, und ein Konflikt würde dort echte Arbeit verwerfen. Minuten zu addieren
ist kein Ersetzen und kann mit keiner fremden Änderung kollidieren. Projektgrenzen gelten
unverändert: Leserechte erlauben keine Zeitbuchung, und für Fremde bleibt das Ticket
unauffindbar.

Geprüft mit 70 MariaDB-, 70 PostgreSQL-, 95 HTTPS- und 25 Profilprüfungen, den Worker- und
AI-Checks sowie der vollständigen Stilprüfung. Die Prüfungen decken Übertrag unter einer
Minute, das Beenden eines Laufs beim Start eines anderen — auch projektübergreifend —,
Pausieren mit veralteter Version, zwei Personen am selben Ticket, Skalenwechsel mit Erhalt
und Abbildung sowie Spaltensummen gegen die tatsächlich gerenderten Karten ab.
`/health/ready` meldet Schema `202609170002`.


## Sprache im Kopfbereich

Neben dem Avatar stand die eigene Projektrolle — eine Angabe, die daneben in der Seitenleiste
und über dem Board ohnehin steht. An ihrer Stelle wählt man dort jetzt die Sprache. Die Namen
kommen aus `Naf\I18n\Support\Language`, wo jede Sprache in sich selbst geschrieben ist, damit
sie auch erkennt, wer die gerade eingestellte nicht lesen kann. Angeboten wird nur, wofür eine
Übersetzungsdatei existiert; `App\Support\Locales` leitet die Liste aus dem Verzeichnis ab,
statt die 24 Sprachen des Frameworks zu versprechen. Flaggen stehen daneben als Wiedererkennung,
nicht als Kennung — ein Land ist keine Sprache, deshalb trägt der geschriebene Name die Aussage.

Bedient wird ein gewöhnliches `select`, nur die Hülle ist gestaltet: Tastatur, Bildschirmleser
und das Menü des Betriebssystems bleiben damit unverändert. Auf schmalen Bildschirmen entfällt
der geschriebene Name, die Flagge bleibt. Die Auswahl schreibt über einen eigenen, engen
Endpunkt nur das Sprachfeld. Der vorhandene `save()` schreibt jedes Feld, ob mitgeschickt oder
nicht, und hätte Thema, Zeitzone und beide Benachrichtigungsschalter auf die Vorgaben
zurückgesetzt; ein Prüffall hält das fest.


## Symbole und aufklappbare Bereiche

Die Oberfläche mischte Schriftzeichen als Symbole — ▦, ◉, ⚙, ◐ — mit einzelnen Inline-SVGs.
Jetzt zeichnet durchgängig Material Symbols Outlined. Die Schrift liegt im Projekt unter
`app/public/assets/vendor/material-symbols`, weil die Content-Security-Policy Schriften nur
von dieser Herkunft erlaubt und die Oberfläche nicht darauf warten soll, dass ein Dritter
erreichbar ist. Ausgeliefert wird nur, was benutzt wird: 60 Glyphen in 6,4 kB statt der
vollständigen Familie in mehreren Megabyte; `README.md` daneben nennt die Namen und den
Befehl, mit dem die Teilmenge neu erzeugt wird. `App\Support\Icon` setzt sie und versteckt
jedes Symbol vor Bildschirmlesern, denn die Ligatur trägt den Namen als Text und der gehört
nicht vorgelesen — die Beschriftung sitzt am umgebenden Bedienelement.

Ein `details` springt beim Öffnen und Schließen in einem Frame und verschiebt damit alles
darunter um eine ganze Bereichshöhe. `disclosure.js` gibt jedem Aufklappbereich der Anwendung
dieselbe gemessene Bewegung; animiert wird die Höhe des Elements selbst und kein Hüllelement,
weil mehrere Ansichten den Inhalt eines `details` austauschen und eine Hülle dabei verlören.
Die Kurve bremst ab statt zu federn. Führt eine geöffnete Stelle unter den Fensterrand, wird
sie gerade so weit hereingeholt; war sie ohnehin sichtbar, bewegt sich nichts. Bleibt ein
Frame aus, schließt ein Zeitlimit den Bereich trotzdem.

Die Informationen am Fuß der Ticket-Seitenleiste klappen damit zu. Die Zeitzeile richtet sich
jetzt an der Wertespalte aus — erst die verstrichene Zeit, dann die Schaltfläche am Zeilenrand,
damit `18:42` unter `0m` steht und nicht daneben. Die Spaltenauswahl heißt `Status`, weil sie
im Kanban genau das ist; die Auswahl offen/geschlossen daneben heißt `Abschluss`, sonst
trügen zwei Dinge in derselben Leiste denselben Namen.


## Die Ticketleiste fährt ein

Lesen und Anlegen benutzen jetzt dieselbe Leiste am rechten Rand; das Anlegen stand vorher
mittig, was zu einer Bewegung von rechts nicht gepasst hätte. Sie fährt von dort ein, wo sie
verankert ist, und wieder dorthin zurück.

Beide Richtungen macht das Stylesheet allein: `display` und `overlay` werden als diskrete
Eigenschaften mitübergeben, wodurch der Dialog bis zum Ende der Bewegung in der obersten
Ebene bleibt. Das deckt auch das Schließen per Escape ab, das kein Skript von uns zu sehen
bekäme, ohne den vorhandenen Schließweg mit seinem Entwurfschutz anzufassen. Browser ohne
`@starting-style` zeigen die Leiste ohne Bewegung; nichts geht dabei verloren.

Gemessen: Beim Öffnen steht die Leiste auf `translateX(983px)`, also vollständig außerhalb
des rechten Rands, und liegt am Ende bündig an ihm — rechte Kante 1024 bei 1024 Pixeln
Fensterbreite.


## Die Aktionen eines Tickets

„Ticket archivieren“ stand als einzelner Link am Fuß der Seitenleiste und die Auswahl
offen/geschlossen als eigene Zeile darüber. Beides liegt jetzt hinter einem Punktemenü in der
oberen rechten Ecke der Seitenleiste, wo weitere Aktionen Platz haben, ohne je eine Zeile zu
belegen. Die Zeile `Abschluss` entfällt damit; der Zustand steht ohnehin im Kopf des Tickets.

Ein Menü ist kein Aufklappbereich: Es legt sich über die Seite, statt sie auseinanderzuschieben,
und behält deshalb den nativen Umschalter statt der gemessenen Höhenbewegung. Es schließt, wie
Menüs schließen — durch einen Klick daneben oder Escape, das den Fokus zurückgibt.

Der Rückweg oben heißt außerhalb der Leiste jetzt „Zurück zum Board“ statt des Projektnamens;
in der Leiste bleibt der Name, weil der Klick dort die Leiste schließt und nirgendwo hinführt.


## Anhängen mit Fortschritt

Das Anhängen war das nackte Dateifeld des Browsers samt „Keine Datei ausgewählt“ und der
Sprechblase, die erscheint, wenn man ohne Auswahl absendet. An seine Stelle tritt eine Fläche,
auf die man klicken oder ziehen kann, danach die Auswahl im Klartext mit Größe und einem
Kreuz zum Verwerfen, und darunter ein Balken, der meldet, wie viel vom Rumpf tatsächlich
hinausgegangen ist.

Gesendet wird mit `XMLHttpRequest`, nicht mit `fetch`: Nur dort gibt es Fortschritt beim
Hochladen. Mit 9 MiB gegen einen absichtlich langsamen Endpunkt meldet der Balken zwanzig
Stufen, 0, 12, 15, 18, 25, 37 und so fort bis 100; bei kleinen Dateien springt er auf einmal
durch, weil der Socket-Puffer sie im Ganzen schluckt — das ist ehrlich und nicht zu beheben.

Die Auswahl ist die ganze Handlung: Wer eine Datei wählt oder fallen lässt, hat sie damit
hochgeladen, es gibt nichts weiter zu bestätigen. Der Knopf „Hochladen“ bleibt im Markup und
wird vom Skript ausgeblendet — er ist der Weg für einen Browser, in dem nichts davon läuft.
Dasselbe Kreuz, das eine Auswahl verwirft, bricht einen laufenden Upload ab.

Das Formular bleibt ein gewöhnliches Multipart-Formular. Das Dateifeld wird nur optisch
verborgen und nie ersetzt, deshalb funktioniert das Anhängen auch ohne das Skript. Die Grenze
von zehn Mebibyte wird schon im Browser geprüft, damit niemand eine Minute hochlädt, um dann
abgewiesen zu werden; der Server prüft sie unverändert weiter. Nach dem Hochladen wird die
Liste als Fragment nachgeladen statt geraten, und die neue Zeile leuchtet einmal auf.


## Ein Ticket wechselt das Projekt

Im Punktemenü der Seitenleiste steht jetzt „In ein anderes Projekt verschieben“. Der Eintrag
klappt an Ort und Stelle auf, statt das Menü zu verlassen: Zielprojekt, Folgen und der Knopf
stehen in einer Ansicht. Angeboten wird nur, wo die Person wirklich schreiben darf — die
Rechte werden je Projekt gelesen, weil eine eigene Rolle weniger gewähren kann, als ihr Name
vermuten lässt. Archivierte Projekte und das eigene stehen nicht darin.

Zwei Sätze nennen, was passiert. Mitkommen: Kommentare, Anhänge, Verlauf und erfasste Zeit.
Zurückbleiben: Labels, Verknüpfungen und Zuständige ohne Zugriff. Das Ticket bekommt drüben
eine neue Nummer und damit ein neues Kürzel; die alte Adresse antwortet danach mit 404, und
das Formular folgt der neuen, statt einen Stand nachzuladen, den es nicht mehr gibt.

### Autorschaft hängt nicht mehr an einer Mitgliedschaft

Drei Schlüssel zeigten auf `project_members`: wer ein Ticket angelegt, einen Kommentar
geschrieben oder eine Datei angehängt hat. Das ist eine Aussage über die Vergangenheit und
bleibt wahr, wenn die Person das Projekt verlässt oder im Zielprojekt nie war. Gelesen werden
durfte deshalb ohnehin nie — das entscheidet jede Anfrage gegen das Projekt, in dem die Zeile
jetzt liegt. Die drei zeigen nun auf `users`. Ohne diesen Schritt bräuchte es eine Vorabprüfung
über jeden Kommentar und jeden Anhang und eine Absage, die niemand beheben kann außer dadurch,
den früheren Autor nachträglich einzuladen.

### Was am Ticket hängt, folgt ihm

`activities`, `attachments`, `comments` und `ticket_assignees` tragen ihren Schlüssel auf
`tickets(project_id, id)` jetzt mit `ON UPDATE CASCADE`. Ein einziges `UPDATE` auf dem Ticket
nimmt sie mit, statt sie Tabelle für Tabelle umzuschreiben. `ticket_labels`, `ticket_links`,
`notifications` und `ticket_timers` behalten absichtlich den einfachen Schlüssel: Sie bedeuten
nur in dem Projekt etwas, in dem sie entstanden sind, werden vorher aufgelöst — und wer eine
davon vergisst, bekommt einen Fehler der Datenbank statt einer Zeile, die irgendwo ankommt, wo
sie nicht hingehört.

Eine Antwort zeigt über `(project_id, ticket_id, parent_id)` auf den Kommentar darüber, und
beide Treiber prüfen das je Zeile, während sie sich ändert, nicht erst am Ende der Anweisung —
gegen MariaDB nachgestellt und in beide Sortierrichtungen abgewiesen. Der Faden wird deshalb
vor dem Umzug auseinandergenommen und danach wieder zusammengesetzt; die Lücke besteht nur
innerhalb der Transaktion und wird von niemandem gelesen.

Laufende Uhren werden beendet, bevor das Ticket geht: Was gearbeitet wurde, erreicht das
Ticket noch, nur der Lauf selbst kann nicht mitkommen, denn er gehört zu einer Mitgliedschaft.
Beide Projekte werden gesperrt, das kleinere zuerst, damit zwei Umzüge in entgegengesetzter
Richtung aufeinander warten statt sich gegenseitig zu blockieren.

Der Rückweg der Migration verengt die Schlüssel wieder und behauptet damit etwas, das ein
bereits verschobenes Ticket unwahr gemacht hat. Die einzige Reparatur, die nichts erfindet,
trägt die betroffenen Personen als inaktive Mitglieder ein — genau die Zeile, die die Anwendung
behält, wenn jemand aus einem Projekt entfernt wird. Sie gewährt nichts: Jeder Lese- und
Schreibpfad verbindet über eine aktive Mitgliedschaft.

Geprüft mit 74 MariaDB-, 74 PostgreSQL-, 102 HTTPS- und 25 Profilprüfungen, den Worker- und
AI-Checks sowie der vollständigen Stilprüfung. Die Prüfungen decken den vollständigen Umzug
samt Kommentarfaden, Anhang, Verlauf, Zuständigen und gebuchter Zeit ab, die Liste der
angebotenen Ziele, sowie jede abgelehnte Form: dasselbe Projekt, ein unbekanntes, eines mit
bloßem Leserecht, eine veraltete Version und ein archiviertes Ticket — jedes Mal ohne Spur.
`/health/ready` meldet Schema `202609180001`.


## Eigenes Profil

Der Avatar öffnet das Profil-Modal mit der aktuellen Projektrolle, Passwortwechsel,
verifizierter E-Mail-Änderung und Logout. Die Abläufe verwenden NAFs Auth-/Session-,
Formular-, Mail- und Queue-Verträge. Neue Adressen bleiben bis zum Bestätigungscode
inaktiv; erfolgreiche Kontowechsel widerrufen bestehende Sitzungen. Sicherheitshinweise
gehen an die bisherige Adresse, lokal über Mailpit auf http://localhost:8025.

[Profile.md](Profile.md) beschreibt Bedienung, Architektur und Grenzen;
[Profile-Evidenz.json](Profile-Evidenz.json) enthält die aktuellen Prüfergebnisse.
174 Datenbank-/HTTPS-/Worker-Prüfungen, beide AI-Suites und alle Stilprüfungen sind grün.
Die direkte Abnahme im angemeldeten Firefox ist noch offen: Firefox ist auf dem gesperrten Mac
nicht steuerbar; der interne Browser meldet weiterhin einen Zertifikatsfehler. Diese
Zertifikatswarnung wurde nicht umgangen. Die HTTPS-Tests verwenden die lokale CA regulär.

### Nutzerkarte, Rollenanzeige und Bedienhinweise

Die gesamte Nutzerkarte am unteren Rand der Seitenleiste öffnet das native Profil-Modal.
Beide Auslöser teilen dessen Öffnungszustand und geben beim Schließen den Fokus zurück.
Die Karte und die Kopfzeile zeigen `ProjectScope::roleName`; ohne Projektkontext steht
„Persönliches Konto“. Im Modal sind die eigenen Projektrollen aus der bereits autorisierten
Projektabfrage verlinkt. Der frühere Spruch in der Seitenleiste führt jetzt direkt zur
Settings-Karte „Rollen & Rechte“ beziehungsweise zur Projektauswahl.

Anmeldung, Projekte, Board, Ticketformular, Benachrichtigungen, Settings, Profil und AI-Chat
verwenden konkrete Hinweise statt allgemeiner Motivationssätze. Die neuen Texte nutzen
NAFs i18n-Kataloge. Board-Hinweise berücksichtigen Lesezugriff, Filter, Ergebnislimit und
Archivierung. Leere Zellen nennen ihren Zustand und enthalten kein funktionsloses Plus.
Lange Rollennamen werden in kompakten Anzeigen begrenzt; die Seitenleiste scrollt bei wenig Höhe.

11 lokale HTTPS-Prüfungen mit eigenen temporären Sitzungen sind grün: sieben Seiten mit
Owner-Rolle beziehungsweise persönlichem Kontext, Profilabfrage, gefilterter Viewer-Zugriff,
Abmelden der Testsitzungen und identische CSS-/JS-Auslieferung. Keine Kontodaten, Rollen oder
Projektinhalte wurden dabei geändert. Acht synthetische NAF-Ansichten ergänzen die Prüfung,
unter anderem eigene Rollen, archivierte Projekte und Englisch. Stil- und JavaScript-Prüfung
sind grün.

Die isolierte Browser-Vorschau bestätigt die Nutzerkarte, X/Escape und Fokus-Rückgabe,
den Direktlink zum Rollen-Dialog sowie helle/dunkle Darstellung. Bei 390 × 844 und
320 × 568 Pixeln bleiben Profil und Seitenleiste bedienbar; die Seite läuft auch mit
langem Rollennamen nicht horizontal über. [Bedienung-UI-Evidenz.json](Bedienung-UI-Evidenz.json)
trennt diese Vorschau von der noch offenen Firefox-Abnahme. Die Änderungen gelten für
Source-Betrieb; der frühere Candidate wurde für diese UI-Runde nicht neu gebaut.

## Settings und lokale AI

Der Source-Prototyp verwendet außerdem einen eigenen, abgerundeten Kontur-Cursor mit
violetter Hervorhebung über klickbaren Elementen. Vier SVGs mit jeweils 28 × 28 Pixeln passen ihn an helle
und dunkle Darstellung an. Native CSS-Cursor behalten den präzisen Klickpunkt auch in
Dialogen; Textfelder, Ziehen und Wartezustände verwenden weiterhin die passenden Cursor.
Touch-Geräte und erzwungene Systemfarben erhalten die Browser-Standards. Es gibt keinen
zusätzlichen JavaScript-Prozess für Mausbewegungen. SVGs und CSS wurden über verifiziertes
HTTPS geprüft. Firefox bestätigt den Kontur-Cursor für Flächen, die violette Variante für
Buttons und Links sowie den Textcursor in Eingabefeldern. Der aktualisierte Runtime-Snapshot
unten enthält auch die Profiloberfläche und die neuen Kontofunktionen.

Die neue Settings-Seite bündelt persönliche und projektbezogene Einstellungen in acht
kompakten Karten. Beim Öffnen und Schließen animiert der Dialog zwischen Karte und
Inhalt; X, Escape, Fokus-Rückgabe und reduzierte Bewegung sind berücksichtigt.
Owner können eigene projektgebundene Rollen mit konfigurierbaren Rechten anlegen und
Benutzern zuordnen. Native Auth-Policies prüfen die aktuellen Rechte bei jeder Aktion,
einschließlich AI-Aufrufen und der Wiederherstellung unterbrochener Uploads.

Die lokale AI übernimmt kompatible Ollama-, Werkzeug- und Markdown-Bausteine aus der
NAF-Version von nixcms. Modellwahl, Verbindungstest, Prompts, Gedächtnis, Feedback und
ein Chat mit Streaming sind integriert. Die Werkzeuge verwenden NAFs MCP-Verträge und
die bestehenden App-Services. Schreibende Vorschläge benötigen eine ausdrückliche
Bestätigung mit sichtbaren Argumenten. Browserdaten sind nach Nutzer und Projekt getrennt.
Der Embedding-Layer indiziert Werkzeugdefinitionen in einem persistenten Browser-Cache,
begrenzt die Vorauswahl und berücksichtigt benötigte Lesewerkzeuge. Ein lokaler Katalogtest
mit 500 Definitionen ergab rund sieben Sekunden für den ersten Index und 66 ms für die
nächste Auswahl mit vorhandenem Index. [Settings-AI.md](Settings-AI.md) beschreibt die Grenzen.

Im Firefox über vertrauenswürdiges HTTPS geprüft: Kartenübersicht, Rollen-Dialog,
Schließen mit X und Escape sowie Fokus-Rückgabe, Modellabfrage, Speichern der
AI-Einstellungen und Live-Antwort. `gemma4:e2b` hat über das Board-Werkzeug die vier
tatsächlichen Spalten gelesen. Eine vorgeschlagene Ticketanlage zeigte ihre Argumente
und wurde nach „Ablehnen“ nicht ausgeführt. Die AI ist im geprüften Alice-Browser aktiviert.
Diese Settings-Abnahme fand am Desktop statt; die frühere mobile Board-Abnahme unten
ist ein separater Nachweis.

Bedienung und Architektur stehen in [Settings-AI.md](Settings-AI.md), aktuelle
Prüfergebnisse in [Settings-AI-Evidenz.json](Settings-AI-Evidenz.json). Die neue Migration
ergänzt eigene Rollen ohne Änderungen an bestehenden Mitgliedschaften. Vor dem Einspielen
wurde `work/backups/20260915T202311Z` erstellt.

### Überarbeiteter AI-Chat

Der aktuelle Source-Stand ersetzt den breiten Launcher durch ein 44 × 44 Pixel großes
Sternsymbol. Die Chatfläche wächst beim Öffnen aus dessen Position und fährt beim
Schließen zurück; Inhalte blenden separat ein. Eine kompakte Kopfzeile, kontextbezogene
Einstiege und der kleine Sende-/Stopppfeil im mitwachsenden Eingabefeld ergänzen das Layout.
Fokus-Rückgabe, Escape und reduzierte Bewegung sind berücksichtigt.

Das echte NAF-Template wurde mit einem synthetischen, nicht gespeicherten Nutzer in einer
isolierten Browser-Vorschau geprüft: dunkle und helle Darstellung, Öffnen/Schließen,
Fokus-Rückgabe, Entwurf ohne Absenden, neuer Chat, mehrzeilige Eingabe, leerer Sendebutton
und Konfigurationshinweis. Bei 390 × 844 und 320 × 568 Pixeln bleibt die Chatfläche innerhalb
des Viewports; der Verlauf scrollt intern. Es gab keine Browserfehler. Die Vorschau hat
keine realen Konten, Chats oder Modellaufrufe verwendet.

JavaScript-Syntax, PHP-Template, `make test-ai` und `bin/style check` sind grün. Die App liefert
die neuen CSS-/JS-Dateien über CA-verifiziertes HTTPS identisch zum Source aus.
[AI-Chat-UI-Evidenz.json](AI-Chat-UI-Evidenz.json) hält die Prüfung und ihre Grenzen fest.
Die direkte Abnahme im angemeldeten Firefox bleibt wegen des gesperrten Macs offen.
Der unten dokumentierte Runtime-Snapshot enthält noch die vorherige Chatgestaltung;
die aktuelle Oberfläche ist im Source-Betrieb auf https://localhost verfügbar.

## Bewegte Board-Karten

Das Board zieht Karten nicht mehr über die native HTML5-Drag-API, sondern über Pointer-Events.
Die aufgenommene Karte schwebt als eigenes Element unter dem Zeiger, neigt sich leicht in die
Bewegungsrichtung und lässt an ihrer alten Stelle einen gestrichelten Platzhalter zurück. Der
Platzhalter wandert live in die Zielzelle; die verdrängten Karten gleiten per FLIP-Technik an
ihre neue Position statt zu springen. Zellen am Rand scrollen das Board mit, Escape bricht ab
und legt die Karte zurück, und ein Ablegen auf der Ausgangsposition erzeugt gar keine Anfrage.
Auf Touch und Stift hebt erst ein kurzes Halten die Karte an, damit Wischen weiterhin scrollt.

Erreicht eine Karte eine abschließende Spalte, zündet ein kleines Feuerwerk auf einem
gemeinsamen Canvas über der Seite: ein Blitz mit Druckring, gut fünfzig Funken und Konfetti,
dazu zwei kleine Raketen, die neben der Karte aufsteigen und verzögert zerplatzen. Die Karte
selbst bekommt einen grünen Rahmen, einen kurzen Stempel und dauerhaft ein Häkchen vor der
Ticketnummer. Weil additive Mischung auf hellem Grund ausbleicht, wählt der Canvas Blendmodus
und Farbpalette nach dem aktiven Farbschema. Der Canvas räumt sich selbst ab, sobald der
letzte Funke erloschen ist, und bleibt bei reduzierter Bewegung vollständig aus.

Damit das Board ohne Neuladen stimmig bleibt, liefert `POST /projects/{id}/tickets/{id}/move`
jetzt zusätzlich die neue Version und den Status des Tickets. Spalten- und Swimlane-Zähler,
leere Zellen und der Erledigt-Haken werden daraus direkt aktualisiert; eine abgelehnte
Verschiebung wandert sichtbar zurück, wackelt kurz und meldet den Servertext. Der Weg über
das Kartenmenü reicht die Feier über `sessionStorage` an die neu geladene Seite weiter.

Geprüft wurde der echte Seitenquelltext mit den echten Assets in einer isolierten Vorschau:
Ziehen zwischen Spalten und Swimlanes, Ablegen in leere Zellen, Umsortieren innerhalb einer
Spalte, Abbruch per Escape, abgelehnte Verschiebung mit Rücksprung, Tippen ohne Ziehen,
Wischen ohne Aufnehmen und Halten mit Aufnehmen. Der Rundlauf gegen die laufende App
bestätigt Version und Status in der Antwort. `bin/style check` und `make test-http` sind grün.

### Bis es ruhig war

Das Ablegen sah danach noch unruhig aus, und es brauchte mehrere Anläufe, die Ursachen
auseinanderzuhalten — sie sahen sich ähnlich, hatten aber nichts miteinander zu tun.

Die Einflugbewegung vom Seitenaufbau galt dauerhaft. Weil ein Zug die Karte aus dem Dokument
nimmt und wieder einsetzt, spielte sie dabei erneut ab: mit ihrer gestaffelten Verzögerung
also erst unsichtbar, dann einblendend. Sie hängt jetzt an einer Markierung, die nach dem
ersten Abspielen entfernt wird.

Beim Landen stritten drei Wirkungen um dieselbe Eigenschaft: eine federnde Maßstabsanimation,
die in jedem Abschnitt über ihr Ziel hinausschoss, und die Hover-Anhebung, sobald die
Zeigersperre am Ende des Zuges fiel. Die Ankunft wird jetzt nur noch durch einen Ring
markiert, der nichts bewegt.

Der Platzhalter wählte seine Stelle als reine Funktion der Kartenposition, ohne Beharrung.
An der Grenze zwischen zwei Stellen kippte er dadurch schon bei einem Pixel Handzittern um
eine ganze Kartenhöhe. Er gibt seine Stelle jetzt erst auf, wenn eine andere spürbar besser
passt, gemessen dreizehn Pixel; dieselbe Beharrung gilt für die Spalte, weil im Spalt zwischen
zwei Spalten die Abstände fast gleich sind.

Beim Ziehen quer über das Board fuhr die Karte durch die Spalten dazwischen hindurch und
sortierte sie jedes Mal um — deren Karten machten Platz und nahmen ihn sofort wieder zurück.
Unterwegs sein ist aber nicht zielen: Der Platzhalter bleibt jetzt liegen, solange die Karte
zügig bewegt wird, und folgt, sobald die Hand zur Ruhe kommt. Kommt sie ganz zum Stillstand,
holt die Bildschleife das nach, weil dann keine Zeigerereignisse mehr eintreffen; beim
Loslassen wird die Stelle in jedem Fall final bestimmt.

Zuletzt blieb ein Flimmern der ganzen Karte, zwei- bis dreimal pro Zug — also einmal je
Gleitbewegung. Die schwebende Karte lässt Zeigerereignisse durch, sodass die Karte darunter
den Hover-Zustand annahm und ihren Rahmen samt Titel aufblitzen ließ; das ist abgeschaltet,
seit die Zielspalte geometrisch statt über Treffererkennung bestimmt wird. Der eigentliche
Grund lag jedoch tiefer: Jede Gleitbewegung hob die Karte auf eine eigene Zeichenebene und
nahm sie danach wieder herunter, und dieses Auf und Ab musste Farbverlauf, Schlagschatten und
runde Ecken jedes Mal neu rastern. Die Beförderung hält jetzt für die Dauer des Zuges, und
der ohnehin unsichtbare Verlauf hinter der Karte entfällt dabei.

Diese letzte Ursache ließ sich in der Vorschau nicht beobachten, weil sie keine Bilder
zeichnet; sie wurde aus dem Symptom erschlossen und am Nutzer bestätigt.

## Seitenleiste als Schiene

Die Navigation steht im Ruhezustand nur noch als 56 Pixel breite Schiene: Markenzeichen,
Projektsymbole, Theme, Einstellungen und Avatar bleiben sichtbar, alle Beschriftungen sind
ausgeblendet. Ein Projekt mit offenen Tickets trägt dabei einen kleinen Punkt auf seiner
Kachel. Beim Überfahren, beim Tastaturfokus oder per Pin wächst die Leiste auf ihre volle
Breite von 244 Pixeln und legt sich mit Schatten über den Inhalt, statt ihn umzubrechen.
Das Öffnen setzt kurz verzögert ein und das Schließen etwas später, damit die Leiste beim
bloßen Vorbeifahren nicht aufspringt; während eine Ticketkarte gezogen wird, bleibt sie zu.

Der Pin sitzt als eigener Eintrag über Theme und Einstellungen und merkt sich seinen Zustand
lokal. Weil die Content-Security-Policy `script-src 'self'` setzt und Inline-Skripte damit
ausschließt, wendet ein kleines blockierendes `boot.js` den gespeicherten Zustand vor dem
ersten Zeichnen an; ohne das würde eine angeheftete Leiste bei jedem Seitenaufruf kurz als
Schiene aufblitzen. Unterhalb von 761 Pixeln bleibt alles beim Bisherigen: Hamburger-Knopf
und einfahrendes Overlay, ohne Schiene und ohne Pin.

Die Beschriftungen werden über ein einziges `--panel-open`-Flag ein- und ausgeblendet, damit
die Liste der betroffenen Elemente nur an einer Stelle steht. Sie verschwinden über die
Deckkraft und bleiben im Accessibility-Baum, sodass die Icon-Links ihren Namen behalten;
nur die beiden fokussierbaren Elemente der Leiste — Projekt-Plus und Hinweiskarte — werden
zusätzlich über `visibility` aus dem Zugriff genommen.

Zwei Dinge fielen beim Ausprobieren auf. Die Hinweiskarte wuchs mit der Leiste mit und
brach ihren Text bei jeder Zwischenbreite neu um, was sie sichtbar auseinanderzog; sie
erscheint jetzt erst, wenn die Breite nach 0,34 Sekunden steht, und verschwindet beim
Einfahren weiterhin sofort. Und die Projektliste trug `overflow: auto`, sodass ihre Zeilen
in der schmalen Schiene kurz einen waagerechten Rollbalken erzeugten; sie wird dort jetzt
beschnitten, und ein langer Projektname endet mit Auslassungspunkten statt mitten im Wort.
Die gemeinsame Liste der Beschriftungen steht zudem in `:where()`, damit einzelne Elemente
ohne Spezifitätskampf eigene Zeiten bekommen können.

Beim Ziehen dockte der Platzhalter nicht immer dort an, wo die Karte zu sehen war. Die Ursache
war die Bezugsgröße: Entschieden wurde nach dem Mauszeiger, gesehen wird aber der Kartenkörper.
Wer eine Karte am unteren Rand greift, hält sie deutlich über dem Zeiger — die Karte stand dann
längst über der Zielkarte, während der Zeiger noch unter deren Mitte lag, und der Platzhalter
rutschte darunter. Umgekehrt beim Griff am oberen Rand. Maßgeblich ist jetzt der Körper der
gezogenen Karte; der Zeiger entscheidet nicht mehr mit.

Die Mitte der Karte gegen die Mitte der Zielkarte zu stellen, war dabei zunächst naheliegend,
aber immer noch die falsche Bezugsgröße, sobald Karten unterschiedlich hoch sind. Eine 230 Pixel
hohe Karte über einer 190 Pixel hohen: Ihre Oberkante steht sichtbar
darüber, ihre Mitte liegt trotzdem fünf Pixel unter der Mitte der Zielkarte, und der
Platzhalter rutschte darunter — eine ganze Kartenhöhe von der Stelle entfernt, an der die
Karte gehalten wird. Der Platzhalter geht jetzt dorthin, wo die Karte ist: Für jede mögliche
Stelle in der Spalte wird ausgerechnet, wo der Platzhalter dann läge, und es gewinnt die
Stelle, die der Oberkante der getragenen Karte am nächsten kommt. Weil diese Stellen ohne den
Platzhalter im Fluss gemessen werden, hängt die Wahl nicht davon ab, wo er gerade steht; es
gibt also keine tote Zone, in der sich nichts mehr bewegt.

Dazu kam ein Rechenfehler: Der Platzhalter wächst beim Aufnehmen erst in seine Höhe hinein,
gerechnet wurde aber immer mit der vollen Höhe der Karte. In den ersten Zehntelsekunden eines
Zuges verschob das jede Stelle unterhalb des Platzhalters um bis zu eine Kartenhöhe. Maßgeblich
ist jetzt, was er gerade einnimmt. Und wer eine Karte an den Fuß einer vollen Spalte hält, hat
ihre Mitte unter der Spalte; solche Punkte treffen keine Zelle mehr und fallen jetzt auf die
nächstgelegene zurück, statt den Zug ins Leere laufen zu lassen. Über fünf Kombinationen aus
Kartenhöhe (173 bis 202 Pixel) und Griffpunkt (5, 50 und 95 Prozent) trifft der Platzhalter
alle sieben möglichen Stellen einer Spalte, einschließlich der letzten, und der Griffpunkt
ändert das Ergebnis nicht mehr.

Zwei weitere Fehler in derselben Rechnung kamen dabei mit heraus. Die Positionen wurden über
`getBoundingClientRect()` gelesen, und das schließt laufende Transformationen ein: Eine Karte,
die gerade zur Seite gleitet, meldete eine Position, die sie noch gar nicht hatte, und die
angehobene Karte unter dem Zeiger zusätzlich ihre drei Pixel Hover-Versatz. Beides wird jetzt
herausgerechnet, und die Hover-Anhebung bleibt während eines Zuges ohnehin aus. Außerdem maß
die FLIP-Animation ihre Zielposition, während die vorherige noch lief, wodurch sich der Fehler
über mehrere Züge aufschaukelte. Die Zielposition wird jetzt rechnerisch um die laufende
Transformation bereinigt, und eine Karte, die bereits genau dorthin unterwegs ist, läuft
ungestört weiter, statt abgebrochen und neu gestartet zu werden.

Geprüft wurden Schiene, Hover, Tastaturfokus, Pin über einen Seitenwechsel hinweg, das
Verhalten bei 1280, 800 und 375 Pixeln Breite sowie Ziehen und Ablegen bei ausgefahrener
Nachbarschaft. Dabei kam ein Fehler ans Licht, der nichts mit der Leiste zu tun hatte: Das
Ablegen einer Karte wartete auf das Ende der Fluganimation, und dieses Versprechen löst in
einem unsichtbaren Tab nicht aus. Wer eine Karte ablegte und sofort den Tab wechselte, sah
die Karte verschwinden. Der Einschub in die Spalte hängt jetzt nicht mehr allein an der
Animation. `bin/style check` und `make test-http` sind grün.

## Texte, die etwas sagen

Unter fast jeder Überschrift stand ein Satz, der die Überschrift noch einmal sagte. Im
Profilfenster stand über zwei Abschnitten namens „Deine Projektrollen" und „Anmeldung" der
Hinweis, man könne hier seine Anmeldung verwalten und seine Rollen sehen; neben dem Knopf
„Abmelden" stand, dass Abmelden die Sitzung beendet. Solche Sätze sind ersatzlos entfallen,
ebenso die Aufforderung, eine Einstellungskarte zu öffnen, der Anmeldehinweis über dem
Anmeldeformular und die Tippkarte beim neuen Ticket. Das Formular für ein neues Ticket nimmt
die frei gewordene Spalte jetzt selbst ein, weil es daneben weder Verlauf noch Status gibt.

Wo der Platz sich lohnte, steht statt der Beschreibung der Zustand. Die Einstellungskacheln
zeigen nicht mehr, welche Felder sie enthalten, sondern was eingestellt ist: „Dunkel ·
Deutsch · Europe/Berlin", „Offen, In Arbeit, Review, Erledigt", „Aktiv · Nafinity". Die
Kachel der lokalen AI kennt der Server nicht, weil ihre Einrichtung im Browser liegt; sie
wird nach dem Laden aus dem gespeicherten Zustand nachgetragen und zeigt bis dahin, dass
nichts eingerichtet ist. Projektübersicht, Benachrichtigungen und Aktivität nennen ihre
Zahlen, und die Aktivität sagt jetzt, wenn ihre Liste bei hundert Einträgen endet — das war
vorher nirgends zu sehen. Die Karte in der Seitenleiste nennt Rolle und Anzahl der Rechte.

Die Rolle stand danach noch zweimal im Profilfenster: einmal als Marke über dem Namen, einmal
in der Liste der Projektrollen darunter. Die Marke ist entfallen; die Liste hebt stattdessen
das Projekt hervor, in dem man gerade ist, und schreibt die eingebauten Rollen groß, die aus
der Datenbank klein kommen.

Im Profilfenster kam dabei eine Lücke ans Licht: Die Schnittstelle liefert seit jeher
`email_verified`, die Oberfläche hat das Feld weggeworfen. Man konnte seiner Adresse also
nicht ansehen, ob sie je bestätigt wurde. Die Zeile unter „E-Mail-Adresse ändern" zeigt nun
die Adresse mit ihrem Zustand, eine offene Änderung im Akzentton und eine unbestätigte
Adresse in der Warnfarbe.

Erhalten bleibt alles, was eine Folge oder eine Grenze erklärt: Passwortregeln, Ablauf des
Bestätigungscodes, Anhanggrenzen, Rollen- und Archivierungsregeln, die Erklärungen zur
lokalen AI und sämtliche Leerzustände. Auch die Board-Hinweise für Archiv, Lesezugriff und
gefilterte Ansicht bleiben, weil sie begründen, warum Ziehen gerade nicht geht; nur der
Hinweis, dass man Karten ziehen kann, ist entfallen.

Nebenbei vereinheitlicht: Benutzer, Personen und Mitglieder meinten dieselben Leute und
heißen jetzt durchgehend Mitglieder. Das Feld für eine neue Mitgliedschaft war mit
„Bestehendes Konto" beschriftet, was keine Feldbezeichnung ist; es heißt jetzt
„E-Mail-Adresse", und die Regel steht im erklärenden Absatz darunter. Weil der Übersetzer
keine Pluralformen kennt und daher „1 Projekte" erschien, wählt `Format::count()` die Form
nach der Zahl.

## Ergebnis des Plans

| Bereich | Umsetzung |
|---|---|
| P0 | Projektumzug, Alpine/PHP-Runtime, Compose, Source-Symlinks, Paketkorrekturen, Migrationen und Auth/PDO-Grundpfad |
| P1 | Lokale Anmeldung, getrennte Projekte, echte Tickets, Versionen/409-Konflikte, Activity, SSR-Board und Ticketdetail/Drawer |
| P2 | Mitgliedschaften/Rollen, Spalten/Swimlanes/Labels, mehrere Verantwortliche, Kommentare, Archiv, Filter/Volltext, Einstellungen |
| P3 | Private Dateien, Quoten, Staging/Recovery, PDO-Queue, Worker/Ticker, In-App-Benachrichtigungen und getesteter Mail-Zustellpfad |
| P4 | Limiter, Health/Schema-Prüfung, Backup/Restore und Runtime-Snapshot geprüft; externe Anmeldung vorbereitet und auf Nutzerwunsch deaktiviert |

Die noch nicht veröffentlichten Pakete verhindern derzeit einen stabilen Install aus
öffentlichen Mindestversionen. Das ist ein eigener Release-Schritt, kein erfolgreicher
Distributionsnachweis. LDAP/OIDC bleiben entsprechend der bestätigten Kontenregel optional;
es gibt weder automatische Kontoanlage noch eine Verknüpfung allein anhand der E-Mail-Adresse.

## Framework als Grundlage

Nafinity verwendet NAF-Routing und Responses, den Container, Auth und Policies, Views und
Escaping, Form/CSRF/Validierung, PDO und die Migration Registry, ORM-Modelle/Repositories,
Events, CLI Commands, Queue, Scheduler, i18n, MCP-Werkzeugverträge und den Mail-Transportvertrag.
Geschäftsregeln liegen in App-Services. Allgemeine Fehler wurden in den zuständigen Paketen
korrigiert. Es gibt keine veränderten Vendor-Kopien.

Die neuen Limiter- und LDAP-Pakete bleiben auf ausdrücklichen Wunsch lokal. Das Storage-Paket
wurde parallel auf `v0.1.0-rc` weiterentwickelt und vom anderen Arbeitskontext auf GitHub
gesichert. Nafinity wurde an dessen aktuelle API angepasst: `Naf\Storage\storage('attachments')`
liefert den konfigurierten privaten Datenträger. Stream-I/O, Verschieben und Löschen
übernimmt das Plugin; `App\Support\AttachmentStorage` enthält Upload-Regeln, sichere
Schlüssel und die lokale Aufräumstrategie. Die SQL-/Dateizustände bleiben im AttachmentService.

Wesentliche Paketkorrekturen betreffen CSRF bei unsicheren Methoden, portable Migrationen
und Sessions, den ORM-Tabellenvertrag und das Eigentum an Transaktionen, Null-Bindings im
Container, Event-Callables, sichere Fehlerausgabe, begrenztes Response-Streaming,
dauerhafte Queue-Reservierungen und den tatsächlichen Ticker-/CLI-Aufruf.

## Prüfergebnisse

| Prüfung | Ergebnis |
|---|---|
| Acht geänderte NAF-Pakete | 371 Tests, 833 Assertions erfolgreich, Host-PHP 8.5.4 |
| App auf MariaDB 11.4 | 36 Integrationsszenarien erfolgreich, Container-PHP 8.5.10 |
| App auf PostgreSQL 17 | Dieselben 36 Szenarien erfolgreich |
| Echte HTTPS-Anfragen | 42 Prüfungen erfolgreich, getrennte Cookie-Jars und parallele Schreibzugriffe |
| AI-Transport | Streaming-Paketgrenzen, Unicode, Werkzeugantworten, Fehler, Abbruch, lokale URLs und Speichertrennung erfolgreich |
| Lokales Ollama | Echte Werkzeugrunde mit `gemma4:e2b`; zusätzlich Chat und Ablehnen einer Schreibaktion im Firefox geprüft |
| Aktueller Code-Stil | 67 PHP-Dateien nach PER Coding Style 3.0, alle JS-/CSS-Dateien und acht Python-Skripte erfolgreich geprüft |
| Neue Plugin-Verträge | Private Upload-Grenzen, Limiter und LDAP-Provider mit Fake-Directory erfolgreich |
| Aktuelle Storage-Paketsuite | Isolierte PHP-8.5.10-Runtime: 123 Tests, 498 Assertions, ein plattformabhängiger Skip; Host-Discovery, DI und Beispiele erfolgreich |
| Minimaler NAF-Stack | Sieben Plugins gebootet; erforderliche PDO-Bindung auch ohne OAuth vorhanden |
| Alle bestehenden relevanten Plugins | 15/15 gebootet, 20 Commands und 14 Routen unter PHP 8.5.10 |
| Worker-Prozessabbruch | SIGKILL während einer Aufgabe, dauerhafte Reservierung erhalten, frischer Worker übernimmt und bestätigt; Lease im Test gezielt vorgezogen |
| Deadletter | Fehlende Jobklasse liefert Fehlerstatus und bleibt als fehlgeschlagene Aufgabe sichtbar |
| Upload-Ausfall | Unterbrochene Promotion bleibt staged, Download gesperrt, Wiederholung erzeugt genau eine erfolgreiche Activity |
| Mail-Ausfall | Fehler sichtbar, Retry erfolgreich, normale Doppelzustellung unterdrückt, entzogene Mitgliedschaft verhindert Versand; ausschließlich Testtransporte |
| Backup/Restore | 24 Tabellen, drei Konten, neun Tickets und sechs Migrationen in isolierter Restore-DB; private Testdatei mit gleichem Hash |
| Source-Live-Reload | Zwei Änderungen derselben Framework-Klasse über FPM beobachtet; Reflection zeigt `/workspace/packages/framework` |
| Download | 64 MiB tatsächlich übertragen, ohne entsprechende Vergrößerung des PHP-Heap-Peaks; Messung mit PHP-Allokationsseiten, kein Nachweis von null Speicherverbrauch |
| Lokales Runtime-Image | Anmeldung, fünf geschützte Seiten, Fremdprojekt 404 und privater Download erfolgreich; 18 NAF-Pakete, keine Source-Mounts/Vendor-Symlinks |
| Composer | Manifeste der beteiligten Pakete und der App mit `validate --strict` geprüft |

Die HTTP-Abnahme umfasst Fremdprojekt-IDs, Rollen, CSRF einschließlich manipuliertem
Bearer-Header und ungültigen Tokens, gespeichertes HTML als escaped Text, parallele Moves
mit einem Erfolg und einem 409, private Downloads, Dateilöschung, Login-Limit und den
Schutz von `.env`, `vendor`, Composer-Dateien und Storage durch den Public-Webroot.

Im Browser geprüft: Demo-Login, Ticket anlegen, direkte Ticket-URL, Verschieben über das
zugängliche Menü, privater Upload, lesbare Activity, Desktop sowie 390 × 844 Pixel,
gespeichertes helles Theme, Englisch und Zeitzone. Die Einstellungen wurden anschließend
zurückgestellt. Die automatisierte Mauszieh-Geste führte im verwendeten Browserwerkzeug
zu keiner sichtbaren Änderung; der native Drag-and-drop-Pfad ist damit noch nicht manuell
abgenommen. Der alternative Verschiebedialog und derselbe serverseitige Move-Pfad sind geprüft.

## Board-Messung

Messbestand: acht Seed-Tickets plus 5.000 zusätzliche Tickets im isolierten Testprojekt.
Ein Warm-up, danach zehn Messungen. Je Abfrage maximal 300 Karten sowie Gesamtzahl,
Metadaten und Zuordnungen. Das ist kein HTTP-/Browser-Lasttest.

| Abfrage | MariaDB Median | PostgreSQL Median |
|---|---:|---:|
| Board ohne Filter | 3,54 ms | 2,10 ms |
| Volltext „sunflower“ | 10,12 ms | 18,23 ms |

Bei mehr als 300 Treffern fordert das Board zum Filtern auf. Drag-and-drop ist bei
gefilterten oder begrenzten Ansichten deaktiviert, damit unsichtbare Nachbarn keine
falsche Position erzeugen; das Verschiebemenü bleibt verfügbar.

## Lokaler Betrieb

Die Runtime folgt dem ASPX-Aufbau mit Alpine, nativen PHP-Paketen, Nginx, PHP-FPM und
Supervisor. Alpine 3.24.1 ist per Digest festgelegt; PHP 8.5.10 läuft nativ auf ARM64.
App-Prozesse laufen als `www` mit UID 1000. Öffentlich ist ausschließlich `app/public`.
Ports werden an Loopback gebunden. HTTP auf 8088 leitet auf HTTPS/443 weiter. Logs sind größenbegrenzt.

Seit dem 15. September 2026 folgt auch die Verzeichnisstruktur den lokalen Projekten
`website`, `weonlywalk` und `nafphp/studio`: `docker/rootfs/etc` enthält getrennte
nginx-, PHP-FPM- und Supervisor-Konfigurationen. Ein Development-Overlay schaltet
die OPcache-Zeitstempelprüfung ein. `make first-install` richtet eine fehlende private
`.env` ein, baut das Image, installiert die lokalen Composer-Verknüpfungen und startet
die Anwendung über NAF-Migrationen und den wiederholbaren Demo-Seed.
`make` zeigt alle Befehle; Aufbau und tägliche Bedienung stehen in der README.
Der erste Docker-Abnahmelauf wurde mit `make first-install`, `make test`, `make style-check`
und dem Candidate-Build geprüft. Alle 85 MariaDB-/PostgreSQL-/HTTPS-Szenarien und
drei Worker-Prüfungen bestanden. `docs/Docker-Evidenz.json` und
`docs/Tests-Docker-Make.txt` dokumentieren diesen Durchlauf.
Auf Nutzerwunsch steuert Supervisor jetzt auch die nativen NAF-Worker-/Ticker-Befehle
im App-Container. `make restart-background` startet nur diese beiden Prozesse neu.
Der Container-Healthcheck umfasst HTTPS, Supervisor-Status und beide Heartbeats.
Die Test- und Candidate-Dienste deaktivieren Hintergrundprozesse ausdrücklich.

`make certificates` erzeugt eine private Entwicklungs-CA und ein signiertes TLS-Serverzertifikat mit DNS-/IP-SANs.
Zertifikat und privater Schlüssel sind von Git und Images ausgeschlossen und werden
nur lesbar eingebunden. Das Systemvertrauen wird nicht verändert; die Tests prüfen
das Zertifikat ausdrücklich. Die zuvor Port 443 belegende Studio-App wurde mit
ausdrücklicher Nutzerfreigabe angehalten; ihre Datenbank bleibt aktiv.

```sh
cd ~/PhpStormProjects/nafinity
make run
make status
make logs
make supervisor-status
```

`packages -> ../nafphp` ist der IDE-Link. Die App und die Quellen werden im Container
separat eingebunden. `bin/dev-composer` erzeugt das ignorierte Development-Manifest und
installiert im Container relative Vendor-Links. Die PHP-Konfiguration referenziert
App-URL und Datenbankwerte mit NAFs `ENV:...`; das gilt auch für das LDAP-/OIDC-Beispiel.
Standardwerte kommen aus Compose, die Auflösung übernimmt der Framework-Config-Service. FPM sieht Änderungen im nächsten Request.
Langlebige Prozesse nach Source-Änderungen mit `make restart-background` neu starten.

NAF-Logs werden über einen PSR-3-Adapter an PHP/FPM und die begrenzten Compose-Logs weitergereicht.
Lokale alte Logdateien werden weder versioniert noch in ein Image kopiert.

`/health/live` prüft den HTTP-Prozess. `/health/ready` prüft PDO, die fünf erforderlichen
App-Migrationen, die Hintergrundtabellen und die Auflösung des AttachmentService mit dem nativen Storage-Datenträger. Worker und Ticker haben eigene Heartbeats.
Die aktuelle Schema-Kennung ist `202609150002`; acht Migrationen inklusive Plugins sind angewandt.

`bin/build-candidate` erzeugt einen eingefrorenen lokalen Runtime-Snapshot mit Package-Hashes.
Er enthält keine Vendor-Symlinks, kein Composer und keine Source-Mounts. Der Builder kontrolliert die vollständige Menge aller benötigten NAF-Pakete.
Private Daten-, Logverzeichnisse und generierte PHPStan-/Test-/Formatter-Caches werden ausgeschlossen; gleichnamige Runtime-Pakete bleiben enthalten.
Der geprüfte Snapshot `nafinity:candidate` hat **130,03 MiB** (136.351.411 Byte),
Image-ID `sha256:efe0a19b9d0bab04b156549034263f36a7c91d5db93d4a420c9cbace72df904d`.
Anmeldung, fünf geschützte Seiten, Profil-API, Projektisolation und der private Download wurden über
HTTPS-Port 8445 erfolgreich geprüft. Eingebunden sind das private Datenverzeichnis und die lokalen TLS-Dateien.
Hashes und Einzelresultate stehen in `docs/Snapshot-Evidenz.json`.
Dieser Snapshot ist ausdrücklich keine veröffentlichte Distribution.

App und Datenbank bleiben aktiv; Worker und Ticker laufen unter Supervisor im App-Container. Die zusätzlichen Test- und Candidate-Container wurden
nach der Abnahme gestoppt. Der geprüfte Candidate lässt sich erneut starten:

```sh
make candidate-up
```

Backups mit `make backup` erstellen. `make verify-restore BACKUP=VERZEICHNIS` stellt ausschließlich
in `nafinity_restore_test` wieder her und kontrolliert Dateien/Hashes. Niemals Test- oder
Down-Migrationsbefehle gegen die Demo-/Produktivdatenbank umleiten.

## Tests wiederholen

`app/tests/run.php`, `benchmark.php` und `queue_process.php` verlangen ausdrücklich
`APP_ENV=test` und `DB_DATABASE=nafinity_test`. Der Integrationsrunner setzt dieses Schema
zurück. Die Make-Ziele legen die Testdatenbank bei Bedarf an; die Demo-Datenbank heißt `nafinity`.

```sh
make test
make test-down
```

`make test-ai` wiederholt die isolierten AI-Transport- und Speicherprüfungen.
`make test-http` setzt die Testdatenbank vor jedem Aufruf mit `tests/run.php` zurück
und seedet sie neu, damit Ratelimits und Testzustände nicht aus dem vorigen Lauf übernommen werden.
Die JSON-Dateien in `docs/` enthalten die einzelnen Szenarien, Messwerte und Runtime-Evidenz.
`bin/check-plugin-stack` wiederholt den Minimal-/Alle-Plugin-Boot in isolierten Hosts
und prüft dort auch eine tatsächlich aufgelöste PDO-Verbindung.

## Verbleibende Grenzen

- Stabile Paket-Releases, der daraus erzeugte Distributions-Lock und ein frischer
  Install ohne lokale Paketquellen stehen aus. Im Rahmen dieser Umsetzung wurden keine Pakete gemergt oder als Release veröffentlicht.
- Kein externer LDAP-Server, OIDC-Issuer oder SMTP-Dienst wurde kontaktiert. Für deren
  Aktivierung fehlen noch Deployment-Konfiguration und End-to-End-Abnahme. Lokales SMTP
  über Mailpit ist für Kontoverifizierung und Sicherheitshinweise geprüft.
- Projekt-Mail ist ausgeschaltet. Die Ledger-/Queue-Kombination begrenzt normale Duplikate;
  ein externes SMTP-Ergebnis lässt sich nicht atomar mit einer PDO-Transaktion bestätigen.
- Deutsch ist die vollständige Basissprache. Englisch ist für zentrale UI-Texte vorhanden;
  einige Meldungen und dynamische Texte bleiben im Prototyp deutsch.
- Datei-Allowlist und MIME-Prüfung sind kein Virenscanner. Maximal 10 MiB je Datei,
  30 MiB je Ticket und 200 MiB je Projekt; Storage bleibt privat.
- Ein Board pro Projekt, keine WIP-Erzwingung, Saved Filters, WebSockets, öffentliche
  Voll-API oder eingehende E-Mail-Verarbeitung. Diese Punkte waren ausdrücklich außerhalb des MVP.

Die zentrale NAF-Dokumentation ist als [Release-abhängiger Entwurf](https://github.com/nafphp/docs/compare/main...docs/nafinity-integration-rc) vorbereitet. Sie darf
erst nach Veröffentlichung und Prüfung der jeweiligen Paketversionen als Stable-Anleitung
erscheinen.

## Lokales Projekt und Dokumentation

Nafinity ist auf dem lokalen Branch `main` versioniert. Für die Anwendung wurde kein
Remote angelegt. `.env`, private Dateien, Logs, Vendor und generierte Entwicklungs-Locks
sind ausgeschlossen; der relative IDE-Symlink ist versioniert.
Der NAF-Dokumentationsentwurf liegt auf `docs/nafinity-integration-rc`, Commit `9036e2e`.
Er bleibt bis zu den erforderlichen Paket-Releases außerhalb der öffentlichen Anleitungen.

## Lesbarkeitsrunde nach der Prototyp-Abnahme

Das gesamte Nafinity-Projekt und 44 Dateien unserer bisherigen NAF-Integration wurden
auf einen gemeinsamen PHP-Stil gebracht: PER Coding Style 3.0 mit gruppenweise ausgerichteten
`=` und `=>`. Controller, Services, SQL, Templates, Tests und Build-Skripte sind gegliedert;
Zwischenvariablen erklären Login-Limits, Providerwahl, Upload-Quoten und Board-Zustände.
JavaScript/CSS folgen Prettier, Python folgt Black. Die separaten Storage-Änderungen blieben unberührt.

`bin/style install`, `bin/style fix` und `bin/style check` machen den Stil reproduzierbar.
Die Versionen sind in separaten Tool-Manifests/Locks festgelegt; kein Formatter gelangt in die Runtime.
Der erfolgreiche Check umfasst 54 PHP-Dateien in Nafinity, 44 Paketdateien, JavaScript/CSS
und sechs Python-Skripte. Die 371 Pakettests sowie jeweils 28 Datenbank- und 28 HTTP-Prüfungen
sind erneut grün. Native Sessions, Worker-Recovery, Limiter/LDAP-Verträge sowie Anmeldung und
privater Download im neu gebauten Snapshot sind bestätigt.

Die bestehenden acht RC-Branches wurden aktualisiert; Limiter und LDAP bleiben lokal.
Die Paket-APIs und fachlichen Verträge sind unverändert, daher war keine Änderung der
öffentlichen Paket-Anleitungen notwendig. `docs/Code-Style.md` dokumentiert die Entwicklerregeln;
`docs/Code-Style-Evidenz.json` enthält die neuen Commit- und Prüfnachweise.

## Git-Übergabe der bestehenden NAF-Pakete

Die folgenden geprüften RC-Branches wurden nach dem vereinbarten NAF-Workflow gepusht.
Der Maintainer übernimmt Merge und Release; es wurde keine neue Veröffentlichung angelegt.

| Paket | Branch | Commit | Review |
|---|---|---|---|
| framework | `v0.2.4-rc` | `1166372` | [Vergleich](https://github.com/nafphp/framework/compare/main...v0.2.4-rc) |
| database | `v0.2.2-rc` | `720fc14` | [Vergleich](https://github.com/nafphp/database/compare/main...v0.2.2-rc) |
| form | `v0.2.3-rc` | `9dbea01` | [Vergleich](https://github.com/nafphp/form/compare/main...v0.2.3-rc) |
| session | `v0.2.2-rc` | `27d8014` | [Vergleich](https://github.com/nafphp/session/compare/main...v0.2.2-rc) |
| orm | `v0.2.2-rc` | `d653bfb` | [Vergleich](https://github.com/nafphp/orm/compare/main...v0.2.2-rc) |
| queue | `v0.2.3-rc` | `a19e242` | [Vergleich](https://github.com/nafphp/queue/compare/main...v0.2.3-rc) |
| schedule | `v0.2.3-rc` | `72b35f6` | [Vergleich](https://github.com/nafphp/schedule/compare/main...v0.2.3-rc) |
| cli | `v0.2.2-rc` | `76df414` | [Vergleich](https://github.com/nafphp/cli/compare/main...v0.2.2-rc) |

## Plugin-Erweiterbarkeit

Umgesetzt auf `feat/plugin-extensibility` nach dem Auftrag in
`docs/audits/2026-09-17-plugin-extensibility/IMPLEMENTATION_PROMPT.md`.
Ausgangsstand war `a194993`, nicht der im Audit genannte Commit `016931e`.

Nafinity war bis dahin fest zusammengesetzt. Ein Paket konnte Klassen, Routen und
NAF-Dienste beitragen, aber nichts Sichtbares: kein Menüeintrag, keine
Settingskarte, kein Ticketfeld, kein Widget, kein Filter. Ein in der
`bootstrap.php` eines Composer-Plugins gesetztes Override ging außerdem verloren,
weil die App ihre eigenen Defaults erst danach registriert.

Der Kern ist deshalb ein ausdrücklicher Zeitpunkt: ein Paket merkt über
`Nafinity\extensions()` einen Provider vor, und alle vorgemerkten Provider laufen
in einem Durchlauf **nach** den App-Defaults, aufsteigend nach Index und ID. Die
Reihenfolge steht in `app/bootstrap.php`; `app/app/extensions.php` bleibt das
letzte Wort des Hosts.

Vierzehn Registries tragen die Beiträge, alle mit denselben Regeln
(`add`/`get`/`all`/`remove`, ein Sortierwert `index`, Gleichstand nach ID,
Duplikate nur mit ausdrücklichem Ersatz). Siebzehn Contracts beschreiben die
austauschbaren Dienste; sie sind lazy gebunden, und alle produktiven Verbraucher
— Controller, Dienste untereinander, Jobs, Seed und Readiness — fragen den
Contract, damit ein Ersatz auch den Worker erreicht.

Feste Ticketbereiche bleiben fest: Titel, Beschreibung und Kommentare lassen sich
über die Registries weder ersetzen noch entfernen. Bestehende Werte bleiben in
ihren bisherigen Tabellen; beigetragene Werte bekommen eigene Zeilen in
`user_settings`, `project_settings`, `project_user_settings` und
`ticket_metadata`, sodass ein neues Feld keine Migration braucht.

Zwei Beispielpakete unter `examples/` sind Teil der Abnahme und werden über
ausschließlich lokale Path-Repositories in einen Wegwerf-Host installiert.

| Prüfung | Ergebnis |
|---|---|
| `make test-mariadb` | 74 grün |
| `make test-postgres` | 74 grün |
| `make test-http` | 102 grün |
| `make test-plugins` | 46 grün: 27 in-process, 6 über HTTP, 7 Assets, 6 ohne die Pakete |
| `bin/style check`, `git diff --check` | grün |
| `composer test` in naf/framework | 137 Tests, 280 Assertions, grün |
| `composer test` in naf/i18n | 39 Tests, 108 Assertions, grün |

Offene Punkte stehen ehrlich in
[`docs/Plugin-Erweiterbarkeit-Status.md`](Plugin-Erweiterbarkeit-Status.md):
T12, T14, T19–T22, T24, T25, T29, T31 und T32 sind teilweise oder offen. Es gab
keinen Browserlauf und keinen Candidate-Build mit veröffentlichten Plugin-Assets.

### Git-Übergabe

| Paket | Branch | Commit | Review |
|---|---|---|---|
| framework | `v0.2.4-rc` | `3832e14` | [Vergleich](https://github.com/nafphp/framework/compare/main...v0.2.4-rc) |
| i18n | `v0.2.2-rc` | `1fa2ee7` | [Vergleich](https://github.com/nafphp/i18n/compare/main...v0.2.2-rc) |

Nafinity fordert jetzt `naf/i18n: ^0.2.2`. Beide Pakete sind **nicht
veröffentlicht**; die stabile Distribution ist davon abhängig, dass der
Maintainer sie merged und veröffentlicht. Der Nafinity-Pull-Request ist
[nafphp/nafinity#1](https://github.com/nafphp/nafinity/pull/1).

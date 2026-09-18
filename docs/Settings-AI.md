# Settings, eigene Rollen und lokale AI

Die Settings sind über den Eintrag unten in der Seitenleiste erreichbar. Der Projektwähler
wechselt zwischen persönlichen Einstellungen und den Einstellungen eines sichtbaren Projekts.
Eine Karte öffnet sich als großes Dialogfenster und fährt über das X oder Escape zurück.
Eingaben bleiben beim Schließen erhalten; nach einem erfolgreichen Speichern wird dieselbe
Karte wieder geöffnet. Die Animation berücksichtigt die Betriebssystemoption für reduzierte
Bewegung. Auf kleinen Bildschirmen nimmt der Dialog nahezu die gesamte Fläche ein.

## Karten und Rechte

- Persönlich: Darstellung, Sprache, Zeitzone, Benachrichtigungen und konfigurierte externe Konten.
- Lokale AI: Verbindung, Modelle, Live-Test, zusätzliche Prompts, Gedächtnis und Feedback.
- Allgemein: Projektdetails und Archivierung entsprechend den eigenen Rechten.
- Rollen & Rechte: Standardrollen einsehen; Owner können eigene Rollen erstellen, ändern und löschen.
- Benutzer: bestehende Konten per E-Mail zuordnen, Rollen ändern und Zugriff entziehen.
- Spalten, Swimlanes und Labels: bestehende Board-Struktur bearbeiten.

Eigene Rollen gelten jeweils nur in ihrem Projekt. Leserechte sind die gemeinsame Grundlage.
Zusätzlich können Tickets, Kommentare, Kommentar-Moderation, Anhänge, Projektdetails,
Benutzerzuordnung und Board-Struktur freigegeben werden. Moderation benötigt das Kommentarrecht.
Owner-Verwaltung, Rollenverwaltung und Archivierung bleiben Ownern vorbehalten. Die Standardrollen
sind unveränderliche Vorlagen. Es bleibt mindestens ein aktiver Owner erhalten.

Benutzerverwalter können nur Rechte vergeben oder entziehen, die sie selbst besitzen.
Owner und Manager können weiterhin nur von Ownern zugeordnet oder geändert werden.
Verwendete eigene Rollen können erst nach einer anderen Zuordnung gelöscht werden.
Versionen verhindern das Überschreiben zwischenzeitlicher Rollenänderungen. Ein Rechteentzug
greift bei der nächsten Aktion, auch bei den AI-Werkzeugen und der Upload-Wiederherstellung.

## Ollama

1. Ollama lokal starten und mindestens ein Modell mit Werkzeugunterstützung installieren.
2. In „Lokale AI“ die Adresse setzen, normalerweise `http://localhost:11434`.
3. „Modelle laden“ prüft die tatsächlichen Fähigkeiten über `/api/show`. Chat-Modelle benötigen
   `tools`; Embedding-Modelle benötigen `embedding`.
4. Ein Chat-Modell wählen, den Assistenten aktivieren und speichern. Der Chat erscheint unten rechts.
5. Ein Embedding-Modell auswählen, um Werkzeuge semantisch vorzufiltern. Im geprüften Browser
   ist das bereits installierte `embeddinggemma:latest` ausgewählt. Ohne Embedding-Modell oder
   bei einem Fehler bleibt eine begrenzte Stichwortsuche verfügbar.

## Semantische Auswahl für große Werkzeugkataloge

Der Chat lädt zunächst den aktuell autorisierten Katalog aus NAFs MCP-Registry. Der Browser
berechnet daraus einen lokalen Vektorindex und übergibt dem Chat-Modell nur die ausgewählten
Werkzeugdefinitionen. Die Auswahl ist standardmäßig auf acht Werkzeuge und unabhängig davon
auf 16.000 Zeichen an Werkzeugschemas begrenzt. Das Anzahl-Limit ist zwischen drei und 16
einstellbar. Eine höhere Mindestähnlichkeit wählt strenger aus; zusätzlich werden Ergebnisse
mehr als 0,15 unter dem besten Kosinus-Score ausgeschlossen. Die Standardgrenze ist 0,35.
Diese Werte sind Heuristiken, keine Garantie für eine inhaltlich richtige Modellauswahl.

Benötigte Lesewerkzeuge zählen zum selben Budget. Beispielsweise benötigt „Ticket erstellen“
das Board; Bearbeiten und Verschieben benötigen zusätzlich die Ticketdetails. Diese Beziehungen
stehen als `meta.requires` an den nativen Werkzeugdefinitionen. Fehlt ein berechtigtes
Voraussetzungswerkzeug oder passt die Gruppe nicht ins Budget, wird die Aktion nicht angeboten.
`meta.keywords` ergänzt bei Bedarf Suchbegriffe für die Stichwortsuche. Plugins müssen ihre
Werkzeuge weiterhin bewusst in den autorisierten App-Katalog integrieren; öffentliche MCP-
Werkzeuge werden nicht pauschal als Browser-Werkzeuge freigeschaltet.

Der Index verwendet SHA-256-Fingerprints der vollständigen Definition einschließlich Schema
und Metadaten. Nutzer, Projekt, Ollama-Adresse, Modellname und der aktuelle Modelldigest bilden
den Namensraum. Der Digest wird vor jeder Auswahl abgefragt, damit ein unter demselben Tag
ersetztes Modell neue Vektoren erhält. Nur fehlende oder veränderte Definitionen werden in
Paketen von höchstens 32 Eingaben eingebettet. Danach benötigt eine neue Frage einen einzelnen
Query-Vektor. EmbeddingGemma und Nomic erhalten ihre jeweiligen Query-/Dokument-Präfixe.

Vektoren und Fingerprints liegen in IndexedDB und überstehen ein Neuladen. Der Cache enthält
keine Chats oder Werkzeugergebnisse; er ist auf 2.000 Einträge begrenzt, persistierte Einträge
verfallen nach 30 Tagen. Gesperrter Browserspeicher fällt auf einen flüchtigen Cache zurück.
Bei einem Embedding-Fehler werden passende Stichworttreffer unter denselben Mengen- und
Schema-Limits ausgewählt. Der vollständige Katalog wird auch im Fehlerfall nicht an das
Chat-Modell gesendet. Ohne Treffer erhält das Modell keine Fachwerkzeuge.

Der Chat zeigt „Semantische Auswahl“ beziehungsweise „Stichwortauswahl“ und die ausgewählte
Anzahl. Aufgeklappt nennt die Anzeige die Werkzeuge sowie wiederverwendete und neu berechnete
Indexeinträge. Die spätere Ausführung prüft die Rechte weiterhin erneut auf dem Server;
ein alter Cache kann entzogene Rechte nicht wiederherstellen.

Geprüft mit einem Katalog aus sieben echten und 493 synthetischen Werkzeugbeschreibungen:
Der erste Aufbau mit `embeddinggemma:latest` dauerte rund sieben Sekunden; die folgende
Auswahl bei vorhandenem Index 66 ms. Die Spaltenfrage lieferte ausschließlich das Board-
Werkzeug. Das ist eine lokale Auswahlmessung, keine Aussage über 500 implementierte Aktionen
oder die Dauer der anschließenden Chat-Antwort. Details: [AI-Routing-Benchmark.json](AI-Routing-Benchmark.json).

API- und Präfix-Verträge: [Ollama Embed](https://docs.ollama.com/api/embed),
[EmbeddingGemma Retrieval](https://ai.google.dev/gemma/docs/embeddinggemma/inference-embeddinggemma-with-sentence-transformers),
[Nomic-Modellkarte](https://huggingface.co/nomic-ai/nomic-embed-text-v1.5).

Ollama muss die Herkunft `https://localhost` erlauben. Dazu dient beispielsweise
`OLLAMA_ORIGINS=https://localhost`; Ollama anschließend neu starten. Je nach Browser ist eine
Freigabe für den lokalen Netzwerkzugriff nötig. Nafinity erlaubt ausschließlich die Loopback-Hosts
`localhost` und `127.0.0.1`, mit HTTP oder HTTPS und einem einstellbaren Port. Es sendet weder
Cookies noch Zugangsdaten an Ollama und folgt dort keinen Weiterleitungen.

Der Browser spricht direkt mit Ollama. Es gibt keine Cloud-Anmeldung, keinen API-Schlüssel und
keinen serverseitigen HTTP-Proxy. Die nginx-CSP erlaubt die oben genannten lokalen Ziele.
Ein noch nicht vertrautes Nafinity-Entwicklungszertifikat muss einmal über die erzeugte lokale CA
im eigenen Browser beziehungsweise Schlüsselbund freigegeben werden; TLS-Prüfungen bleiben aktiv.

## Chat und Werkzeuge

Das kleine Sternsymbol unten rechts öffnet den Chat. Die Fläche entfaltet sich direkt
aus dem 44-Pixel-Button; beim Schließen über X oder Escape fährt sie dorthin zurück.
Der Inhalt blendet versetzt ein, ohne die Schrift zu skalieren. Die Betriebssystemoption
für reduzierte Bewegung überspringt die Animation. Nach dem Schließen liegt der
Tastaturfokus wieder auf dem Einstiegssymbol.

In der Kopfzeile liegen die Symbole für einen neuen Chat, AI-Einstellungen und Schließen.
Drei Einstiege im leeren Chat passen zum aktuellen Projektkontext. Ein Klick übernimmt
nur den Vorschlag ins Eingabefeld; gesendet wird erst per Pfeil oder Enter. Shift+Enter
fügt einen Zeilenumbruch ein. Das Feld wächst mit, und der Sendepfeil bleibt bei leerer
Eingabe deaktiviert. Während einer Antwort steht an derselben Stelle das Stoppsymbol.
Der kleine Live-Schalter im Fußbereich steuert das Streaming.

Der Chat unterstützt gestreamte und vollständige Antworten, Stoppen, Markdown mit Tabellen und
Codeblöcken, Kopieren, neue Gespräche sowie Feedback zu einzelnen Antworten. Die History umfasst
bis zu 40 Nachrichten. Das Modell erhält einen begrenzten Ausschnitt der letzten Nachrichten.
Pro Antwort sind maximal acht Werkzeugrunden möglich. Modellantworten werden dargestellt,
aber nicht durch einen zweiten Modellaufruf umgeschrieben.

Die tatsächlichen Werkzeugdefinitionen stammen aus `Naf\MCP\Support\ToolRegistry`.
Nafinitys eigene Liste ist dabei ein registrierter Anbieter unter mehreren: ein
installiertes Paket liefert eigene Werkzeuge über `extensions()->aiTools()`, und
`Nafinity\Contracts\ProjectToolInterface` ergänzt NAFs `ToolInterface` um Titel, Recht,
Voraussetzungen und Suchbegriffe. Rechte werden vor der Auslieferung des Katalogs und
erneut vor der Ausführung geprüft; zwei Anbieter können denselben Werkzeugnamen nicht
zufällig belegen. Nafinity stellt selbst folgende Funktionen bereit:

- Eigene Projekte auflisten, wenn kein Projekt ausgewählt ist.
- Im Projekt: Board, Ticketdetails und Aktivitäten lesen.
- Mit passenden Rechten: Tickets erstellen, bearbeiten oder verschieben und Kommentare schreiben.

Schreibende Aufrufe zeigen die konkrete Aktion mit Argumenten zur Bestätigung im Chat.

Die Einstellungsseite selbst ist ebenfalls registriert: Karten, Felder und Feldtypen stehen
in `extensions()->settingSections()`, `->settings()` und `->fieldTypes()`, und ein Paket fügt
eine Karte hinzu, ohne dass diese Seite ihren Namen kennt. Der PHP-Zugang zu den Werten ist
`Nafinity\settings()` mit `get()`, `all()`, `has()` und `collection()` sowie den Kontexten
`forProject()`, `forProjectUser()` und `forApplication()`; dazu kommen geschützte
JSON-Endpunkte. Die bestehenden Werte bleiben in ihren bisherigen Tabellen. Die lokale AI
behält ihren Browser-Speicher: `settings()->all()` kann localStorage nicht lesen, und weder
Chatverlauf noch Gedächtnis oder Prompts werden auf den Server übertragen. Einzelheiten in
[Plugin-Erweiterbarkeit](Plugin-Erweiterbarkeit.md#settings).
Der Server fordert diese Bestätigung zusätzlich an und prüft bei jeder Ausführung die aktuelle
Anmeldung und Projektberechtigung. Die Aktionen verwenden dieselben Application Services,
Transaktionen, Versionsprüfungen und Events wie die Oberfläche. Die HTTP-Routen unter `/ai`
verwenden native NAF-Session, CSRF und Rate-Limit. Die lokale Registrierung ist vom öffentlichen,
weiterhin tokenpflichtigen `/mcp`-Endpunkt getrennt; sie öffnet keinen anonymen Zugriff.

## Speicherung und Übernahme aus nixcms

Die Ollama-Transportfunktionen, Werkzeug-Konvertierung und sichere Markdown-Darstellung wurden
gezielt aus der NAF-Version von nixcms übernommen (`nafphp/cms`, MIT). Der Lizenztext liegt bei
den ausgelieferten Modulen unter `app/public/assets/ai/LICENSE`. Transport-Abbruch und sichtbare
Streaming-Fehler ergänzen die vorhandene Implementierung. CMS-spezifische PageBuilder-,
Artikel- und Systemwerkzeuge sind durch projektgebundene Nafinity-Werkzeuge ersetzt.

Verbindung, Modellwahl und persönliche Prompts liegen je Nutzer im lokalen Browser-Speicher.
Verlauf, Entwurf, Feedback und Gedächtnis sind zusätzlich je Projekt getrennt. Es gibt keine
Synchronisierung zwischen Browsern. „Feedback analysieren“ schlägt Hinweise für das Gedächtnis
vor. Erst die ausdrückliche Übernahme und das Speichern ändern es. Die mitgelieferten Regeln
für Projektgrenzen und Bestätigungen bleiben Teil des Systemprompts.

## Entwicklung und Tests

`make test` enthält Datenbank-, HTTP-, Worker- und AI-Transporttests. `make test-ai` prüft nur
Streaming, Unicode über Paketgrenzen, Werkzeugantworten, Fehler, Abbruch, erlaubte lokale URLs
und getrennte Speicherbereiche sowie die Auswahl aus 500 Werkzeugen, Cache-Invalidierung,
Rechteentzug, Abhängigkeiten und Fallback-/Schema-Limits. Der optionale Live-Benchmark läuft mit
`node app/tests/ai_routing_live.mjs PFAD_ZUM_AUTORISIERTEN_TOOL_ARRAY.json`; er verwendet ein
bereits installiertes lokales Modell und führt keine Fachwerkzeuge aus.
`make style-check` umfasst alle neuen JS-/CSS-Dateien sowie PHP
nach PER Coding Style 3.0. `naf/mcp` ist als lokales Source-Paket eingebunden. Das fertiggestellte
Storage-Paket benötigt jetzt `naf/client` ab 0.2.2; die Anwendung deklariert diese Mindestversion.

Die neue native Migration `M202609150001ProjectRoles` ergänzt eigene Rollen, Rechte und eine
projektgebundene optionale Mitgliedschaftszuordnung. Bestehende Mitgliedschaften bleiben erhalten.
`make test-up` wendet Migrationen vor dem Readiness-Warten in der isolierten Testdatenbank an.

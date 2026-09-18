# Nafinity — Review der Analyse und Planung

Stand: 14. September 2026. Gegenstand: [Analyse](Nafinity-Analyse.md), [Prototypplan](Nafinity-Prototypplan.md), Framework Findings, [Prüfevidenz](../Analyse-Evidenz.json).

Dieses Dokument bewertet die vorliegende Planung. **Es ändert keine Entscheidung, implementiert nichts und veröffentlicht nichts.** Empfehlungen sind als solche gekennzeichnet.

## 1. Prüfmethode

Unabhängige Gegenprüfung der dokumentierten Findings gegen die lokalen Package-Quellen unter `/Users/flo/PhpstormProjects/nafphp`. Alle geprüften Repositories standen auf `main`, mit sauberem Working Tree und **exakt den in der Analyse genannten Commits**:

| Package | HEAD | Analyse nennt | Working Tree |
|---|---|---|---|
| framework | `acabfb56a205` | `acabfb56a205` | clean |
| database | `f2027ff9535b` | `f2027ff9535b` | clean |
| form | `598bee7b0f46` | `598bee7b0f46` | clean |
| orm | `b2f01d7a9cf7` | `b2f01d7a9cf7` | clean |
| queue | `699b80dccad8` | `699b80dccad8` | clean |
| schedule | `86732ec210f9` | `86732ec210f9` | clean |
| session | `9ace904401e7` | `9ace904401e7` | clean |
| auth | `3b721331c734` | `3b721331c734` | clean |

Geprüft wurden 10 der 17 Findings sowie zwei tragende Architekturannahmen. Nicht erneut ausgeführt wurden die Datenbank-, Docker- und Suite-Läufe der Originalanalyse; deren Ergebnisse sind in der Evidenzdatei plausibel und in sich konsistent dokumentiert, aber hier nicht reproduziert.

## 2. Gesamturteil

**Die Planung ist tragfähig.** Die Befunde sind belegt und präzise formuliert, die Architektur ist durchdacht, die Abgrenzung zwischen `APP`, `EXISTING NAF PLUGIN`, `NEW NAF PLUGIN` und `NAF CORE` wird konsequent durchgehalten.

Die Schwächen liegen nicht in der Technik, sondern in der fehlenden Projektebene: keine Aufwände, kein kritischer Pfad, keine Release-Choreografie, kein Abbruchkriterium. In der jetzigen Form beantwortet die Planung „was und warum" vollständig und „wie lange und in welcher Reihenfolge unter Randbedingungen" gar nicht.

## 3. Verifikation der Findings

Alle stichprobenartig geprüften Findings treffen zu, einschließlich der Nebenaussagen. Kein Fall von Übertreibung, kein Fall einer falsch zugeordneten Ursache.

| Finding | Geprüfte Aussage | Fundstelle | Ergebnis |
|---|---|---|---|
| F01 | Methodenliste ohne PATCH, pauschale Bearer-Ausnahme | `form/src/Events/CsrfListener.php:31,38` | bestätigt |
| F02 | `charset=` wird für jeden Nicht-SQLite-Treiber angehängt | `database/src/Core/Database.php:50` | bestätigt |
| F03 | Backtick-DDL, `VARCHAR(32)`, Identität nur über `basename()` | `database/src/Commands/MigrateCommand.php:84,203,218,220` | bestätigt |
| F05 | Repository nutzt `$entity->table` bzw. eigene Pluralisierung statt `getTableName()` | `orm/src/Repository/AbstractRepository.php:61` | bestätigt |
| F07 | `unlink()` des Claims vor `execute()` | `queue/src/Drivers/FileDriver.php:97` | bestätigt |
| F08 | `queue:worker` aufgerufen, `queue:consume` registriert | `schedule/src/Commands/ScheduleTickerCommand.php:115`, `queue/src/Commands/QueueConsumeCommand.php:22` | bestätigt |
| F09 | Jedes Array wird als Klassenname+Methode behandelt | `framework/src/Core/EventManager.php:58` | bestätigt |
| F10 | `echo $response->getBody()` an drei Stellen | `framework/src/Core/ResponseEmitter.php:33,48,87` | bestätigt |
| F16 | Nur `PROD`/`TEST` werden sanitisiert | `framework/src/Core/ErrorHandler.php:190` | bestätigt |
| F17 | Kein PDO-Binding in database; OAuth-Plugins binden es und maskieren die Lücke | `database/bootstrap.php`, `oauth-client/bootstrap.php:28`, `oauth-server/bootstrap.php:28` | bestätigt |

**Besonders hervorzuheben** ist die Begründung, warum F04 trotz bewusst gewählter File-Sessions P0 bleibt: `session/bootstrap.php:47` registriert den Migrationspfad, sobald `naf/database` installiert ist — unabhängig von `session:storage`. `db:migrate` scheitert auf PostgreSQL damit auch dann, wenn gar keine DB-Sessions verwendet werden. Der Befund ist korrekt und wird leicht übersehen.

Ebenfalls korrekt und methodisch sauber: die Analyse markiert konsequent, was ausgeführt wurde und was Codebefund oder Entwurf ist. Beispiele: der CSRF-Bearer-Befund beansprucht ausdrücklich keinen demonstrierten Cross-Origin-Exploit; das Mehrworker-Race des SQLiteDrivers wird als Codebefund und nicht als Lasttest bezeichnet; F10 nennt explizit, dass kein Peak-Memory-Test lief. Diese Disziplin ist selten und erhöht den Gebrauchswert der Dokumente erheblich.

## 4. Gegenprüfung zweier tragender Architekturannahmen

Die Analyse fordert in Abschnitt 5: „Innerhalb einer Operation derselbe PDO und, bei ORM-Schreibzugriffen, derselbe EntityManager." Das ist die Grundlage des gesamten Transaktions- und Sperrdesigns. Geprüft:

**Annahme A — dieselbe PDO-Instanz erreicht ORM, Auth und Repositories. Bestätigt.**
`Container::get()` ersetzt die Factory-Closure durch das Ergebnis (`framework/src/Core/Container.php:31-35`); `Database::class` ist damit faktisch Singleton. `orm/bootstrap.php` baut EntityManager und RepositoryFactory beide aus `$container->get(Database::class)->getConnection()`. Es entstehen keine zwei Verbindungen.

**Annahme B — Autowiring verteilt keine frischen EntityManager. Bestätigt.**
`AutoResolvingContainer::resolveParameters()` löst Klassenabhängigkeiten zuerst über `$this->get($dependencyId)` auf (`framework/src/Decorators/AutoResolvingContainer.php:236`) und fällt erst bei `ServiceNotFoundException` auf `make()` zurück. Ein per `make()` erzeugter `TicketService` erhält daher den registrierten, geteilten EntityManager. Die Gefahr, dass ein autowired Service versehentlich einen zweiten EntityManager mit `transactionLevel = 0` auf einer bereits offenen Verbindung bekommt — also F06 über die DI-Hintertür — besteht nicht.

**Einschränkung, die im Plan fehlen sollte es nicht:** `EntityManager::__construct(protected PDO $pdo)` (`orm/src/Core/EntityManager.php:24`) bietet **keinen Accessor und keine Query-Methode**. Das Sperrdesign aus Abschnitt 8 braucht aber ein rohes `SELECT … FOR UPDATE` auf genau dieser Verbindung. Die App muss die PDO also getrennt beziehen — heute über `get(Database::class)->getConnection()`, nach F17 direkt über `PDO::class`.

Daraus folgt eine **Reihenfolgeregel, die nirgends im Plan steht und stehen sollte:**

> Der EntityManager muss die Transaktion eröffnen (`$em->begin()`), *danach* läuft das `SELECT … FOR UPDATE` über die geteilte PDO innerhalb dieser Transaktion. Die umgekehrte Reihenfolge — `$pdo->beginTransaction()`, dann `$em->begin()` — ist exakt F06 und wirft `There is already an active transaction`.

Das ist keine theoretische Gefahr: die naheliegende Leseart von „Schreibtransaktion öffnen, Project sperren" (Abschnitt 8, Schritt 1) ist genau die falsche Reihenfolge. F17 macht den Zugriff ergonomischer und wird für das Auth-Wiring ohnehin gebraucht, ist aber kein harter Blocker für das Sperrdesign — die App kann die PDO auch heute schon über `Database::class` beziehen.

## 5. Neuer Befund — Vorschlag F18: Container verliert Services mit `null`-Factory

**B · P0 bei Fehlkonfiguration · NAF CORE — `Naf\Core\Container`**

- **Problem/Nachweis:** `database/bootstrap.php` registriert `Database::class` mit einer Factory, die bei fehlender `database`-Konfiguration `null` zurückgibt. `Container::get()` schreibt dieses `null` in `$this->services[$id]` zurück. Da `has()` und `get()` beide `isset()` verwenden und `isset()` auf `null` **false** liefert, verschwindet der Service nach dem ersten Zugriff.
- **Ausgeführt** gegen die reale Klasse (`framework/src/Core/Container.php`, PSR-Interfaces gestubbt):

  ```text
  has() vor get():   true
  1. get() liefert:  NULL
  has() nach get():  false
  2. get() wirft:    Naf\Exceptions\ServiceNotFoundException: Service 'Database' not found.
  ```

- **Wirkung:** Eine einzige Fehlkonfiguration erzeugt zwei verschiedene, beide irreführende Fehlerbilder. Der erste Zugriff endet in `Call to a member function getConnection() on null`; jeder weitere behauptet, der Service sei nicht registriert. Beides verweist nicht auf die eigentliche Ursache — die fehlende `database`-Sektion.
- **Relevanz für Nafinity:** trifft genau den P0-Pfad. Ein Docker-Setup mit nicht durchgereichter Environment-Variable ist der wahrscheinlichste Fehlerfall der ersten Stunde; die Analyse warnt in Abschnitt 3 selbst davor, dass `ENV:KEY` aus `$_ENV` liest, während Container-Environment je nach PHP-Konfiguration nur über `getenv()` ankommt. Fehlende ENV-Feldwerte entfernen eine vorhandene database-Sektion nicht; diese Herleitung ist zurückgenommen.
- **Kleinste Änderung:** Factory-Ergebnisse mit `array_key_exists()` statt `isset()` behandeln, damit ein legitim gespeichertes `null` nicht zum Verschwinden des Service führt. Der dokumentierte nullable database()-Helper bleibt erhalten. Ein Fehler gehört an den verpflichtenden PDO-Verbraucher bzw. die Pflichtkonfiguration der App; eine generell werfende Database-Factory würde den bestehenden Vertrag ändern.
- **Empfehlung:** als F18 ins Findings-Protokoll aufnehmen und gemeinsam mit F17 im selben Database-Durchgang erledigen. Der Container-Fix ist getrennt zu bewerten, weil er Core ist und jede Factory betrifft, die legitim `null` liefern darf.
- **Tests:** fehlende Config beim ersten und zweiten Zugriff, `has()` vor/nach Auflösung, Factory mit legitimem `null`-Ergebnis, `reset()` nach fehlgeschlagener Auflösung.

## 6. Stärken

Diese Punkte sind bewusst benannt, weil sie beim Umsetzen erhalten bleiben sollten:

- **Composite Foreign Keys als Isolationsgrenze.** `tickets(project_id,board_id,column_id) → board_columns(project_id,board_id,id)` lässt eine Vermischung von Project A und B auf Datenbankebene scheitern, nicht erst im Service. Das ist die stärkste Einzelentscheidung im Entwurf und schützt die referenzielle Integrität. Ein vergessenes `WHERE project_id = ?` kann weiterhin fremde Datensätze offenlegen; projektgebundene Abfragen und Policies bleiben zwingend.
- **Der Client sendet Nachbar-IDs, keine Positionszahlen.** Schließt die naheliegendste Manipulation am Move-Endpunkt aus und macht die kanonische Reihenfolge serverseitig prüfbar.
- **Queue-Tabelle als Outbox** statt zweiter Infrastruktur, mit ehrlicher At-least-once-Zusage und ohne Exactly-once-Versprechen für SMTP.
- **Activity in derselben Transaktion**, keine Activity bei Rollback, Payload-Minimierung. Verhindert den häufigen Fehler eines Audit-Trails, der Ereignisse zeigt, die nie stattgefunden haben.
- **404 für fremdes Projekt, 403 für unzureichende Rolle.** Verhindert Existenz-Orakel über Projektgrenzen hinweg.
- **Issuer + Subject als externe Identität, niemals E-Mail allein.** Schließt die klassische Account-Übernahme über Mailadressen aus.
- **Attachments mit staged/ready/deleting**, weil Dateisystem und SQL nicht gemeinsam transaktional sind — inklusive der ausdrücklichen Weigerung zu behaupten, ein PDO-Rollback könne eine verschobene Datei zurückrollen.
- **Echte Default-Swimlane statt `NULL`.** Erspart Sonderfälle in jedem Unique-Index und jeder Positionsabfrage.
- **Die Negativliste ist lang und konkret.** Ein belastbares Zeichen, dass der Scope tatsächlich kontrolliert ist und nicht nur kontrolliert klingt.

## 7. Lücken und Empfehlungen

### 7.1 Keine Aufwände, kein kritischer Pfad, keine Ressourcenangabe

**Die wichtigste Lücke.** 17 Findings über 8 Packages plus fünf App-Phasen ohne eine einzige Schätzung. Ohne Sizing gibt es keinen Maßstab dafür, ob P0 zwei Wochen oder drei Monate dauert — und P0 allein verlangt neun Framework-Korrekturen, jede mit eigenem Regressionstest.

*Empfehlung:* grobe T-Shirt-Größen pro Finding und pro P-Phase, dazu eine explizite Angabe, ob ein oder mehrere Entwickler arbeiten. Die Reihenfolge innerhalb P0 sollte den kritischen Pfad benennen: F02 blockiert PostgreSQL; F03 den robusten Migrationspfad; F17 verpflichtende PDO-Injection; F18 wiederholte Auflösung einer null-Factory, F01/F11 blockieren jede Mutation, F16 blockiert nichts vor dem Deployment.

### 7.2 Die Release-Serialisierung ist nicht eingeplant

Der Plan verlangt korrekt, dass ein Distributionslauf nur veröffentlichte Mindestversionen voraussetzt, und verbietet ebenso korrekt das Bündeln unabhängiger Fixes in einen anonymen Sammelpatch. Beides zusammen bedeutet: **rund acht Package-Releases stehen zwischen dem ersten Fix und einem lauffähigen Distributionsmodus**, jedes mit Fix → Regressionstest → RC → Tag → Publish → Anheben des App-Minimums. Die Mail-RC steckt bereits sichtbar in diesem Zustand: lokal `v0.2.2-rc`, veröffentlicht `0.2.1`.

*Empfehlung:* eine eigene Tabelle „Release-Reihenfolge und blockierte App-Meilensteine" ergänzen. Sie sollte je Package festhalten, welcher App-Meilenstein auf dessen Veröffentlichung wartet, und ob der Meilenstein im Source-Modus vorgezogen werden darf.

### 7.3 Zwei verschiedene Skalen heißen gleich

Eine frühere Fassung dieses Reviews behauptete hier einen Prioritätswiderspruch zwischen Findings-Protokoll und Prototypplan, unter anderem für F06. **Das war falsch und ist zurückgezogen.** Die beiden Dokumente verwenden zwei verschiedene Achsen, die beide „P0/P1/P2" geschrieben werden:

- **Findings-Protokoll:** Dringlichkeitsklassen. P1 heißt dort ausdrücklich „vor produktivem MVP bzw. Aktivierung der Funktion".
- **Prototypplan:** fünf sequenzielle Lieferphasen P0–P4. Phase P2 heißt „Produktumfang des MVP vervollständigen".

Unter dieser Lesart ist F06 mit Dringlichkeit P1 in Planphase P2 **korrekt eingeordnet**, nicht widersprüchlich. Die Prüfung aller achtzehn Findings ergibt ein durchgehend stimmiges Bild; einzig F09 und F16 sind gegenüber ihrer Dringlichkeit vorgezogen, was zulässig ist, weil beide kleine Core-Fixes sind, die ohnehin im selben Release mitlaufen.

Der reale Defekt ist damit kein inhaltlicher, sondern ein Lesbarkeitsdefekt: **zwei Skalen mit identischer Schreibweise in Dokumenten, die sich ständig gegenseitig zitieren.** Diese Kollision hat bei der ersten Durchsicht dieses Reviews zu genau der Fehldiagnose geführt, die sie wahrscheinlich macht — sie ist keine theoretische Gefahr.

*Empfehlung, umgesetzt:* im Findings-Protokoll steht nun ein ausdrücklicher Hinweis auf die beiden Skalen sowie eine vollständige Abbildung Dringlichkeit → geplante Phase für alle achtzehn Findings, mit der Pflicht, diese Zeile bei jedem neuen Finding mitzuführen. Damit ist die Zuordnung nachprüfbar statt implizit.

**Inhaltlich bleibt von diesem Punkt ein Hinweis zu F06 übrig.** Der Move-Pfad der Planphase P1 — Project-Zeile per `SELECT … FOR UPDATE` sperren und anschließend Tickets schreiben — *ist* ein komponierter PDO/ORM-Ablauf, also schon vor Phase P2 relevant. Der Plan löst das über die Disziplinregel, alle eigenen Transaktionen über denselben EntityManager zu führen, und bezeichnet das ausdrücklich nicht als Behebung von F06. Das ist vertretbar und funktioniert (siehe Abschnitt 4), hing aber an einer Reihenfolge, die nirgends niedergeschrieben war.

*Empfehlung, umgesetzt:* die Reihenfolgeregel steht jetzt in [Analyse](Nafinity-Analyse.md) Abschnitt 5 mit Begründung, in Abschnitt 8 Schritt 1 als verbindliche Anweisung, und als Negativtest in der Moves-Zeile der Teststrategie.

### 7.4 Kein Abbruch- oder Neubewertungskriterium

Die Prämisse lautet „NAF ist geeignet". Allein P0 verlangt neun Framework-Korrekturen; F18 kommt hinzu. Die Analyse benennt Risiken ausführlich, definiert aber keinen Auslöser, bei dem die Richtungsentscheidung erneut geprüft wird.

*Empfehlung:* ein Satz genügt, zum Beispiel: „Überschreitet P0 das gesetzte Zeitbudget oder taucht eine weitere Klasse von Vertragsdefekten auf, die nicht durch die F-Liste abgedeckt ist, wird die Richtungsentscheidung vor P1 erneut bewertet." Ohne einen solchen Satz gibt es keinen definierten Moment, in dem Nachsteuern legitim ist.

### 7.5 Der Unique-Index auf `position` kostet mehr als er einbringt

`(project_id,board_id,column_id,swimlane_id,position)` als Unique erzwingt das zweistufige Rebalancing über temporär negative Positionen, samt der ausdrücklichen Regel, dass kein DB-CHECK diese Phase verhindern darf. Gleichzeitig serialisiert die projektweite Schreibsperre ohnehin schon sämtliche Mutationen eines Projekts — Eindeutigkeit wäre im Service durchsetzbar.

Der Index ist als Invariantenschutz verteidigbar und fängt Implementierungsfehler, die die Sperre nicht fängt. Die Entscheidung sollte aber als bewusster Trade-off dastehen und nicht als Selbstverständlichkeit.

*Empfehlung:* entweder den Trade-off explizit notieren („wir akzeptieren das zweistufige Rebalancing, um die Invariante hart zu halten"), oder den Unique-Index für den Prototyp auf einen nicht-eindeutigen Index reduzieren und ihn erst im MVP verschärfen, wenn die Move-Logik stabil ist.

### 7.6 MariaDB-FULLTEXT kombiniert schlecht mit dem Projektfilter

P2 sagt echte Volltextsuche auf Titel/Beschreibung zu, mit je einem real getesteten SQL-Dialekt. Für MariaDB gilt: `MATCH … AGAINST` in Kombination mit `WHERE project_id = ?` führt regelmäßig dazu, dass zuerst der Volltextindex ausgewertet und erst danach auf das Projekt gefiltert wird. Bei der genannten Messgröße von 10.000 Tickets pro Projekt unkritisch, bei vielen Projekten in einer Installation aber genau die falsche Richtung.

*Empfehlung:* in die Performance-Zeile der Teststrategie aufnehmen, dass die Volltextsuche mit mehreren Projekten und nicht nur mit einem großen Projekt gemessen wird.

### 7.7 Kleinere Punkte

- **Alpine 3.20 ist seit 1. April 2026 außerhalb der Unterstützung.** Der Plan weiß das und verschiebt die Auswahl in den P0-Build. Das ist vertretbar, betrifft aber die eine Entscheidung, die die gesamte Container-Strecke blockieren kann — Verfügbarkeit von `pdo_pgsql` und `ldap` für die gewählte PHP-Version auf ARM64. *Empfehlung:* diese Prüfung als erste P0-Aufgabe ziehen, nicht als Teilaspekt des Image-Baus.
- **Abnahmekriterium 9 des Prototyps** (lokale Sourceänderung nach dem nächsten Request sichtbar) gilt laut Abschnitt 10 ausdrücklich nicht für Worker und Ticker. Das ist konsistent dokumentiert, sollte im Abnahmekriterium selbst aber erwähnt werden, damit die Abnahme nicht an einer falschen Erwartung scheitert.
- **`config('csrf_validation', true) === false`** schaltet den Listener global ab (`form/src/Events/CsrfListener.php:26`). Das Findings-Protokoll verbietet die globale CSRF-Abschaltung bereits. *Empfehlung:* ein App-Test, der sicherstellt, dass diese Konfiguration in keiner ausgelieferten Umgebung gesetzt ist.

## 8. Empfohlene Ergänzungen vor Beginn von P0

In dieser Reihenfolge:

1. **Aufwandsschätzung** je Finding und je P-Phase, mit Angabe der Personenzahl.
2. ~~**Reihenfolgeregel EntityManager vor rohem `FOR UPDATE`** in Analyse-Abschnitt 8 aufnehmen, mit zugehörigem Negativtest.~~ — erledigt: Begründung in [Analyse](Nafinity-Analyse.md) Abschnitt 5, verbindliche Reihenfolge in Abschnitt 8 Schritt 1, Negativtest in der Moves-Zeile der Teststrategie.
3. ~~**F18 ins Findings-Protokoll** und in den P0-Block des Database-Durchgangs.~~ — erledigt: F18 im Protokoll, im [P0-Block](Nafinity-Prototypplan.md) auf den Database- und den Core-Durchgang aufgeteilt.
4. **Zeitbudget für P0 mit definierter Reißleine.**
5. **Release-Reihenfolge-Tabelle** mit den jeweils blockierten App-Meilensteinen.
6. ~~**Prioritätslabels** zwischen Plan und Findings angleichen, insbesondere F06.~~ — hinfällig: die Labels widersprechen sich nicht, siehe Abschnitt 7.3. Stattdessen erledigt: Abbildung Dringlichkeit → Phase im Findings-Protokoll.

Die Punkte 1, 4 und 5 sind Projektsteuerung und kosten zusammen wenige Stunden. Die Punkte 2, 3 und 6 sind inhaltlich und verhindern jeweils einen konkreten, wahrscheinlichen Fehler in der ersten Umsetzungswoche.

## 9. Was dieses Review nicht geprüft hat

- Die sieben nicht stichprobenartig geprüften Findings (F04 teilweise, F06, F11, F12, F13, F14, F15). F04 wurde nur hinsichtlich der Registrierung des Migrationspfads geprüft, nicht hinsichtlich des Upsert-Verhaltens.
- Die Testsuiten-Läufe, DB-Proben und Docker-Fixtures der Originalanalyse wurden nicht reproduziert.
- Die Angemessenheit der Produktentscheidungen — Featureumfang, Rollenmatrix, UI-Konzept — wurde nicht bewertet. Das Review betrifft die technische Tragfähigkeit.
- Es wurde kein formaler Security-Audit durchgeführt. Das Sicherheitsmodell in Abschnitt 13 der Analyse wirkt vollständig und adressiert die einschlägigen Risiken, ist hier aber nicht gegen Angriffsszenarien geprüft worden.

# Eigenes Profil

Der Avatar oben rechts und die gesamte Nutzerkarte unten in der Seitenleiste öffnen
denselben Dialog. Name, Avatar und Pfeil sind eine gemeinsame Schaltfläche, auch per
Tastatur bedienbar. In der Karte und der Kopfzeile steht die Rolle im aktuellen Projekt,
einschließlich selbst angelegter Rollen. Außerhalb eines Projekts erscheint „Persönliches
Konto“, da Rollen projektbezogen sind. Das Modal zeigt die zugewiesenen Projektrollen
mit Links zu den jeweiligen Boards sowie Passwortwechsel, E-Mail-Wechsel und Logout.

Der native HTML-Dialog hält den Tastaturfokus. X, Escape und ein Klick auf den Hintergrund
schließen ihn und geben den Fokus an den Auslöser zurück. Öffnen/Schließen animieren
260/150 ms; `prefers-reduced-motion` schaltet die Bewegung ab. Mobile Breiten, beide
Themes und erzwungene Systemfarben sind im CSS berücksichtigt. Passwort und Code werden
beim Absenden und Schließen aus den Feldern entfernt; die Anwendung legt sie nicht in
LocalStorage oder IndexedDB ab.

## Passwort ändern

Erforderlich sind aktuelles Passwort, neues Passwort und Wiederholung. Das neue Passwort
braucht mindestens 15 Zeichen und ist auf 72 Bytes begrenzt, damit der native
`PasswordHasher` mit seinem aktuellen bcrypt-Standard nichts stillschweigend abschneidet.
Leerzeichen und Unicode sind erlaubt; Nullbytes werden abgewiesen. Der Dienst prüft das
aktuelle Passwort unter einer Sperre der Kontozeile und verwendet NAFs Hash-/Verify-Vertrag.

Der Wechsel erhöht `users.security_version`, verwirft offene E-Mail-Änderungen und reiht
einen Sicherheitshinweis an die bestehende Adresse atomar in die native PDO-Queue ein.
Die aktuelle Sitzung wird abgemeldet; andere Sitzungen werden beim nächsten Request
verworfen. Bereits laufende fachliche Requests werden dadurch nicht rückwirkend beendet.
Die Login-Seite bestätigt den Wechsel per NAF-Session-Flash und verlangt das neue Passwort.

## E-Mail-Adresse ändern

1. Neue Adresse und aktuelles Passwort eingeben.
2. Im neuen Postfach den zwölfstelligen Code ablesen und im Profil eingeben.
3. Nach erfolgreicher Bestätigung mit neuer Adresse und unverändertem Passwort anmelden.

Bis Schritt 3 bleiben Adresse und Anmeldung unverändert. Eine offene Änderung überlebt
Reloads und ist auch in einer zweiten eigenen Sitzung sichtbar. „Änderung abbrechen /
neuen Code anfordern“ verwirft den alten Versuch; ein erneuter Versand verlangt wieder das
aktuelle Passwort. Konten ohne lokales Passwort verweisen auf ihren externen Anbieter.

Codes sind zufällig, 15 Minuten gültig, an Konto und zufällige Request-ID gebunden und
nur einmal verwendbar. In der Datenbank liegt ausschließlich der SHA-256-Hash. Fünf
falsche Codes löschen den Versuch; NAFs persistenter Limiter begrenzt zusätzlich Versand,
Bestätigung und Passwortprüfung pro Konto. Fremde Konten können Versuche weder lesen,
bestätigen noch abbrechen. Eine neue Anforderung ersetzt den vorherigen Versuch.

Bestätigung prüft unter derselben Kontosperre Ablaufzeit, Sicherheitsversion und die
Verfügbarkeit der neuen Adresse erneut. Der Unique-Index schützt auch bei gleichzeitigen
Anforderungen verschiedener Konten. Nach Erfolg sind `email_verified_at` und NAFs
`UserProfile::emailVerified` gesetzt; alle bisherigen Sitzungen werden widerrufen.
Die bisherige Adresse erhält sowohl bei Anforderung als auch bei Abschluss einen
Sicherheitshinweis. Der Code selbst wird ausschließlich an die neue Adresse versendet.

## NAF-Bausteine

- `Auth`, `PasswordCredentials`, `PasswordHasher` und das eigene ORM-User-Modell.
- `SessionStateStore` hinter dem nativen `StateStoreInterface`; der App-Decorator ergänzt
  nur die Kontoversion und behält NAFs Session-ID-Rotation bei.
- Native Formularvalidierung, CSRF, Routing, JSON-Responses, Views und Escaping.
- Native Migration, PDO und `EntityManager`-Transaktionen für Sperren und atomare Updates.
- `Mailer`/`MailTransport`, PDO-Queue, Job-Auflösung und Scheduler-Cleanup.
- `PdoLimiter` für persistente Limits. Sicherheitsmails hängen nicht von Projekt-Mailpräferenzen ab.

Die Auth-State-Bindung wird vor der ersten Auth-Auflösung registriert. Bestehende Sitzungen
werden bei der Migration als Revision null akzeptiert. Passwort-Anmeldung und Änderungen
sind über dieselbe Kontosperre serialisiert. Vor sicherheitsrelevanten Schreibvorgängen wird
die persistierte Sitzung nach der Sperre erneut über NAF geprüft; eine zwischenzeitliche
Änderung kann dadurch keine veraltete Sitzung legitimieren. Request-only identities in
isolierten Tests bleiben ein eigener nativer Auth-Modus.

## Lokales Testpostfach

`make run` startet Mailpit mit; `make mailpit` startet es einzeln. Die Oberfläche liegt
auf **http://localhost:8025** (`NAFINITY_MAILPIT_PORT` in `.env`), ausschließlich an Loopback
gebunden. SMTP-Port 1025 bleibt im Compose-Netz. Mailpit leitet keine Nachrichten ins
Internet weiter. Nachrichten liegen im lokalen Container und können bei dessen Neuerstellung
verloren gehen. Das Testpostfach ist für alle Benutzer dieses lokalen Rechners erreichbar.

Der Versandweg ist NAF `MailTransport` → PHP `mail()` → msmtp → Mailpit.
`docker/rootfs/etc/msmtprc` enthält die lokale SMTP-Konfiguration,
`docker/rootfs/etc/php85/conf.d/99-nafinity.ini` den Sendmail-Aufruf. Der Absender kommt
über `ENV:NAFINITY_MAIL_FROM`, mit Standardwert in Compose. Für echtes SMTP muss eine
private msmtp-Konfiguration mit Authentifizierung und TLS eingebunden werden; Zugangsdaten
gehören nicht ins Repository/Image. Projektbenachrichtigungen bleiben standardmäßig deaktiviert.

Der Bestätigungscode wird synchron versendet. Ein Versandfehler rollt die offene Änderung
zurück und liefert HTTP 503. SMTP und SQL haben keine gemeinsame atomare Transaktion:
Wenn SMTP akzeptiert und der Commit danach scheitert, kann ein nicht verwendbarer Code
ankommen. Die UI meldet in diesem Fall keinen erfolgreichen Abschluss. Sicherheitshinweise
nutzen dagegen NAFs dauerhafte Queue mit Retry/Deadletter; nach einem Worker-Absturz nach
SMTP-Akzeptanz sind doppelte Hinweise möglich. Es liegen keine Klartextcodes oder Passwörter
in Queue-Payloads.

## Prüfung und Grenzen

`make test-profile` erzeugt ausschließlich in `nafinity_test` eigene HTTP-Testkonten.
Es prüft Passwortwechsel, CSRF, echte SMTP-Zustellung, Adressbestätigung, parallele
Bestätigung, Einmalverwendung, Session-ID-Rotation und Widerruf zweier Cookie-Jars.
Der native Worker liefert anschließend die Sicherheitshinweise an die ursprünglichen
Testadressen. Zusätzliche Servicefälle laufen in MariaDB und PostgreSQL mit `make test`.
Demo-Passwörter und Demo-Adressen werden von diesen Tests nicht verändert.

`M202609150002AccountProfile` ergänzt zwei User-Felder und `account_email_changes`.
Schema-Kennung: `202609150002`. Vor dem Einspielen wurde das lokale Backup
`work/backups/20260915T214401Z` angelegt. Prüfergebnisse und die noch offene visuelle Abnahme
stehen in [Profile-Evidenz.json](Profile-Evidenz.json).

Nicht enthalten sind Passwort-vergessen/Recovery, MFA und eine Synchronisierung externer
LDAP-/OIDC-Zugangsdaten. Das sind eigene Kontowiederherstellungs- bzw. Identity-Flows.

Sicherheitsabläufe orientieren sich an den primären OWASP-Anleitungen zur
[E-Mail-Verifizierung](https://cheatsheetseries.owasp.org/cheatsheets/Email_Validation_and_Verification_Cheat_Sheet.html),
[Authentifizierung](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
und [Session-Verwaltung](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
Die lokalen Versandoptionen folgen der offiziellen Dokumentation von
[Mailpit](https://mailpit.axllent.org/docs/install/docker/) und
[msmtp](https://marlam.de/msmtp/msmtp.html).

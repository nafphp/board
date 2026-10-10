<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Export\ExportLine;
use Naf\Board\Export\InvoiceExportOptions;
use Naf\Board\Export\TimeExportOptions;
use Naf\Board\Support\Input;
use Naf\ORM\Core\EntityManager;
use PDO;
use PDOException;
use Throwable;

use function Naf\I18n\t;

/** Immutable previews and durable booking reservations around a single external attempt. */
final class InvoiceDraftService
{
    public function __construct(
        private PDO $pdo,
        private AccessInterface $access,
        private InvoiceConnections $connections,
        private InvoiceExportService $exports,
        private EntityManager $transactions,
    ) {
    }

    public function prepare(array $input): string
    {
        $actor           = $this->access->actor();
        $connection      = $this->connections->get(Input::id($input['connection'] ?? null, 'connection'));
        $input['format'] = $connection['provider'];
        $options         = InvoiceExportOptions::fromInput($input);
        $company         = (int) $connection['owner_id'] === 0;
        $this->access->project($options->project, $company ? 'export' : 'read');
        $fields = Input::validate($input, [
            'customer_id'   => 'required|string|max:80', 'title' => 'required|string|max:240',
            'invoice_date'  => 'required|string|date', 'service_from' => 'required|string|date',
            'service_until' => 'required|string|date', 'tax_case' => 'required|string',
        ]);
        foreach (['invoice_date', 'service_from', 'service_until'] as $date) {
            // Reuse strict, real calendar-date validation; never substitute the booking date.
            TimeExportOptions::fromInput(['from' => $fields[$date], 'until' => $fields[$date]]);
        }
        if ($fields['service_from'] > $fields['service_until'] || !in_array($fields['tax_case'], ['standard', 'small_business'], true)
            || ($fields['tax_case'] === 'small_business' ? $options->taxBasisPoints !== 0 : !in_array($options->taxBasisPoints, [700, 1900], true))) {
            throw new Failure(t('Bitte prüfe Leistungszeitraum, Steuerfall und Steuersatz. Unterstützt werden 7 oder 19 % sowie 0 % für Kleinunternehmen.'), 422);
        }
        $adapter    = $this->connections->adapter($connection['provider']);
        $rows       = [];
        $bookingIds = [];
        $claims     = $this->pdo->prepare('SELECT c.booking_id FROM invoice_booking_claims c JOIN invoice_drafts d ON d.id=c.draft_id WHERE c.owner_id=? AND d.project_id=?');
        $claims->execute([$connection['owner_id'], $options->project]);
        $excluded = array_fill_keys($claims->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($this->exports->rows($options, $company, $excluded, 5000)[$options->project] as $row) {
            $rows[] = $row;
            array_push($bookingIds, ...$row['record']['booking_ids']);
            if (count($rows) > 300 || count($bookingIds) > 5000) {
                throw new Failure(t('Bitte grenze den Export auf höchstens 300 Positionen und 5000 Buchungen ein.'), 422);
            }
        }
        if ($rows === []) {
            throw new Failure(t('Für diese Auswahl gibt es keine gebuchten Stunden.'), 422);
        }
        $id          = bin2hex(random_bytes(16));
        $previewRows = [];
        $file        = $this->exports->render($options, [$options->project => $rows], static function (ExportLine $line) use (&$previewRows): void {
            $previewRows[] = ['record' => $line->record, 'data' => $line->data];
        });

        try {
            $positions = json_decode(stream_get_contents($file['stream']), true, 64, JSON_THROW_ON_ERROR);
        } finally {
            fclose($file['stream']);
        }
        $payload  = $adapter->payload($positions, [...$input, ...$fields], 'Nafinity ' . $id);
        $customer = $adapter->customer($fields['customer_id'], $this->connections->credentials((int) $connection['id']));
        $snapshot = json_encode([
            'input'    => $fields, 'rows' => $previewRows, 'booking_ids' => $bookingIds, 'payload' => $payload,
            'customer' => $customer, 'format' => $connection['provider'], 'company_name' => $connection['company_name'], 'company_reference' => $connection['company_reference'],
        ], JSON_THROW_ON_ERROR);
        if (strlen($snapshot) > 4000000) {
            throw new Failure(t('Bitte grenze den Export auf höchstens 300 Positionen und 5000 Buchungen ein.'), 422);
        }
        $query = $this->pdo->prepare("INSERT INTO invoice_drafts(id,actor_id,project_id,connection_id,owner_id,state,snapshot) VALUES(?,?,?,?,?,'prepared',?)");
        $query->execute([$id, $actor, $options->project, $connection['id'], $connection['owner_id'], $snapshot]);

        return $id;
    }

    public function get(string $id): array
    {
        $actor = $this->access->actor();
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new Failure(t('Dieser Rechnungsentwurf ist nicht verfügbar.'), 404);
        }
        $query = $this->pdo->prepare('SELECT * FROM invoice_drafts WHERE id=? AND (actor_id=? OR owner_id=0)');
        $query->execute([$id, $actor]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Failure(t('Dieser Rechnungsentwurf ist nicht verfügbar.'), 404);
        }
        $this->connections->owner((int) $row['owner_id'] === 0);
        $this->access->project((int) $row['project_id'], (int) $row['owner_id'] === 0 ? 'export' : 'read');
        $row['snapshot'] = json_decode($row['snapshot'], true, 64, JSON_THROW_ON_ERROR);

        return $row;
    }

    /** The reservation commits BEFORE network I/O; no automatic replay after a crash. */
    public function send(string $id): array
    {
        $draft = $this->get($id);
        if ($draft['state'] === 'created') {
            return $draft;
        }
        $connection  = $this->connections->get((int) $draft['connection_id']);
        $adapter     = $this->connections->adapter($connection['provider']);
        $credentials = $this->connections->credentials((int) $connection['id']);
        $this->transactions->begin();

        try {
            $projectLock = $this->pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
            $projectLock->execute([$draft['project_id']]);
            $this->access->project((int) $draft['project_id'], (int) $draft['owner_id'] === 0 ? 'export' : 'read', true);
            // Lock the connection as well: disconnect cannot race a new reservation.
            $lock = $this->pdo->prepare('SELECT revoked_at FROM invoice_connections WHERE id=? FOR UPDATE');
            $lock->execute([$connection['id']]);
            if ($lock->fetchColumn() !== null) {
                throw new Failure(t('Dieser Rechnungszugang ist nicht verfügbar.'), 409);
            }
            $reserve = $this->pdo->prepare("UPDATE invoice_drafts SET state='sending',attempted_at=? WHERE id=? AND state='prepared' AND created_at>=CURRENT_TIMESTAMP - INTERVAL 1 HOUR");
            $reserve->execute([time(), $id]);
            if ($reserve->rowCount() !== 1) {
                throw new Failure(t('Dieser Entwurf wurde bereits übergeben oder ist abgelaufen. Bitte prüfe den gespeicherten Status.'), 409);
            }
            $claim = $this->pdo->prepare('INSERT INTO invoice_booking_claims(owner_id,booking_id,draft_id) VALUES(?,?,?)');
            foreach ($draft['snapshot']['booking_ids'] as $booking) {
                $claim->execute([$draft['owner_id'], $booking, $id]);
            }
            $this->transactions->commit();
        } catch (Throwable $error) {
            $this->transactions->rollback();
            if ($error instanceof PDOException && $error->getCode() === '23000') {
                throw new Failure(t('Mindestens eine Buchung wurde in diesem Abrechnungsbereich bereits übergeben oder reserviert.'), 409);
            }
            throw $error;
        }

        try {
            $remote = $adapter->create($draft['snapshot']['payload'], $credentials);
            $this->pdo->prepare("UPDATE invoice_drafts SET state='created',remote_id=? WHERE id=? AND state='sending'")->execute([$remote, $id]);
        } catch (Throwable $error) {
            $message = $error instanceof Failure ? $error->getMessage() : t('Das Rechnungstool hat keine gültige Bestätigung geliefert.');
            $this->pdo->prepare("UPDATE invoice_drafts SET state='uncertain',message=? WHERE id=? AND state='sending'")->execute([mb_substr($message, 0, 1000), $id]);
            // A rejection can originate from a proxy after a successful upstream write.
            // Keep every claim until a person has checked the external account.
        }

        return $this->get($id);
    }

    /** Explicit manual resolution only after checking the provider account. */
    public function resolve(string $id, array $input): void
    {
        $draft = $this->get($id);
        if (($input['confirmation'] ?? null) !== 'checked' || !in_array($input['resolution'] ?? null, ['created', 'not_created'], true)) {
            throw new Failure(t('Bitte bestätige die Prüfung im Rechnungstool.'), 422);
        }
        $remote = $input['resolution'] === 'created' ? Input::validate($input, ['remote_id' => 'required|string|max:80'])['remote_id'] : null;
        if ($remote !== null && !preg_match('/^[a-zA-Z0-9-]+$/D', $remote)) {
            throw new Failure(t('Bitte prüfe deine Eingaben.'), 422);
        }
        $this->transactions->begin();

        try {
            $update = $this->pdo->prepare("UPDATE invoice_drafts SET state=?,remote_id=? WHERE id=? AND state IN ('sending','uncertain') AND attempted_at<?");
            $update->execute([$remote === null ? 'released' : 'created', $remote, $id, time() - 60]);
            if ($update->rowCount() !== 1) {
                throw new Failure(t('Der Vorgang läuft noch oder wurde bereits geklärt.'), 409);
            }
            if ($remote === null) {
                $this->pdo->prepare('DELETE FROM invoice_booking_claims WHERE draft_id=?')->execute([$id]);
            }
            $this->transactions->commit();
        } catch (Throwable $error) {
            $this->transactions->rollback();
            throw $error;
        }
    }

    public function history(): array
    {
        $actor = $this->access->actor();
        $query = $this->pdo->prepare('SELECT id FROM invoice_drafts WHERE actor_id=? OR (owner_id=0 AND ?=1) ORDER BY created_at DESC,id DESC LIMIT 30');
        $query->execute([$actor, (int) $this->connections->companyAllowed()]);
        $result = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try {
                $result[] = $this->get($id);
            } catch (Failure $failure) {
                if (!in_array($failure->status, [403, 404], true)) {
                    throw $failure;
                }
            }
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Naf\Board\Services;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Contracts\InvoiceDraftAdapterInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Rbac\Installation;
use Naf\Board\Support\Input;
use Naf\Board\Support\Resolver;
use Naf\OAuth\Client\Token\Cipher;
use Naf\ORM\Core\EntityManager;
use PDO;
use SensitiveParameter;
use Throwable;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\config;
use function Naf\I18n\t;
use function Naf\Rbac\rbac;

/** Own credentials or installation credentials, never another person's access. */
final class InvoiceConnections
{
    public function __construct(private PDO $pdo, private AccessInterface $access, private EntityManager $transactions)
    {
    }

    public function companyAllowed(): bool
    {
        return rbac()->allows($this->access->actor(), Installation::MANAGE_SETTINGS);
    }

    public function owner(bool $company): int
    {
        $actor = $this->access->actor();
        if ($company && !$this->companyAllowed()) {
            throw new Failure(t('Diese Seite ist Administratoren vorbehalten.'), 403);
        }

        return $company ? 0 : $actor;
    }

    public function adapter(string $provider): InvoiceDraftAdapterInterface
    {
        $definition = extensions()->exporters()->get($provider);
        if ($definition?->draftAdapter === null || !in_array('invoice-items', $definition->sources, true)) {
            throw new Failure(t('Dieser Exportadapter unterstützt keine Rechnungsentwürfe.'), 422);
        }
        $adapter = Resolver::service(app()->container(), $definition->draftAdapter);
        if (!$adapter instanceof InvoiceDraftAdapterInterface) {
            throw new Failure(t('Dieser Exportadapter unterstützt keine Rechnungsentwürfe.'), 422);
        }

        return $adapter;
    }

    public function available(): array
    {
        $actor = $this->access->actor();
        $query = $this->pdo->prepare('SELECT id,owner_id,provider,company_name,company_reference FROM invoice_connections WHERE revoked_at IS NULL AND owner_id IN (?,?) ORDER BY company_name,id');
        $query->execute([$actor, $this->companyAllowed() ? 0 : $actor]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Internal only: callers must never return this row to a browser. */
    public function get(int $id, bool $active = true): array
    {
        $this->access->actor();
        $query = $this->pdo->prepare('SELECT * FROM invoice_connections WHERE id=?');
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || ((int) $row['owner_id'] === 0 ? !$this->companyAllowed() : (int) $row['owner_id'] !== $this->access->actor()) || ($active && $row['revoked_at'] !== null)) {
            throw new Failure(t('Dieser Rechnungszugang ist nicht verfügbar.'), 404);
        }

        return $row;
    }

    public function ready(): bool
    {
        try {
            $this->cipher();

            return true;
        } catch (Failure) {
            return false;
        }
    }

    public function save(#[SensitiveParameter] array $input): int
    {
        $fields = Input::validate($input, [
            'scope'        => 'required|string', 'provider' => 'required|string|max:80',
            'company_name' => 'required|string|max:240', 'company_reference' => 'required|string|max:240',
            'api_key'      => 'required|string|max:4096',
        ]);
        if (!in_array($fields['scope'], ['personal', 'company'], true) || preg_match('/[\x00-\x20\x7f]/', $fields['api_key'])) {
            throw new Failure(t('Bitte prüfe deine Eingaben.'), 422);
        }
        $owner = $this->owner($fields['scope'] === 'company');
        $this->adapter($fields['provider']);
        $credentials = ['api_key' => $fields['api_key']];
        if ($fields['provider'] === 'invoice.fastbill') {
            $credentials += Input::validate($input, ['email' => 'required|string|email|max:240']);
            if (str_contains($credentials['email'], ':')) {
                throw new Failure(t('Bitte prüfe deine Eingaben.'), 422);
            }
        }
        $cipher = $this->cipher();
        $this->transactions->begin();

        try {
            $query = $this->pdo->prepare('INSERT INTO invoice_connections(owner_id,provider,company_name,company_reference,credentials) VALUES(?,?,?,?,?)');
            $query->execute([$owner, $fields['provider'], $fields['company_name'], $fields['company_reference'], '']);
            $id        = (int) $this->pdo->lastInsertId();
            $encrypted = $cipher->encrypt(json_encode($credentials, JSON_THROW_ON_ERROR), $this->context($owner, $fields['provider'], $id));
            $this->pdo->prepare('UPDATE invoice_connections SET credentials=? WHERE id=?')->execute([$encrypted, $id]);
            $this->transactions->commit();

            return $id;
        } catch (Throwable $error) {
            $this->transactions->rollback();
            throw $error;
        }
    }

    public function revoke(int $id): void
    {
        $this->get($id);
        $this->pdo->prepare("UPDATE invoice_connections SET credentials='',revoked_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$id]);
    }

    public function credentials(int $id): array
    {
        $row = $this->get($id);

        try {
            return json_decode($this->cipher()->decrypt($row['credentials'], $this->context((int) $row['owner_id'], $row['provider'], $id)), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new Failure(t('Der Rechnungszugang kann nicht entschlüsselt werden. Bitte verbinde das Konto erneut.'), 503);
        }
    }

    private function context(int $owner, string $provider, int $id): string
    {
        return 'board.invoice:' . $owner . ':' . $provider . ':' . $id;
    }

    private function cipher(): Cipher
    {
        try {
            return Cipher::fromKey(config('nafinity:exports:key'));
        } catch (Throwable) {
            throw new Failure(t('Für Rechnungszugänge muss der Betreiber zuerst den Export-Schlüssel konfigurieren.'), 503);
        }
    }
}

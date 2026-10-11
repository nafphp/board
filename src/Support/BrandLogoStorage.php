<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Domain\Failure;
use Naf\Storage\Storage;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

use function Naf\I18n\t;

/** Small public raster logos on the existing disk, separate from private attachments. @internal */
final class BrandLogoStorage
{
    public const int MAX_BYTES = 524288;

    private const array TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public function __construct(private Storage $disk)
    {
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{64}\.(png|jpg|webp)$/D', $key) === 1;
    }

    public function exists(string $key): bool
    {
        return self::validKey($key) && $this->disk->exists('branding/' . $key);
    }

    public function upload(UploadedFileInterface $upload): string
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new Failure(t('Der Logo-Upload ist fehlgeschlagen. Erlaubt sind höchstens 512 KB.'), 422);
        }

        $stream = $upload->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $contents = '';
        while (!$stream->eof()) {
            $chunk = $stream->read(8192);
            if ($chunk === '' && !$stream->eof()) {
                throw new RuntimeException('Incomplete logo upload.');
            }
            $contents .= $chunk;
            if (strlen($contents) > self::MAX_BYTES) {
                throw new Failure(t('Das Logo darf höchstens 512 KB groß sein.'), 422);
            }
        }
        $image = @getimagesizefromstring($contents);
        $type  = is_array($image) ? ($image['mime'] ?? '') : '';
        if (!isset(self::TYPES[$type]) || $image[0] < 1 || $image[1] < 1 || $image[0] > 2048 || $image[1] > 2048) {
            throw new Failure(t('Bitte lade ein PNG-, JPG- oder WebP-Bild mit höchstens 2048 × 2048 Pixeln hoch.'), 422);
        }

        $key = bin2hex(random_bytes(32)) . '.' . self::TYPES[$type];
        $this->disk->put('branding/' . $key, $contents);

        return $key;
    }

    /** @return resource */
    public function open(string $key): mixed
    {
        if (!$this->exists($key)) {
            throw new Failure(t('Dieses Logo ist nicht verfügbar.'), 404);
        }

        return $this->disk->readStream('branding/' . $key);
    }

    public function delete(string $key): void
    {
        if (self::validKey($key)) {
            $this->disk->delete('branding/' . $key);
        }
    }

    public static function contentType(string $key): string
    {
        return array_search(pathinfo($key, PATHINFO_EXTENSION), self::TYPES, true) ?: 'application/octet-stream';
    }
}

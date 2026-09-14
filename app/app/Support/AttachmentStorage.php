<?php

declare(strict_types=1);

namespace App\Support;

use Naf\Storage\Storage;
use Psr\Http\Message\UploadedFileInterface;
use InvalidArgumentException;
use RuntimeException;

/** Nafinity's upload policy and lifecycle, backed by a private named NAF disk. */
final class AttachmentStorage
{
    public function __construct(private Storage $disk, private string $localRoot)
    {
    }

    public function stage(UploadedFileInterface $upload, int $maxBytes = 10485760): array
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Der Upload ist fehlgeschlagen oder zu groß.');
        }
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Invalid upload limit.');
        }

        $name = str_replace('\\', '/', $upload->getClientFilename() ?? 'file');
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name);
        $name = mb_substr(basename($name), 0, 180);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $types = [
            'txt' => ['text/plain'], 'md' => ['text/plain'], 'csv' => ['text/plain', 'text/csv'],
            'pdf' => ['application/pdf'], 'png' => ['image/png'], 'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'], 'webp' => ['image/webp'], 'zip' => ['application/zip'],
        ];
        if (!isset($types[$extension])) {
            throw new InvalidArgumentException('Erlaubt sind TXT, MD, CSV, PDF, PNG, JPG, WebP und ZIP.');
        }

        // Private temporary validation stream; never buffer an entire upload in PHP memory.
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('Cannot create upload validation stream.');
        }
        $size = 0;
        $hash = hash_init('sha256');
        try {
            $input = $upload->getStream();
            if ($input->isSeekable()) {
                $input->rewind();
            }
            while (!$input->eof()) {
                $chunk = $input->read(8192);
                if ($chunk === '' && !$input->eof()) {
                    throw new RuntimeException('Incomplete upload stream.');
                }
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    throw new InvalidArgumentException('Die Datei überschreitet das Uploadlimit.');
                }
                hash_update($hash, $chunk);
                if (fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Incomplete upload validation write.');
                }
            }
            if ($size === 0) {
                throw new InvalidArgumentException('Leere Dateien sind nicht erlaubt.');
            }
            fflush($temporary);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file(stream_get_meta_data($temporary)['uri']);
            if (!in_array($mime, $types[$extension], true)) {
                throw new InvalidArgumentException('Dateiinhalt und Dateityp passen nicht zusammen.');
            }

            $key = bin2hex(random_bytes(32));
            rewind($temporary);
            $this->disk->writeStream('staging/'.$key, $temporary);

            return ['key' => $key, 'name' => $name, 'mime' => $mime, 'size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($temporary);
        }
    }

    public function promote(string $key): void
    {
        $this->validateKey($key);
        if (!$this->disk->exists('ready/'.$key)) {
            $this->disk->move('staging/'.$key, 'ready/'.$key);
        }
    }

    /** @return resource */
    public function open(string $key)
    {
        $this->validateKey($key);
        return $this->disk->readStream('ready/'.$key);
    }

    public function delete(string $key): void
    {
        $this->validateKey($key);
        $this->disk->delete('staging/'.$key);
        $this->disk->delete('ready/'.$key);
    }

    /** Local-only orphan enumeration; the host owns metadata and retention policy. */
    public function cleanup(callable $isReferenced, int $olderThan): int
    {
        $count = 0;
        foreach (['staging', 'ready'] as $state) {
            foreach (glob($this->localRoot.'/'.$state.'/*') ?: [] as $file) {
                $key = basename($file);
                if (!preg_match('/^[a-f0-9]{64}$/D', $key) || is_link($file) || !is_file($file)) {
                    continue;
                }
                if (filemtime($file) >= $olderThan || $isReferenced($key)) {
                    continue;
                }
                $this->disk->delete($state.'/'.$key);
                ++$count;
            }
        }
        return $count;
    }

    private function validateKey(string $key): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Invalid storage key.');
        }
    }
}

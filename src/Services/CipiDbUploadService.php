<?php

namespace CipiApi\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Database dumps uploaded through the API, to be restored with
 * `cipi db restore` (Cipi CLI ≥ 5.9.0).
 *
 * A dump is sent in pieces, so no single request has to fit the whole file
 * into the limits of nginx and PHP (64 MB per request on a stock panel):
 * start → one chunk per request, in order → restore. The pieces are appended
 * to a file in the API's own storage, owned by the API's user; Cipi 5.9.0
 * opens a dump found there as that user, not as root — which is why the
 * routes refuse to work on an older core, where root would open it itself.
 *
 * Limits (config/cipi.php, `db_upload`): the size of one dump, the size of a
 * chunk, and how much the spool may hold in all. Whatever is not restored
 * within a day is deleted; a restored dump is deleted when its job ends.
 */
class CipiDbUploadService
{
    public const MIN_CIPI = '5.9.0';

    protected const ID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    protected const STALE_SECONDS = 86400;

    public function __construct(
        protected CipiVersionService $version,
    ) {}

    /** @throws \RuntimeException on an older core */
    public function requireCore(): void
    {
        $this->version->require(self::MIN_CIPI, 'Uploading a database dump');
    }

    public function dir(): string
    {
        return storage_path('app/cipi-db-uploads');
    }

    public function maxBytes(): int
    {
        return max(1, (int) config('cipi.db_upload.max_mb', 2048)) * 1048576;
    }

    public function chunkBytes(): int
    {
        return max(1, min(32, (int) config('cipi.db_upload.chunk_mb', 8))) * 1048576;
    }

    public function spoolBytes(): int
    {
        return max($this->maxBytes(), (int) config('cipi.db_upload.spool_mb', 4096) * 1048576);
    }

    /**
     * Limits a client needs before it starts.
     *
     * @return array{max_size: int, chunk_size: int, extensions: list<string>}
     */
    public function limits(): array
    {
        return ['max_size' => $this->maxBytes(), 'chunk_size' => $this->chunkBytes(), 'extensions' => ['.sql', '.sql.gz']];
    }

    /**
     * @return array{upload_id: string, size: int, chunk_size: int, chunks: int, max_size: int}
     *
     * @throws \InvalidArgumentException when the file is not acceptable (422)
     * @throws \RuntimeException when the server cannot take it (507)
     */
    public function start(string $filename, int $size): array
    {
        $ext = $this->extension($filename);
        if ($ext === null) {
            throw new \InvalidArgumentException('Only .sql and .sql.gz dumps can be uploaded');
        }
        if ($size < 1) {
            throw new \InvalidArgumentException('The dump is empty');
        }
        if ($size > $this->maxBytes()) {
            throw new \InvalidArgumentException('The dump is larger than the limit of ' . $this->mb($this->maxBytes()) . ' MB (db_upload.max_mb). Copy it to the server and restore it from its path instead.');
        }

        $dir = $this->dir();
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Cannot create the upload directory');
        }
        $this->sweep();

        if ($this->spoolUsed() + $size > $this->spoolBytes()) {
            throw new \RuntimeException('Too many uploads are waiting to be restored — try again later, or restore or cancel the pending ones');
        }
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size + 268435456) {
            throw new \RuntimeException('Not enough free disk space on the server for this dump');
        }

        $id = (string) Str::uuid();
        $part = $this->partPath($id);
        if (@file_put_contents($part, '') === false) {
            throw new \RuntimeException('Cannot write to the upload directory');
        }
        @chmod($part, 0600);
        $this->writeMeta($id, [
            'ext' => $ext,
            'size' => $size,
            'received' => 0,
            'next' => 0,
            'created' => time(),
            'name' => substr((string) preg_replace('/[^A-Za-z0-9._-]/', '_', basename($filename)), 0, 120),
        ]);

        return [
            'upload_id' => $id,
            'size' => $size,
            'chunk_size' => $this->chunkBytes(),
            'chunks' => (int) ceil($size / $this->chunkBytes()),
            'max_size' => $this->maxBytes(),
        ];
    }

    /**
     * Append the next chunk. Chunks arrive in order: an index that is not the
     * next one is refused, with the index the server expects.
     *
     * @return array{upload_id: string, received: int, size: int, next: int, complete: bool}
     *
     * @throws \InvalidArgumentException (422) · \OutOfBoundsException unknown upload (404) · \LogicException wrong index (409)
     */
    public function append(string $id, int $index, UploadedFile $chunk): array
    {
        $meta = $this->meta($id);
        $lock = @fopen($this->metaPath($id), 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the upload');
        }
        try {
            $meta = $this->meta($id);
            if ($index !== (int) $meta['next']) {
                throw new \LogicException('Chunk ' . $index . ' is out of order: the next one is ' . $meta['next']);
            }
            if (! $chunk->isValid()) {
                throw new \InvalidArgumentException('The chunk did not arrive whole: ' . $chunk->getErrorMessage());
            }
            $bytes = (int) $chunk->getSize();
            if ($bytes < 1 || $bytes > $this->chunkBytes()) {
                throw new \InvalidArgumentException('A chunk must be between 1 byte and ' . $this->mb($this->chunkBytes()) . ' MB');
            }
            if ($meta['received'] + $bytes > $meta['size']) {
                throw new \InvalidArgumentException('More data than the size declared when the upload started');
            }

            $in = @fopen($chunk->getRealPath(), 'rb');
            $out = @fopen($this->partPath($id), 'ab');
            if ($in === false || $out === false) {
                throw new \RuntimeException('Cannot write the chunk');
            }
            $copied = stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            if ($copied !== $bytes) {
                throw new \RuntimeException('The chunk was not written whole (disk full?)');
            }

            $meta['received'] += $bytes;
            $meta['next'] = $index + 1;
            $this->writeMeta($id, $meta);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return [
            'upload_id' => $id,
            'received' => (int) $meta['received'],
            'size' => (int) $meta['size'],
            'next' => (int) $meta['next'],
            'complete' => $meta['received'] === $meta['size'],
        ];
    }

    /**
     * The path of a complete upload, under the name `cipi db restore` reads
     * the format from (.sql or .sql.gz).
     *
     * @throws \OutOfBoundsException unknown upload · \InvalidArgumentException incomplete or not what it says
     */
    public function finalize(string $id): string
    {
        $meta = $this->meta($id);
        $final = $this->dir() . '/' . $id . $meta['ext'];
        if (is_file($final)) {
            return $final;
        }
        $part = $this->partPath($id);
        clearstatcache(true, $part);
        if ($meta['received'] !== $meta['size'] || @filesize($part) !== $meta['size']) {
            throw new \InvalidArgumentException('The upload is not complete: ' . $meta['received'] . ' of ' . $meta['size'] . ' bytes');
        }
        $head = (string) @file_get_contents($part, false, null, 0, 2);
        $isGzip = $head === "\x1f\x8b";
        if ($meta['ext'] === '.sql.gz' && ! $isGzip) {
            throw new \InvalidArgumentException('The file is named .sql.gz but is not gzip-compressed');
        }
        if ($meta['ext'] === '.sql' && $isGzip) {
            throw new \InvalidArgumentException('The file is named .sql but is gzip-compressed: upload it as .sql.gz');
        }
        if (! @rename($part, $final)) {
            throw new \RuntimeException('Cannot finish the upload');
        }

        return $final;
    }

    /** Original file name of an upload, for messages. */
    public function name(string $id): string
    {
        return (string) ($this->meta($id)['name'] ?? '');
    }

    /** Delete an upload — cancelled, or restored. Unknown ids are ignored. */
    public function discard(string $id): void
    {
        if (! preg_match(self::ID_RE, $id)) {
            return;
        }
        foreach (['.part', '.sql', '.sql.gz', '.json'] as $suffix) {
            @unlink($this->dir() . '/' . $id . $suffix);
        }
    }

    /** Delete what was started more than a day ago and never restored. */
    public function sweep(): void
    {
        $limit = time() - self::STALE_SECONDS;
        foreach ((array) @glob($this->dir() . '/*') as $file) {
            if (is_file($file) && ! is_link($file) && (int) @filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    protected function spoolUsed(): int
    {
        $total = 0;
        foreach ((array) @glob($this->dir() . '/*.json') as $file) {
            $meta = json_decode((string) @file_get_contents($file), true);
            $total += is_array($meta) ? (int) ($meta['size'] ?? 0) : 0;
        }

        return $total;
    }

    protected function extension(string $filename): ?string
    {
        $lower = strtolower($filename);
        if (str_ends_with($lower, '.sql.gz')) {
            return '.sql.gz';
        }

        return str_ends_with($lower, '.sql') ? '.sql' : null;
    }

    /**
     * @return array{ext: string, size: int, received: int, next: int, created: int, name: string}
     *
     * @throws \OutOfBoundsException
     */
    protected function meta(string $id): array
    {
        if (! preg_match(self::ID_RE, $id)) {
            throw new \OutOfBoundsException('Unknown upload');
        }
        $meta = json_decode((string) @file_get_contents($this->metaPath($id)), true);
        if (! is_array($meta) || ! isset($meta['ext'], $meta['size'], $meta['received'], $meta['next'])
            || ! in_array($meta['ext'], ['.sql', '.sql.gz'], true)) {
            throw new \OutOfBoundsException('Unknown upload (expired, cancelled or already restored)');
        }
        $meta['size'] = (int) $meta['size'];
        $meta['received'] = (int) $meta['received'];
        $meta['next'] = (int) $meta['next'];

        return $meta;
    }

    /** @param array<string, mixed> $meta */
    protected function writeMeta(string $id, array $meta): void
    {
        $path = $this->metaPath($id);
        if (@file_put_contents($path, json_encode($meta)) === false) {
            throw new \RuntimeException('Cannot write to the upload directory');
        }
        @chmod($path, 0600);
    }

    protected function metaPath(string $id): string
    {
        return $this->dir() . '/' . $id . '.json';
    }

    protected function partPath(string $id): string
    {
        return $this->dir() . '/' . $id . '.part';
    }

    protected function mb(int $bytes): int
    {
        return (int) round($bytes / 1048576);
    }
}

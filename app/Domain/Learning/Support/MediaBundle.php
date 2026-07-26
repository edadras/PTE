<?php

declare(strict_types=1);

namespace App\Domain\Learning\Support;

use App\Domain\Learning\Enums\MediaKind;
use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * The media half of a question import: a ZIP of audio/image files whose names
 * the CSV's `audio_file` / `image_file` columns point at (docs/05 §3).
 *
 * Every file is judged by its bytes, not its name — the MIME type comes from
 * finfo on the content, and an .mp3 that is actually a PHP script is refused
 * with the detected type in the message. Files land on the tenant disk under
 * the same questions/{kind} folders the panel's own uploads use, at a path
 * this class generates itself; entry names never touch the filesystem, which
 * is what makes zip-slip impossible by construction. Hostile entry names are
 * still rejected outright so a tampered bundle fails loudly instead of
 * half-importing.
 */
final class MediaBundle
{
    public const DEFAULT_MAX_BYTES = 26_214_400; // 25 MiB

    private const DIRECTORIES = [
        MediaKind::Audio->value => 'questions/audio',
        MediaKind::Image->value => 'questions/images',
        MediaKind::Video->value => 'questions/video',
    ];

    private const EXTENSIONS = [
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/webm' => 'weba',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
    ];

    private readonly ZipArchive $zip;

    /** @var array<string, string> basename => full entry name */
    private array $entries = [];

    /** @var array<string, true> basenames that appear more than once */
    private array $ambiguous = [];

    /** @var array<string, array{kind: string, s3_path: string, mime: string, size_bytes: int}> */
    private array $resolved = [];

    /** @var array<string, true> cache keys whose file has been written */
    private array $written = [];

    /** @var array<int, string> */
    private array $stored = [];

    public function __construct(
        private readonly string $zipPath,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException("[{$zipPath}] is not a readable ZIP archive.");
        }

        $this->zip = $zip;
        $this->index();
    }

    /**
     * @return array<int, string> the file names the bundle can serve
     */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    public function has(string $filename): bool
    {
        return isset($this->entries[trim($filename)]);
    }

    /**
     * Validate, store on the tenant disk and describe one file.
     *
     * @return array{kind: string, s3_path: string, mime: string, size_bytes: int}
     */
    public function stage(string $filename, MediaKind $kind): array
    {
        return $this->resolve($filename, $kind, store: true);
    }

    /**
     * Validate and describe one file without writing anything — the dry-run
     * path. The returned s3_path is where stage() would put it.
     *
     * @return array{kind: string, s3_path: string, mime: string, size_bytes: int}
     */
    public function inspect(string $filename, MediaKind $kind): array
    {
        return $this->resolve($filename, $kind, store: false);
    }

    /**
     * @return array<int, string> tenant-disk paths written by stage()
     */
    public function storedPaths(): array
    {
        return $this->stored;
    }

    /**
     * Remove everything stage() wrote. Called when an import rolls back: the
     * files were written before the database transaction, so they would
     * otherwise survive as orphans pointing at questions that never existed.
     */
    public function purge(): void
    {
        foreach ($this->stored as $path) {
            Storage::disk('tenant')->delete($path);
        }

        $this->stored = [];
        $this->written = [];
    }

    public function close(): void
    {
        $this->zip->close();
    }

    private function index(): void
    {
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $name = (string) $this->zip->getNameIndex($i);

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            $normalized = str_replace('\\', '/', $name);

            if (
                str_contains($name, "\0")
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:/', $normalized) === 1
                || in_array('..', explode('/', $normalized), true)
            ) {
                throw new InvalidArgumentException(
                    "Media bundle entry [{$name}] is not a plain relative path; refusing the whole bundle."
                );
            }

            $base = basename($normalized);

            if (isset($this->entries[$base])) {
                $this->ambiguous[$base] = true;
            } else {
                $this->entries[$base] = $name;
            }
        }
    }

    /**
     * @return array{kind: string, s3_path: string, mime: string, size_bytes: int}
     */
    private function resolve(string $filename, MediaKind $kind, bool $store): array
    {
        $key = trim($filename);

        if ($key === '' || str_contains($key, '/') || str_contains($key, '\\')) {
            throw new InvalidArgumentException(
                "[{$filename}] must be a bare file name; rows reference bundle entries by name only."
            );
        }

        if (isset($this->ambiguous[$key])) {
            throw new RuntimeException("[{$key}] appears more than once in the media bundle; rename the duplicates.");
        }

        if (! isset($this->entries[$key])) {
            throw new RuntimeException(sprintf(
                '[%s] is not in the media bundle (%d file(s): %s).',
                $key,
                count($this->entries),
                Str::limit(implode(', ', $this->names()), 200),
            ));
        }

        $cacheKey = $kind->value.':'.$key;

        if (! isset($this->resolved[$cacheKey])) {
            $this->resolved[$cacheKey] = $this->describe($key, $kind);
        }

        $meta = $this->resolved[$cacheKey];

        if ($store && ! isset($this->written[$cacheKey])) {
            $contents = $this->read($key);

            if (Storage::disk('tenant')->put($meta['s3_path'], $contents) === false) {
                throw new RuntimeException("Unable to store [{$key}] on the tenant disk.");
            }

            $this->written[$cacheKey] = true;
            $this->stored[] = $meta['s3_path'];
        }

        return $meta;
    }

    /**
     * @return array{kind: string, s3_path: string, mime: string, size_bytes: int}
     */
    private function describe(string $key, MediaKind $kind): array
    {
        $contents = $this->read($key);
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);

        if (! in_array($mime, $kind->allowedMimeTypes(), true)) {
            throw new RuntimeException(sprintf(
                '[%s] is [%s] by content, not a supported %s type (%s).',
                $key,
                $mime,
                $kind->value,
                implode(', ', $kind->allowedMimeTypes()),
            ));
        }

        return [
            'kind' => $kind->value,
            's3_path' => sprintf(
                '%s/%s-%s.%s',
                self::DIRECTORIES[$kind->value],
                $this->stem($key),
                Str::lower(Str::random(8)),
                self::EXTENSIONS[$mime],
            ),
            'mime' => $mime,
            'size_bytes' => strlen($contents),
        ];
    }

    private function read(string $key): string
    {
        $entry = $this->entries[$key];
        $stat = $this->zip->statName($entry);
        $declared = is_array($stat) ? (int) $stat['size'] : 0;

        if ($declared > $this->maxBytes) {
            throw new RuntimeException(sprintf(
                '[%s] is %d bytes; the media limit is %d bytes.',
                $key,
                $declared,
                $this->maxBytes,
            ));
        }

        // Read at most one byte over the cap: the header's size claim is
        // attacker-controlled, so the real expansion is what gets enforced.
        $contents = $this->zip->getFromName($entry, $this->maxBytes + 1);

        if ($contents === false || $contents === '') {
            throw new RuntimeException("[{$key}] could not be read from the bundle or is empty.");
        }

        if (strlen($contents) > $this->maxBytes) {
            throw new RuntimeException(sprintf(
                '[%s] expands past the media limit of %d bytes.',
                $key,
                $this->maxBytes,
            ));
        }

        return $contents;
    }

    /**
     * The original name survives in the stored path for traceability, but only
     * after being reduced to characters every object store and URL is happy
     * with; the random suffix keeps two imports of the same name apart.
     */
    private function stem(string $key): string
    {
        $stem = pathinfo($key, PATHINFO_FILENAME);
        $stem = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $stem);
        $stem = trim(Str::limit($stem, 40, ''), '-');

        return $stem === '' ? 'media' : $stem;
    }
}

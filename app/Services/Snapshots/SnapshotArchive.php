<?php

namespace App\Services\Snapshots;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

/**
 * Collects entries for a snapshot ZIP: files that already live on a disk (the private disk) and
 * generated strings. Missing source files are recorded, not fatal.
 */
final class SnapshotArchive
{
    private ZipArchive $zip;

    private string $zipPath;

    /** @var array<int, string> temp copies that must live until the archive is closed */
    private array $tempFiles = [];

    /** @var array<string, bool> entry names already used */
    private array $used = [];

    /** @var array<int, array{path: string, size: int, source: string|null}> */
    private array $entries = [];

    /** @var array<int, string> source paths that were listed but not found on the disk */
    private array $missing = [];

    public function __construct(private readonly Filesystem $disk)
    {
        $this->zipPath = tempnam(sys_get_temp_dir(), 'snapshot-');
        $this->zip = new ZipArchive();

        if ($this->zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the snapshot archive.');
        }
    }

    /** Add a file stored on the disk as "$folder/<its file name>". Returns the entry name or null if missing. */
    public function addStored(string $folder, string $sourcePath): ?string
    {
        if (! $this->disk->exists($sourcePath)) {
            $this->missing[] = $sourcePath;

            return null;
        }

        $name = $this->uniqueName($folder.'/'.$this->safeName(basename($sourcePath)));
        $local = $this->localCopy($sourcePath);
        $this->zip->addFile($local, $name);
        $this->used[$name] = true;
        $this->entries[] = ['path' => $name, 'size' => (int) filesize($local), 'source' => $sourcePath];

        return $name;
    }

    /** Add generated content. */
    public function addString(string $name, string $contents): string
    {
        $name = $this->uniqueName($name);
        $this->zip->addFromString($name, $contents);
        $this->used[$name] = true;
        $this->entries[] = ['path' => $name, 'size' => strlen($contents), 'source' => null];

        return $name;
    }

    /** @return array<int, array{path: string, size: int, source: string|null}> */
    public function entries(): array
    {
        return $this->entries;
    }

    /** @return array<int, string> */
    public function missing(): array
    {
        return $this->missing;
    }

    /** Close the archive; returns the path of the finished temporary ZIP file. Call cleanup() afterwards. */
    public function close(): string
    {
        if (! $this->zip->close()) {
            throw new RuntimeException('Could not write the snapshot archive.');
        }

        return $this->zipPath;
    }

    /** Delete the temporary ZIP and any temporary copies. */
    public function cleanup(): void
    {
        foreach ([...$this->tempFiles, $this->zipPath] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->tempFiles = [];
    }

    private function localCopy(string $sourcePath): string
    {
        // Local disks: zip straight from the stored file. Remote disks: stream into a temp file.
        if (method_exists($this->disk, 'path')) {
            $path = $this->disk->path($sourcePath);
            if (is_file($path)) {
                return $path;
            }
        }

        $temp = tempnam(sys_get_temp_dir(), 'snapshot-src-');
        $in = $this->disk->readStream($sourcePath);
        $out = fopen($temp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);
        $this->tempFiles[] = $temp;

        return $temp;
    }

    private function uniqueName(string $name): string
    {
        if (! isset($this->used[$name])) {
            return $name;
        }

        $info = pathinfo($name);
        $dir = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'].'/';
        $ext = isset($info['extension']) ? '.'.$info['extension'] : '';

        for ($i = 2; ; $i++) {
            $candidate = $dir.$info['filename'].'-'.$i.$ext;
            if (! isset($this->used[$candidate])) {
                return $candidate;
            }
        }
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]/', '_', $name);

        return trim((string) $name) === '' ? 'file' : $name;
    }
}

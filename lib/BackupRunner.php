<?php

namespace PrmGoogleDrive;

use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Backups\DeleteBackupService;
use Pterodactyl\Services\Backups\InitiateBackupService;
use ZipArchive;

class BackupRunner
{
    public static function volumesRoot(): string
    {
        return is_dir('/peto-volumes') ? '/peto-volumes' : storage_path('app');
    }

    public static function backupsRoot(): string
    {
        foreach (['/peto-backups', '/run/desktop/mnt/host/d/PETO-FILES/backups'] as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }

        // Fallback: host path via docker desktop mount style inside panel if volumes sibling
        $guess = dirname(self::volumesRoot()) . '/backups';
        if (is_dir($guess)) {
            return $guess;
        }

        $tmp = storage_path('app/prmgoogledrive/tmp');
        if (!is_dir($tmp)) {
            @mkdir($tmp, 0755, true);
        }

        return $tmp;
    }

    public static function selectedServers(array $settings): \Illuminate\Support\Collection
    {
        $q = Server::query()->orderBy('id');
        $ids = $settings['server_ids'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $q->whereIn('id', array_map('intval', $ids));
        }

        return $q->get();
    }

    public static function run(?array $onlyServerIds = null): array
    {
        $settings = Store::all();
        if (empty($settings['connected'])) {
            throw new \RuntimeException('Connect Google Drive first.');
        }

        $folderId = Google::ensureFolder(
            $settings['folder_name'] ?: 'PRM-Backups',
            $settings['folder_id'] ?: null
        );
        Store::put(['folder_id' => $folderId]);

        $servers = self::selectedServers($settings);
        if ($onlyServerIds) {
            $servers = $servers->whereIn('id', $onlyServerIds)->values();
        }

        $summary = ['ok' => 0, 'fail' => 0, 'items' => []];
        foreach ($servers as $server) {
            try {
                $item = self::backupOne($server, $settings, $folderId);
                $summary['ok']++;
                $summary['items'][] = $item;
                Store::log('info', 'Backup uploaded: ' . $server->name, $item);
            } catch (\Throwable $e) {
                $summary['fail']++;
                $summary['items'][] = [
                    'server' => $server->name,
                    'error' => $e->getMessage(),
                ];
                Store::log('error', 'Backup failed: ' . $server->name, [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Store::put(['last_run_at' => date('c')]);

        return $summary;
    }

    public static function backupOne(Server $server, array $settings, string $folderId): array
    {
        $mode = $settings['mode'] ?? 'wings';

        // backup_limit=0 iken Wings backup açılamaz → ZIP'e düş
        if ($mode === 'wings' && (int) $server->backup_limit <= 0) {
            Store::log('info', $server->name . ': backup_limit=0, ZIP kullanılıyor');
            $mode = 'zip';
        }

        if ($mode === 'zip') {
            return self::backupZip($server, $settings, $folderId);
        }

        try {
            return self::backupWings($server, $settings, $folderId);
        } catch (\Throwable $e) {
            // Wings/daemon patlarsa Drive yedeği yine gitsin
            Store::log('info', $server->name . ': Wings başarısız, ZIP denenecek — ' . $e->getMessage());
            return self::backupZip($server, $settings, $folderId);
        }
    }

    protected static function backupWings(Server $server, array $settings, string $folderId): array
    {
        /** @var InitiateBackupService $initiator */
        $initiator = app(InitiateBackupService::class);
        $ignored = self::parseIgnore($settings['ignore'] ?? '');

        $backup = $initiator
            ->setIsLocked(false)
            ->setIgnoredFiles($ignored)
            ->handle($server, 'Drive ' . date('Y-m-d H:i'), true);

        $deadline = time() + 60 * 90; // 90 min
        while (time() < $deadline) {
            $backup->refresh();
            if ($backup->completed_at !== null) {
                break;
            }
            sleep(3);
        }

        $backup->refresh();
        if (!$backup->is_successful || !$backup->completed_at) {
            throw new \RuntimeException('Wings backup did not complete in time (uuid ' . $backup->uuid . ').');
        }

        $local = self::findBackupFile($backup);
        if (!$local) {
            throw new \RuntimeException(
                'Backup finished but file not found under backups mount. Mount D:/PETO-FILES/backups as /peto-backups in the panel container.'
            );
        }

        $name = sprintf(
            '%s_%s_%s%s',
            self::safeName($server->name),
            substr($server->uuid, 0, 8),
            date('Ymd_His'),
            self::extensionOf($local)
        );

        $uploaded = Google::uploadFile($local, $name, $folderId, self::mimeOf($local));

        if (empty($settings['keep_local'])) {
            try {
                app(DeleteBackupService::class)->handle($backup);
            } catch (\Throwable $e) {
                // keep going; Drive copy exists
            }
        }

        return [
            'server' => $server->name,
            'mode' => 'wings',
            'drive_id' => $uploaded['id'] ?? null,
            'file' => $name,
            'bytes' => filesize($local) ?: null,
        ];
    }

    protected static function backupZip(Server $server, array $settings, string $folderId): array
    {
        $root = self::volumesRoot() . '/' . $server->uuid;
        if (!is_dir($root)) {
            throw new \RuntimeException('Server volume not found: ' . $root);
        }

        $tmpDir = storage_path('app/prmgoogledrive/tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }

        $zipPath = $tmpDir . '/' . $server->uuid . '_' . time() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create zip archive.');
        }

        $ignored = self::parseIgnore($settings['ignore'] ?? '');
        self::addDirToZip($zip, $root, '', $ignored);
        $zip->close();

        if (!is_file($zipPath) || filesize($zipPath) < 1) {
            @unlink($zipPath);
            throw new \RuntimeException('Zip archive is empty.');
        }

        $name = sprintf(
            '%s_%s_%s.zip',
            self::safeName($server->name),
            substr($server->uuid, 0, 8),
            date('Ymd_His')
        );

        try {
            $uploaded = Google::uploadFile($zipPath, $name, $folderId, 'application/zip');
        } finally {
            @unlink($zipPath);
        }

        return [
            'server' => $server->name,
            'mode' => 'zip',
            'drive_id' => $uploaded['id'] ?? null,
            'file' => $name,
            'bytes' => null,
        ];
    }

    protected static function findBackupFile(Backup $backup): ?string
    {
        $root = self::backupsRoot();
        $candidates = [
            $root . '/' . $backup->uuid . '.tar.gz',
            $root . '/' . $backup->uuid . '.tar',
            $root . '/' . $backup->uuid . '.gz',
            $root . '/' . $backup->server->uuid . '/' . $backup->uuid . '.tar.gz',
            $root . '/' . $backup->server->uuid . '/' . $backup->uuid . '.tar',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        // recursive search by uuid filename
        if (is_dir($root)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (str_contains($file->getFilename(), $backup->uuid)) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    protected static function parseIgnore(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    protected static function ignoredPath(string $relative, array $patterns): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        foreach ($patterns as $pattern) {
            $pattern = ltrim(str_replace('\\', '/', $pattern), '/');
            if (self::matchGlob($pattern, $relative)) {
                return true;
            }
            // also match basename patterns like *.vpk
            if (self::matchGlob($pattern, basename($relative))) {
                return true;
            }
        }

        return false;
    }

    protected static function matchGlob(string $pattern, string $path): bool
    {
        $regex = '#^' . str_replace(
            ['\*\*', '\*', '\?'],
            ['.*', '[^/]*', '.'],
            preg_quote($pattern, '#')
        ) . '$#i';

        return (bool) preg_match($regex, $path);
    }

    protected static function addDirToZip(ZipArchive $zip, string $abs, string $prefix, array $ignored): void
    {
        $entries = @scandir($abs) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $rel = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (self::ignoredPath($rel, $ignored)) {
                continue;
            }
            $full = $abs . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $zip->addEmptyDir($rel);
                self::addDirToZip($zip, $full, $rel, $ignored);
            } elseif (is_file($full)) {
                $zip->addFile($full, $rel);
            }
        }
    }

    protected static function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'server';

        return trim($name, '_');
    }

    protected static function extensionOf(string $path): string
    {
        if (str_ends_with($path, '.tar.gz')) {
            return '.tar.gz';
        }

        return '.' . pathinfo($path, PATHINFO_EXTENSION);
    }

    protected static function mimeOf(string $path): string
    {
        if (str_ends_with($path, '.zip')) {
            return 'application/zip';
        }

        return 'application/gzip';
    }

    public static function dueForSchedule(array $settings): bool
    {
        $schedule = $settings['schedule'] ?? 'off';
        if ($schedule === 'off') {
            return false;
        }

        $last = !empty($settings['last_run_at']) ? strtotime($settings['last_run_at']) : 0;
        $now = time();
        return match ($schedule) {
            'hourly' => ($now - $last) >= 3600,
            'twicedaily' => ($now - $last) >= 43200,
            'daily' => ($now - $last) >= 86400,
            default => false,
        };
    }
}

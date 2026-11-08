<?php

namespace PrmGoogleDrive;

use Illuminate\Support\Facades\Crypt;

class Store
{
    public static function path(): string
    {
        $dir = storage_path('app/prmgoogledrive');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . '/settings.json';
    }

    public static function defaults(): array
    {
        return [
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
            'connected' => false,
            'google_email' => '',
            'access_token' => '',
            'refresh_token' => '',
            'token_expires' => 0,
            'folder_id' => '',
            'folder_name' => 'PRM-Backups',
            'mode' => 'wings', // wings | zip
            'schedule' => 'off', // off | hourly | daily | twicedaily
            'server_ids' => [], // empty = all
            'ignore' => "game/**\nsteamapps/**\n*.vpk\n*.dem",
            'keep_local' => false,
            'cron_key' => bin2hex(random_bytes(16)),
            'last_run_at' => null,
            'logs' => [],
        ];
    }

    public static function all(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            $data = self::defaults();
            self::save($data);

            return AppCredentials::apply($data);
        }

        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw)) {
            $raw = [];
        }

        return AppCredentials::apply(array_merge(self::defaults(), $raw));
    }

    public static function save(array $data): void
    {
        // Never rewrite cron_key accidentally empty
        $current = is_file(self::path())
            ? (json_decode((string) file_get_contents(self::path()), true) ?: [])
            : [];
        if (empty($data['cron_key']) && !empty($current['cron_key'])) {
            $data['cron_key'] = $current['cron_key'];
        }

        file_put_contents(
            self::path(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public static function put(array $patch): array
    {
        $data = array_merge(self::all(), $patch);
        self::save($data);

        return $data;
    }

    public static function encryptToken(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Crypt::encryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public static function decryptToken(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $data = self::all();
        $logs = $data['logs'] ?? [];
        array_unshift($logs, [
            'at' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
        $data['logs'] = array_slice($logs, 0, 100);
        self::save($data);
    }
}

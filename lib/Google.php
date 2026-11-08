<?php

namespace PrmGoogleDrive;

use Illuminate\Support\Facades\Http;

class Google
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    public const DRIVE_FILES = 'https://www.googleapis.com/drive/v3/files';
    public const DRIVE_UPLOAD = 'https://www.googleapis.com/upload/drive/v3/files';
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/userinfo.email';

    public static function authUrl(array $settings, string $state): string
    {
        $query = http_build_query([
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'],
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);

        return self::AUTH_URL . '?' . $query;
    }

    public static function exchangeCode(array $settings, string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'redirect_uri' => $settings['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Google token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    public static function refresh(array $settings): array
    {
        $refresh = Store::decryptToken($settings['refresh_token'] ?? '');
        if ($refresh === '') {
            throw new \RuntimeException('Google refresh token missing. Reconnect the account.');
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Google token refresh failed: ' . $response->body());
        }

        $json = $response->json();
        $patch = [
            'access_token' => Store::encryptToken($json['access_token'] ?? ''),
            'token_expires' => time() + (int) ($json['expires_in'] ?? 3600) - 60,
        ];
        if (!empty($json['refresh_token'])) {
            $patch['refresh_token'] = Store::encryptToken($json['refresh_token']);
        }

        return Store::put($patch);
    }

    public static function accessToken(): string
    {
        $settings = Store::all();
        if (empty($settings['connected'])) {
            throw new \RuntimeException('Google Drive is not connected.');
        }

        if ((int) ($settings['token_expires'] ?? 0) <= time()) {
            $settings = self::refresh($settings);
        }

        $token = Store::decryptToken($settings['access_token'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('Google access token missing. Reconnect the account.');
        }

        return $token;
    }

    public static function userEmail(string $accessToken): string
    {
        $response = Http::withToken($accessToken)->get(self::USERINFO_URL);
        if (!$response->successful()) {
            return '';
        }

        return (string) ($response->json('email') ?? '');
    }

    public static function ensureFolder(string $name, ?string $existingId = null): string
    {
        $token = self::accessToken();

        if ($existingId) {
            $check = Http::withToken($token)->get(self::DRIVE_FILES . '/' . $existingId, [
                'fields' => 'id,trashed',
            ]);
            if ($check->successful() && empty($check->json('trashed'))) {
                return $existingId;
            }
        }

        $q = sprintf(
            "mimeType='application/vnd.google-apps.folder' and name='%s' and trashed=false",
            str_replace("'", "\\'", $name)
        );
        $list = Http::withToken($token)->get(self::DRIVE_FILES, [
            'q' => $q,
            'spaces' => 'drive',
            'fields' => 'files(id,name)',
        ]);
        if ($list->successful()) {
            $files = $list->json('files') ?? [];
            if (!empty($files[0]['id'])) {
                return $files[0]['id'];
            }
        }

        $create = Http::withToken($token)->post(self::DRIVE_FILES, [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        if (!$create->successful()) {
            throw new \RuntimeException('Could not create Drive folder: ' . $create->body());
        }

        return (string) $create->json('id');
    }

    /**
     * Resumable upload for large backup archives.
     */
    public static function uploadFile(string $localPath, string $fileName, string $folderId, string $mime = 'application/gzip'): array
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException('Backup file not found: ' . $localPath);
        }

        $token = self::accessToken();
        $size = filesize($localPath);
        $meta = [
            'name' => $fileName,
            'parents' => [$folderId],
        ];

        $session = Http::withToken($token)
            ->withHeaders([
                'X-Upload-Content-Type' => $mime,
                'X-Upload-Content-Length' => (string) $size,
                'Content-Type' => 'application/json; charset=UTF-8',
            ])
            ->withBody(json_encode($meta), 'application/json; charset=UTF-8')
            ->post(self::DRIVE_UPLOAD . '?uploadType=resumable');

        if (!$session->successful()) {
            throw new \RuntimeException('Drive resumable session failed: ' . $session->body());
        }

        $uploadUrl = $session->header('Location');
        if (!$uploadUrl) {
            throw new \RuntimeException('Drive did not return upload URL.');
        }

        $chunk = 8 * 1024 * 1024; // 8 MiB
        $fh = fopen($localPath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open backup file for upload.');
        }

        $offset = 0;
        $result = null;
        try {
            while ($offset < $size) {
                $length = (int) min($chunk, $size - $offset);
                $data = fread($fh, $length);
                if ($data === false) {
                    throw new \RuntimeException('Failed reading backup chunk.');
                }
                $end = $offset + strlen($data) - 1;
                $response = Http::withBody($data, $mime)
                    ->withHeaders([
                        'Content-Length' => (string) strlen($data),
                        'Content-Range' => sprintf('bytes %d-%d/%d', $offset, $end, $size),
                    ])
                    ->put($uploadUrl);

                $status = $response->status();
                if ($status === 200 || $status === 201) {
                    $result = $response->json();
                    break;
                }
                if ($status !== 308) {
                    throw new \RuntimeException('Drive chunk upload failed (' . $status . '): ' . $response->body());
                }
                $offset = $end + 1;
            }
        } finally {
            fclose($fh);
        }

        if (!is_array($result)) {
            throw new \RuntimeException('Drive upload did not complete.');
        }

        return $result;
    }
}

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PrmGoogleDrive\BackupRunner;
use PrmGoogleDrive\Google;
use PrmGoogleDrive\Store;
use Pterodactyl\Http\Middleware\AdminAuthenticate;
use Pterodactyl\Models\Server;

$prmGdriveLib = '/blueprint_extensions/prmgoogledrive/lib';
if (!is_dir($prmGdriveLib)) {
    $prmGdriveLib = dirname(__DIR__) . '/lib';
}
require_once $prmGdriveLib . '/AppCredentials.php';
require_once $prmGdriveLib . '/Store.php';
require_once $prmGdriveLib . '/Google.php';
require_once $prmGdriveLib . '/BackupRunner.php';

$admin = ['web', 'auth', AdminAuthenticate::class];

Route::get('/oauth/callback', function (Request $request) {
    $settings = Store::all();
    $state = (string) $request->session()->get('prmgoogledrive_oauth_state', '');
    if (!$request->filled('code') || !$request->filled('state') || !hash_equals($state, (string) $request->input('state'))) {
        return redirect('/admin/extensions/prmgoogledrive')->with('error', 'Google OAuth state mismatch.');
    }

    try {
        $tokens = Google::exchangeCode($settings, (string) $request->input('code'));
        $access = $tokens['access_token'] ?? '';
        $email = $access ? Google::userEmail($access) : '';
        Store::put([
            'connected' => true,
            'google_email' => $email,
            'access_token' => Store::encryptToken($access),
            'refresh_token' => Store::encryptToken($tokens['refresh_token'] ?? Store::decryptToken($settings['refresh_token'] ?? '')),
            'token_expires' => time() + (int) ($tokens['expires_in'] ?? 3600) - 60,
        ]);
        Store::log('info', 'Google Drive connected', ['email' => $email]);
        $request->session()->forget('prmgoogledrive_oauth_state');

        return redirect('/admin/extensions/prmgoogledrive')->with('success', 'Google Drive bağlandı: ' . ($email ?: 'OK'));
    } catch (\Throwable $e) {
        Store::log('error', 'OAuth failed', ['error' => $e->getMessage()]);

        return redirect('/admin/extensions/prmgoogledrive')->with('error', $e->getMessage());
    }
})->middleware(['web'])->name('prmgoogledrive.oauth.callback');

Route::middleware($admin)->group(function () {
    Route::post('/settings', function (Request $request) {
        $data = Store::all();
        // Client ID/Secret UI'dan gelmez — AppCredentials / env
        $data['redirect_uri'] = \PrmGoogleDrive\AppCredentials::redirectUri();
        $data['folder_name'] = trim((string) $request->input('folder_name', 'PRM-Backups')) ?: 'PRM-Backups';
        $data['mode'] = in_array($request->input('mode'), ['wings', 'zip'], true) ? $request->input('mode') : 'wings';
        $data['schedule'] = in_array($request->input('schedule'), ['off', 'hourly', 'daily', 'twicedaily'], true)
            ? $request->input('schedule')
            : 'off';
        $data['ignore'] = (string) $request->input('ignore', $data['ignore']);
        $data['keep_local'] = $request->boolean('keep_local');
        $ids = $request->input('server_ids', []);
        $data['server_ids'] = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
        Store::save($data);

        return redirect('/admin/extensions/prmgoogledrive')->with('success', 'Ayarlar kaydedildi.');
    })->name('prmgoogledrive.settings');

    Route::post('/connect', function (Request $request) {
        $settings = Store::all();
        if (!\PrmGoogleDrive\AppCredentials::ready()) {
            return redirect('/admin/extensions/prmgoogledrive')->with(
                'error',
                'Eklenti OAuth anahtarı henüz gömülü değil. Panel env: PRM_GDRIVE_CLIENT_ID / PRM_GDRIVE_CLIENT_SECRET'
            );
        }
        $state = bin2hex(random_bytes(16));
        $request->session()->put('prmgoogledrive_oauth_state', $state);

        return redirect()->away(Google::authUrl($settings, $state));
    })->name('prmgoogledrive.connect');

    Route::post('/disconnect', function () {
        Store::put([
            'connected' => false,
            'google_email' => '',
            'access_token' => '',
            'refresh_token' => '',
            'token_expires' => 0,
            'folder_id' => '',
        ]);
        Store::log('info', 'Google Drive disconnected');

        return redirect('/admin/extensions/prmgoogledrive')->with('success', 'Google bağlantısı kesildi.');
    })->name('prmgoogledrive.disconnect');

    Route::post('/run', function (Request $request) {
        try {
            @set_time_limit(0);
            $only = $request->input('server_ids');
            $only = is_array($only) ? array_map('intval', $only) : null;
            $summary = BackupRunner::run($only);
            $msg = $summary['ok'] . ' OK';
            if ($summary['fail']) {
                $msg .= ', ' . $summary['fail'] . ' FAIL';
            }

            return redirect('/admin/extensions/prmgoogledrive')->with(
                $summary['fail'] ? 'error' : 'success',
                'Yedekleme bitti: ' . $msg
            );
        } catch (\Throwable $e) {
            Store::log('error', 'Manual run failed', ['error' => $e->getMessage()]);

            return redirect('/admin/extensions/prmgoogledrive')->with('error', $e->getMessage());
        }
    })->name('prmgoogledrive.run');

    Route::get('/status', function () {
        $s = Store::all();

        return response()->json([
            'connected' => (bool) $s['connected'],
            'email' => $s['google_email'],
            'mode' => $s['mode'],
            'schedule' => $s['schedule'],
            'last_run_at' => $s['last_run_at'],
            'folder_id' => $s['folder_id'],
            'logs' => array_slice($s['logs'] ?? [], 0, 20),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name', 'uuid'])->toArray(),
        ]);
    })->name('prmgoogledrive.status');
});

Route::get('/cron/{key}', function (string $key) {
    $settings = Store::all();
    if (!$settings['cron_key'] || !hash_equals($settings['cron_key'], $key)) {
        abort(403, 'Invalid cron key');
    }
    if (!BackupRunner::dueForSchedule($settings)) {
        return response()->json(['skipped' => true, 'reason' => 'not due', 'schedule' => $settings['schedule']]);
    }
    try {
        @set_time_limit(0);
        $summary = BackupRunner::run();

        return response()->json(['ok' => true, 'summary' => $summary]);
    } catch (\Throwable $e) {
        Store::log('error', 'Cron run failed', ['error' => $e->getMessage()]);

        return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
})->middleware(['web'])->name('prmgoogledrive.cron');

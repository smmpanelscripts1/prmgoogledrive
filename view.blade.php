@php
  $lib = '/blueprint_extensions/prmgoogledrive/lib';
  if (is_file($lib . '/AppCredentials.php')) {
      require_once $lib . '/AppCredentials.php';
      require_once $lib . '/Store.php';
  }
  $settings = class_exists(\PrmGoogleDrive\Store::class) ? \PrmGoogleDrive\Store::all() : [
      'connected' => false, 'google_email' => '', 'folder_name' => 'PRM-Backups', 'mode' => 'wings',
      'schedule' => 'off', 'ignore' => '', 'keep_local' => false, 'server_ids' => [], 'cron_key' => '',
      'last_run_at' => null, 'logs' => [],
  ];
  $oauthReady = class_exists(\PrmGoogleDrive\AppCredentials::class) && \PrmGoogleDrive\AppCredentials::ready();
  $servers = \Pterodactyl\Models\Server::query()->orderBy('name')->get(['id', 'name', 'uuid']);
  $selected = $settings['server_ids'] ?? [];
  $cronUrl = !empty($settings['cron_key']) ? url('/extensions/prmgoogledrive/cron/' . $settings['cron_key']) : '';
@endphp

<div class="row">
  <div class="col-xs-12">
    @if(session('success'))
      <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
      <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
  </div>
</div>

<div class="row">
  <div class="col-md-5">
    <div class="box box-success">
      <div class="box-header with-border">
        <h3 class="box-title">Google Drive yetkisi</h3>
      </div>
      <div class="box-body">
        <p>Kendi Google hesabınla giriş yap, Drive yazma iznini ver. Yedekler <strong>senin</strong> Drive’ına gider.</p>

        @if(!$oauthReady)
          <div class="alert alert-warning">
            Eklenti OAuth anahtarı henüz panelde yok. Sunucu sahibinin bir kez
            <code>PRM_GDRIVE_CLIENT_ID</code> / <code>PRM_GDRIVE_CLIENT_SECRET</code>
            env vermesi yeterli — kuran admin Cloud Console açmaz.
          </div>
        @endif

        @if(!empty($settings['connected']))
          <p>
            <span class="label label-success">Bağlı</span>
            {{ $settings['google_email'] ?: 'Google hesabı' }}
          </p>
          <form method="post" action="{{ url('/extensions/prmgoogledrive/disconnect') }}" style="display:inline">
            @csrf
            <button class="btn btn-danger btn-sm" type="submit">Bağlantıyı kes</button>
          </form>
        @else
          <form method="post" action="{{ url('/extensions/prmgoogledrive/connect') }}">
            @csrf
            <button class="btn btn-success btn-lg" type="submit" {{ !$oauthReady ? 'disabled' : '' }}>
              Google ile bağlan / yetki ver
            </button>
          </form>
        @endif
      </div>
    </div>

    <div class="box box-warning">
      <div class="box-header with-border">
        <h3 class="box-title">Yedekle</h3>
      </div>
      <div class="box-body">
        <p>Son: <strong>{{ $settings['last_run_at'] ?? '—' }}</strong></p>
        <form method="post" action="{{ url('/extensions/prmgoogledrive/run') }}">
          @csrf
          <button class="btn btn-warning" type="submit" {{ empty($settings['connected']) ? 'disabled' : '' }}>
            Şimdi yedekle
          </button>
        </form>
        <hr>
        <label>Cron URL</label>
        <input type="text" class="form-control" readonly value="{{ $cronUrl }}" onclick="this.select()">
      </div>
    </div>
  </div>

  <div class="col-md-7">
    <div class="box box-primary">
      <div class="box-header with-border">
        <h3 class="box-title">Yedek ayarları</h3>
      </div>
      <form method="post" action="{{ url('/extensions/prmgoogledrive/settings') }}">
        @csrf
        <div class="box-body">
          <div class="form-group">
            <label>Drive klasör adı</label>
            <input type="text" class="form-control" name="folder_name" value="{{ $settings['folder_name'] ?? 'PRM-Backups' }}">
          </div>
          <div class="form-group">
            <label>Format</label>
            <select name="mode" class="form-control">
              <option value="wings" {{ ($settings['mode'] ?? '') === 'wings' ? 'selected' : '' }}>Wings arşivi (.tar.gz)</option>
              <option value="zip" {{ ($settings['mode'] ?? '') === 'zip' ? 'selected' : '' }}>ZIP (ignore list)</option>
            </select>
          </div>
          <div class="form-group">
            <label>Zamanlama</label>
            <select name="schedule" class="form-control">
              <option value="off" {{ ($settings['schedule'] ?? '') === 'off' ? 'selected' : '' }}>Kapalı</option>
              <option value="hourly" {{ ($settings['schedule'] ?? '') === 'hourly' ? 'selected' : '' }}>Saatlik</option>
              <option value="twicedaily" {{ ($settings['schedule'] ?? '') === 'twicedaily' ? 'selected' : '' }}>Günde 2</option>
              <option value="daily" {{ ($settings['schedule'] ?? '') === 'daily' ? 'selected' : '' }}>Günlük</option>
            </select>
          </div>
          <div class="form-group">
            <label>Ignore</label>
            <textarea name="ignore" class="form-control" rows="4">{{ $settings['ignore'] ?? '' }}</textarea>
          </div>
          <div class="checkbox">
            <label>
              <input type="checkbox" name="keep_local" value="1" {{ !empty($settings['keep_local']) ? 'checked' : '' }}>
              Yerel Wings yedeğini silme
            </label>
          </div>
          <div class="form-group">
            <label>Sunucular (boş = hepsi)</label>
            <select name="server_ids[]" class="form-control" multiple size="8">
              @foreach($servers as $server)
                <option value="{{ $server->id }}" {{ in_array($server->id, $selected, true) ? 'selected' : '' }}>
                  {{ $server->name }}
                </option>
              @endforeach
            </select>
          </div>
        </div>
        <div class="box-footer">
          <button type="submit" class="btn btn-primary">Kaydet</button>
        </div>
      </form>
    </div>

    <div class="box box-default">
      <div class="box-header with-border"><h3 class="box-title">Log</h3></div>
      <div class="box-body" style="max-height:280px;overflow:auto">
        @forelse(($settings['logs'] ?? []) as $log)
          <div class="prm-gdrive-log prm-gdrive-log--{{ $log['level'] ?? 'info' }}">
            <code>{{ $log['at'] ?? '' }}</code> {{ $log['message'] ?? '' }}
            @if(!empty($log['context']['error']))
              <span class="text-danger">— {{ $log['context']['error'] }}</span>
            @endif
          </div>
        @empty
          <p class="text-muted">Log yok.</p>
        @endforelse
      </div>
    </div>
  </div>
</div>

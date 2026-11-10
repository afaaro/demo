<?php
/** @var list<array<string,mixed>> $streams */
/** @var array<string,mixed>|null $stream */
/** @var string $obsUrl */
/** @var string $obsUrlLocal */
/** @var string $watchUrl */
/** @var string $hlsUrl */
/** @var string $hlsUrlRaw */
/** @var array<string,mixed> $cfg */
/** @var string $csrf */
/** @var bool $canCreate */
/** @var string $statsUrl */
$e = static fn(string|int|float $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$streams = $streams ?? [];
$stream = $stream ?? null;
$canCreate = $canCreate ?? true;
$selectedId = (int) ($stream['id'] ?? 0);
?>
<div class="ls-shell">
  <header class="ls-head">
    <h1>Livestream studio</h1>
    <a class="ls-btn is-ghost" href="<?= $e(url('livestream')) ?>">Directory</a>
  </header>

  <section class="ls-section">
    <h2>Your streams</h2>
    <?php if ($streams === []): ?>
      <p class="ls-muted">No streams yet — create one below.</p>
    <?php else: ?>
      <ul class="ls-stream-list">
        <?php foreach ($streams as $row): ?>
          <?php
            $id = (int) ($row['id'] ?? 0);
            $isActive = $id === $selectedId;
            $isLive = (($row['status'] ?? '') === 'live');
          ?>
          <li class="<?= $isActive ? 'is-active' : '' ?>">
            <a href="<?= $e(url('livestream/studio', ['id' => $id])) ?>">
              <?php if ($isLive): ?>
                <span class="ls-badge">LIVE</span>
              <?php else: ?>
                <span class="ls-badge is-off">Idle</span>
              <?php endif; ?>
              <span class="ls-stream-list-title"><?= $e((string) ($row['title'] ?? '')) ?></span>
              <code><?= $e((string) ($row['slug'] ?? '')) ?></code>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if ($canCreate): ?>
    <section class="ls-section">
      <h2>Create stream</h2>
      <form method="post" action="<?= $e(url('livestream/studio')) ?>" class="ls-form">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="intent" value="create">
        <label>
          Title
          <input type="text" name="title" maxlength="120" value="" placeholder="e.g. Mogadishu evening"
                 required>
        </label>
        <button type="submit" class="ls-btn">Create stream</button>
      </form>
    </section>
  <?php else: ?>
    <p class="ls-muted">Stream limit reached. Delete one to create another.</p>
  <?php endif; ?>

  <?php if ($stream !== null): ?>
    <section class="ls-section">
      <h2>Selected stream</h2>
      <form method="post" action="<?= $e(url('livestream/studio')) ?>" class="ls-form">
        <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
        <input type="hidden" name="intent" value="save">
        <input type="hidden" name="stream_id" value="<?= (int) $stream['id'] ?>">
        <label>
          Title
          <input type="text" name="title" maxlength="120"
                 value="<?= $e((string) ($stream['title'] ?? '')) ?>" required>
        </label>
        <button type="submit" class="ls-btn">Save title</button>
      </form>
      <p>
        <?php if (($stream['status'] ?? '') === 'live'): ?>
          <span class="ls-badge">LIVE</span>
        <?php else: ?>
          <span class="ls-badge is-off">Idle</span>
        <?php endif; ?>
        Path / slug: <code><?= $e((string) $stream['slug']) ?></code>
      </p>
      <p>
        <a href="<?= $e($watchUrl) ?>">Public watch page</a>
      </p>
    </section>

    <?php if ($statsUrl !== ''): ?>
      <section class="ls-section" id="ls-live-stats" data-stats-url="<?= $e($statsUrl) ?>">
        <h2>Live feed</h2>
        <p class="ls-muted" id="ls-stats-status">Connecting to MediaMTX…</p>
        <div class="ls-stats-grid" id="ls-stats-grid" hidden>
          <article class="ls-stats-card" data-role="ingest">
            <h3>Ingest <span class="ls-stats-path"></span></h3>
            <dl class="ls-stats-dl"></dl>
          </article>
          <article class="ls-stats-card" data-role="h264">
            <h3>Transcode → H.264 <span class="ls-stats-path"></span></h3>
            <dl class="ls-stats-dl"></dl>
          </article>
        </div>
      </section>
    <?php endif; ?>

    <section class="ls-section">
      <h2>Publish setup</h2>
      <p class="ls-muted">Open a protocol below. Use RTMP for remote / high bitrate; SRT for local or low-latency.</p>

      <div class="ls-accordions">
        <details class="ls-acc" open>
          <summary class="ls-acc-sum">
            <span class="ls-acc-title">Wirecast <span class="ls-acc-tag">SRT</span></span>
            <span class="ls-acc-hint">Split host / port / stream ID</span>
          </summary>
          <div class="ls-acc-body">
            <ol class="ls-steps">
              <li>Output → Output Settings → Destination → <strong>SRT</strong>.</li>
              <li>Do <strong>not</strong> paste the full <code>srt://…</code> URL into Address (Wirecast often breaks it).</li>
              <li>Fill the split fields below. Leave <strong>Passphrase empty</strong>.</li>
              <li>Encoder: H.264 or HEVC (H.265). Audio AAC. Remote SRT: keep video ≤ ~8 Mbps.</li>
              <li>Same PC as MediaMTX → use <strong>local host</strong>. Remote → use <strong>public host</strong>, or switch to RTMP if SRT drops.</li>
            </ol>
            <label class="ls-copy-field">
              Wirecast Address (local)
              <div class="ls-copy-row">
                <input type="text" id="ls-wc-host-local" readonly value="<?= $e($srtHostLocal ?? '127.0.0.1') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-wc-host-local">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              Wirecast Address (public / DynDNS)
              <div class="ls-copy-row">
                <input type="text" id="ls-wc-host-public" readonly value="<?= $e($srtHostPublic ?? 'afaaro.myvnc.com') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-wc-host-public">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              Wirecast Port
              <div class="ls-copy-row">
                <input type="text" id="ls-wc-port" readonly value="<?= (int) ($srtPort ?? 8890) ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-wc-port">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              Wirecast Stream ID
              <div class="ls-copy-row">
                <input type="text" id="ls-wc-streamid" readonly value="<?= $e($srtStreamId ?? '') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-wc-streamid">Copy</button>
              </div>
            </label>
          </div>
        </details>

        <details class="ls-acc">
          <summary class="ls-acc-sum">
            <span class="ls-acc-title">OBS <span class="ls-acc-tag">SRT</span></span>
            <span class="ls-acc-hint">Full srt:// URL</span>
          </summary>
          <div class="ls-acc-body">
            <ol class="ls-steps">
              <li>Settings → Stream → Service = <strong>Custom</strong>.</li>
              <li>Server = paste the SRT URL below (real colons in <code>publish:slug:obs:…</code>, not <code>%3A</code>).</li>
              <li>Leave Stream Key empty for SRT.</li>
              <li>Same PC as MediaMTX → use <strong>this PC / LAN</strong> URL.</li>
              <li>If OBS rejects it: use Wirecast’s Stream ID with an SRT output that has a separate Stream ID field.</li>
            </ol>
            <label class="ls-copy-field">
              SRT URL — this PC / LAN
              <div class="ls-copy-row">
                <input type="text" id="ls-obs-url-local" readonly value="<?= $e($obsUrlLocal ?? '') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-obs-url-local">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              SRT URL — public
              <div class="ls-copy-row">
                <input type="text" id="ls-obs-url" readonly value="<?= $e($obsUrl) ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-obs-url">Copy</button>
              </div>
            </label>
          </div>
        </details>

        <details class="ls-acc">
          <summary class="ls-acc-sum">
            <span class="ls-acc-title">OBS / Phone <span class="ls-acc-tag is-pref">RTMP</span></span>
            <span class="ls-acc-hint">Preferred for remote / high bitrate</span>
          </summary>
          <div class="ls-acc-body">
            <ol class="ls-steps">
              <li>Forward router TCP <strong><?= (int) ($rtmpPort ?? 1935) ?></strong> → this PC.</li>
              <li>If SRT logs show <code>max recorded size exceeded</code>, switch to <strong>RTMP</strong> and keep bitrate ≤ ~8 Mbps.</li>
              <li><strong>OBS Custom RTMP:</strong> Server = RTMP server below, Stream Key = stream key (password).</li>
              <li><strong>OBS with authentication:</strong> Server = RTMP server, Stream Key empty, Username = <code>obs</code>, Password = stream key.</li>
              <li>Larix / Prism: paste the full RTMP URL (<code>?user=&amp;pass=</code>).</li>
              <li>Encode <strong>H.264 + AAC</strong> for best compatibility.</li>
            </ol>
            <label class="ls-copy-field">
              RTMP URL — public
              <div class="ls-copy-row">
                <input type="text" id="ls-rtmp-url" readonly value="<?= $e($rtmpUrl ?? '') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-url">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              RTMP URL — this PC / LAN
              <div class="ls-copy-row">
                <input type="text" id="ls-rtmp-url-local" readonly value="<?= $e($rtmpUrlLocal ?? '') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-url-local">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              RTMP server (no credentials)
              <div class="ls-copy-row">
                <input type="text" id="ls-rtmp-server" readonly value="<?= $e($rtmpServer ?? '') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-server">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              RTMP username
              <div class="ls-copy-row">
                <input type="text" id="ls-rtmp-user" readonly value="<?= $e($rtmpUser ?? 'obs') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-user">Copy</button>
              </div>
            </label>
            <label class="ls-copy-field">
              RTMP password (stream key)
              <div class="ls-copy-row">
                <input type="text" id="ls-rtmp-pass" readonly value="<?= $e($rtmpPass ?? '') ?>">
                <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-pass">Copy</button>
              </div>
            </label>
          </div>
        </details>

        <details class="ls-acc">
          <summary class="ls-acc-sum">
            <span class="ls-acc-title">Playback <span class="ls-acc-tag">HLS</span></span>
            <span class="ls-acc-hint">Website / VLC</span>
          </summary>
          <div class="ls-acc-body">
            <p class="ls-muted" style="margin-top:0">
              HLS (website / H.264): <code><?= $e($hlsUrl) ?></code>
              <?php if (!empty($hlsUrlRaw) && ($hlsUrlRaw !== $hlsUrl)): ?>
                <br>HLS (VLC / original): <code><?= $e($hlsUrlRaw) ?></code>
              <?php endif; ?>
            </p>
            <?php if (!empty($cfg['hls_transcode_h264'])): ?>
              <p class="ls-muted">HEVC ingest is OK; ffmpeg builds the website H.264 path automatically for listed slugs.</p>
            <?php endif; ?>
          </div>
        </details>

        <details class="ls-acc">
          <summary class="ls-acc-sum">
            <span class="ls-acc-title">MediaMTX <span class="ls-acc-tag">Webhooks</span></span>
            <span class="ls-acc-hint">YAML hooks</span>
          </summary>
          <div class="ls-acc-body">
            <p class="ls-muted" style="margin-top:0">Put these in your MediaMTX YAML (see example file).</p>
            <ul class="ls-mono">
              <li>Auth: <code><?= $e(url('livestream/webhook', [], true)) ?></code></li>
              <li>Ready:
                <code><?= $e(url('livestream/webhook/status', [
                    'event'  => 'ready',
                    'secret' => (string) ($cfg['webhook_secret'] ?? ''),
                ], true)) ?>&amp;path=$MTX_PATH</code>
              </li>
              <li>Not ready:
                <code><?= $e(url('livestream/webhook/status', [
                    'event'  => 'notready',
                    'secret' => (string) ($cfg['webhook_secret'] ?? ''),
                ], true)) ?>&amp;path=$MTX_PATH</code>
              </li>
            </ul>
          </div>
        </details>
      </div>

      <div class="ls-actions">
        <form method="post" action="<?= $e(url('livestream/studio/rotate')) ?>"
              onsubmit="return confirm('Rotate stream key? Update your encoder afterward.');">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
          <input type="hidden" name="stream_id" value="<?= (int) $stream['id'] ?>">
          <button type="submit" class="ls-btn is-danger">Rotate stream key</button>
        </form>
        <form method="post" action="<?= $e(url('livestream/studio/delete')) ?>"
              onsubmit="return confirm('Delete this stream permanently?');">
          <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
          <input type="hidden" name="stream_id" value="<?= (int) $stream['id'] ?>">
          <button type="submit" class="ls-btn is-ghost">Delete stream</button>
        </form>
      </div>
    </section>
  <?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-ls-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var id = btn.getAttribute('data-ls-copy');
    var input = id ? document.getElementById(id) : null;
    if (!input) return;
    input.select();
    navigator.clipboard.writeText(input.value).then(function () {
      btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = 'Copy'; }, 1200);
    }).catch(function () {
      document.execCommand('copy');
    });
  });
});

(function () {
  var root = document.getElementById('ls-live-stats');
  if (!root) return;
  var url = root.getAttribute('data-stats-url');
  var statusEl = document.getElementById('ls-stats-status');
  var grid = document.getElementById('ls-stats-grid');
  if (!url || !statusEl || !grid) return;

  function fmtMbps(v) {
    if (v == null || v === '') return '—';
    return Number(v).toFixed(2) + ' Mbps';
  }
  function fmtBytes(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
    return (n / 1073741824).toFixed(2) + ' GB';
  }
  function trackLine(t) {
    if (!t || !t.codec) return '';
    var bits = [t.codec];
    if (t.width && t.height) bits.push(t.width + '×' + t.height);
    if (t.profile) bits.push(t.profile + (t.level ? '@' + t.level : ''));
    if (t.rate) bits.push(t.rate + ' Hz');
    if (t.channels) bits.push(t.channels + ' ch');
    return bits.join(' · ');
  }
  function fillCard(role, data) {
    var card = grid.querySelector('[data-role="' + role + '"]');
    if (!card || !data) return;
    var pathEl = card.querySelector('.ls-stats-path');
    var dl = card.querySelector('.ls-stats-dl');
    if (pathEl) pathEl.textContent = data.name ? '(' + data.name + ')' : '';
    if (!dl) return;
    if (!data.online) {
      dl.innerHTML = '<div><dt>Status</dt><dd><span class="ls-badge is-off">Offline</span></dd></div>';
      return;
    }
    var rows = [
      ['Status', '<span class="ls-badge">LIVE</span>'],
      ['Video / audio', (data.tracks || []).map(trackLine).filter(Boolean).join('<br>') || '—'],
      ['Bitrate in', fmtMbps(data.bitrate_in_mbps)],
      ['SRT RTT', data.rtt_ms != null ? data.rtt_ms + ' ms' : '—'],
      ['Packet loss', data.loss_pct != null ? data.loss_pct + '%' : '—'],
      ['Readers', data.readers != null ? String(data.readers) : '—'],
      ['Bytes in', fmtBytes(data.bytes_in)],
      ['Bytes out', fmtBytes(data.bytes_out)],
      ['Publisher', data.remote_addr || data.source_type || '—']
    ];
    dl.innerHTML = rows.map(function (r) {
      return '<div><dt>' + r[0] + '</dt><dd>' + r[1] + '</dd></div>';
    }).join('');
  }

  function tick() {
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.j || !res.j.ok) {
          statusEl.textContent = (res.j && res.j.error)
            ? ('Stats unavailable: ' + res.j.error)
            : 'Stats unavailable (is MediaMTX API reachable?)';
          grid.hidden = true;
          return;
        }
        var srcOn = res.j.source && res.j.source.online;
        var webOn = res.j.web && res.j.web.online;
        var via = res.j.via ? String(res.j.via) : '';
        var viaNote = via === 'hls'
          ? ' · details from HLS (MediaMTX API not local)'
          : (via === 'upstream' ? ' · via home telemetry' : '');
        statusEl.textContent = srcOn
          ? ('Ingest live' + (webOn ? ' · H.264 transcode live' : (res.j.transcode_on === false ? ' · waiting for transcoder…' : '')) + viaNote)
          : 'Publisher offline' + viaNote;
        fillCard('ingest', res.j.source);
        fillCard('h264', res.j.web);
        grid.hidden = false;
      })
      .catch(function () {
        statusEl.textContent = 'Stats unavailable (network error)';
        grid.hidden = true;
      });
  }

  tick();
  setInterval(tick, 2000);
})();
</script>

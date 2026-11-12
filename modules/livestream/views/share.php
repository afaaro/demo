<?php
/** @var array<string,mixed> $stream */
/** @var string $obsUrl */
/** @var string $rtmpUrl */
/** @var string $rtmpServer */
/** @var string $rtmpUser */
/** @var string $rtmpPass */
/** @var int $rtmpPort */
/** @var string $watchUrl */
$e = static fn(string|int|float $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="ls-shell">
  <header class="ls-head">
    <h1><?= $e((string) ($stream['title'] ?? 'Broadcast settings')) ?></h1>
    <a class="ls-btn is-ghost" href="<?= $e($watchUrl) ?>">Watch</a>
  </header>

  <section class="ls-section">
    <p class="ls-muted">Use these settings in OBS, Wirecast, or Larix. This page does not give access to the studio.</p>

    <h2>SRT</h2>
    <ol class="ls-steps">
      <li>Service = <strong>Custom</strong>.</li>
      <li>Paste the SRT URL as the server. Leave the stream key empty.</li>
    </ol>
    <label class="ls-copy-field">
      SRT URL
      <div class="ls-copy-row">
        <input type="text" id="ls-obs-url" readonly value="<?= $e($obsUrl) ?>">
        <button type="button" class="ls-btn" data-ls-copy="ls-obs-url">Copy</button>
      </div>
    </label>

    <h2>RTMP</h2>
    <ol class="ls-steps">
      <li>Server = RTMP server below. Username = <code>obs</code>. Password = stream key.</li>
      <li>Or paste the full RTMP URL into Larix / Prism.</li>
      <li>Encode <strong>H.264 + AAC</strong>. Port <?= (int) $rtmpPort ?> must be reachable.</li>
    </ol>
    <label class="ls-copy-field">
      RTMP URL
      <div class="ls-copy-row">
        <input type="text" id="ls-rtmp-url" readonly value="<?= $e($rtmpUrl) ?>">
        <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-url">Copy</button>
      </div>
    </label>
    <label class="ls-copy-field">
      RTMP server
      <div class="ls-copy-row">
        <input type="text" id="ls-rtmp-server" readonly value="<?= $e($rtmpServer) ?>">
        <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-server">Copy</button>
      </div>
    </label>
    <label class="ls-copy-field">
      Username
      <div class="ls-copy-row">
        <input type="text" id="ls-rtmp-user" readonly value="<?= $e($rtmpUser) ?>">
        <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-user">Copy</button>
      </div>
    </label>
    <label class="ls-copy-field">
      Stream key
      <div class="ls-copy-row">
        <input type="text" id="ls-rtmp-pass" readonly value="<?= $e($rtmpPass) ?>">
        <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-pass">Copy</button>
      </div>
    </label>
  </section>
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
</script>

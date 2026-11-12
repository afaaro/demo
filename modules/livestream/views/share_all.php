<?php
/** @var list<array<string,string>> $cards */
/** @var int $rtmpPort */
$e = static fn(string|int|float $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$cards = $cards ?? [];
?>
<div class="ls-shell">
  <header class="ls-head">
    <h1>Broadcast settings</h1>
  </header>

  <?php if ($cards === []): ?>
    <section class="ls-section">
      <p class="ls-muted">No streams on this account yet.</p>
    </section>
  <?php endif; ?>

  <?php foreach ($cards as $i => $card): ?>
    <section class="ls-section">
      <h2>
        <?= $e($card['title']) ?>
        <?php if (($card['status'] ?? '') === 'live'): ?>
          <span class="ls-badge">LIVE</span>
        <?php endif; ?>
      </h2>
      <p class="ls-muted"><code><?= $e($card['slug']) ?></code>
        · <a href="<?= $e($card['watchUrl']) ?>">Watch</a></p>

      <label class="ls-copy-field">
        SRT URL
        <div class="ls-copy-row">
          <input type="text" id="ls-srt-<?= (int) $i ?>" readonly value="<?= $e($card['obsUrl']) ?>">
          <button type="button" class="ls-btn" data-ls-copy="ls-srt-<?= (int) $i ?>">Copy</button>
        </div>
      </label>
      <label class="ls-copy-field">
        RTMP URL
        <div class="ls-copy-row">
          <input type="text" id="ls-rtmp-<?= (int) $i ?>" readonly value="<?= $e($card['rtmpUrl']) ?>">
          <button type="button" class="ls-btn" data-ls-copy="ls-rtmp-<?= (int) $i ?>">Copy</button>
        </div>
      </label>
      <label class="ls-copy-field">
        RTMP server
        <div class="ls-copy-row">
          <input type="text" id="ls-srv-<?= (int) $i ?>" readonly value="<?= $e($card['rtmpServer']) ?>">
          <button type="button" class="ls-btn" data-ls-copy="ls-srv-<?= (int) $i ?>">Copy</button>
        </div>
      </label>
      <label class="ls-copy-field">
        Username
        <div class="ls-copy-row">
          <input type="text" id="ls-user-<?= (int) $i ?>" readonly value="<?= $e($card['rtmpUser']) ?>">
          <button type="button" class="ls-btn" data-ls-copy="ls-user-<?= (int) $i ?>">Copy</button>
        </div>
      </label>
      <label class="ls-copy-field">
        Stream key
        <div class="ls-copy-row">
          <input type="text" id="ls-key-<?= (int) $i ?>" readonly value="<?= $e($card['rtmpPass']) ?>">
          <button type="button" class="ls-btn" data-ls-copy="ls-key-<?= (int) $i ?>">Copy</button>
        </div>
      </label>
      <p class="ls-muted">SRT: paste the URL, leave the stream key empty. RTMP: server + username <code>obs</code> + stream key. Port <?= (int) $rtmpPort ?>.</p>
    </section>
  <?php endforeach; ?>
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

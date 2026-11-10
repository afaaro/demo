(function () {
  function readIntAttr(el, name, fallback) {
    if (!el) return fallback;
    var n = parseInt(el.getAttribute(name) || '', 10);
    return isFinite(n) && n > 0 ? n : fallback;
  }

  function playerTuning(root) {
    var liveSync = readIntAttr(root, 'data-ls-live-sync', 2);
    var maxLatency = readIntAttr(root, 'data-ls-max-latency', 4);
    if (maxLatency <= liveSync) maxLatency = liveSync + 1;
    return {
      pollMs: readIntAttr(root, 'data-ls-poll-ms', 3000),
      liveSync: liveSync,
      maxLatency: maxLatency,
    };
  }

  function startPlayback(video, src, onReady, fallbackSrc, tuning) {
    fallbackSrc = fallbackSrc || '';
    var usedFallback = false;
    tuning = tuning || {};
    var liveSync = tuning.liveSync || 2;
    var maxLatency = tuning.maxLatency || 4;

    function tryNative(url) {
      video.src = url;
      video.play().catch(function () {});
      if (typeof onReady === 'function') {
        video.addEventListener('loadedmetadata', onReady, { once: true });
      }
    }

    stopPlayback(video);
    video._lsHlsRetries = 0;

    // Prefer hls.js. Chrome returns "maybe" for native HLS but cannot
    // demux MediaMTX fMP4 playlists (DEMUXER_ERROR_COULD_NOT_PARSE).
    if (window.Hls && window.Hls.isSupported()) {
      var hls = new window.Hls({
        enableWorker: true,
        lowLatencyMode: true,
        backBufferLength: 12,
        maxBufferLength: Math.max(2, liveSync + 1),
        maxMaxBufferLength: Math.max(4, maxLatency + 1),
        liveSyncDurationCount: liveSync,
        liveMaxLatencyDurationCount: maxLatency,
        maxLiveSyncPlaybackRate: 1.5,
        liveDurationInfinity: true,
      });
      hls.loadSource(src);
      hls.attachMedia(video);
      hls.on(window.Hls.Events.MANIFEST_PARSED, function () {
        video.play().catch(function () {});
        if (typeof onReady === 'function') onReady();
      });
      // Nudge toward live edge if we drift behind.
      hls.on(window.Hls.Events.FRAG_LOADED, function () {
        try {
          if (!video || video.paused || !hls.liveSyncPosition) return;
          var lag = hls.liveSyncPosition - video.currentTime;
          if (lag > Math.max(2.5, maxLatency)) {
            video.currentTime = hls.liveSyncPosition - 0.5;
          }
        } catch (e) {}
      });
      hls.on(window.Hls.Events.ERROR, function (_event, data) {
        if (!data || !data.fatal) return;
        if (data.type === window.Hls.ErrorTypes.NETWORK_ERROR) {
          // Missing {_h264} path → try raw ingest once.
          if (!usedFallback && fallbackSrc && fallbackSrc !== src) {
            usedFallback = true;
            try { hls.destroy(); } catch (e) {}
            startPlayback(video, fallbackSrc, onReady, '');
            return;
          }
          // Cap retries — offline paths were hammering MediaMTX forever.
          var retries = (video._lsHlsRetries || 0) + 1;
          video._lsHlsRetries = retries;
          if (retries > 4) {
            try { hls.destroy(); } catch (e) {}
            video._lsHls = null;
            // Allow multiview status poll to start again when path becomes ready.
            var tile = video.closest && video.closest('[data-ls-mv-tile]');
            if (tile) tile.setAttribute('data-ls-started', '0');
            var watch = video.closest && video.closest('[data-ls-watch]');
            if (watch) watch.setAttribute('data-ls-started', '0');
            return;
          }
          hls.startLoad();
          return;
        }
        if (!usedFallback && fallbackSrc && fallbackSrc !== src) {
          usedFallback = true;
          try { hls.destroy(); } catch (e) {}
          startPlayback(video, fallbackSrc, onReady, '', tuning);
          return;
        }
        try { hls.destroy(); } catch (e) {}
        video._lsHls = null;
        var deadTile = video.closest && video.closest('[data-ls-mv-tile]');
        if (deadTile) deadTile.setAttribute('data-ls-started', '0');
        var deadWatch = video.closest && video.closest('[data-ls-watch]');
        if (deadWatch) deadWatch.setAttribute('data-ls-started', '0');
      });
      video._lsHls = hls;
      return;
    }

    // Safari / iOS native HLS only when hls.js is unavailable.
    if (video.canPlayType('application/vnd.apple.mpegurl')) {
      tryNative(src);
      return;
    }
  }

  function stopPlayback(video) {
    if (!video) return;
    if (video._lsHls) {
      try { video._lsHls.destroy(); } catch (e) {}
      video._lsHls = null;
    }
    try {
      video.pause();
      video.removeAttribute('src');
      video.load();
    } catch (e) {}
  }

  var audioCtx = null;
  var meterTiles = [];

  // Phone/WebKit do not divert MediaElementSource away from the handset speaker.
  // Unmuting for analysers = audible output + often still no meter data.
  function isPhoneBrowser() {
    var ua = navigator.userAgent || '';
    if (/Android|iPhone|iPad|iPod|IEMobile|Mobile/i.test(ua)) return true;
    return (navigator.maxTouchPoints || 0) > 1
      && /Mac/.test(navigator.platform || '');
  }

  function ensureAudioCtx() {
    if (audioCtx) return audioCtx;
    var Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;
    audioCtx = new Ctx();
    return audioCtx;
  }

  function keepTileSilent(video) {
    if (!video) return;
    video.muted = true;
    video.volume = 0;
    video.setAttribute('muted', '');
  }

  // Desktop only: unmuted element feeds MediaElementSource after hijack.
  function enableMeterInput(video) {
    if (!video || isPhoneBrowser()) {
      keepTileSilent(video);
      return;
    }
    video.volume = 1;
    video.muted = false;
    video.removeAttribute('muted');
  }

  function unlockAudioGraph() {
    var ctx = ensureAudioCtx();
    if (!ctx) return Promise.resolve(false);
    return ctx.resume().then(function () {
      meterTiles.forEach(function (entry) {
        keepTileSilent(entry.video);
        if (!isPhoneBrowser() && entry.tapped) {
          enableMeterInput(entry.video);
        }
      });
      var hint = document.getElementById('ls-mv-audio-hint');
      if (hint) hint.hidden = true;
      var btn = document.getElementById('ls-mv-enable-audio');
      if (btn) btn.hidden = true;
      return true;
    }).catch(function () {
      return false;
    });
  }

  function peakLevel(analyser, timeData) {
    analyser.getByteTimeDomainData(timeData);
    var peak = 0;
    for (var i = 0; i < timeData.length; i++) {
      var v = Math.abs(timeData[i] - 128) / 128;
      if (v > peak) peak = v;
    }
    return peak;
  }

  function paintBars(bars, level) {
    var n = bars.length;
    for (var i = 0; i < n; i++) {
      // Fill from bottom (column-reverse): lower LEDs light first.
      var threshold = (i + 0.35) / n;
      var on = level >= threshold;
      bars[i].classList.toggle('is-on', on);
      bars[i].classList.toggle('is-hot', on && i >= Math.floor(n * 0.75));
      bars[i].classList.toggle('is-warn', on && i >= Math.floor(n * 0.5) && i < Math.floor(n * 0.75));
    }
  }

  // Phone: stay muted forever; animate the bar from playback activity so the
  // meter still reads while the handset speaker stays quiet.
  function attachPhoneMeter(tile, video) {
    var meter = tile.querySelector('.ls-mv-vu-l');
    if (!meter || !video) return;
    var bars = meter.querySelectorAll('span');
    var level = 0;
    var lastTime = 0;
    var stillFrames = 0;

    function paint() {
      if (!tile.isConnected) return;
      keepTileSilent(video);

      var playing = !video.paused && !video.ended && video.readyState >= 2;
      var t = video.currentTime || 0;
      var advancing = Math.abs(t - lastTime) > 0.01;
      if (advancing) {
        lastTime = t;
        stillFrames = 0;
      } else {
        stillFrames += 1;
      }

      if (playing && stillFrames < 12) {
        var now = performance.now() / 1000;
        var wave = 0.28
          + 0.22 * Math.sin(now * 5.1 + (t * 3.3))
          + 0.18 * Math.sin(now * 11.7 + t)
          + 0.12 * Math.sin(now * 2.3);
        level = level * 0.72 + Math.max(0.08, Math.min(0.95, wave)) * 0.28;
      } else {
        level *= 0.82;
      }

      paintBars(bars, level);
      tile._lsVuRaf = requestAnimationFrame(paint);
    }

    tile._lsVuRaf = requestAnimationFrame(paint);
    meterTiles.push({ tile: tile, video: video, tapped: false });
  }

  function attachDesktopVuMeters(tile, video) {
    var meter = tile.querySelector('.ls-mv-vu-l');
    if (!meter || !video) return;

    video.crossOrigin = 'anonymous';
    var ctx = ensureAudioCtx();
    if (!ctx) {
      attachPhoneMeter(tile, video);
      return;
    }

    try {
      keepTileSilent(video);
      var source = ctx.createMediaElementSource(video);
      var analyser = ctx.createAnalyser();
      analyser.fftSize = 256;
      analyser.smoothingTimeConstant = 0.55;
      source.connect(analyser);

      var timeData = new Uint8Array(analyser.fftSize);
      var bars = meter.querySelectorAll('span');
      var peakHold = 0;

      function paint() {
        if (!tile.isConnected) return;
        var level = peakLevel(analyser, timeData);
        peakHold = Math.max(level, peakHold * 0.92);
        paintBars(bars, Math.max(level, peakHold * 0.85));
        tile._lsVuRaf = requestAnimationFrame(paint);
      }
      tile._lsVuRaf = requestAnimationFrame(paint);
      meterTiles.push({ tile: tile, video: video, tapped: true });

      if (ctx.state === 'suspended') {
        var hint = document.getElementById('ls-mv-audio-hint');
        if (hint) hint.hidden = false;
        var btn = document.getElementById('ls-mv-enable-audio');
        if (btn) btn.hidden = false;
      } else {
        enableMeterInput(video);
      }
    } catch (e) {
      keepTileSilent(video);
      attachPhoneMeter(tile, video);
    }
  }

  function attachVuMeters(tile, video) {
    if (!video || video._lsVuAttached) return;
    video._lsVuAttached = true;
    keepTileSilent(video);

    if (isPhoneBrowser()) {
      attachPhoneMeter(tile, video);
      return;
    }
    attachDesktopVuMeters(tile, video);
  }

  function detachVuMeters(tile, video) {
    if (tile && tile._lsVuRaf) {
      try { cancelAnimationFrame(tile._lsVuRaf); } catch (e) {}
      tile._lsVuRaf = null;
    }
    if (video) {
      video._lsVuAttached = false;
      keepTileSilent(video);
    }
    meterTiles = meterTiles.filter(function (entry) {
      return entry.tile !== tile && entry.video !== video;
    });
  }

  function startTilePlayback(tile, tuning) {
    var src = tile.getAttribute('data-hls') || '';
    var video = tile.querySelector('video.ls-mv-video');
    if (!src || !video) return;
    if (tile.getAttribute('data-ls-started') === '1') return;

    video.hidden = false;
    video.crossOrigin = 'anonymous';
    keepTileSilent(video);
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');
    video.setAttribute('autoplay', '');

    var offline = tile.querySelector('.ls-mv-offline');
    var tally = tile.querySelector('.ls-mv-tally');
    if (offline) offline.hidden = true;
    if (tally) tally.hidden = false;

    tile.classList.add('is-live');
    tile.classList.remove('is-off');
    tile.setAttribute('data-live', '1');
    tile.setAttribute('data-ls-started', '1');
    tile.setAttribute('data-hls-playing', src);

    var fallback = tile.getAttribute('data-hls-fallback') || '';
    startPlayback(video, src, function () {
      attachVuMeters(tile, video);
    }, fallback, tuning);
  }

  function stopTilePlayback(tile) {
    var video = tile.querySelector('video.ls-mv-video');
    detachVuMeters(tile, video);
    stopPlayback(video);
    if (video) {
      video.hidden = true;
      keepTileSilent(video);
    }
    var offline = tile.querySelector('.ls-mv-offline');
    var tally = tile.querySelector('.ls-mv-tally');
    if (offline) offline.hidden = false;
    if (tally) tally.hidden = true;
    tile.classList.remove('is-live');
    tile.classList.add('is-off');
    tile.setAttribute('data-live', '0');
    tile.setAttribute('data-ls-started', '0');
    tile.setAttribute('data-hls', '');
    tile.setAttribute('data-hls-fallback', '');
    tile.removeAttribute('data-hls-playing');
  }

  function updateLiveCount(liveCount, total) {
    var el = document.getElementById('ls-mv-live-count');
    if (!el) return;
    var n = total != null ? total : document.querySelectorAll('#ls-mv-grid [data-ls-mv-tile]').length;
    el.textContent = liveCount + ' live · ' + n + ' source' + (n === 1 ? '' : 's');
  }

  function applyMultiviewStatus(payload) {
    var grid = document.getElementById('ls-mv-grid');
    if (!grid || !payload || !payload.ok) return;
    var tuning = playerTuning(document.querySelector('.ls-mv-page'));

    var bySlug = {};
    (payload.streams || []).forEach(function (s) {
      if (s && s.slug) bySlug[String(s.slug)] = s;
    });

    grid.querySelectorAll('[data-ls-mv-tile]').forEach(function (tile) {
      var slug = tile.getAttribute('data-slug') || '';
      var info = bySlug[slug];
      if (!info) return;

      var wantLive = !!info.live;
      var isLive = tile.getAttribute('data-live') === '1';
      var hls = info.hls || '';
      var fallback = info.hls_fallback || '';

      if (wantLive) {
        tile.setAttribute('data-hls', hls);
        tile.setAttribute('data-hls-fallback', fallback);
        var videoEl = tile.querySelector('video.ls-mv-video');
        var playing = tile.getAttribute('data-ls-started') === '1';
        var sameSrc = (tile.getAttribute('data-hls-playing') || '') === hls;
        var dead = playing && videoEl && !videoEl._lsHls;
        if (!hls) {
          // Live flag without playable URL yet (transcoder warming) — wait for next poll.
          return;
        }
        if (!isLive || !playing || dead) {
          // Coming online, recovered from failed start, or first boot while already live.
          if (dead) {
            tile.setAttribute('data-ls-started', '0');
          }
          startTilePlayback(tile, tuning);
        } else if (hls && !sameSrc) {
          // URL changed (e.g. h264 became ready) — restart quietly.
          stopPlayback(videoEl);
          tile.setAttribute('data-ls-started', '0');
          startTilePlayback(tile, tuning);
        }
      } else {
        // Offline: always tear down HLS, even if flags were already cleared.
        var video = tile.querySelector('video.ls-mv-video');
        if (isLive || tile.getAttribute('data-ls-started') === '1' || (video && video._lsHls)) {
          stopTilePlayback(tile);
        }
      }
    });

    updateLiveCount(payload.live_count || 0);
  }

  function pollJson(url, onData, pollMs) {
    if (!url) return;
    var busy = false;
    var interval = Math.max(1500, pollMs || 3000);
    var tick = function () {
      if (busy) return;
      busy = true;
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) { onData(j); })
        .catch(function () {})
        .finally(function () { busy = false; });
    };
    tick();
    return setInterval(tick, interval);
  }

  function bootMultiview() {
    var grid = document.getElementById('ls-mv-grid');
    if (!grid) return;

    var page = document.querySelector('.ls-mv-page');
    var statusUrl = page ? (page.getAttribute('data-ls-status-url') || '') : '';
    var tuning = playerTuning(page);

    var enableBtn = document.getElementById('ls-mv-enable-audio');
    if (isPhoneBrowser()) {
      // Phones stay muted — no Web Audio unlock / unmute path.
      if (enableBtn) enableBtn.hidden = true;
      var phoneHint = document.getElementById('ls-mv-audio-hint');
      if (phoneHint) phoneHint.hidden = true;
    } else if (enableBtn) {
      enableBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        unlockAudioGraph();
      });
    }

    // Desktop: first gesture resumes AudioContext for real VU meters.
    if (!isPhoneBrowser()) {
      var unlocked = false;
      var unlockOnce = function () {
        if (unlocked) return;
        unlocked = true;
        unlockAudioGraph();
        document.removeEventListener('pointerdown', unlockOnce, true);
        document.removeEventListener('keydown', unlockOnce, true);
      };
      document.addEventListener('pointerdown', unlockOnce, true);
      document.addEventListener('keydown', unlockOnce, true);
    }

    function startLiveTiles() {
      grid.querySelectorAll('[data-ls-mv-tile][data-live="1"]').forEach(function (tile) {
        var start = function () {
          startTilePlayback(tile, tuning);
        };
        if ('IntersectionObserver' in window) {
          var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting) {
                start();
                io.unobserve(tile);
              }
            });
          }, { rootMargin: '120px' });
          io.observe(tile);
        } else {
          start();
        }
      });
    }

    function run() {
      startLiveTiles();
      if (statusUrl) {
        pollJson(statusUrl, function (j) {
          applyMultiviewStatus(j);
        }, tuning.pollMs);
      }
    }

    if (!window.Hls && !(document.createElement('video').canPlayType('application/vnd.apple.mpegurl'))) {
      var tries = 0;
      var timer = setInterval(function () {
        tries += 1;
        if (window.Hls || tries > 40) {
          clearInterval(timer);
          run();
        }
      }, 50);
      return;
    }
    run();
  }

  function applyWatchStatus(root, payload) {
    if (!root || !payload || !payload.ok) return;
    var streams = payload.streams || [];
    var info = streams[0] || null;
    var video = document.getElementById('ls-player');
    var offline = document.getElementById('ls-offline');
    var badge = document.getElementById('ls-watch-badge');
    var badgeOff = document.getElementById('ls-watch-badge-off');
    if (!video) return;
    var tuning = playerTuning(root);

    var wantLive = !!(info && info.live);
    var isLive = root.getAttribute('data-live') === '1';
    var hls = info && info.hls ? info.hls : (root.getAttribute('data-hls') || '');
    var fallback = info && info.hls_fallback ? info.hls_fallback : (root.getAttribute('data-hls-fallback') || '');

    if (wantLive) {
      root.setAttribute('data-live', '1');
      root.setAttribute('data-hls', hls);
      root.setAttribute('data-hls-fallback', fallback);
      if (badge) badge.hidden = false;
      if (badgeOff) badgeOff.hidden = true;
      if (offline) offline.hidden = true;
      video.hidden = false;
      video.setAttribute('playsinline', '');
      video.setAttribute('autoplay', '');

      var playing = root.getAttribute('data-ls-started') === '1';
      var sameSrc = (root.getAttribute('data-hls-playing') || '') === hls;
      if (!playing || !sameSrc) {
        // Mute only when (re)starting for autoplay — never on every status poll.
        video.muted = true;
        root.setAttribute('data-ls-started', '1');
        root.setAttribute('data-hls-playing', hls);
        if (hls) {
          startPlayback(video, hls, null, fallback, tuning);
        }
      }
    } else if (isLive || root.getAttribute('data-ls-started') === '1') {
      root.setAttribute('data-live', '0');
      root.setAttribute('data-ls-started', '0');
      root.removeAttribute('data-hls-playing');
      stopPlayback(video);
      video.hidden = true;
      if (offline) offline.hidden = false;
      if (badge) badge.hidden = true;
      if (badgeOff) badgeOff.hidden = false;
    }
  }

  function bootWatch() {
    var root = document.querySelector('[data-ls-watch]');
    var video = document.getElementById('ls-player');
    if (!root || !video) {
      // Legacy boot: LIVESTREAM_HLS global from older watch template.
      var src = window.LIVESTREAM_HLS;
      if (video && src) {
        startPlayback(video, src);
      }
      return;
    }

    var statusUrl = root.getAttribute('data-ls-status-url') || '';
    var initiallyLive = root.getAttribute('data-live') === '1';
    var hls = root.getAttribute('data-hls') || '';
    var fallback = root.getAttribute('data-hls-fallback') || '';
    var tuning = playerTuning(root);

    function startIfLive() {
      if (!initiallyLive || !hls) return;
      video.hidden = false;
      video.muted = true;
      root.setAttribute('data-ls-started', '1');
      root.setAttribute('data-hls-playing', hls);
      startPlayback(video, hls, null, fallback, tuning);
    }

    function run() {
      startIfLive();
      if (statusUrl) {
        pollJson(statusUrl, function (j) {
          applyWatchStatus(root, j);
        }, tuning.pollMs);
      }
    }

    if (!window.Hls && !video.canPlayType('application/vnd.apple.mpegurl')) {
      var tries = 0;
      var timer = setInterval(function () {
        tries += 1;
        if (window.Hls || tries > 40) {
          clearInterval(timer);
          run();
        }
      }, 50);
      return;
    }
    run();
  }

  function bootPlayer() {
    // Watch page owns bootWatch; keep this for any leftover LIVESTREAM_HLS embeds.
    if (document.querySelector('[data-ls-watch]')) return;
    var video = document.getElementById('ls-player');
    var src = window.LIVESTREAM_HLS;
    if (!video || !src) return;
    startPlayback(video, src);
  }

  function boot() {
    bootPlayer();
    bootMultiview();
    bootWatch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

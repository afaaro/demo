(function () {
  'use strict';

  function csrf() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) return meta.content;
    var input = document.querySelector('input[name="_csrf_token"]');
    return input ? input.value : (window.MEDIA_CSRF || '');
  }

  function postForm(url, data) {
    var body = new URLSearchParams(data);
    body.set('_csrf_token', csrf());
    return fetch(url, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrf(),
      },
      body: body,
    }).then(function (r) { return r.json(); });
  }

  window.mediaSendReaction = function (videoId, type) {
    return postForm(window.MEDIA_URLS.reaction, {
      video_id: videoId,
      type: type,
    }).then(function (data) {
      if (!data.success) {
        alert(data.error || 'Could not save reaction.');
        return data;
      }
      var likes = document.getElementById('media-likes');
      var dislikes = document.getElementById('media-dislikes');
      if (likes) likes.textContent = data.likes_count;
      if (dislikes) dislikes.textContent = data.dislikes_count;
      document.querySelectorAll('[data-media-react]').forEach(function (btn) {
        btn.classList.toggle('is-on', btn.getAttribute('data-media-react') === data.user_reaction);
      });
      return data;
    });
  };

  function extractThumb(file) {
    return new Promise(function (resolve) {
      if (!file || !file.type.startsWith('video/')) {
        resolve(null);
        return;
      }
      var video = document.createElement('video');
      video.preload = 'metadata';
      video.muted = true;
      video.playsInline = true;
      video.src = URL.createObjectURL(file);
      video.onloadedmetadata = function () {
        video.currentTime = Math.min(3, video.duration / 2 || 0);
      };
      video.onseeked = function () {
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 360;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
          URL.revokeObjectURL(video.src);
          resolve(blob);
        }, 'image/jpeg', 0.85);
      };
      video.onerror = function () { resolve(null); };
    });
  }

  window.mediaUploadFile = async function (file, channelId) {
    if (!file) return;
    var bar = document.getElementById('media-progress');
    var fill = bar ? bar.querySelector('span') : null;
    var label = document.getElementById('media-progress-label');
    if (bar) bar.style.display = 'block';

    var thumb = await extractThumb(file);
    var chunkSize = 1024 * 1024; // 1MB — under typical 2M upload_max
    var total = Math.ceil(file.size / chunkSize) || 1;
    var uuid = (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : String(Date.now()) + '-' + Math.random().toString(16).slice(2);

    var isVideo = (file.type || '').indexOf('video/') === 0
      || /\.(mp4|webm|mkv|avi|mov|m4v)$/i.test(file.name || '');

    for (var i = 0; i < total; i++) {
      var slice = file.slice(i * chunkSize, Math.min(file.size, (i + 1) * chunkSize));
      var fd = new FormData();
      fd.append('file', slice);
      var url = window.MEDIA_URLS.chunk
        + '?channel_id=' + encodeURIComponent(channelId)
        + '&uuid=' + encodeURIComponent(uuid)
        + '&index=' + i
        + '&total=' + total
        + '&filename=' + encodeURIComponent(file.name);

      // Last chunk assembles (and may ffmpeg-compress) on the server.
      if (i === total - 1 && label) {
        label.textContent = isVideo ? 'Compressing…' : 'Finalizing…';
      }

      var res = await fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-Token': csrf() },
      });
      var data = await res.json();
      if (data.error) {
        alert(data.error);
        return;
      }
      var pct = Math.round(((i + 1) / total) * 100);
      if (fill) fill.style.width = pct + '%';
      if (label && i < total - 1) {
        label.textContent = 'Uploading… ' + pct + '%';
      }

      if (data.completed) {
        if (label) label.textContent = 'Saving…';
        var save = new FormData();
        save.append('channel_id', channelId);
        save.append('file_path', data.filepath);
        save.append('media_type', data.media_type || 'video');
        save.append('title', file.name.replace(/\.[^/.]+$/, ''));
        if (thumb) save.append('thumbnail', thumb, 'preview.jpg');
        var saved = await fetch(window.MEDIA_URLS.save, {
          method: 'POST',
          body: save,
          headers: { 'X-CSRF-Token': csrf() },
        }).then(function (r) { return r.json(); });
        if (saved.success) {
          window.location.href = window.MEDIA_URLS.studio;
        } else {
          alert(saved.error || 'Could not save media.');
        }
        return;
      }
    }
  };

  window.mediaSaveLink = function (channelId) {
    var title = document.getElementById('media-link-title');
    var url = document.getElementById('media-link-url');
    var desc = document.getElementById('media-link-desc');
    if (!url || !url.value.trim()) {
      alert('Paste a YouTube, Vimeo, or direct media URL.');
      return;
    }
    return postForm(window.MEDIA_URLS.link, {
      channel_id: channelId,
      title: title ? title.value : '',
      url: url.value,
      description: desc ? desc.value : '',
    }).then(function (data) {
      if (data.success) {
        window.location.href = window.MEDIA_URLS.studio;
      } else {
        alert(data.error || 'Could not add link.');
      }
    });
  };

  document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('[data-media-tab]');
    tabs.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var target = btn.getAttribute('data-media-tab');
        tabs.forEach(function (b) { b.classList.toggle('is-active', b === btn); });
        document.querySelectorAll('[data-media-panel]').forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-media-panel') !== target;
        });
      });
    });

    if (window.MEDIA_VIDEO_ID && window.MEDIA_URLS && window.MEDIA_URLS.view) {
      setTimeout(function () {
        fetch(window.MEDIA_URLS.view + '?id=' + encodeURIComponent(window.MEDIA_VIDEO_ID))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.success || !data.counted) return;
            var el = document.getElementById('media-views');
            if (!el) return;
            var n = parseInt(el.getAttribute('data-count') || '0', 10) + 1;
            el.setAttribute('data-count', String(n));
            el.textContent = n.toLocaleString();
          })
          .catch(function () {});
      }, 3000);
    }
  });
})();

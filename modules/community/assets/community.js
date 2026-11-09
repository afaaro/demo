(function () {
  var VIS_KEY = 'community.visibility';

  function postForm(url, data) {
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (key) {
      body.append(key, data[key]);
    });
    if (window.COMMUNITY_CSRF) {
      body.append('_csrf_token', window.COMMUNITY_CSRF);
    }
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': window.COMMUNITY_CSRF || '',
        Accept: 'application/json',
      },
      body: body.toString(),
      credentials: 'same-origin',
    }).then(function (res) {
      return res.json();
    });
  }

  function bindReactions() {
    document.querySelectorAll('[data-community-react]').forEach(function (btn) {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', function () {
        var postId = btn.getAttribute('data-community-react');
        if (!postId || !window.COMMUNITY_REACT_URL) return;
        btn.classList.add('is-busy');
        btn.disabled = true;
        postForm(window.COMMUNITY_REACT_URL, { post_id: postId })
          .then(function (data) {
            btn.disabled = false;
            btn.classList.remove('is-busy');
            if (!data || !data.success) {
              alert((data && data.error) || 'Could not update like.');
              return;
            }
            btn.classList.toggle('is-on', !!data.liked);
            var label = btn.querySelector('[data-like-label]');
            if (label) label.textContent = data.liked ? 'Liked' : 'Like';
            var count = btn.querySelector('[data-like-count]');
            if (count) count.textContent = String(data.count || 0);
          })
          .catch(function () {
            btn.disabled = false;
            btn.classList.remove('is-busy');
            alert('Could not update like.');
          });
      });
    });
  }

  function updateComposeReady(form) {
    var body = form.querySelector('[data-cm-body]');
    var photo = form.querySelector('[data-cm-photo]');
    var submit = form.querySelector('[data-cm-submit]');
    if (!submit) return;
    var hasText = !!(body && body.value.trim());
    var hasPhoto = !!(photo && photo.files && photo.files.length);
    submit.disabled = !(hasText || hasPhoto);
  }

  function bindCompose(form) {
    if (form.dataset.bound === '1') return;
    form.dataset.bound = '1';

    var body = form.querySelector('[data-cm-body]');
    var photo = form.querySelector('[data-cm-photo]');
    var preview = form.querySelector('[data-cm-preview]');
    var previewImg = form.querySelector('[data-cm-preview-img]');
    var clearBtn = form.querySelector('[data-cm-preview-clear]');
    var visInput = form.querySelector('[data-cm-visibility]');
    var visBtns = form.querySelectorAll('[data-cm-vis]');

    try {
      var saved = localStorage.getItem(VIS_KEY);
      if (saved && (saved === 'friends' || saved === 'public') && visInput) {
        visInput.value = saved;
        visBtns.forEach(function (btn) {
          btn.classList.toggle('is-on', btn.getAttribute('data-cm-vis') === saved);
        });
      }
    } catch (e) {}

    visBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var value = btn.getAttribute('data-cm-vis');
        if (!value || !visInput) return;
        visInput.value = value;
        visBtns.forEach(function (b) {
          b.classList.toggle('is-on', b === btn);
        });
        try {
          localStorage.setItem(VIS_KEY, value);
        } catch (e) {}
      });
    });

    if (body) {
      var resize = function () {
        body.style.height = 'auto';
        body.style.height = Math.min(280, Math.max(72, body.scrollHeight)) + 'px';
      };
      body.addEventListener('input', function () {
        resize();
        updateComposeReady(form);
      });
      resize();
    }

    if (photo) {
      photo.addEventListener('change', function () {
        var file = photo.files && photo.files[0];
        if (!file || !preview || !previewImg) {
          updateComposeReady(form);
          return;
        }
        if (!file.type || file.type.indexOf('image/') !== 0) {
          photo.value = '';
          updateComposeReady(form);
          return;
        }
        var url = URL.createObjectURL(file);
        previewImg.onload = function () {
          URL.revokeObjectURL(url);
        };
        previewImg.src = url;
        preview.hidden = false;
        updateComposeReady(form);
      });
    }

    if (clearBtn && photo && preview) {
      clearBtn.addEventListener('click', function () {
        photo.value = '';
        preview.hidden = true;
        if (previewImg) previewImg.removeAttribute('src');
        updateComposeReady(form);
      });
    }

    form.addEventListener('submit', function () {
      var submit = form.querySelector('[data-cm-submit]');
      if (submit) {
        submit.disabled = true;
        submit.textContent = 'Posting…';
      }
    });

    updateComposeReady(form);
  }

  function bindNotifications() {
    if (!window.COMMUNITY_NOTIF_READ_URL) return;
    document.querySelectorAll('[data-cm-notif-link]').forEach(function (link) {
      if (link.dataset.bound === '1') return;
      link.dataset.bound = '1';
      link.addEventListener('click', function () {
        if (link.getAttribute('data-is-unread') !== '1') return;
        var id = link.getAttribute('data-notif-id');
        if (!id) return;
        postForm(window.COMMUNITY_NOTIF_READ_URL, { id: id }).catch(function () {});
      });
    });
  }

  function boot() {
    bindReactions();
    document.querySelectorAll('[data-cm-compose]').forEach(bindCompose);
    bindNotifications();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

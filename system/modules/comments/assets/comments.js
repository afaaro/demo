(function () {
  function postForm(url, data) {
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (key) {
      body.append(key, data[key]);
    });
    if (window.COMMENTS_CSRF) {
      body.append('_csrf_token', window.COMMENTS_CSRF);
    }
    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': window.COMMENTS_CSRF || '',
      },
      body: body.toString(),
      credentials: 'same-origin',
    }).then(function (res) {
      return res.json();
    });
  }

  function bindWidget(root) {
    if (!root || root.dataset.bound === '1') return;
    root.dataset.bound = '1';

    var btn = root.querySelector('.site-comments__post');
    var input = root.querySelector('.site-comments__input');
    var list = root.querySelector('.site-comments__list');
    if (!btn || !input || !list) return;

    btn.addEventListener('click', function () {
      var text = input.value.trim();
      if (!text) return;
      btn.disabled = true;

      postForm((window.COMMENTS_URLS && window.COMMENTS_URLS.post) || '', {
        module: root.dataset.module || '',
        entity_type: root.dataset.entityType || '',
        entity_id: root.dataset.entityId || '',
        body: text,
      })
        .then(function (data) {
          btn.disabled = false;
          if (!data || !data.success) {
            alert((data && data.error) || 'Could not post comment.');
            return;
          }
          var empty = list.querySelector('.site-comments__empty');
          if (empty) empty.remove();
          var el = document.createElement('article');
          el.className = 'site-comments__item';
          el.innerHTML = '<header><strong></strong><time></time></header><p></p>';
          el.querySelector('strong').textContent = data.username || 'User';
          el.querySelector('time').textContent = data.when || 'Just now';
          el.querySelector('p').textContent = data.body || text;
          list.insertBefore(el, list.firstChild);
          input.value = '';
        })
        .catch(function () {
          btn.disabled = false;
          alert('Could not post comment.');
        });
    });
  }

  function boot() {
    document.querySelectorAll('.site-comments').forEach(bindWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

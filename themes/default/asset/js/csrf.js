(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? (meta.getAttribute('content') || '') : '';
    if (!token) {
        return;
    }

    function isMutating(method) {
        method = String(method || 'GET').toUpperCase();
        return method === 'POST' || method === 'PUT' || method === 'PATCH' || method === 'DELETE';
    }

    function sameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function headerName(name) {
        return String(name || '').toLowerCase();
    }

    function isCsrfHeader(name) {
        var n = headerName(name);
        return n === 'x-csrf-token' || n === 'x-xsrf-token' || n.indexOf('x-csrf-token-') === 0;
    }

    function hasCsrfHeader(headers) {
        if (!headers) {
            return false;
        }
        if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            var found = false;
            headers.forEach(function (_value, name) {
                if (isCsrfHeader(name)) {
                    found = true;
                }
            });
            return found;
        }
        if (Array.isArray(headers)) {
            return headers.some(function (pair) {
                return isCsrfHeader(pair && pair[0]);
            });
        }
        return Object.keys(headers).some(isCsrfHeader);
    }

    function addHeader(headers, name, value) {
        if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            headers.set(name, value);
            return headers;
        }
        if (Array.isArray(headers)) {
            headers.push([name, value]);
            return headers;
        }
        var next = headers ? Object.assign({}, headers) : {};
        next[name] = value;
        return next;
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }
        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        if (!isMutating(method) || form.querySelector('input[name="_csrf_token"]')) {
            return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_csrf_token';
        input.value = token;
        form.appendChild(input);
    }, true);

    if (typeof window.fetch === 'function') {
        var originalFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init ? Object.assign({}, init) : {};
            var request = (typeof Request !== 'undefined' && input instanceof Request) ? input : null;
            var url = request ? request.url : String(input || '');
            var method = init.method || (request && request.method) || 'GET';
            var headers = init.headers;
            if (!headers && request) {
                headers = new Headers(request.headers);
            }
            if (isMutating(method) && sameOrigin(url || window.location.href) && !hasCsrfHeader(headers)) {
                init.headers = addHeader(headers || {}, 'X-CSRF-Token', token);
            }
            return originalFetch.call(this, input, init);
        };
    }

    var originalOpen = XMLHttpRequest.prototype.open;
    var originalSetHeader = XMLHttpRequest.prototype.setRequestHeader;
    var originalSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url) {
        this.__sfCsrf = { method: method, url: url, has: false };
        return originalOpen.apply(this, arguments);
    };

    XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
        if (this.__sfCsrf && isCsrfHeader(name)) {
            this.__sfCsrf.has = true;
        }
        return originalSetHeader.apply(this, arguments);
    };

    XMLHttpRequest.prototype.send = function () {
        var info = this.__sfCsrf;
        if (info && isMutating(info.method) && sameOrigin(info.url || window.location.href) && !info.has) {
            try {
                originalSetHeader.call(this, 'X-CSRF-Token', token);
            } catch (e) {}
        }
        return originalSend.apply(this, arguments);
    };

    if (window.jQuery && typeof window.jQuery.ajaxPrefilter === 'function') {
        window.jQuery.ajaxPrefilter(function (options, _original, xhr) {
            var method = options.type || options.method || 'GET';
            var url = options.url || window.location.href;
            if (!isMutating(method) || !sameOrigin(url)) {
                return;
            }
            xhr.setRequestHeader('X-CSRF-Token', token);
        });
    }
})();

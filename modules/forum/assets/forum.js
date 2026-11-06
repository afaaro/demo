(function () {
    function replyBox() {
        return document.querySelector('.xf-reply__form textarea[name="content"]');
    }

    function insertQuote(text) {
        var box = replyBox();
        if (!box || !text) {
            return false;
        }
        var current = box.value.replace(/\s+$/, '');
        box.value = current ? current + '\n\n' + text : text;
        box.focus();
        var end = box.value.length;
        try {
            box.setSelectionRange(end, end);
        } catch (e) {}
        var reply = document.getElementById('reply');
        if (reply && typeof reply.scrollIntoView === 'function') {
            reply.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return true;
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-quote]');
        if (!link) {
            return;
        }
        var quoted = link.getAttribute('data-quote') || '';
        if (quoted && insertQuote(quoted)) {
            event.preventDefault();
        }
    });
})();

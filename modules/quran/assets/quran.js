(function () {
    var root = document.querySelector('[data-quran-root]');
    if (!root) {
        return;
    }

    var dataNode = document.getElementById('quran-player-data');
    var data = { ayahs: [], reciters: [], highlight: 0 };
    if (dataNode) {
        try { data = JSON.parse(dataNode.textContent || '{}'); } catch (e) { data = { ayahs: [], reciters: [] }; }
    }

    var storage = {
        get: function (key, fallback) {
            try {
                var raw = localStorage.getItem(key);
                return raw === null ? fallback : JSON.parse(raw);
            } catch (e) {
                return fallback;
            }
        },
        set: function (key, value) {
            try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) {}
        }
    };

    function absUrl(route) {
        var base = document.querySelector('base');
        var rootUrl = base ? base.href : (window.location.origin + '/');
        return rootUrl.replace(/\/?$/, '/') + route.replace(/^\//, '');
    }

    var suraSelect = root.querySelector('[data-quran-sura]');
    var ayaSelect = root.querySelector('[data-quran-aya]');
    var juzSelect = root.querySelector('[data-quran-juz]');
    var pageSelect = root.querySelector('[data-quran-page]');

    if (suraSelect) {
        suraSelect.addEventListener('change', function () {
            window.location.href = absUrl('quran/' + suraSelect.value);
        });
    }
    if (ayaSelect) {
        ayaSelect.addEventListener('change', function () {
            var sura = suraSelect ? suraSelect.value : '1';
            var aya = ayaSelect.value;
            window.location.href = aya === '0'
                ? absUrl('quran/' + sura)
                : absUrl('quran/' + sura + '/' + aya);
        });
    }
    if (juzSelect) {
        juzSelect.addEventListener('change', function () {
            window.location.href = absUrl('quran/juz/' + juzSelect.value);
        });
    }
    if (pageSelect) {
        pageSelect.addEventListener('change', function () {
            window.location.href = absUrl('quran/page/' + pageSelect.value);
        });
    }

    var showTrans = storage.get('quran.showTranslation', true);
    var transIds = storage.get('quran.translationIds', null);
    var fontSize = storage.get('quran.fontSize', 32);
    var reciterId = storage.get('quran.reciterId', data.reciters[0] ? data.reciters[0].id : 0);
    var repeat = storage.get('quran.repeat', 'off');
    var speed = storage.get('quran.speed', 1);

    var toggle = root.querySelector('[data-quran-toggle-trans]');
    var font = root.querySelector('[data-quran-font]');
    var reciterSelect = root.querySelector('[data-quran-reciter]');
    var repeatSelect = root.querySelector('[data-quran-repeat]');
    var speedSelect = root.querySelector('[data-quran-speed]');
    var now = root.querySelector('[data-quran-now]');
    var playBtn = root.querySelector('[data-quran-play]');

    function applyTranslationVisibility() {
        root.classList.toggle('hide-translations', !showTrans);
        var boxes = root.querySelectorAll('[data-quran-trans-id]');
        var selected = [];
        boxes.forEach(function (box) {
            if (transIds === null) {
                box.checked = true;
            } else {
                box.checked = transIds.indexOf(parseInt(box.value, 10)) !== -1;
            }
            if (box.checked) {
                selected.push(parseInt(box.value, 10));
            }
        });
        root.querySelectorAll('.quran-tr').forEach(function (el) {
            var id = parseInt(el.getAttribute('data-translation-id'), 10);
            el.classList.toggle('is-hidden', selected.indexOf(id) === -1);
        });
        storage.set('quran.translationIds', selected);
    }

    if (toggle) {
        toggle.checked = !!showTrans;
        toggle.addEventListener('change', function () {
            showTrans = toggle.checked;
            storage.set('quran.showTranslation', showTrans);
            applyTranslationVisibility();
        });
    }

    root.querySelectorAll('[data-quran-trans-id]').forEach(function (box) {
        box.addEventListener('change', function () {
            transIds = [];
            root.querySelectorAll('[data-quran-trans-id]').forEach(function (item) {
                if (item.checked) {
                    transIds.push(parseInt(item.value, 10));
                }
            });
            storage.set('quran.translationIds', transIds);
            applyTranslationVisibility();
        });
    });

    if (font) {
        font.value = String(fontSize);
        root.style.setProperty('--quran-arabic-size', fontSize + 'px');
        font.addEventListener('input', function () {
            fontSize = parseInt(font.value, 10);
            root.style.setProperty('--quran-arabic-size', fontSize + 'px');
            storage.set('quran.fontSize', fontSize);
        });
    }

    if (reciterSelect) {
        reciterSelect.value = String(reciterId);
        reciterSelect.addEventListener('change', function () {
            reciterId = parseInt(reciterSelect.value, 10);
            storage.set('quran.reciterId', reciterId);
        });
    }
    if (repeatSelect) {
        repeatSelect.value = repeat;
        repeatSelect.addEventListener('change', function () {
            repeat = repeatSelect.value;
            storage.set('quran.repeat', repeat);
        });
    }
    if (speedSelect) {
        speedSelect.value = String(speed);
        speedSelect.addEventListener('change', function () {
            speed = parseFloat(speedSelect.value);
            storage.set('quran.speed', speed);
            if (audio) {
                audio.playbackRate = speed;
            }
        });
    }

    applyTranslationVisibility();

    var currentIndex = 0;
    if (data.highlight) {
        data.ayahs.forEach(function (item, index) {
            if (item.ayah === data.highlight) {
                currentIndex = index;
            }
        });
    }

    var highlighted = root.querySelector('.quran-ayah.is-current');
    if (highlighted) {
        highlighted.scrollIntoView({ block: 'center' });
    }

    var audio = null;
    var playing = false;

    function reciterById(id) {
        for (var i = 0; i < data.reciters.length; i++) {
            if (data.reciters[i].id === id) {
                return data.reciters[i];
            }
        }
        return data.reciters[0] || null;
    }

    function pad3(n) {
        return String(n).padStart(3, '0');
    }

    function audioUrl(ayah) {
        var reciter = reciterById(reciterId);
        if (!reciter) {
            return '';
        }
        return reciter.pattern
            .replace('{surah:03d}', pad3(ayah.surah))
            .replace('{ayah:03d}', pad3(ayah.ayah));
    }

    function markPlaying(index) {
        root.querySelectorAll('.quran-ayah.is-playing').forEach(function (el) {
            el.classList.remove('is-playing');
        });
        var ayah = data.ayahs[index];
        if (!ayah) {
            return;
        }
        var el = root.querySelector('[data-ayah-id="' + ayah.id + '"]');
        if (el) {
            el.classList.add('is-playing');
            el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        if (now) {
            now.textContent = ayah.surah + ':' + ayah.ayah;
        }
    }

    function setPlayLabel() {
        if (playBtn) {
            playBtn.textContent = playing ? 'Pause' : 'Play';
        }
    }

    function playIndex(index) {
        if (!data.ayahs.length) {
            return;
        }
        currentIndex = (index + data.ayahs.length) % data.ayahs.length;
        var ayah = data.ayahs[currentIndex];
        var src = audioUrl(ayah);
        if (!src) {
            return;
        }
        if (!audio) {
            audio = new Audio();
            audio.addEventListener('ended', onEnded);
        }
        audio.src = src;
        audio.playbackRate = speed;
        audio.play().then(function () {
            playing = true;
            setPlayLabel();
            markPlaying(currentIndex);
        }).catch(function () {
            playing = false;
            setPlayLabel();
            if (now) {
                now.textContent = 'Audio could not start';
            }
        });
    }

    function onEnded() {
        if (repeat === 'ayah') {
            playIndex(currentIndex);
            return;
        }
        if (repeat === 'range' || currentIndex < data.ayahs.length - 1) {
            playIndex(currentIndex + 1);
            return;
        }
        playing = false;
        setPlayLabel();
    }

    if (playBtn) {
        playBtn.addEventListener('click', function () {
            if (playing && audio) {
                audio.pause();
                playing = false;
                setPlayLabel();
                return;
            }
            if (audio && audio.src && audio.paused) {
                audio.play();
                playing = true;
                setPlayLabel();
                return;
            }
            playIndex(currentIndex);
        });
    }

    var prevBtn = root.querySelector('[data-quran-prev]');
    var nextBtn = root.querySelector('[data-quran-next]');
    if (prevBtn) {
        prevBtn.addEventListener('click', function () { playIndex(currentIndex - 1); });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function () { playIndex(currentIndex + 1); });
    }

    root.querySelectorAll('.quran-ayah').forEach(function (el, index) {
        el.addEventListener('dblclick', function () {
            playIndex(index);
        });
    });
})();

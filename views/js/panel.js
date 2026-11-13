/**
 * Quince Insight - warstwa panelu.
 *
 * Czesc 1 i 2 to wlasna kopia skryptu z Quince CMP (toasty z komunikatow PrestaShopa,
 * pomiar przyklejonych paskow, nawigacja na telefonie, potwierdzenia) - modul ma dzialac
 * w sklepie, w ktorym innych modulow Quince nie ma.
 *
 * Czesc 3 jest wlasna: globalny obiekt `QI` dla ekranow modulu -
 * `QI.post`, `QI.toast`, `QI.copy`, `QI.job`, `QI.jobStart`, `QI.icon`.
 *
 * Plik laduje sie przed Alpine (oba z `defer`), wiec `QI.job` jest gotowy, zanim
 * Alpine zacznie czytac atrybuty `x-data`.
 */
(function () {
    'use strict';

    var TYPES = {
        'alert-success': 'success',
        'alert-danger':  'danger',
        'alert-warning': 'warning',
        'alert-info':    'info'
    };

    var ICONS = {
        success: 'M5.3 8.2l1.8 1.8 3.6-4.4M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
        danger:  'M8 5v4m0 3h.01M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
        warning: 'M8 6v3m0 2.5h.01M8.9 2.6l6 10.4a1 1 0 01-.9 1.5H2a1 1 0 01-.9-1.5l6-10.4a1 1 0 011.8 0z',
        info:    'M8 7v4m0-6.5h.01M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z'
    };

    function stack() {
        var el = document.querySelector('.qc-toasts');
        if (!el) {
            el = document.createElement('div');
            el.className = 'qc-toasts';
            document.body.appendChild(el);
        }
        return el;
    }

    /**
     * Przepisuje tresc alertu PrestaShopa, zachowujac jej strukture.
     * Komunikaty bywaja wielopunktowe (lista bledow walidacji) albo zawieraja odnosnik
     * do miejsca, w ktorym trzeba cos poprawic - splaszczenie do jednego zdania gubi
     * jedno i drugie. Przepuszczamy wylacznie znaczniki z listy: alert idzie do nas
     * jako gotowy HTML, wiec wstawienie go bez filtra byloby otwarciem na wstrzykniecie.
     */
    var ALLOWED = {
        A: ['href', 'target', 'rel'],
        STRONG: [], B: [], EM: [], I: [], U: [], SMALL: [], CODE: [], BR: [],
        UL: [], OL: [], LI: [], P: [], SPAN: [], DIV: []
    };

    function sanitize(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (child) {
            if (child.nodeType === Node.TEXT_NODE) {
                return;
            }
            if (child.nodeType !== Node.ELEMENT_NODE) {
                child.remove();
                return;
            }

            var allowed = ALLOWED[child.tagName];
            if (!allowed) {
                // Znacznik odpada, ale jego tresc zostaje - inaczej zniknalby caly komunikat
                // opakowany w nieznany kontener.
                while (child.firstChild) {
                    node.insertBefore(child.firstChild, child);
                }
                child.remove();
                return;
            }

            Array.prototype.slice.call(child.attributes).forEach(function (attr) {
                if (allowed.indexOf(attr.name) === -1) {
                    child.removeAttribute(attr.name);
                }
            });

            // Odnosniki tylko na zwykle adresy - javascript: i data: wypadaja.
            var href = child.getAttribute && child.getAttribute('href');
            if (href && !/^(https?:|\/|#|mailto:)/i.test(href.trim())) {
                child.removeAttribute('href');
            }

            sanitize(child);
        });

        return node;
    }

    /**
     * @param {string|Node} message tekst albo gotowy wezel z trescia komunikatu
     */
    function toast(message, type) {
        var el = document.createElement('div');
        el.className = 'qc-toast qc-toast--' + type;
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        el.innerHTML =
            '<svg class="qc-toast-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" ' +
            'stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="' + ICONS[type] + '"/></svg>' +
            '<span class="qc-toast-text"></span>' +
            '<button type="button" class="qc-toast-close" aria-label="Close">&times;</button>';
        var body = el.querySelector('.qc-toast-text');
        if (typeof message === 'string') {
            body.textContent = message;
        } else {
            body.appendChild(message);
        }

        var close = function () {
            el.classList.add('is-leaving');
            setTimeout(function () { el.remove(); }, 200);
        };
        el.querySelector('.qc-toast-close').addEventListener('click', close);

        stack().appendChild(el);
        requestAnimationFrame(function () { el.classList.add('is-visible'); });

        // Bledy zostaja - uzytkownik musi je przeczytac i zwykle cos poprawic.
        if (type !== 'danger') {
            setTimeout(close, 4500);
        }
    }

    /**
     * Mierzy, jak wysoko siegaja przyklejone elementy PrestaShopa, i zapisuje wynik
     * w --qc-top-offset. Sztywna liczba nie wystarcza: na gorze siedzi czarny pasek,
     * breadcrumb i tytul strony, a ich laczna wysokosc zmienia sie z dlugoscia tytulu
     * i szerokoscia okna.
     */
    function syncTopOffset() {
        var root = document.querySelector('.qc-root');
        if (!root) {
            return;
        }

        var bottom = 0;
        document.querySelectorAll('body *').forEach(function (el) {
            if (el.closest('.qc-root')) {
                return;                        // nasze wlasne elementy nie licza sie
            }
            var pos = getComputedStyle(el).position;
            if (pos !== 'fixed' && pos !== 'sticky') {
                return;
            }
            var r = el.getBoundingClientRect();

            // Paski ukladaja sie pietrowo: czarny z wyszukiwarka stoi na top: 0,
            // bialy z tytulem strony dopiero pod nim. Warunek `top <= 4` wylapywal
            // tylko pierwszy z nich, przez co offset byl o wysokosc naglowka za maly.
            // Bierzemy najnizsza krawedz wszystkiego, co trzyma sie gornej czesci ekranu.
            if (r.height > 0 && r.top >= -2 && r.top < 240
                && r.bottom > bottom && r.bottom < window.innerHeight / 2) {
                bottom = r.bottom;
            }
        });

        document.documentElement.style.setProperty('--qc-top-offset', Math.round(bottom) + 'px');
    }

    /**
     * Mierzy, co przykleja sie do DOLU ekranu - w praktyce pasek debugowania Symfony,
     * ktory w trybie deweloperskim zaslanialby pasek akcji. Ma inna wysokosc na
     * desktopie i na telefonie, a na produkcji nie ma go wcale.
     *
     * Bez paska zostaje sam przeswit, wiec akcje nie wisza wysoko nad krawedzia.
     */
    function syncBottomOffset() {
        var GAP = 14;
        var height = 0;

        document.querySelectorAll('body *').forEach(function (el) {
            if (el.closest('.qc-root') || el.closest('.qc-toasts')) {
                return;                        // nasze elementy sie nie licza
            }
            var pos = getComputedStyle(el).position;
            if (pos !== 'fixed' && pos !== 'sticky') {
                return;
            }
            var r = el.getBoundingClientRect();
            // Przyklejone do dolnej krawedzi, o sensownej wysokosci i szerokosci
            if (r.height > 0 && r.height < 200
                && Math.abs(r.bottom - window.innerHeight) < 4
                && r.width > window.innerWidth * 0.5
                && r.height > height) {
                height = r.height;
            }
        });

        document.documentElement.style.setProperty('--qc-bottom-offset', Math.round(height + GAP) + 'px');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Dzialamy tylko na stronach Quince, zeby nie zmieniac zachowania reszty panelu.
        if (!document.querySelector('.qc-root')) {
            return;
        }

        var sync = function () { syncTopOffset(); syncBottomOffset(); };

        sync();
        window.addEventListener('resize', sync);
        // Paski doklejaja sie dopiero po przewinieciu - mierzymy ponownie.
        window.addEventListener('scroll', sync, { passive: true });
        // Pasek debugowania Symfony montuje sie asynchronicznie, juz po DOMContentLoaded.
        setTimeout(sync, 400);
        setTimeout(sync, 1500);

        document.querySelectorAll('.alert').forEach(function (alert) {
            if (alert.closest('.qc-root')) {
                return;                       // nasze wlasne alerty zostawiamy w spokoju
            }

            var type = null;
            Object.keys(TYPES).forEach(function (cls) {
                if (alert.classList.contains(cls)) {
                    type = TYPES[cls];
                }
            });
            if (!type) {
                return;
            }

            // Bierzemy cala tresc alertu - z listami bledow i odnosnikami - odrzucajac
            // wylacznie sterowanie samego alertu (przycisk zamkniecia, ikony materialowe).
            var clone = alert.cloneNode(true);
            clone.querySelectorAll('button, .close, svg, .material-icons').forEach(function (n) { n.remove(); });

            var content = document.createDocumentFragment();
            var source = clone.querySelector('.alert-text') || clone;
            while (source.firstChild) {
                content.appendChild(source.firstChild);
            }
            sanitize(content);

            if (content.textContent.trim()) {
                toast(content, type);
                alert.remove();
            }
        });
    });

    window.quinceToast = toast;
})();

/**
 * Nawigacja na telefonie.
 *
 * Ponizej 767 px lista pozycji jest schowana, a nad trescia stoi przycisk
 * z nazwa biezacej strony. Obsluga siedzi tutaj, a nie w Alpine, bo Alpine
 * laduja poszczegolne widoki, a nawigacja ma dzialac na kazdym z nich.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.qc-nav-mobile');
        var shell = document.querySelector('.qc-shell');

        if (!toggle || !shell) {
            return;
        }

        toggle.addEventListener('click', function () {
            var open = shell.classList.toggle('qc-nav-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Wybor pozycji przenosi na inna strone, ale gdy ktos dotknie pozycji
        // zaplanowanej albo biezacej, nic sie nie dzieje i lista zostaje otwarta.
        // Zamkniecie po dotknieciu poza nia jest wtedy jedynym wyjsciem.
        document.addEventListener('click', function (event) {
            if (!shell.classList.contains('qc-nav-open')) {
                return;
            }

            if (!event.target.closest('#qc-sidenav') && !event.target.closest('.qc-nav-mobile')) {
                shell.classList.remove('qc-nav-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    });

    /*
     * Potwierdzenie przed operacja, ktorej nie da sie cofnac.
     *
     * Natywne `confirm` zamiast wlasnego okna: blokuje wyslanie formularza bez
     * przechwytywania zdarzenia, wiec przycisk dziala tak samo, gdy skrypt sie
     * nie wczyta. Operacja bez potwierdzenia jest gorsza niz brzydkie okno.
     */
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-qc-confirm]');

        if (el && !window.confirm(el.getAttribute('data-qc-confirm'))) {
            event.preventDefault();
        }
    });
})();

/**
 * Czesc 3: globalny obiekt `QI` dla ekranow Quince Insight.
 *
 *   QI.post(url, data, opts)  -> Promise<json>   (POST JSON + token CSRF; {ok:false} = toast z bledem)
 *   QI.toast(message, kind)                      (kind: success | danger | error | warning | info)
 *   QI.copy(text)             -> Promise<bool>   (kopiowanie takze w panelu po HTTP)
 *   QI.job(config)                               (komponent Alpine paska postepu zadania)
 *   QI.jobStart(job)                             (przekazuje nowe zadanie komponentom QI.job)
 *   QI.icon(name, size, cls)  -> string SVG      (ten sam zestaw co makro ikon)
 *
 * Teksty komunikatow przychodza z layoutu (`data-qi-i18n` na `.qc-root`), juz
 * przetlumaczone — w JS nie ma tlumaczen.
 */
window.QI = (function () {
    'use strict';

    // Kopia zestawu z `_macros/icons.html.twig` i `Ui\Icons` — przy dodaniu ikony dopisac we wszystkich trzech.
    var PATHS = {
        chevronDown: 'M4 6l4 4 4-4',
        chevronRight: 'M6 4l4 4-4 4',
        drag: 'M5 4h.01M5 8h.01M5 12h.01M11 4h.01M11 8h.01M11 12h.01',
        trash: 'M3 4h10M6 4V3a1 1 0 011-1h2a1 1 0 011 1v1m1 0-.6 9a1 1 0 01-1 .9H5.6a1 1 0 01-1-.9L4 4',
        plus: 'M8 3v10M3 8h10',
        x: 'M4 4l8 8M12 4l-8 8',
        check: 'M3.5 8.5l3 3 6-7',
        search: 'M11 11l3.2 3.2M12.5 7.3a5.2 5.2 0 11-10.4 0 5.2 5.2 0 0110.4 0z',
        alertCircle: 'M8 5v4m0 3h.01M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
        alertTriangle: 'M8 2.6 1.6 13.4h12.8L8 2.6zM8 6.7v3M8 11.4h.01',
        info: 'M8 7v4m0-6.5h.01M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
        refresh: 'M13.6 7.2A5.6 5.6 0 103 9.9M13.5 3v4.2h-4.2',
        checkCircle: 'M5.3 8.2l1.8 1.8 3.6-4.4M14.5 8A6.5 6.5 0 111.5 8a6.5 6.5 0 0113 0z',
        image: 'M2.5 3.5h11a1 1 0 011 1v7a1 1 0 01-1 1h-11a1 1 0 01-1-1v-7a1 1 0 011-1zM5 7a1 1 0 100-2 1 1 0 000 2zm-3.3 5.2 4-4.4 2.6 2.6L11 7l3.4 5.9',
        layout: 'M2 3h12v10H2V3zm0 3.6h12M6 6.6v6.4',
        box: 'M8 2 2.3 4.8 8 7.6l5.7-2.8L8 2zM2.3 4.8v6.4L8 14v-6.4M13.7 4.8v6.4L8 14',
        puzzle: 'M6 2.5h2v1.7a1.2 1.2 0 102.4 0V2.5H12v2.6h1.7a1.2 1.2 0 010 2.4H12v2h1.7a1.2 1.2 0 010 2.4H12v1.6H9.4a1.2 1.2 0 10-2.4 0H5v-1.6H3.3a1.2 1.2 0 010-2.4H5v-2H3.3a1.2 1.2 0 010-2.4H5V2.5h1z',
        settings: 'M8 10.2a2.2 2.2 0 100-4.4 2.2 2.2 0 000 4.4zM12.9 8a4.9 4.9 0 01-.06.75l1.4 1.1-1.4 2.4-1.6-.5c-.4.35-.86.63-1.36.83L9.5 14h-3l-.38-1.42a4.9 4.9 0 01-1.36-.83l-1.6.5-1.4-2.4 1.4-1.1A5 5 0 013.1 8c0-.25.02-.5.06-.75l-1.4-1.1 1.4-2.4 1.6.5c.4-.35.86-.63 1.36-.83L6.5 2h3l.38 1.42c.5.2.96.48 1.36.83l1.6-.5 1.4 2.4-1.4 1.1c.04.25.06.5.06.75z',
        edit: 'M11 2l3 3-8 8-3.5 1L3 11l8-9z',
        eye: 'M1.5 8S4 3.5 8 3.5 14.5 8 14.5 8 12 12.5 8 12.5 1.5 8 1.5 8zM8 9.7A1.7 1.7 0 108 6.3a1.7 1.7 0 000 3.4z',
        upload: 'M8 11V3m0 0L5 6m3-3l3 3M2.5 11.5v1a1 1 0 001 1h9a1 1 0 001-1v-1',
        download: 'M8 3v8m0 0 3-3m-3 3-3-3M2.5 11.5v1a1 1 0 001 1h9a1 1 0 001-1v-1',
        folder: 'M1.5 4.5a1 1 0 011-1h3l1.5 2h6.5a1 1 0 011 1v5a1 1 0 01-1 1h-11a1 1 0 01-1-1v-7z',
        link: 'M6.5 9.5a2.5 2.5 0 003.5 0l2-2a2.5 2.5 0 10-3.5-3.5l-.6.6M9.5 6.5a2.5 2.5 0 00-3.5 0l-2 2a2.5 2.5 0 103.5 3.5l.6-.6',
        cookie: 'M14.4 8.3A6.5 6.5 0 118 1.5a2.7 2.7 0 003.3 3.1 2.7 2.7 0 003.1 3.7zM5.8 6.4h.01M9.4 9.6h.01M5.9 11.2h.01',
        shield: 'M8 1.8 3 3.6v4.1c0 3 2.1 5.4 5 6.5 2.9-1.1 5-3.5 5-6.5V3.6L8 1.8zM5.8 8l1.6 1.6L10.4 6',
        user: 'M8 8.2a2.7 2.7 0 100-5.4 2.7 2.7 0 000 5.4zM2.8 14c0-2.5 2.3-4.2 5.2-4.2s5.2 1.7 5.2 4.2'
    };

    function root() {
        return document.querySelector('.qc-root');
    }

    function csrf() {
        var el = root();

        return el ? (el.getAttribute('data-qi-csrf') || '') : '';
    }

    function text(key, fallback) {
        var el = root();
        var map = {};

        try {
            map = JSON.parse((el && el.getAttribute('data-qi-i18n')) || '{}');
        } catch (e) {
            map = {};
        }

        return map[key] || fallback;
    }

    function toast(message, kind) {
        var type = kind === 'error' ? 'danger' : (kind || 'info');

        if (['success', 'danger', 'warning', 'info'].indexOf(type) === -1) {
            type = 'info';
        }

        if (typeof window.quinceToast === 'function' && message) {
            window.quinceToast(String(message), type);
        }
    }

    /**
     * POST do trasy panelu. Adres z `path()` ma juz `?_token=` — nie doklejac do niego
     * parametrow recznie (drugi `?` psuje token); dane ida w tresci jako JSON.
     *
     * Odpowiedz zawsze jest obiektem z `ok`: blad sieci, 403 z ekranu bledu PrestaShop
     * albo strona HTML zamiast JSON zamieniaja sie w {ok:false, error}. Bez `opts.quiet`
     * blad pokazuje sie jako toast.
     */
    function post(url, data, opts) {
        var options = opts || {};

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf()
            },
            body: JSON.stringify(data || {})
        }).then(function (response) {
            return response.text().then(function (body) {
                var json = null;

                try {
                    json = JSON.parse(body);
                } catch (e) {
                    json = null;
                }

                if (!json || typeof json !== 'object') {
                    json = {
                        ok: false,
                        error: response.status === 403
                            ? text('denied', 'You do not have permission to do this, or the page has expired. Reload the page and try again.')
                            : text('failed', 'Something went wrong. Reload the page and try again.')
                    };
                }

                if (typeof json.ok === 'undefined') {
                    json.ok = response.ok;
                }

                json.httpStatus = response.status;

                if (!json.ok && !options.quiet) {
                    toast(json.error || text('failed', 'Something went wrong. Reload the page and try again.'), 'danger');
                }

                return json;
            });
        }, function () {
            var json = { ok: false, httpStatus: 0, error: text('network', 'No connection to the shop. Check your network and try again.') };

            if (!options.quiet) {
                toast(json.error, 'danger');
            }

            return json;
        });
    }

    /**
     * Kopiowanie do schowka. `navigator.clipboard` dziala tylko po HTTPS, a panele
     * sklepow bywaja na HTTP — wtedy tymczasowe pole tekstowe i `execCommand` (jak w CMP).
     */
    function copy(value) {
        var done = function (ok) {
            toast(ok ? text('copied', 'Copied') : text('copyFailed', 'Could not copy. Select the text and copy it manually.'), ok ? 'success' : 'warning');

            return ok;
        };

        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(String(value)).then(function () {
                return done(true);
            }, function () {
                return done(fallbackCopy(value));
            });
        }

        return Promise.resolve(done(fallbackCopy(value)));
    }

    function fallbackCopy(value) {
        var area = document.createElement('textarea');
        var ok = false;

        area.value = String(value);
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '-1000px';
        document.body.appendChild(area);
        area.select();

        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }

        area.remove();

        return ok;
    }

    function icon(name, size, cls) {
        var px = size || 16;

        return '<svg class="qc-icon' + (cls ? ' ' + cls : '') + '" width="' + px + '" height="' + px +
            '" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' +
            (PATHS[name] || PATHS.box) + '"/></svg>';
    }

    /**
     * Komponent paska postepu: `x-data="QI.job({job, tickUrl, cancelUrl, type, onDone, reload})"`.
     *
     * Popycha zadanie zapytaniem `job_tick` co sekunde (dluzej, gdy zadanie kaze czekac).
     * Zamkniecie karty niczego nie psuje — zadanie dokonczy cron. `type` (opcjonalnie)
     * ogranicza komponent do zadan jednego typu, gdy na stronie jest ich kilka.
     */
    function job(config) {
        var cfg = config || {};

        return {
            job: cfg.job || null,
            busy: false,
            ended: false,
            timer: null,
            failures: 0,

            get state() {
                if (!this.job) {
                    return 'idle';
                }
                if (this.job.status === 'done') {
                    return 'done';
                }
                if (this.job.status === 'failed' || this.job.status === 'cancelled') {
                    return 'error';
                }

                return 'running';
            },

            get percent() {
                return this.job ? (this.job.percent || 0) : 0;
            },

            get message() {
                return this.job ? (this.job.message || '') : '';
            },

            get error() {
                return this.job ? (this.job.error || '') : '';
            },

            get items() {
                return this.job && Array.isArray(this.job.items) ? this.job.items : [];
            },

            init: function () {
                var self = this;

                window.addEventListener('qi:job', function (event) {
                    var started = event.detail;

                    if (!started || (cfg.type && started.type !== cfg.type)) {
                        return;
                    }

                    self.job = started;
                    self.ended = false;
                    self.failures = 0;
                    self.schedule(0);
                });

                if (this.job && this.job.active) {
                    this.schedule(0);
                }
            },

            schedule: function (ms) {
                var self = this;

                clearTimeout(this.timer);
                this.timer = setTimeout(function () {
                    self.tick();
                }, ms);
            },

            tick: function () {
                var self = this;

                if (!this.job || this.busy) {
                    return;
                }

                if (!this.job.active) {
                    this.finish();

                    return;
                }

                this.busy = true;

                QI.post(cfg.tickUrl, { id: this.job.id }, { quiet: true }).then(function (response) {
                    self.busy = false;

                    if (response.ok && response.job) {
                        self.failures = 0;
                        self.job = response.job;
                    } else if (response.httpStatus === 0 || response.httpStatus >= 500) {
                        // Chwilowy klopot z siecia albo serwerem: probujemy dalej, coraz rzadziej.
                        self.failures++;
                        if (self.failures >= 5) {
                            toast(response.error, 'danger');

                            return;
                        }
                    } else {
                        toast(response.error, 'danger');
                        self.job = Object.assign({}, self.job, { status: 'failed', active: false, error: response.error || '' });
                    }

                    if (!self.job.active) {
                        self.finish();

                        return;
                    }

                    var wait = self.job.wait > 0 ? Math.min(self.job.wait, 30) * 1000 : 1000;
                    self.schedule(wait * Math.max(1, self.failures));
                });
            },

            cancel: function () {
                var self = this;

                if (!this.job || !cfg.cancelUrl) {
                    return;
                }

                QI.post(cfg.cancelUrl, { id: this.job.id }).then(function (response) {
                    if (response.ok && response.job) {
                        self.job = response.job;
                        clearTimeout(self.timer);
                    }
                });
            },

            finish: function () {
                if (this.ended) {
                    return;
                }

                this.ended = true;
                clearTimeout(this.timer);

                if (cfg.onDone && typeof window[cfg.onDone] === 'function') {
                    window[cfg.onDone](this.job);
                }

                if (cfg.reload && this.job && this.job.status === 'done') {
                    window.location.reload();
                }
            }
        };
    }

    function jobStart(started) {
        if (started) {
            window.dispatchEvent(new CustomEvent('qi:job', { detail: started }));
        }
    }

    return { post: post, toast: toast, copy: copy, job: job, jobStart: jobStart, icon: icon };
})();

/**
 * Quince Insight - kreator (powloka i kroki rdzenia).
 *
 * `QIWizard` - kontrakt dla szablonow krokow uslug (opis w wizard/index.html.twig):
 *   QIWizard.primary({label, disabled, onClick})  zmienia przycisk glowny paska akcji
 *   QIWizard.primary(null)                        przywraca przycisk z powloki
 *   QIWizard.submit()                             wysyla formularz kroku ("Continue")
 *   QIWizard.shop                                 id sklepu formularza
 *
 * Komponenty Alpine sa funkcjami globalnymi - plik laduje sie przed Alpine.
 */
(function () {
    'use strict';

    var EVENT = 'qi:wizard-primary';

    window.QIWizard = {
        shop: 0,

        // Ostatnie ustawienie przycisku: krok moze je wyslac, zanim pasek akcji
        // (nizej w dokumencie) zdazy sie uruchomic.
        last: undefined,

        primary: function (options) {
            window.QIWizard.last = options || null;
            window.dispatchEvent(new CustomEvent(EVENT, { detail: options || null }));
        },

        submit: function () {
            var form = document.getElementById('qi-wizard-form');

            if (!form) {
                return;
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }
    };

    /** Pasek akcji: przycisk glowny ("Continue", "Open dashboard"...). */
    window.QIWizardBar = function (cfg) {
        var base = { label: cfg.label, disabled: !!cfg.disabled };

        window.QIWizard.shop = cfg.shop || 0;

        return {
            label: base.label,
            disabled: base.disabled,
            busy: false,
            handler: null,

            init: function () {
                var self = this;
                var apply = function (o) {

                    if (!o) {
                        self.label = base.label;
                        self.disabled = base.disabled;
                        // Krok, ktory ustawil `busy` (np. Tag Manager w trakcie zadania), oddaje
                        // przycisk przez primary(null) — bez tego zostawal krecacy sie "Continue"
                        // (fala 2, agent I: styk A/D).
                        self.busy = false;
                        self.handler = null;

                        return;
                    }

                    if (typeof o.label === 'string' && o.label !== '') {
                        self.label = o.label;
                    }
                    if (typeof o.disabled !== 'undefined') {
                        self.disabled = !!o.disabled;
                    }
                    if (typeof o.busy !== 'undefined') {
                        self.busy = !!o.busy;
                    }
                    if (typeof o.onClick !== 'undefined') {
                        self.handler = typeof o.onClick === 'function' ? o.onClick : null;
                    }
                };

                window.addEventListener(EVENT, function (event) { apply(event.detail); });

                if (typeof window.QIWizard.last !== 'undefined') {
                    apply(window.QIWizard.last);
                }
            },

            press: function () {
                if (this.disabled || this.busy) {
                    return;
                }

                if (this.handler) {
                    this.handler();

                    return;
                }

                var form = document.getElementById(cfg.form);

                if (!form) {
                    return;
                }

                this.busy = true;

                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        };
    };

    /** Krok Services: zaleznosci jak w prototypie, "Continue" wymaga choc jednej uslugi. */
    window.QIWizardServices = function (selected) {
        return {
            svc: Object.assign({ ga4: false, ads: false, gtm: false, gmc: false, gcr: false, gsc: false, psi: false }, selected || {}),

            init: function () {
                var self = this;

                this.$watch('svc.ads', function (v) { if (v) { self.svc.gtm = true; } });
                this.$watch('svc.gtm', function (v) { if (!v) { self.svc.ads = false; } });
                this.$watch('svc.gcr', function (v) { if (v) { self.svc.gmc = true; } });
                this.$watch('svc.gmc', function (v) { if (!v) { self.svc.gcr = false; } });
                this.$watch('svc', function () { self.sync(); }, { deep: true });
                this.$nextTick(function () { self.sync(); });
            },

            sync: function () {
                var any = Object.keys(this.svc).some(function (k) { return this.svc[k]; }, this);

                window.QIWizard.primary({ disabled: !any });
            }
        };
    };

    /** Krok Google Cloud project: podkroki zwijane i "Check". */
    window.QIWizardCloud = function (cfg) {
        return {
            steps: cfg.steps || [1, 2, 3, 4],
            open: (cfg.steps || [1])[0],
            done: {},
            conn: cfg.conn || 'new',
            clientId: cfg.clientId || '',
            secret: '',
            apiKey: '',
            production: !!cfg.production,
            state: 'idle',
            error: '',

            toggle: function (n) {
                this.open = this.open === n ? 0 : n;
            },

            markDone: function (n) {
                this.done[n] = true;

                var self = this;
                var next = this.steps.filter(function (i) { return i > n && !self.done[i]; })[0];

                this.open = next || 0;
            },

            check: function () {
                var self = this;

                this.state = 'checking';
                this.error = '';

                QI.post(cfg.checkUrl, {
                    conn: this.conn,
                    client_id: this.clientId,
                    client_secret: this.secret,
                    api_key: this.apiKey,
                    psi: cfg.psi
                }, { quiet: true }).then(function (r) {
                    if (r.ok) {
                        self.state = 'ok';
                    } else {
                        self.state = 'error';
                        self.error = r.error || '';
                    }
                });
            }
        };
    };

    /**
     * Krok Search Console — przycisk glowny jak prototyp `primaryLabel()` dla `gsc`:
     * "Generate and submit" (mapa z modulu) / "Save and submit" (istniejaca mapa) uruchamia
     * zadanie `sitemap.rebuild` (trasa `gsc_save`), w trakcie "Working…", po bledzie
     * "Try again", po udanym zadaniu "Continue" wysyla formularz kroku (bez drugiego zadania:
     * pole `task_job`). Zmiana wyboru po udanym zadaniu wraca do "… and submit".
     */
    window.QIWizardGsc = function (cfg) {
        return {
            property: cfg.property || 'prefix',
            sitemap: cfg.sitemap || 'module',
            task: cfg.task || '',
            taskJob: cfg.taskJob || 0,
            busy: false,

            init: function () {
                var self = this;
                var saved = { property: this.property, sitemap: this.sitemap };

                window.addEventListener('qi:gsc-setup', function (e) {
                    var job = e.detail || {};

                    self.task = job.status === 'done' ? 'done' : 'error';
                    self.taskJob = job.status === 'done' ? (job.id || 0) : 0;
                    saved = { property: self.property, sitemap: self.sitemap };
                    self.sync();
                });

                ['property', 'sitemap'].forEach(function (key) {
                    self.$watch(key, function () {
                        if (self.task === 'done' && (self.property !== saved.property || self.sitemap !== saved.sitemap)) {
                            self.task = '';
                            self.taskJob = 0;
                        }
                        self.sync();
                    });
                });

                this.sync();
            },

            sync: function () {
                var self = this;
                var running = this.busy || this.task === 'running';
                var label = this.task === 'done' ? cfg.t.next
                    : (running ? cfg.t.working : (this.task === 'error' ? cfg.t.retry : (this.sitemap === 'module' ? cfg.t.generate : cfg.t.save)));

                window.QIWizard.primary({
                    label: label,
                    disabled: running || !!cfg.needsGrant,
                    busy: running,
                    onClick: function () { self.primary(); }
                });
            },

            primary: function () {
                var self = this;

                if (this.task === 'done') {
                    window.QIWizard.submit();

                    return;
                }

                if (this.busy || this.task === 'running') {
                    return;
                }

                this.busy = true;
                this.sync();
                QI.post(cfg.saveUrl, { id_shop: cfg.shop, property: this.property, sitemap: this.sitemap }).then(function (r) {
                    self.busy = false;

                    if (r.ok && r.job) {
                        self.task = r.job.status === 'done' ? 'done' : (r.job.status === 'failed' ? 'error' : 'running');
                        self.taskJob = r.job.status === 'done' ? r.job.id : 0;
                        QI.jobStart(r.job);
                    }

                    self.sync();
                });
            }
        };
    };

    /* Koniec zadania `sitemap.rebuild` z kroku Search Console — wolane przez pasek postepu (onDone). */
    window.qiGscSetupDone = function (job) {
        window.dispatchEvent(new CustomEvent('qi:gsc-setup', { detail: job }));
    };

    /** Krok Site verification: sprawdzenie przy wejsciu, tag i weryfikacja, "Check again" ("Remove" — Settings). */
    window.QIWizardVerify = function (cfg) {
        return {
            state: cfg.state || 'idle',
            method: cfg.method || 'meta',
            meta: !!cfg.meta,
            confirm: false,
            busy: false,

            init: function () {
                var self = this;

                this.sync();
                this.$watch('state', function () { self.sync(); });

                // Prototyp `onEnter`: krok sam pyta Google, czy adres jest juz zweryfikowany.
                if (this.state === 'idle' && !cfg.shared) {
                    this.call(cfg.checkUrl, { shop: cfg.shop, mode: 'auto' }, 'checking');
                }
            },

            sync: function () {
                window.QIWizard.primary({ disabled: this.state !== 'verified' });
            },

            call: function (url, data, during) {
                var self = this;
                var before = this.state;

                this.state = during;

                return QI.post(url, data).then(function (r) {
                    if (r.ok && r.state) {
                        self.state = r.state;
                        if (r.method) {
                            self.method = r.method;
                        }
                    } else {
                        self.state = before === 'checking' ? 'unverified' : before;
                    }

                    return r;
                });
            },

            add: function () {
                var self = this;

                if (!this.confirm) {
                    return;
                }

                this.call(cfg.startUrl, { shop: cfg.shop, confirm: 1 }, 'adding').then(function (r) {
                    if (r.ok && r.state === 'verified') {
                        self.meta = true;
                    }
                });
            },

            manual: function () {
                this.call(cfg.checkUrl, { shop: cfg.shop, mode: 'manual' }, 'checking');
            }
        };
    };
})();

/**
 * Quince Insight — ekran Merchant Center (agent E): komponenty Alpine zakladek Overview,
 * Products, What is sent, widoku Add feed i kroku kreatora `gmc`.
 *
 * Wszystkie wywolania przez `QI.post` (CSRF, bledy jako toast). Dane startowe przychodza
 * w `x-data` z serwera; zadnych danych przykladowych.
 */
window.QIMerchant = (function () {
    'use strict';

    function reload(delay) {
        setTimeout(function () { window.location.reload(); }, delay || 0);
    }

    /** Popychanie zadania `merchant.sync` (job_tick co sekunde) — bez wlasnego paska. */
    function ticker(self, url, onEnd) {
        var tick = function () {
            if (!self.job || !self.job.active) {
                onEnd && onEnd(self.job);

                return;
            }

            QI.post(url, { id: self.job.id }, { quiet: true }).then(function (r) {
                if (r.ok && r.job) {
                    self.job = r.job;
                }

                setTimeout(tick, self.job && self.job.wait ? Math.min(30, self.job.wait) * 1000 : 1000);
            });
        };

        setTimeout(tick, 300);
    }

    function overview(cfg) {
        return {
            confirm: false,
            busy: false,
            onlyReady: !!cfg.onlyReady,
            job: cfg.job || null,

            get running() {
                return !!(this.job && this.job.active);
            },

            init: function () {
                var self = this;

                if (this.running) {
                    ticker(this, cfg.tickUrl, function () { reload(); });
                }

                return self;
            },

            toSendNow: function () {
                return this.onlyReady ? cfg.ready : cfg.matched;
            },

            percent: function () {
                return this.job ? (this.job.percent || 0) : 0;
            },

            progressText: function (sending, checking) {
                var tpl = cfg.status === 'draft' ? checking : sending;

                return tpl.replace('%done%', this.job ? this.job.done : 0).replace('%total%', this.job ? this.job.total : 0);
            },

            saveOnlyReady: function () {
                QI.post(cfg.statusUrl, { feed: cfg.feed, action: 'only_ready', value: this.onlyReady });
            },

            start: function () {
                var self = this;

                this.busy = true;
                QI.post(cfg.statusUrl, { feed: cfg.feed, action: 'start', only_ready: this.onlyReady }).then(function (r) {
                    self.busy = false;
                    self.confirm = false;

                    if (r.ok) {
                        cfg.status = 'active';
                        self.job = r.job;
                        ticker(self, cfg.tickUrl, function (job) {
                            if (job && job.status === 'done') {
                                QI.toast(r.message, 'success');
                            }

                            reload(600);
                        });
                    }
                });
            },

            syncNow: function () {
                var self = this;

                this.busy = true;
                QI.post(cfg.syncUrl, { feed: cfg.feed }).then(function (r) {
                    self.busy = false;

                    if (r.ok) {
                        QI.toast(r.message, 'success');
                        self.job = r.job;
                        ticker(self, cfg.tickUrl, function () { reload(); });
                    }
                });
            },

            setStatus: function (action) {
                var self = this;

                this.busy = true;
                QI.post(cfg.statusUrl, { feed: cfg.feed, action: action }).then(function (r) {
                    self.busy = false;

                    if (r.ok) {
                        QI.toast(r.message, 'success');
                        reload(400);
                    }
                });
            }
        };
    }

    function products(cfg) {
        return {
            selected: {},
            expanded: null,
            busy: false,

            count: function () {
                var self = this;

                return Object.keys(this.selected).filter(function (k) { return self.selected[k]; }).length;
            },

            toggle: function (key) {
                this.expanded = this.expanded === key ? null : key;
            },

            run: function (action, keys) {
                var self = this;
                var list = keys || Object.keys(this.selected).filter(function (k) { return self.selected[k]; });

                if (!list.length) {
                    return;
                }

                this.busy = true;
                QI.post(cfg.actionUrl, { feed: cfg.feed, action: action, keys: list }).then(function (r) {
                    self.busy = false;

                    if (r.ok) {
                        self.selected = {};
                        QI.toast(r.message, 'success');
                        reload(500);
                    }
                });
            }
        };
    }

    function sent(cfg) {
        return {
            open: 'settings',
            saved: true,
            busy: false,
            confirmDelete: false,
            inGoogle: cfg.inGoogle || 0,
            deleting: !!cfg.deleting,
            count: cfg.count,
            total: cfg.total,
            summary: cfg.summary || [],
            settings: cfg.settings,
            filters: JSON.parse(JSON.stringify(cfg.filters)),
            features: cfg.features || [],
            excludedNames: cfg.excludedNames || {},
            draft: !!cfg.draft,
            tagQuery: '',
            excludeId: '',
            timer: null,

            toggleOpen: function (key) {
                this.open = this.open === key ? '' : key;
            },

            has: function (key, id) {
                return this.filters[key].indexOf(id) !== -1;
            },

            picked: function (key) {
                return this.filters[key].length;
            },

            flip: function (key, id) {
                var i = this.filters[key].indexOf(id);

                if (i === -1) {
                    this.filters[key].push(id);
                } else {
                    this.filters[key].splice(i, 1);
                }

                this.changed();
            },

            flipValue: function (key, value) {
                this.flip(key, value);
            },

            valuesOf: function (idFeature) {
                var f = this.features.filter(function (x) { return x.id === idFeature; })[0];

                return f ? f.values : [];
            },

            firstValue: function (idFeature) {
                var values = this.valuesOf(idFeature);

                return values.length ? values[0].id : 0;
            },

            addFeature: function () {
                if (!this.features.length) {
                    return;
                }

                this.filters.features.push({ id_feature: this.features[0].id, id_feature_value: this.firstValue(this.features[0].id) });
                this.changed();
            },

            addTag: function (list) {
                var t = this.tagQuery.trim();

                if (t && this.filters[list].indexOf(t) === -1) {
                    this.filters[list].push(t);
                }

                this.tagQuery = '';
                this.changed();
            },

            addExcluded: function () {
                var id = parseInt(this.excludeId, 10);

                if (id > 0 && this.filters.excluded_products.indexOf(id) === -1) {
                    this.filters.excluded_products.push(id);
                }

                this.excludeId = '';
                this.changed();
            },

            /** Zmiana: "Unsaved changes" i przeliczenie licznika na serwerze (z opoznieniem). */
            changed: function () {
                var self = this;

                this.saved = false;
                clearTimeout(this.timer);
                this.timer = setTimeout(function () {
                    QI.post(cfg.countUrl, { feed: cfg.feed, filters: self.filters, with_tax: self.settings.with_tax ? 1 : 0 }, { quiet: true }).then(function (r) {
                        if (r.ok) {
                            self.count = r.count;
                            self.total = r.total;
                            self.summary = r.summary;
                        }
                    });
                }, 350);
            },

            save: function () {
                var self = this;

                this.busy = true;
                QI.post(cfg.saveUrl, { feed: cfg.feed, settings: this.settings, filters: this.filters }).then(function (r) {
                    self.busy = false;

                    if (r.ok) {
                        self.saved = true;
                        QI.toast(r.message, 'success');

                        // Ocena feedu po zapisie idzie w tle; popychamy ja, dopoki karta jest otwarta.
                        if (r.job) {
                            var holder = { job: r.job };
                            ticker(holder, cfg.tickUrl, null);
                        }
                    }
                });
            },

            /**
             * "Delete feed" (decyzja #10): serwer przyjmuje tylko z liczba produktow, ktora user
             * widzial; zmieniona liczba = odmowa z nowa liczba i ponowne potwierdzenie.
             * Po przerwaniu (np. dzienny limit Google) ten sam przycisk wznawia usuwanie.
             */
            remove: function () {
                var self = this;

                this.busy = true;
                QI.post(cfg.deleteUrl, { feed: cfg.feed, confirm: true, products: this.inGoogle }).then(function (r) {
                    self.busy = false;

                    if (r.ok) {
                        self.deleting = true;
                        self.confirmDelete = false;
                        QI.toast(r.message, 'success');

                        if (r.job) {
                            QI.jobStart(r.job);
                        }
                    } else if (typeof r.products === 'number') {
                        self.inGoogle = r.products;
                    }
                });
            }
        };
    }

    function newFeed(cfg) {
        return {
            busy: false,
            form: cfg.form,
            cancelUrl: cfg.cancelUrl,

            langIso: function () {
                var id = this.form.id_lang;
                var l = cfg.languages.filter(function (x) { return x.id === id; })[0];

                return l ? l.iso : '';
            },

            langInShop: function () {
                var id = this.form.id_lang;
                var l = cfg.languages.filter(function (x) { return x.id === id; })[0];

                return !!(l && l.active);
            },

            currencyInShop: function () {
                var id = this.form.id_currency;
                var c = cfg.currencies.filter(function (x) { return x.id === id; })[0];

                return !!(c && c.active);
            },

            /** Podpowiedz jezyka i waluty po wyborze kraju — tylko sposrod aktywnych w sklepie. */
            countryChanged: function () {
                var s = cfg.suggest[this.form.country];

                if (s) {
                    this.form.id_lang = s.id_lang;
                    this.form.id_currency = s.id_currency;
                }
            },

            exists: function () {
                return cfg.taken.indexOf(this.form.country + ':' + this.form.id_lang) !== -1;
            },

            create: function () {
                var self = this;

                if (this.exists()) {
                    return;
                }

                this.busy = true;
                QI.post(cfg.createUrl, this.form).then(function (r) {
                    self.busy = false;

                    if (r.ok) {
                        QI.toast(r.message, 'success');
                        window.location.href = r.redirect;
                    }
                });
            }
        };
    }

    /** Krok kreatora `gmc`: konto, rejestracja, przejecie adresu. Pola formularza zapisuje powloka kreatora. */
    function gmcStep(cfg) {
        return {
            account: cfg.account || (cfg.accounts.length ? cfg.accounts[0].id : 'manual'),
            manualId: cfg.manualId || '',
            accounts: cfg.accounts || [],
            registered: !!cfg.registered,
            regState: 'idle',
            claim: cfg.claim || 'unclaimed',
            claimState: 'idle',
            form: cfg.form,
            busySelect: false,
            // Zadanie "Create draft feed": '' | running | done | error (R-09).
            task: cfg.feedExists ? 'done' : (cfg.draftStatus === 'pending' || cfg.draftStatus === 'running' ? 'running' : (cfg.draftStatus === 'failed' ? 'error' : '')),
            busy: false,

            init: function () {
                var self = this;

                window.addEventListener('qi:gmc-draft', function (e) {
                    self.task = e.detail && e.detail.status === 'done' ? 'done' : 'error';
                    self.sync();
                });
                this.$watch('registered', function () { self.sync(); });
                this.$watch('claim', function () { self.sync(); });
                this.sync();
            },

            /** Przycisk glowny paska akcji kreatora — jak `primaryLabel()` / `primaryDisabled()` prototypu. */
            sync: function () {
                var self = this;

                if (!window.QIWizard || typeof window.QIWizard.primary !== 'function') {
                    return;
                }

                var running = this.busy || this.task === 'running';
                var label = this.task === 'done' ? cfg.t.next : (running ? cfg.t.working : (this.task === 'error' ? cfg.t.retry : cfg.t.create));

                window.QIWizard.primary({
                    label: label,
                    disabled: running || !this.registered || this.claim !== 'claimed',
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
                QI.post(cfg.draftUrl, Object.assign({}, this.form, {
                    id_shop: cfg.idShop, account: this.account, manual_id: this.manualId, with_tax: this.form.with_tax ? 1 : 0
                })).then(function (r) {
                    self.busy = false;

                    if (r.ok && r.done) {
                        self.task = 'done';
                    } else if (r.ok && r.job) {
                        self.task = r.job.status === 'done' ? 'done' : 'running';
                        QI.jobStart(r.job);
                    }

                    self.sync();
                });
            },

            selectedId: function () {
                return this.account === 'manual' ? String(this.manualId).trim() : this.account;
            },

            /** Rejestracja i przejecie dzialaja na wybranym koncie — najpierw je zapisujemy. */
            ensureAccount: function () {
                var self = this;
                var id = this.selectedId();

                if (!id) {
                    return Promise.resolve(false);
                }

                if (id === cfg.saved) {
                    return Promise.resolve(true);
                }

                return QI.post(cfg.accountsUrl, { select: id, id_shop: cfg.idShop }).then(function (r) {
                    if (r.ok) {
                        cfg.saved = id;
                    }

                    return r.ok;
                });
            },

            register: function () {
                var self = this;

                this.regState = 'running';
                this.ensureAccount().then(function (ok) {
                    if (!ok) {
                        self.regState = 'idle';

                        return;
                    }

                    QI.post(cfg.registerUrl, { id_shop: cfg.idShop }).then(function (r) {
                        self.regState = 'idle';

                        if (r.ok) {
                            self.registered = true;
                        }
                    });
                });
            },

            claimSite: function (overwrite) {
                var self = this;

                this.claimState = 'running';
                this.ensureAccount().then(function (ok) {
                    if (!ok) {
                        self.claimState = 'idle';

                        return;
                    }

                    QI.post(cfg.claimUrl, { overwrite: !!overwrite, id_shop: cfg.idShop }).then(function (r) {
                        self.claimState = 'idle';

                        if (r.ok) {
                            self.claim = r.claim;
                        }
                    });
                });
            }
        };
    }

    /* Koniec zadania `merchant.draft` — wywolywane przez pasek postepu (onDone). */
    window.qiGmcDraftDone = function (job) {
        window.dispatchEvent(new CustomEvent('qi:gmc-draft', { detail: job }));
    };

    return { overview: overview, products: products, sent: sent, newFeed: newFeed, gmcStep: gmcStep };
})();

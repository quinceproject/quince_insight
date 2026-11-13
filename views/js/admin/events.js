/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 *
 * Ekran Events: autozapis ustawien (prototyp nie ma przycisku Save), rozwijane wiersze
 * dziennikow, test polaczenia, brakujace zamowienia. Ladowany przed Alpine (`quince_scripts`).
 */
window.qiEvents = (function () {
    'use strict';

    var urls = {};

    function save(key, value) {
        return QI.post(urls.save, { key: key, value: value }).then(function (r) {
            if (r.ok) {
                QI.toast(r.message, 'success');
            }
            return r;
        });
    }

    return {
        init: function (config) {
            urls = config || {};
        },

        save: save,

        /* Event list: wlacznik wysylki i „Key event”. */
        list: function (cfg) {
            return {
                on: cfg.on,
                keys: cfg.keys,
                // Decyzja #5: juz kluczowe w GA4 = zablokowane (modul tylko dodaje, nigdy nie usuwa).
                locked: cfg.locked || {},
                toggle: function (event) {
                    var self = this;
                    QI.post(urls.eventToggle, { event: event, on: self.on[event] }).then(function (r) {
                        if (r.ok) {
                            QI.toast(r.message, 'success');
                        } else {
                            self.on[event] = !self.on[event];
                        }
                    });
                },
                key: function (event) {
                    var self = this;
                    if (self.locked[event] || !self.keys[event]) {
                        self.keys[event] = !!self.locked[event];
                        return;
                    }
                    QI.post(urls.keyToggle, { event: event, on: true }).then(function (r) {
                        if (r.ok) {
                            self.locked[event] = true;
                            QI.toast(r.message, 'success');
                        } else {
                            self.keys[event] = !self.keys[event];
                        }
                    });
                }
            };
        },

        /* Consent: zrodlo, ciasteczko, stan domyslny, czas oczekiwania, mapowanie. */
        consent: function (cfg) {
            return {
                source: cfg.source,
                cookie: cfg.cookie,
                defaults: cfg.defaults,
                wait: cfg.wait,
                map: cfg.map,
                setSource: function () {
                    save('consentSource', this.source);
                },
                setDefault: function (signal, granted) {
                    this.defaults[signal] = granted ? 'granted' : 'denied';
                    var value = {};
                    value[signal] = this.defaults[signal];
                    save('consentDefaults', value);
                },
                setMap: function (signal) {
                    var value = {};
                    value[signal] = this.map[signal];
                    save('consentMap', value);
                }
            };
        },

        /* Server-side: test zdarzenia. */
        server: function () {
            return {
                testState: 'idle',
                message: '',
                test: function () {
                    var self = this;
                    self.testState = 'running';
                    QI.post(urls.testServer, {}).then(function (r) {
                        self.testState = r.ok ? 'ok' : 'idle';
                        self.message = r.ok ? r.message : '';
                    });
                }
            };
        },

        /* Order log: rozwijany wiersz z danymi wyslanymi do GA4 i ponowna wysylka. */
        order: function (id) {
            return {
                open: false,
                loaded: false,
                payload: '',
                refunds: [],
                toggle: function () {
                    var self = this;
                    self.open = !self.open;
                    if (self.open && !self.loaded) {
                        QI.post(urls.orderPayload, { id: id }).then(function (r) {
                            if (r.ok) {
                                self.loaded = true;
                                self.payload = r.payload;
                                self.refunds = r.refunds || [];
                            }
                        });
                    }
                },
                resend: function () {
                    QI.post(urls.orderResend, { id: id }).then(function (r) {
                        if (r.ok) {
                            QI.toast(r.message, 'success');
                            window.setTimeout(function () { window.location.reload(); }, 800);
                        }
                    });
                }
            };
        },

        /* Missing orders: skan, wybor, przebieg testowy, wysylka. */
        missing: function (cfg) {
            return {
                scan: 'idle',
                orders: [],
                pick: {},
                dry: true,
                state: 'idle',
                auto: cfg.auto,
                hours: cfg.hours,
                run: function () {
                    var self = this;
                    self.scan = 'running';
                    QI.post(urls.missingScan, {}).then(function (r) {
                        self.scan = r.ok ? 'done' : 'idle';
                        self.state = 'idle';
                        self.orders = r.ok ? r.orders : [];
                        self.pick = {};
                        self.orders.forEach(function (o) {
                            if (o.sendable) {
                                self.pick[o.id] = true;
                            }
                        });
                    });
                },
                picked: function () {
                    var self = this;
                    return self.orders.filter(function (o) { return o.sendable && self.pick[o.id]; }).length;
                },
                send: function () {
                    var self = this;
                    var ids = self.orders.filter(function (o) { return o.sendable && self.pick[o.id]; }).map(function (o) { return o.id; });
                    self.state = 'running';
                    QI.post(urls.missingSend, { ids: ids, dry: self.dry }).then(function (r) {
                        self.state = r.ok ? 'done' : 'idle';
                        if (r.ok) {
                            QI.toast(r.message, self.dry ? 'info' : 'success');
                            if (!self.dry) {
                                self.orders.forEach(function (o) {
                                    if (ids.indexOf(o.id) !== -1) {
                                        o.done = true;
                                        o.sendable = false;
                                    }
                                });
                            }
                        }
                    });
                }
            };
        }
    };
})();

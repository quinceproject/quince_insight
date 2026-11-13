/**
 * Quince Insight - blok "Scheduled tasks (cron)" (Settings i Summary kreatora).
 *
 * Linie curl / wget sklada przegladarka z adresu grupy i zalecanego wyrazenia crona,
 * tak jak w prototypie (`cronLine`). "Run now" i "New token" tylko w Settings.
 */
(function () {
    'use strict';

    window.QICron = function (cfg) {
        var t = cfg.i18n || {};

        return {
            rows: cfg.rows || [],
            all: cfg.all || {},
            tool: cfg.tool || 'curl',
            way: cfg.way || 'separate',
            running: null,
            tokenBusy: false,

            url: function (group) {
                if (group === 'all') {
                    return this.all.url || '';
                }

                var row = this.rows.filter(function (r) { return r.key === group; })[0];

                return row ? row.url : '';
            },

            line: function (group, expr) {
                return expr + ' ' + (this.tool === 'curl' ? 'curl -s "' : 'wget -q -O /dev/null "') + this.url(group) + '"';
            },

            lastText: function (g) {
                if (!g.when) {
                    return t.never || '';
                }
                if (g.late) {
                    return (t.lastLate || '').replace('%when%', g.when).replace('%every%', g.every);
                }

                return ((g.lastStatus === 'error' ? t.lastError : t.lastOk) || '').replace('%when%', g.when);
            },

            run: function (g) {
                var self = this;

                if (!cfg.runUrl) {
                    return;
                }

                this.running = g.key;

                QI.post(cfg.runUrl, { group: g.key }).then(function (r) {
                    self.running = null;

                    if (r.ok) {
                        if (r.row) {
                            Object.assign(g, r.row);
                        }
                        QI.toast((t.finished || '').replace('%name%', g.name), r.failed ? 'warning' : 'success');
                    }
                });
            },

            newToken: function () {
                var self = this;

                if (!cfg.tokenUrl) {
                    return;
                }

                this.tokenBusy = true;

                QI.post(cfg.tokenUrl, {}).then(function (r) {
                    self.tokenBusy = false;

                    if (r.ok && r.urls) {
                        self.rows.forEach(function (row) {
                            if (r.urls[row.key]) {
                                row.url = r.urls[row.key];
                            }
                        });
                        if (r.urls.all) {
                            self.all.url = r.urls.all;
                        }
                        QI.toast(t.tokenDone || '', 'success');
                    }
                });
            }
        };
    };
})();

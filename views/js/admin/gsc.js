/**
 * Quince Insight - ekran Search Console (prototyp `pg==='gsc'`, `js/searchconsole.js`).
 *
 * Dwa komponenty Alpine:
 *   qiGscOverview(config)  - przelacznik okresu 7 / 28 / 90 dni (dane wszystkich okresow z serwera)
 *   qiGscSitemap(config)   - Rebuild (zadanie w tle), Submit, usuwanie innych map, wlaczniki zakresu,
 *                            zgoda na linie `Sitemap:` w robots.txt
 *
 * Teksty komunikatow przychodza z szablonu juz przetlumaczone (`config.t`).
 */
(function () {
    'use strict';

    window.qiGscOverview = function (config) {
        return {
            period: '28',
            summaries: config.summaries || {},
            current() {
                return this.summaries[this.period] || { tiles: [], trend: [], hasData: false };
            },
            tiles() {
                return this.current().tiles || [];
            },
            trend() {
                return this.current().trend || [];
            }
        };
    };

    window.qiGscSitemap = function (config) {
        return {
            include: Object.assign({}, config.include || {}),
            robotsLine: !!config.robotsLine,
            rebuilding: !!(config.job && config.job.active),
            submitting: false,
            removed: {},

            init() {
                // Zadanie przebudowy rozpoczete wczesniej (inna karta, cron) - dokanczamy je stad.
                if (config.job && config.job.active) {
                    this.follow(config.job.id);
                }

                // Stan mapy w Google czytany po cichu, gdy jest stary; przeladowanie tylko po zmianie.
                if (config.googleStale && config.refreshUrl) {
                    QI.post(config.refreshUrl, {}, { quiet: true }).then(function (r) {
                        if (r.ok && config.reloadOnRefresh) {
                            location.reload();
                        }
                    });
                }

                this.$watch('include', (value) => this.save(value), { deep: true });

                // Zgoda na linie w robots.txt: tylko zapis ustawienia, plik pisze PrestaShop (decyzja #19).
                this.$watch('robotsLine', (value) => QI.post(config.saveUrl, { robots_line: value ? 1 : 0 }));
            },

            rebuild() {
                if (this.rebuilding) {
                    return;
                }

                this.rebuilding = true;

                QI.post(config.rebuildUrl, {}).then((r) => {
                    if (!r.ok) {
                        this.rebuilding = false;

                        return;
                    }

                    this.follow(r.job.id);
                });
            },

            follow(id) {
                var self = this;

                var tick = function () {
                    QI.post(config.tickUrl, { id: id }, { quiet: true }).then(function (r) {
                        if (!r.ok) {
                            self.rebuilding = false;
                            QI.toast(r.error || config.t.failed, 'danger');

                            return;
                        }

                        var job = r.job;

                        if (job.active) {
                            setTimeout(tick, job.wait > 0 ? job.wait * 1000 : 1000);

                            return;
                        }

                        self.rebuilding = false;

                        if (job.status === 'done') {
                            QI.toast(config.t.rebuilt.replace('%count%', String((job.result && job.result.urls) || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')), 'success');
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            QI.toast(job.error || config.t.failed, 'danger');
                        }
                    });
                };

                this.rebuilding = true;
                tick();
            },

            submit() {
                if (this.submitting) {
                    return;
                }

                this.submitting = true;

                QI.post(config.submitUrl, {}).then((r) => {
                    this.submitting = false;

                    if (r.ok) {
                        QI.toast(config.t.submitted, 'success');
                        setTimeout(function () { location.reload(); }, 800);
                    }
                });
            },

            remove(path) {
                QI.post(config.removeUrl, { path: path }).then((r) => {
                    if (r.ok) {
                        this.removed[path] = true;
                        QI.toast(config.t.removed, 'success');
                    }
                });
            },

            save(value) {
                // Zdjecia bez produktow: wlacznik wyszarzony (jak w prototypie), serwer i tak ich nie wpisze.
                QI.post(config.saveUrl, { include: value });
            }
        };
    };
})();

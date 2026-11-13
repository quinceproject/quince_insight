/**
 * Quince Insight — ekran Page speed (komponent Alpine `qiPsi`).
 *
 * Stan i zachowanie 1:1 z prototypem (`prototypy/js/pagespeed.js`, `psState`), dane z serwera:
 * wyniki obu urzadzen sa w stronie, przelacznik Mobile / Desktop nie pyta serwera.
 */
window.qiPsi = function (cfg) {
    'use strict';

    return {
        ps: {
            device: 'mobile',
            running: !!(cfg.job && cfg.job.active),
            open: null,
            schedule: cfg.schedule,
            newUrl: '',
            pages: cfg.pages || [],
        },
        field: cfg.field || {},
        last: cfg.last || {},

        get fieldNow() {
            return this.field[this.ps.device] || { state: 'none', metrics: [], passed: false };
        },

        psLab(p) {
            return p[this.ps.device] || { pending: true };
        },

        psRatingLabel(r) {
            return { good: cfg.t.good, ni: cfg.t.ni, poor: cfg.t.poor }[r];
        },

        psRatingClass(r) {
            return { good: 'qc-badge-success', ni: 'qc-badge-warning', poor: 'qc-badge-danger' }[r];
        },

        psRun() {
            if (this.ps.running) {
                return;
            }
            this.ps.running = true;
            QI.post(cfg.urls.run, {}).then((r) => {
                if (r.ok && r.job) {
                    QI.jobStart(r.job);
                } else {
                    this.ps.running = false;
                }
            });
        },

        psAdd() {
            const v = this.ps.newUrl.trim();
            if (!v || this.ps.pages.length >= cfg.maxPages) {
                return;
            }
            QI.post(cfg.urls.add, { url: v }).then((r) => {
                if (r.ok) {
                    this.ps.pages = r.pages;
                    this.ps.newUrl = '';
                    QI.toast(r.message, 'success');
                }
            });
        },

        psRemove(p) {
            QI.post(cfg.urls.remove, { id: p.id }).then((r) => {
                if (r.ok) {
                    this.ps.pages = r.pages;
                    if (this.ps.open === p.id) {
                        this.ps.open = null;
                    }
                }
            });
        },

        psSchedule() {
            QI.post(cfg.urls.save, { schedule: this.ps.schedule }).then((r) => {
                if (r.ok) {
                    QI.toast(r.message, 'success');
                }
            });
        },
    };
};

/* Koniec zadania `pagespeed.check` — wywolywane przez komponent paska postepu (onDone). */
window.qiPsiDone = function (job) {
    'use strict';

    if (job && job.status === 'done') {
        window.location.reload();

        return;
    }

    window.dispatchEvent(new CustomEvent('qi-psi-stopped'));
};

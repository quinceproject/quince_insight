/**
 * Quince Insight — ekran Analytics: male komponenty Alpine (Refresh, sekret MP, retencja).
 * Laduje sie po panel.js (QI) i przed Alpine.
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */
(function () {
    'use strict';

    // "Refresh": czysci pamiec raportow i przeladowuje ekran.
    window.qiAnalyticsRefresh = function (url) {
        return {
            busy: false,
            run: function () {
                var self = this;
                self.busy = true;
                QI.post(url, {}).then(function (r) {
                    if (r.ok) {
                        window.location.reload();
                        return;
                    }
                    self.busy = false;
                });
            }
        };
    };

    // Sekret Measurement Protocol z zakladki Property; przy odmowie Google pokazuje potwierdzenie.
    window.qiAnalyticsSecret = function (cfg) {
        return {
            busy: false,
            ack: false,
            ackNeeded: !!cfg.ackNeeded,
            run: function () {
                var self = this;
                self.busy = true;
                QI.post(cfg.url, { ack: self.ack }, { quiet: true }).then(function (r) {
                    self.busy = false;
                    if (r.ok) {
                        QI.toast(r.message, 'success');
                        window.location.reload();
                        return;
                    }
                    if (r.ack) {
                        self.ackNeeded = true;
                    }
                    QI.toast(r.error, 'danger');
                });
            }
        };
    };

    // Retencja danych — zapis od razu po zmianie, powrot do poprzedniej wartosci przy bledzie.
    window.qiAnalyticsRetention = function (cfg) {
        return {
            busy: false,
            value: cfg.value,
            saved: cfg.value,
            save: function () {
                var self = this;
                self.busy = true;
                QI.post(cfg.url, { field: 'retention', value: self.value }).then(function (r) {
                    self.busy = false;
                    if (r.ok) {
                        self.saved = self.value;
                        QI.toast(r.message, 'success');
                    } else {
                        self.value = self.saved;
                    }
                });
            }
        };
    };
})();

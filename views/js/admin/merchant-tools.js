/**
 * Quince Insight — zakladki Mapping, Rules, Promotions, Shipping, Reports ekranu Merchant Center.
 *
 * Komponenty Alpine: x-data="QIMt.mapping({...})" itd. Dane i przetlumaczone teksty przychodza
 * z Twig w obiekcie konfiguracji (|json_encode), adresy akcji z path(). Zachowanie 1:1 z
 * prototypem (prototypy/js/gmc.js), dane prawdziwe z akcji JSON (QI.post).
 *
 * Plik laduje sie zwyklym <script> w tresci zakladki — przed Alpine (ten ma `defer`), wiec
 * globalne QIMt jest gotowe, zanim Alpine zacznie czytac x-data. QI (panel.js, `defer`) jest
 * uzywane dopiero w zdarzeniach.
 */
(function () {
    'use strict';

    if (window.QIMt) {
        return;
    }

    function fmt(n) {
        return String(n == null ? 0 : n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }

    function tr(cfg, key, fallback) {
        return (cfg.i18n && cfg.i18n[key]) || fallback;
    }

    /* ---------- Mapping ---------- */

    function mapping(cfg) {
        return {
            ids: cfg.identifiers,
            conditions: cfg.conditions || [],
            attrs: cfg.attrs,
            sources: cfg.sources,
            cats: cfg.categories,
            mappedCount: cfg.mappedCount,
            categoryCount: cfg.categoryCount,
            catEdit: null,
            catQuery: '',
            hits: [],
            timer: null,

            saveIdentifier(row) {
                QI.post(cfg.urls.save, { type: 'identifier', key: row.k, value: row.value }).then(function (r) {
                    if (r.ok) QI.toast(r.message, 'success');
                });
            },

            saveCondition(c) {
                QI.post(cfg.urls.save, { type: 'condition', state: c.state, value: c.value }).then(function (r) {
                    if (r.ok) QI.toast(r.message, 'success');
                });
            },

            saveAttribute(a) {
                a.on = !!a.source;
                a.auto = false;
                QI.post(cfg.urls.save, { type: 'attribute', target: a.target, source: a.source }).then(function (r) {
                    if (r.ok) QI.toast(r.message, 'success');
                });
            },

            toggleAttribute(a) {
                // Wylaczenie = zapis "nie wysylac"; wlaczenie wymaga zrodla (przelacznik jest wtedy aktywny).
                if (!a.on) {
                    a.source = '';
                    this.saveAttribute(a);
                }
            },

            startEdit(row) {
                this.catEdit = row.id;
                this.catQuery = '';
                this.hits = [];
            },

            search() {
                var self = this;
                clearTimeout(this.timer);
                var q = this.catQuery.trim();
                if (q.length < 2) { this.hits = []; return; }
                this.timer = setTimeout(function () {
                    QI.post(cfg.urls.search, { q: q, locale: cfg.locale }, { quiet: true }).then(function (r) {
                        if (r.ok && self.catQuery.trim() === q) self.hits = r.hits || [];
                    });
                }, 200);
            },

            pick(row, hit) {
                var self = this;
                QI.post(cfg.urls.save, { type: 'category', id_category: row.id, google_id: hit.id, locale: cfg.locale }).then(function (r) {
                    if (!r.ok) return;
                    row.google = hit.id;
                    row.googlePath = hit.path;
                    self.mappedCount = r.mappedCount;
                    self.catEdit = null;
                    self.catQuery = '';
                    self.hits = [];
                    QI.toast(r.message, 'success');
                });
            },

            mappedLabel() {
                return tr(cfg, 'mapped', '%mapped% of %total% mapped').replace('%mapped%', this.mappedCount).replace('%total%', this.categoryCount);
            }
        };
    }

    /* ---------- Rules ---------- */

    function rules(cfg) {
        return {
            RULE_TYPES: cfg.types,
            RULE_FIELDS: cfg.fields,
            RULE_OPS: cfg.ops,
            PRICING_ACTIONS: cfg.pricingActions,
            IDENT_TARGETS: cfg.identTargets,
            rules: cfg.rules,
            feeds: cfg.feeds || [],
            ruleType: 'exclusion',
            editRule: null,
            saving: false,

            rulesOf(type) { return this.rules.filter(function (r) { return r.type === type; }); },

            typeLabel(t) {
                var n = this.rulesOf(t.key).length;
                return t.label + (n ? ' · ' + n : '');
            },

            emptyText() {
                var self = this;
                return this.RULE_TYPES.find(function (t) { return t.key === self.ruleType; }).empty;
            },

            newRule() {
                this.editRule = { id: 0, type: this.ruleType, name: '', on: true, priority: 0, feed: 0,
                    conds: [{ field: 'price', op: 'gt', value: '' }], action: 'min_price', value: '', label: 0, labelValue: '', target: 'gtin', source: 'fixed' };
            },

            edit(r) { this.editRule = JSON.parse(JSON.stringify(r)); },

            isExisting() {
                var self = this;
                return !!(this.editRule && this.rules.some(function (r) { return r.id === self.editRule.id; }));
            },

            save() {
                var self = this;
                this.saving = true;
                QI.post(cfg.urls.save, { feed: cfg.feedId, rule: this.editRule }).then(function (r) {
                    self.saving = false;
                    if (!r.ok) return;
                    self.rules = r.rules;
                    self.editRule = null;
                    QI.toast(r.message, 'success');
                });
            },

            remove(r) {
                var self = this;
                QI.post(cfg.urls.remove, { feed: cfg.feedId, id: r.id }).then(function (res) {
                    if (!res.ok) return;
                    self.rules = res.rules;
                    if (self.editRule && self.editRule.id === r.id) self.editRule = null;
                    QI.toast(res.message, 'success');
                });
            },

            /** Zasieg reguly (decyzja #14): "All feeds" albo nazwa feedu. */
            scopeLabel(r) {
                if (!r.feed) return tr(cfg, 'allFeeds', 'All feeds');
                var f = this.feeds.find(function (x) { return x.id === r.feed; });
                return f ? f.name : '#' + r.feed;
            },

            summary(r) {
                var conds = r.conds.length + (r.conds.length === 1 ? ' ' + tr(cfg, 'condition', 'condition') : ' ' + tr(cfg, 'conditions', 'conditions'));
                var action = '';
                if (r.type === 'exclusion') action = tr(cfg, 'excluded', 'excluded from the feed');
                if (r.type === 'pricing') action = this.PRICING_ACTIONS[r.action] + (r.value ? ' ' + r.value : '');
                if (r.type === 'custom_label') action = 'custom_label_' + r.label + ' = ' + (r.labelValue || '…');
                if (r.type === 'identifier') action = this.IDENT_TARGETS[r.target || 'gtin'];
                return conds + ' · ' + tr(cfg, 'priority', 'priority') + ' ' + r.priority + ' · ' + action;
            },

            opsFor(c) { return this.RULE_FIELDS[c.field].ops; },
            fieldChanged(c) { c.op = this.RULE_FIELDS[c.field].ops[0]; c.value = this.RULE_FIELDS[c.field].input === 'select' ? this.RULE_FIELDS[c.field].options[0] : ''; },
            needsValue(c) { return ['empty', 'not_empty'].indexOf(c.op) < 0; }
        };
    }

    /* ---------- Promotions ---------- */

    function promotions(cfg) {
        return {
            promos: cfg.promos,
            scan: null,
            skipped: [],
            scanning: false,
            adding: null,

            find() {
                var self = this;
                this.scanning = true;
                QI.post(cfg.urls.scan, { feed: cfg.feedId }).then(function (r) {
                    self.scanning = false;
                    if (!r.ok) return;
                    self.scan = r.rules;
                    self.skipped = r.skipped;
                });
            },

            add(s) {
                var self = this;
                this.adding = s.id;
                QI.post(cfg.urls.send, { feed: cfg.feedId, id_cart_rule: s.id }).then(function (r) {
                    self.adding = null;
                    if (r.promos) self.promos = r.promos;
                    if (!r.ok) return;
                    self.scan = self.scan.filter(function (x) { return x !== s; });
                    QI.toast(r.message, 'success');
                });
            },

            badge(p) {
                return { active: 'qc-badge-success', pending: 'qc-badge-warning', rejected: 'qc-badge-danger' }[p.status] || '';
            },

            statusLabel(p) { return (cfg.statuses && cfg.statuses[p.status]) || p.status; },

            ago(p) { return p.syncedAt ? QIMt.ago(p.syncedAt, cfg.now, cfg.i18n) : '—'; }
        };
    }

    /* ---------- Shipping ---------- */

    function shipping(cfg) {
        return {
            services: cfg.services,
            skipped: cfg.skipped,
            current: null,
            currentBySelf: false,
            sentAt: '',
            hash: '',
            loadError: '',
            shipConfirm: false,
            shipState: 'loading',

            init() { this.load(); },

            load() {
                var self = this;
                this.shipState = 'loading';
                QI.post(cfg.urls.preview, {}, { quiet: true }).then(function (r) {
                    if (!r.ok) {
                        self.loadError = r.error || '';
                        self.shipState = 'error';
                        return;
                    }
                    self.apply(r);
                    self.shipState = 'idle';
                });
            },

            apply(p) {
                this.services = p.services;
                this.skipped = p.skipped;
                this.current = p.current;
                this.currentBySelf = p.currentBySelf;
                this.sentAt = p.sentAt;
                this.hash = p.hash;
            },

            currentLine() {
                if (this.current === null) return '';
                var n = this.current.length;
                if (!n) return tr(cfg, 'none', 'No shipping settings yet');
                var count = n + ' ' + (n === 1 ? tr(cfg, 'service', 'service') : tr(cfg, 'services', 'services'));
                if (this.currentBySelf) return count + ', ' + tr(cfg, 'sentBy', 'sent by this module') + (this.sentAt ? ' ' + QIMt.ago(this.sentAt, cfg.now, cfg.i18n) : '');
                return count + ', ' + tr(cfg, 'manual', 'set manually in Merchant Center');
            },

            send() {
                var self = this;
                if (!this.shipConfirm) return;
                this.shipState = 'running';
                QI.post(cfg.urls.send, { confirm: true, hash: this.hash }).then(function (r) {
                    if (r.preview) self.apply(r.preview);
                    self.shipConfirm = false;
                    self.shipState = 'idle';
                    if (r.ok) {
                        self.currentBySelf = true;
                        QI.toast(r.message, 'success');
                    }
                });
            }
        };
    }

    /* ---------- Reports ---------- */

    function reports(cfg) {
        return {
            period: '30',
            country: 'all',
            reportTab: 'product',
            page: 1,
            per: 10,
            data: null,
            loading: true,
            error: '',

            init() { this.load(false); },

            load(refresh) {
                var self = this;
                this.loading = true;
                QI.post(cfg.urls.report, { period: +this.period, country: this.country, market: cfg.market, refresh: !!refresh }, { quiet: true }).then(function (r) {
                    self.loading = false;
                    if (!r.ok) { self.error = r.error || ''; self.data = null; return; }
                    self.error = '';
                    self.data = r;
                    self.page = 1;
                });
            },

            setPeriod(p) { this.period = p; this.load(false); },

            fmt: fmt,

            ctr(clk, imp) { return imp > 0 ? (clk / imp * 100).toFixed(1) + '%' : '—'; },

            change(v, unit) {
                if (v === null || v === undefined) return null;
                return (v > 0 ? '+' : (v < 0 ? '−' : '')) + Math.abs(v) + (unit || '%');
            },

            changeClass(v) { return v > 0 ? 'qc-badge-success' : (v < 0 ? 'qc-badge-danger' : ''); },

            countryName(iso) { return (this.data && this.data.countryNames && this.data.countryNames[iso]) || iso; },

            rows() {
                if (!this.data) return [];
                var self = this;
                return this.reportTab === 'country'
                    ? this.data.byCountry.map(function (r) { return { key: r.country, name: self.countryName(r.country), sub: '', imp: r.imp, clk: r.clk }; })
                    : this.data.byProduct.map(function (r) { return { key: r.offer, name: r.name, sub: r.name !== r.offer ? r.offer : '', imp: r.imp, clk: r.clk }; });
            },

            pageRows() {
                var all = this.rows(), pages = Math.max(1, Math.ceil(all.length / this.per));
                if (this.page > pages) this.page = pages;
                return all.slice((this.page - 1) * this.per, this.page * this.per);
            },

            pages() { return Math.max(1, Math.ceil(this.rows().length / this.per)); },

            nums() {
                var pages = this.pages(), cur = this.page, out = [];
                for (var n = 1; n <= pages; n++) {
                    if (n === 1 || n === pages || Math.abs(n - cur) <= 1) out.push(n);
                    else if (out[out.length - 1] !== '…') out.push('…');
                }
                return out;
            },

            info() {
                var len = this.rows().length;
                if (!len) return tr(cfg, 'items0', '0 items');
                var start = (this.page - 1) * this.per + 1;
                return start + '–' + Math.min(len, start + this.per - 1) + ' ' + tr(cfg, 'of', 'of') + ' ' + len;
            },

            go(n) { this.page = Math.min(this.pages(), Math.max(1, n)); }
        };
    }

    /** "2 h ago" / "5 min ago" / "just now" (jak w prototypie). */
    function ago(datetime, now, i18n) {
        var t = Date.parse(String(datetime).replace(' ', 'T'));
        var n = Date.parse(String(now).replace(' ', 'T'));
        if (isNaN(t) || isNaN(n)) return String(datetime);
        var s = Math.max(0, Math.round((n - t) / 1000));
        var text = function (k, f) { return (i18n && i18n[k]) || f; };
        if (s < 60) return text('justNow', 'just now');
        if (s < 3600) return text('minAgo', '%n% min ago').replace('%n%', Math.round(s / 60));
        if (s < 86400) return text('hAgo', '%n% h ago').replace('%n%', Math.round(s / 3600));
        return String(datetime).slice(0, 10);
    }

    window.QIMt = { mapping: mapping, rules: rules, promotions: promotions, shipping: shipping, reports: reports, ago: ago, fmt: fmt };
})();

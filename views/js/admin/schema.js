/**
 * Quince Insight — ekran Structured data (komponent Alpine `qiSchema`).
 *
 * Zachowanie 1:1 z prototypem (`prototypy/js/structured.js`, `sdState`); skan, zapis i podglad
 * ida do serwera. Podglad liczy serwer tymi samymi klasami, ktore wypisuja JSON-LD na stronie.
 */
window.qiSchema = function (cfg) {
    'use strict';

    return {
        types: cfg.types,
        present: cfg.present || {},
        noForce: cfg.noForce || [],
        cfgT: cfg.t || {},
        // Bez skanu nie wiadomo, co wypisuje motyw — bez odznak "Missing in the theme".
        scanned: !!cfg.scanned,
        sd: {
            scanning: !!(cfg.job && cfg.job.active),
            preview: 'product',
            saved: true,
            on: Object.assign({}, cfg.on),
            force: Object.assign({}, cfg.force),
            json: '',
            skipped: [],
            loading: false,
        },

        init() {
            this.sdLoadPreview();
            if (cfg.autoScan) {
                this.sdScan();
            }
        },

        sdInTheme(t) {
            return !!this.present[t.key];
        },

        sdSourceLabel(t) {
            return this.present[t.key] === 'Theme' ? cfg.t.inTheme : cfg.t.onShop;
        },

        // Typ bez "Add anyway" (decyzja #18: Product variants przy Product z motywu / innego modulu).
        sdNoForce(t) {
            return this.noForce.indexOf(t.key) !== -1;
        },

        // Zablokowany typ mozna zawsze wylaczyc — blokada dotyczy tylko wlaczania.
        sdLocked(t) {
            if (!this.sdInTheme(t) || this.sd.on[t.key]) {
                return false;
            }

            return this.sdNoForce(t) || !this.sd.force[t.key];
        },

        sdToggle() {
            this.sd.saved = false;
            this.sdLoadPreview();
        },

        sdForce(t) {
            if (this.sdNoForce(t)) {
                return;
            }
            this.sd.force[t.key] = true;
            this.sd.saved = false;
        },

        sdOnCount() {
            return Object.values(this.sd.on).filter(Boolean).length;
        },

        sdDuplicates() {
            const names = this.types.filter((t) => t.key !== 'variants' && this.sdInTheme(t) && this.sd.on[t.key]).map((t) => t.name);
            // Warianty wlaczone przed skanem, ktory znalazl Product motywu — Google dostaje dwa opisy produktu.
            if (this.sd.on.variants && this.present.variants && names.indexOf('Product') === -1) {
                names.push('Product');
            }

            return names;
        },

        sdScan() {
            if (this.sd.scanning) {
                return;
            }
            this.sd.scanning = true;
            QI.post(cfg.urls.scan, {}).then((r) => {
                if (r.ok && r.job) {
                    QI.jobStart(r.job);
                } else {
                    this.sd.scanning = false;
                }
            });
        },

        sdSave() {
            QI.post(cfg.urls.save, { on: this.sd.on, force: this.sd.force }).then((r) => {
                if (r.ok) {
                    this.sd.saved = true;
                    QI.toast(r.message, 'success');
                }
            });
        },

        sdLoadPreview() {
            this.sd.loading = true;
            QI.post(cfg.urls.preview, { page: this.sd.preview, on: this.sd.on }, { quiet: true }).then((r) => {
                this.sd.loading = false;
                this.sd.json = r.ok ? r.json : '';
                this.sd.skipped = r.ok ? r.skipped : [];
            });
        },
    };
};

/* Koniec skanu — wywolywane przez komponent paska postepu (onDone). */
window.qiSchemaScanned = function (job) {
    'use strict';

    if (job && job.status === 'done') {
        window.location.reload();

        return;
    }

    window.dispatchEvent(new CustomEvent('qi-schema-stopped'));
};

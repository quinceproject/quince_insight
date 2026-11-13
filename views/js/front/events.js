/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 *
 * Zdarzenia z przegladarki: add_to_cart, remove_from_cart, select_item, add_shipping_info,
 * add_payment_info. Bez jQuery i bez selektorow motywu (research/03 §14):
 *  - koszyk: zdarzenie rdzenia `updateCart` i odpowiedz serwera (`resp.cart`) — roznica
 *    ilosci miedzy poprzednim a nowym koszykiem daje dodanie / usuniecie z prawdziwa iloscia;
 *  - klikniecie w liste: odnosnik, ktorego adres jest adresem produktu z listy (dane z PHP);
 *  - przewoznik: zdarzenie rdzenia `updatedDeliveryForm`; platnosc: pole `payment-option`
 *    (nazwa pola z rdzenia, `core.js`).
 * Dane produktow przychodza z PHP (`window.quinceInsight`, Storefront::build), nie z HTML.
 */
(function (w, d) {
    'use strict';

    function start() {
        var c = w.quinceInsight;
        if (!c || !c.on) {
            return;
        }

        var dl = w.dataLayer = w.dataLayer || [];
        var cart = {};
        var key;

        for (key in c.cart) {
            if (Object.prototype.hasOwnProperty.call(c.cart, key)) {
                cart[key] = c.cart[key];
            }
        }

        function push(name, ecommerce) {
            if (!c.on[name]) {
                return;
            }
            dl.push({ ecommerce: null });
            dl.push({ event: name, ecommerce: ecommerce });
            beacon(name, ecommerce);
        }

        // Dziennik zdarzen w panelu: zdarzenia z przegladarki nie przechodza przez serwer,
        // wiec skrypt zglasza je sam (tylko gdy dziennik jest wlaczony).
        function beacon(name, ecommerce) {
            if (!c.log || !c.log.on || !c.log.url || !w.navigator || !w.navigator.sendBeacon) {
                return;
            }
            try {
                var body = JSON.stringify({ t: c.log.token, e: name, p: w.location.pathname + w.location.search, d: { event: name, ecommerce: ecommerce } });
                w.navigator.sendBeacon(c.log.url, new Blob([body], { type: 'text/plain' }));
            } catch (e) {
                /* dziennik nie moze zepsuc strony */
            }
        }

        function round(n) {
            return Math.round(n * 100) / 100;
        }

        function num(v) {
            var n = typeof v === 'number' ? v : parseFloat(v);
            return isFinite(n) ? n : 0;
        }

        function itemId(p) {
            if (c.idField === 'reference' && p.reference) {
                return String(p.reference);
            }
            if (c.idField === 'ean' && p.ean13) {
                return String(p.ean13);
            }
            return String(p.id_product);
        }

        // Produkt z odpowiedzi koszyka: nazwy i kategorie z szablonu z PHP, cena z koszyka.
        function fromCart(p, quantity) {
            var k = 'p' + p.id_product + '-' + (p.id_product_attribute || 0);
            var base = c.templates[k] || c.templates['p' + p.id_product] || null;
            var item = {};
            var f;

            if (base) {
                for (f in base) {
                    if (Object.prototype.hasOwnProperty.call(base, f) && f !== 'index' && f !== 'item_list_id' && f !== 'item_list_name') {
                        item[f] = base[f];
                    }
                }
            } else {
                item.item_id = itemId(p);
                item.item_name = String(p.name || '');
                if (p.manufacturer_name) {
                    item.item_brand = String(p.manufacturer_name);
                }
                var cats = c.categories[String(p.id_category_default)] || null;
                if (cats) {
                    for (f in cats) {
                        if (Object.prototype.hasOwnProperty.call(cats, f)) {
                            item[f] = cats[f];
                        }
                    }
                }
            }

            item.item_id = itemId(p);
            if (p.attributes_small) {
                item.item_variant = String(p.attributes_small);
            }
            var incl = p.price_with_reduction !== undefined ? p.price_with_reduction : p.price_wt;
            var excl = p.price_with_reduction_without_tax !== undefined ? p.price_with_reduction_without_tax : p.price_tax_exc;
            var price = num(c.tax ? incl : excl);
            if (price > 0 || !base) {
                item.price = round(price);
            }
            item.quantity = quantity;
            return item;
        }

        function cartProducts(resp) {
            var list = resp && resp.cart && resp.cart.products;
            if (!list) {
                return null;
            }
            return Array.isArray(list) ? list : Object.keys(list).map(function (k) { return list[k]; });
        }

        function onCart(event) {
            var products = cartProducts(event && event.resp);
            if (!products) {
                return;
            }

            var next = {};
            var byKey = {};
            products.forEach(function (p) {
                var k = 'p' + p.id_product + '-' + (p.id_product_attribute || 0);
                next[k] = (next[k] || 0) + parseInt(p.cart_quantity || p.quantity || 0, 10);
                byKey[k] = p;
            });

            var added = [];
            var removed = [];
            var k;

            for (k in next) {
                if (Object.prototype.hasOwnProperty.call(next, k) && next[k] > (cart[k] || 0)) {
                    added.push(fromCart(byKey[k], next[k] - (cart[k] || 0)));
                }
            }
            for (k in cart) {
                if (Object.prototype.hasOwnProperty.call(cart, k) && (next[k] || 0) < cart[k]) {
                    var p = byKey[k] || removedProduct(k);
                    removed.push(fromCart(p, cart[k] - (next[k] || 0)));
                }
            }

            cart = next;

            if (added.length) {
                push('add_to_cart', { currency: c.currency, value: value(added), items: added });
            }
            if (removed.length) {
                push('remove_from_cart', { currency: c.currency, value: value(removed), items: removed });
            }
        }

        // Pozycja usunieta w calosci nie ma juz wiersza w odpowiedzi — dane z szablonu z PHP.
        function removedProduct(k) {
            var m = /^p(\d+)-(\d+)$/.exec(k);
            var t = c.templates[k] || {};
            return {
                id_product: m ? m[1] : '',
                id_product_attribute: m ? m[2] : 0,
                reference: c.idField === 'reference' ? t.item_id : '',
                ean13: c.idField === 'ean' ? t.item_id : '',
                name: t.item_name || '',
                price_with_reduction: t.price,
                price_with_reduction_without_tax: t.price
            };
        }

        function value(items) {
            var s = 0;
            items.forEach(function (i) {
                s += num(i.price) * (i.quantity || 1);
            });
            return round(s);
        }

        function cartItems() {
            var items = [];
            for (var k in cart) {
                if (Object.prototype.hasOwnProperty.call(cart, k) && c.templates[k]) {
                    var t = c.templates[k];
                    var item = {};
                    for (var f in t) {
                        if (Object.prototype.hasOwnProperty.call(t, f) && f !== 'index' && f !== 'item_list_id' && f !== 'item_list_name') {
                            item[f] = t[f];
                        }
                    }
                    item.quantity = cart[k];
                    items.push(item);
                }
            }
            return items;
        }

        var ps = w.prestashop;
        if (ps && typeof ps.on === 'function') {
            ps.on('updateCart', onCart);

            var lastCarrier = null;
            ps.on('updatedDeliveryForm', function (event) {
                var data = (event && event.dataForm) || [];
                var carrier = null;
                data.forEach(function (f) {
                    if (f && /^delivery_option\[/.test(f.name)) {
                        carrier = String(f.value).replace(/,$/, '');
                    }
                });
                if (carrier === null || carrier === lastCarrier) {
                    return;
                }
                lastCarrier = carrier;
                var items = cartItems();
                push('add_shipping_info', { currency: c.currency, value: value(items), shipping_tier: c.carriers[carrier] || carrier, items: items });
            });
        }

        d.addEventListener('change', function (event) {
            var el = event.target;
            if (!el || el.name !== 'payment-option' || !el.checked) {
                return;
            }
            var module = el.getAttribute('data-module-name') || '';
            var items = cartItems();
            push('add_payment_info', { currency: c.currency, value: value(items), payment_type: c.payments[module] || module, items: items });
        }, true);

        if (c.lists && c.lists.length) {
            var urls = {};
            c.lists.forEach(function (l) {
                urls[strip(l.url)] = l.item;
            });
            d.addEventListener('click', function (event) {
                var a = event.target && event.target.closest ? event.target.closest('a[href]') : null;
                if (!a) {
                    return;
                }
                var item = urls[strip(a.href)];
                if (!item) {
                    return;
                }
                push('select_item', { item_list_id: item.item_list_id, item_list_name: item.item_list_name, items: [item] });
            }, true);
        }

        function strip(url) {
            return String(url).split('#')[0].split('?')[0];
        }

        // Drugi kontener GTM w motywie lub innym module (kontrola na Events -> Overview).
        if (c.log && c.log.url && c.gtm && w.navigator && w.navigator.sendBeacon) {
            w.setTimeout(function () {
                try {
                    if (w.sessionStorage.getItem('qi_gtm_check')) {
                        return;
                    }
                    w.sessionStorage.setItem('qi_gtm_check', '1');
                    var other = Object.keys(w.google_tag_manager || {}).filter(function (id) {
                        return /^GTM-/.test(id) && id !== c.gtm;
                    });
                    w.navigator.sendBeacon(c.log.url, new Blob([JSON.stringify({ t: c.log.token, gtm: other })], { type: 'text/plain' }));
                } catch (e) {
                    /* bez znaczenia dla strony */
                }
            }, 4000);
        }
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window, document);

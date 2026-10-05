/* Samand Co. — storefront ↔ database bridge (vanilla JS).
   Loaded on demand by script.js. If PHP/MySQL is not installed every call
   resolves gracefully and the storefront keeps its built-in catalogue. */

(function () {
  "use strict";

  /* base URL of the storefront, e.g. "/SpiceKart" — resolved from where this script lives */
  var BASE = (function () {
    if (typeof document === "undefined") return "";
    var a = document.createElement("a");
    a.href = "assets/";
    var path = a.pathname.replace(/assets\/$/, "");
    return path.replace(/\/+$/, "");
  })();

  /* absolute base for callers that need a full URL (proxies, file:// testing) */
  var ORIGIN = (function () {
    if (typeof location === "undefined" || !location.origin) return "";
    return location.origin;
  })();

  function api(path) {
    return ORIGIN + BASE + "/api/" + path;
  }

  function getJSON(url) {
    return fetch(url, { headers: { Accept: "application/json" }, credentials: "same-origin" })
      .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); });
  }

  function postJSON(url, body) {
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); });
  }

  /* Local images shipped with the site, used when a remote image fails. */
  var LOCAL_FALLBACKS = {
    "SC-TUR-500": "assets/prod_turmeric.jpg",
    "SC-CHI-500": "assets/prod_chilli.jpg",
    "SC-COR-500": "assets/prod_coriander.jpg",
    "SC-GAR-200": "assets/prod_garam.jpg",
    "SC-PEP-200": "assets/prod_pepper.jpg",
    "SC-CAR-100": "assets/prod_cardamom.jpg"
  };

  var StoreDB = {
    enabled: false,
    products: null,
    cats: null,
    categories: [],
    config: { store_name: "Samand Co.", shipping_fee: 49, free_shipping_above: 999, tax_percent: 0, cod_enabled: true, online_payment_enabled: true },

    ready: null,

    load: function () {
      var self = this;

      this.ready = Promise.all([
        getJSON(api("products.php")).catch(function () { return { status: 0, data: {} }; }),
        getJSON(api("categories.php?with_config=1")).catch(function () { return { status: 0, data: {} }; })
      ]).then(function (res) {
        var p = res[0].data || {};
        var c = res[1].data || {};

        if (!p.ok || !Array.isArray(p.products) || !p.products.length) {
          self.enabled = false;
          return self;
        }

        self.products = p.products.map(function (row) {
          var remote = typeof row.img === "string" && row.img.indexOf("http") === 0;
          return {
            id: Number(row.id),
            name: row.name,
            category: row.category,
            slug: row.slug,
            sku: row.sku,
            pack: row.pack || (row.packs && row.packs[0]) || "",
            packs: row.packs || [],
            price: Number(row.price) || 0,
            old: row.old ? Number(row.old) : null,
            img: row.img || "",
            /* local fallback for <img onerror> — matches the assets shipped with the site */
            fb: remote ? LOCAL_FALLBACKS[row.sku] || "assets/prod_turmeric.jpg" : row.img,
            stock: Number(row.stock) || 0,
            rating: Number(row.rating) || 0,
            reviews: Number(row.reviews) || 0,
            desc: row.desc || "",
            ingredients: row.ingredients || "",
            usage: row.usage || ""
          };
        });

        if (Array.isArray(c.categories) && c.categories.length) {
          self.categories = c.categories;
          self.cats = c.categories.map(function (cat) { return [cat.image_url || "", cat.name]; });
        }
        if (c.config) {
          self.config = Object.assign({}, self.config, c.config);
        }

        self.enabled = true;
        return self;
      }).catch(function () {
        self.enabled = false;
        return self;
      });

      return this.ready;
    },

    coupon: function (code, subtotal) {
      if (!this.enabled) return Promise.resolve({ ok: false, fallback: true });
      return getJSON(api("coupon.php?code=" + encodeURIComponent(code) + "&subtotal=" + subtotal))
        .then(function (r) { return r.data; })
        .catch(function () { return { ok: false, fallback: true }; });
    },

    placeOrder: function (payload) {
      if (!this.enabled) return Promise.resolve({ ok: false, fallback: true });
      return postJSON(api("order.php"), payload)
        .then(function (r) { return r.data; })
        .catch(function () { return { ok: false, error: "Could not reach the server." }; });
    },

    orders: function (email) {
      if (!this.enabled || !email) return Promise.resolve(null);
      return getJSON(api("customer-orders.php?email=" + encodeURIComponent(email)))
        .then(function (r) { return r.data && r.data.ok ? r.data : null; })
        .catch(function () { return null; });
    },

    reviews: function (productId) {
      if (!this.enabled || !productId) return Promise.resolve(null);
      return getJSON(api("reviews.php?product_id=" + encodeURIComponent(productId)))
        .then(function (r) { return r.data && r.data.ok ? r.data.reviews : null; })
        .catch(function () { return null; });
    },

    submitReview: function (payload) {
      if (!this.enabled) return Promise.resolve({ ok: false, fallback: true });
      return postJSON(api("reviews.php"), payload)
        .then(function (r) { return r.data; })
        .catch(function () { return { ok: false, error: "Could not reach the server." }; });
    }
  };

  window.StoreDB = StoreDB;
})();
/* Samand Co. — admin JS (vanilla): sidebar, toasts, modals, confirm, canvas charts */
(function () {
  "use strict";

  /* ---------- sidebar (mobile) ---------- */
  document.addEventListener("click", function (ev) {
    var t = ev.target;
    if (t.closest(".menu-btn")) {
      document.querySelector(".sidebar")?.classList.toggle("open");
    } else if (t.closest(".sidebar a")) {
      document.querySelector(".sidebar")?.classList.remove("open");
    }
  });

  /* ---------- toasts ---------- */
  window.skToast = function (msg, type) {
    var box = document.getElementById("toast-box");
    if (!box) {
      box = document.createElement("div");
      box.id = "toast-box";
      document.body.appendChild(box);
    }
    var el = document.createElement("div");
    el.className = "toast" + (type ? " " + type : "");
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(function () {
      el.style.opacity = "0";
      el.style.transition = ".25s";
      setTimeout(function () { el.remove(); }, 250);
    }, 3200);
  };

  /* ---------- modal ---------- */
  window.skModal = function (id, open) {
    var el = document.getElementById(id);
    if (!el) return;
    if (open === false) { el.classList.remove("open"); return; }
    el.classList.add("open");
  };

  document.addEventListener("click", function (ev) {
    if (ev.target.classList?.contains("modal-back") || ev.target.closest("[data-close]")) {
      ev.target.closest(".modal-back")?.classList.remove("open");
    }
    var opener = ev.target.closest("[data-modal]");
    if (opener) {
      ev.preventDefault();
      window.skModal(opener.dataset.modal, true);
    }
  });

  document.addEventListener("keydown", function (ev) {
    if (ev.key === "Escape") {
      document.querySelectorAll(".modal-back.open").forEach(function (m) { m.classList.remove("open"); });
    }
  });

  /* ---------- confirm before destructive submits ---------- */
  document.addEventListener("submit", function (ev) {
    var f = ev.target;
    if (f.dataset.confirm && !window.confirm(f.dataset.confirm)) {
      ev.preventDefault();
    }
  });

  /* ---------- auto-slug from name ---------- */
  var nameInput = document.querySelector("[data-slug-source]");
  var slugInput = document.querySelector("[data-slug-target]");
  if (nameInput && slugInput) {
    nameInput.addEventListener("input", function () {
      if (slugInput.dataset.touched === "1") return;
      slugInput.value = nameInput.value.toLowerCase().trim()
        .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
    });
    slugInput.addEventListener("input", function () { slugInput.dataset.touched = "1"; });
  }

  /* ---------- pack sizes: "100g, 500g, 1kg" -> JSON array ---------- */
  var packInput = document.querySelector("[data-packs]");
  var packHidden = document.querySelector("[data-packs-json]");
  if (packInput && packHidden) {
    var sync = function () {
      packHidden.value = JSON.stringify(
        packInput.value.split(",").map(function (s) { return s.trim(); }).filter(Boolean)
      );
    };
    packInput.addEventListener("input", sync);
    sync();
  }

  /* ---------- image preview ---------- */
  document.querySelectorAll("[data-preview]").forEach(function (input) {
    input.addEventListener("change", function () {
      var img = document.getElementById(input.dataset.preview);
      if (img && input.files && input.files[0]) img.src = URL.createObjectURL(input.files[0]);
    });
  });

  /* ---------- quantity steppers ---------- */
  document.addEventListener("click", function (ev) {
    var btn = ev.target.closest("[data-step]");
    if (!btn) return;
    var input = document.getElementById(btn.dataset.target);
    if (!input) return;
    var step = parseInt(btn.dataset.step, 10);
    var min = parseInt(input.min || "0", 10);
    input.value = Math.max(min, (parseInt(input.value || "0", 10) || 0) + step);
    input.dispatchEvent(new Event("change", { bubbles: true }));
  });

  /* ---------- auto-submit filter forms ---------- */
  document.querySelectorAll("[data-autosubmit]").forEach(function (el) {
    el.addEventListener("change", function () { el.form?.submit(); });
  });

  /* =========================================================
     CHARTS — pure canvas, no libraries
     skChart.line(canvasId, labels, values, opts)
     skChart.bars(canvasId, labels, values, opts)
     ========================================================= */
  var Chart = {
    _prep: function (canvas) {
      var dpr = window.devicePixelRatio || 1;
      var rect = canvas.getBoundingClientRect();
      canvas.width = rect.width * dpr;
      canvas.height = rect.height * dpr;
      var ctx = canvas.getContext("2d");
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, rect.width, rect.height);
      return { ctx: ctx, w: rect.width, h: rect.height };
    },

    line: function (id, labels, values, opts) {
      opts = opts || {};
      var c = document.getElementById(id);
      if (!c) return;
      var p = Chart._prep(c), ctx = p.ctx, w = p.w, h = p.h;
      var pad = { l: 46, r: 14, t: 16, b: 30 };
      var cw = w - pad.l - pad.r, ch = h - pad.t - pad.b;
      var max = Math.max.apply(null, values.concat([1]));
      var nice = Math.ceil(max / 4) * 4 || 4;

      ctx.font = "11px Segoe UI, sans-serif";
      ctx.strokeStyle = "#efece6"; ctx.fillStyle = "#6f7568"; ctx.lineWidth = 1;
      for (var g = 0; g <= 4; g++) {
        var y = pad.t + ch - (ch * g / 4);
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(w - pad.r, y); ctx.stroke();
        ctx.fillText(Math.round(nice * g / 4), 6, y + 4);
      }

      var pts = values.map(function (v, i) {
        var x = pad.l + (values.length > 1 ? cw * i / (values.length - 1) : cw / 2);
        var y = pad.t + ch - (ch * (v / nice));
        return [x, y];
      });

      var grad = ctx.createLinearGradient(0, pad.t, 0, pad.t + ch);
      grad.addColorStop(0, "rgba(210,105,30,.30)");
      grad.addColorStop(1, "rgba(210,105,30,0)");
      ctx.beginPath(); ctx.moveTo(pts[0][0], pad.t + ch);
      pts.forEach(function (pt) { ctx.lineTo(pt[0], pt[1]); });
      ctx.lineTo(pts[pts.length - 1][0], pad.t + ch); ctx.closePath();
      ctx.fillStyle = grad; ctx.fill();

      ctx.beginPath();
      pts.forEach(function (pt, i) { i ? ctx.lineTo(pt[0], pt[1]) : ctx.moveTo(pt[0], pt[1]); });
      ctx.strokeStyle = opts.color || "#d2691e"; ctx.lineWidth = 2.5;
      ctx.lineJoin = "round"; ctx.stroke();

      pts.forEach(function (pt) {
        ctx.beginPath(); ctx.arc(pt[0], pt[1], 3.5, 0, Math.PI * 2);
        ctx.fillStyle = "#fff"; ctx.fill();
        ctx.strokeStyle = opts.color || "#d2691e"; ctx.lineWidth = 2; ctx.stroke();
      });

      ctx.fillStyle = "#6f7568"; ctx.textAlign = "center";
      labels.forEach(function (l, i) {
        var x = pad.l + (values.length > 1 ? cw * i / (values.length - 1) : cw / 2);
        ctx.fillText(String(l).slice(5) || String(l), x, h - 10);
      });
    },

    bars: function (id, labels, values, opts) {
      opts = opts || {};
      var c = document.getElementById(id);
      if (!c) return;
      var p = Chart._prep(c), ctx = p.ctx, w = p.w, h = p.h;
      var pad = { l: 42, r: 14, t: 14, b: 34 };
      var cw = w - pad.l - pad.r, ch = h - pad.t - pad.b;
      var max = Math.max.apply(null, values.concat([1]));
      var slot = cw / Math.max(1, values.length);
      var bw = Math.min(46, slot * 0.62);

      ctx.font = "11px Segoe UI, sans-serif";
      ctx.strokeStyle = "#efece6"; ctx.fillStyle = "#6f7568";
      for (var g = 0; g <= 4; g++) {
        var y = pad.t + ch - (ch * g / 4);
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(w - pad.r, y); ctx.stroke();
        ctx.fillText(Math.round(max * g / 4), 4, y + 4);
      }

      values.forEach(function (v, i) {
        var bh = (v / max) * ch;
        var x = pad.l + slot * i + (slot - bw) / 2;
        var y = pad.t + ch - bh;
        var grad = ctx.createLinearGradient(0, y, 0, y + bh);
        grad.addColorStop(0, opts.color || "#0d2c1b");
        grad.addColorStop(1, opts.color2 || "#2c6b45");
        ctx.fillStyle = grad;
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(x, y, bw, Math.max(2, bh), [5, 5, 0, 0]);
        else ctx.rect(x, y, bw, Math.max(2, bh));
        ctx.fill();
        ctx.fillStyle = "#6f7568"; ctx.textAlign = "center";
        ctx.fillText(String(labels[i]).slice(0, 12), x + bw / 2, h - 12);
      });
    },

    donut: function (id, labels, values, opts) {
      opts = opts || {};
      var c = document.getElementById(id);
      if (!c) return;
      var p = Chart._prep(c), ctx = p.ctx, w = p.w, h = p.h;
      var total = values.reduce(function (a, b) { return a + b; }, 0) || 1;
      var cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 16, ir = r * 0.6;
      var palette = opts.palette || ["#0d2c1b", "#d2691e", "#2c6b45", "#b8860b", "#2563eb", "#8a5a44"];
      var start = -Math.PI / 2;

      values.forEach(function (v, i) {
        var ang = (v / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, start, start + ang);
        ctx.closePath();
        ctx.fillStyle = palette[i % palette.length];
        ctx.fill();
        start += ang;
      });

      ctx.globalCompositeOperation = "destination-out";
      ctx.beginPath(); ctx.arc(cx, cy, ir, 0, Math.PI * 2); ctx.fill();
      ctx.globalCompositeOperation = "source-over";

      ctx.fillStyle = "#22261f"; ctx.textAlign = "center";
      ctx.font = "bold 17px Segoe UI, sans-serif";
      ctx.fillText(String(total), cx, cy + 3);
      ctx.font = "10px Segoe UI, sans-serif"; ctx.fillStyle = "#6f7568";
      ctx.fillText(opts.label || "Total", cx, cy + 18);

      var ly = 12;
      ctx.textAlign = "left";
      labels.forEach(function (l, i) {
        ctx.fillStyle = palette[i % palette.length];
        ctx.fillRect(w - 128, ly + 3, 9, 9);
        ctx.fillStyle = "#4a5147";
        ctx.fillText(l + " (" + values[i] + ")", w - 114, ly + 11);
        ly += 17;
      });
    }
  };
  window.skChart = Chart;

  var resizeTimer;
  window.addEventListener("resize", function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      document.querySelectorAll("canvas[data-chart]").forEach(function (c) {
        window.dispatchEvent(new CustomEvent("sk:redraw", { detail: { id: c.id } }));
      });
    }, 160);
  });
})();
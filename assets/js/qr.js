// Renders QR codes for any element with data-qr="CODE" [data-size="px"]; supports PNG download buttons.
// Loads the QR library itself from several CDNs (first one that works), so one blocked CDN does not break QR codes.
(function () {
    'use strict';
    var SOURCES = [
      'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
      'https://cdn.jsdelivr.net/gh/davidshimjs/qrcodejs@master/qrcode.min.js',
      'https://unpkg.com/qrcodejs@1.0.0/qrcode.min.js'
    ];
    function load(i, done) {
      if (typeof QRCode !== 'undefined') return done(true);
      if (i >= SOURCES.length) return done(false);
      var s = document.createElement('script');
      s.src = SOURCES[i];
      s.onload = function () { typeof QRCode !== 'undefined' ? done(true) : load(i + 1, done); };
      s.onerror = function () { load(i + 1, done); };
      document.head.appendChild(s);
    }
    function render() {
      document.querySelectorAll('[data-qr]').forEach(function (el) {
        if (el.dataset.rendered) return;
        el.dataset.rendered = '1';
        var size = parseInt(el.dataset.size || '120', 10);
        new QRCode(el, { text: el.dataset.qr, width: size, height: size, correctLevel: QRCode.CorrectLevel.M });
      });
      document.querySelectorAll('[data-qr-download]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var box = document.querySelector(btn.dataset.qrDownload); if (!box) return;
          var c = box.querySelector('canvas'), img = box.querySelector('img');
          var href = c ? c.toDataURL('image/png') : (img ? img.src : null); if (!href) return;
          var a = document.createElement('a');
          a.href = href; a.download = (btn.dataset.name || 'qr-code') + '.png'; a.click();
        });
      });
    }
    function fail() {
      document.querySelectorAll('[data-qr]').forEach(function (el) {
        el.innerHTML = '<div class="small text-danger p-2" style="max-width:' + (el.dataset.size || 120) + 'px">QR image could not load. Check the internet connection and reload.</div>';
      });
    }
    document.addEventListener('DOMContentLoaded', function () { load(0, function (ok) { ok ? render() : fail(); }); });
  })();
// Camera QR scanner + API helper. Requires html5-qrcode (loaded before this file).
(function (window) {
    'use strict';
    var meta = document.querySelector('meta[name="csrf-token"]');
    var SL = window.SL = {
      esc: function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      },
      money: function (n) { return Number(n).toFixed(2); },
      api: function (action, data) {
        return fetch(window.APP.baseUrl + '/librarian/api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': meta ? meta.content : '' },
          body: JSON.stringify(Object.assign({ action: action }, data || {}))
        }).then(function (r) { return r.json(); })
          .catch(function () { return { ok: false, error: 'Network error. Check your connection.' }; });
      },
      status: function () {}
    };
  
    /** Wires the scanner card (ids: reader, scanStatus, btnStart, btnStop, btnSwitch, manualForm, manualCode). */
    SL.bindScanner = function (onScan) {
      var el = function (id) { return document.getElementById(id); };
      var qr = null, cams = [], idx = -1, running = false, busy = false, last = '', lastAt = 0;
  
      function status(msg, type) {
        var s = el('scanStatus');
        s.className = 'alert alert-' + (type || 'info') + ' py-2 small mt-2 mb-2';
        s.textContent = msg;
      }
      SL.status = status;
  
      function toggle() {
        el('btnStart').disabled = running; el('btnStop').disabled = !running; el('btnSwitch').disabled = !running;
      }
  
      async function run(text) {
        if (busy) return;
        busy = true;
        try { if (running) qr.pause(true); } catch (e) {}
        try { await onScan(text); } catch (e) { status(e.message || 'Error', 'danger'); }
        setTimeout(function () { busy = false; try { if (running) qr.resume(); } catch (e) {} }, 1000);
      }
      function handle(text) {
        var now = Date.now();
        if (busy || (text === last && now - lastAt < 3000)) return;   // ignore duplicate reads of the same code
        last = text; lastAt = now;
        if (navigator.vibrate) navigator.vibrate(60);
        run(text.trim());
      }
  
      async function begin(camera) {
        qr = qr || new Html5Qrcode('reader');
        await qr.start(camera, {
          fps: 10,
          qrbox: function (vw, vh) { var s = Math.floor(Math.min(vw, vh) * 0.7); return { width: s, height: s }; }
        }, handle, function () {});
        running = true; toggle();
        status('Camera ready. Point it at a QR code.', 'info');
      }
      async function start() {
        if (running) return;
        if (!window.isSecureContext) { status('The camera needs HTTPS or localhost. Use manual entry below.', 'warning'); return; }
        status('Starting camera…', 'info');
        try {
          await begin({ facingMode: 'environment' });           // rear camera when available
          try { cams = await Html5Qrcode.getCameras(); } catch (e) {}
        } catch (e) { status('Cannot open the camera (permission denied or none found). Use manual entry below.', 'danger'); }
      }
      async function stop(quiet) {
        if (!qr || !running) return;
        try { await qr.stop(); qr.clear(); } catch (e) {}
        running = false; toggle();
        if (!quiet) status('Camera stopped.', 'secondary');
      }
      async function switchCam() {
        if (cams.length < 2) { status('Only one camera was found.', 'warning'); return; }
        idx = (idx + 1) % cams.length;
        await stop(true);
        try { await begin(cams[idx].id); } catch (e) { status('Could not switch camera.', 'danger'); }
      }
  
      el('btnStart').addEventListener('click', start);
      el('btnStop').addEventListener('click', function () { stop(false); });
      el('btnSwitch').addEventListener('click', switchCam);
      el('manualForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var v = el('manualCode').value.trim();
        if (v) { run(v); el('manualCode').value = ''; }
      });
      window.addEventListener('pagehide', function () { stop(true); });   // always release the camera
      document.addEventListener('visibilitychange', function () { if (document.hidden) stop(true); });
      toggle();
      return { stop: stop };
    };
  })(window);
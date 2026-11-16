<?php // Shared camera + manual-entry card. Expects optional $manualHint. ?>
<div class="card p-3">
  <div id="reader" class="sl-reader"></div>
  <div id="scanStatus" class="alert alert-info py-2 small mt-2 mb-2">Press “Start camera” to begin scanning.</div>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <button type="button" id="btnStart" class="btn btn-primary flex-fill"><i class="bi bi-camera-video"></i> Start camera</button>
    <button type="button" id="btnStop" class="btn btn-outline-secondary" disabled><i class="bi bi-stop-circle"></i></button>
    <button type="button" id="btnSwitch" class="btn btn-outline-secondary" disabled title="Switch camera"><i class="bi bi-arrow-repeat"></i></button>
  </div>
  <form id="manualForm" class="input-group" autocomplete="off">
    <input id="manualCode" class="form-control" placeholder="<?= e($manualHint ?? 'Manual entry: QR code, accession no. or student ID') ?>" autocapitalize="none">
    <button class="btn btn-outline-primary" type="submit">Go</button>
  </form>
</div>
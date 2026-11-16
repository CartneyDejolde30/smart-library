<?php $bare = $bare ?? false; $user = current_user(); ?>
<?php if (!$bare && $user): ?>
    </main>
  </div>
<?php endif; ?>

<?php $flashes = get_flashes(); ?>
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1100">
  <?php foreach ($flashes as $f):
    $t = in_array($f['type'], ['success','danger','warning','info'], true) ? $f['type'] : 'info'; ?>
    <div class="toast align-items-center text-bg-<?= $t ?> border-0" role="alert" data-bs-delay="5000">
      <div class="d-flex"><div class="toast-body"><?= e($f['msg']) ?></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-body text-center p-4">
        <i class="bi bi-exclamation-triangle text-warning display-5"></i>
        <h5 class="mt-2">Are you sure?</h5>
        <p class="text-muted mb-4" id="confirmText">This action cannot be undone.</p>
        <button type="button" class="btn btn-light me-2 px-4" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger px-4" id="confirmYes">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>window.APP = { baseUrl: <?= json_encode(BASE_URL) ?> };</script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php foreach (($extraScripts ?? []) as $src): ?><script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
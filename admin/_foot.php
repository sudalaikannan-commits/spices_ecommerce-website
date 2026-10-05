<?php
declare(strict_types=1); ?>
    </main>
  </div>
</div>

<div class="modal-back" id="modal"><div class="modal">
  <div class="modal-head"><h3 id="modal-title">Confirm</h3><button class="x-btn" type="button" data-close>&times;</button></div>
  <div class="modal-body" id="modal-body"></div>
  <div class="modal-foot"><button class="btn" type="button" data-close>Close</button></div>
</div></div>

<div id="toast-box"></div>
<script src="<?= e(admin_url('assets/admin.js')) ?>"></script>
<?php if (!empty($pageScript)): ?>
  <script><?= $pageScript ?></script>
<?php endif; ?>
</body>
</html>
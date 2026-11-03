<?php if (!isset($_SESSION)) { $_SESSION = []; } ?>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token($_SESSION), ENT_QUOTES, 'UTF-8') ?>">

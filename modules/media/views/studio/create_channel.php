<?php
declare(strict_types=1);
/** @var string $title */
/** @var string $description */
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<div class="media-shell">
  <div class="media-upload-box">
    <h1 style="margin-top:0">Create channel</h1>
    <form method="post" action="<?= $e(url('media/studio/create')) ?>">
      <?= $this->request->csrfField() ?>
      <label for="title">Title</label>
      <input id="title" type="text" name="title" required maxlength="255" value="<?= $e($title) ?>">

      <label for="description">Description</label>
      <textarea id="description" name="description" rows="4"><?= $e($description) ?></textarea>

      <div style="display:flex;gap:.5rem;justify-content:flex-end">
        <a class="media-btn is-ghost" href="<?= $e(url('media/studio')) ?>">Cancel</a>
        <button class="media-btn is-accent" type="submit">Create</button>
      </div>
    </form>
  </div>
</div>

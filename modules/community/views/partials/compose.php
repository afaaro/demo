<?php
declare(strict_types=1);
/** @var string $csrf */
/** @var string $defaultVisibility */
/** @var string $placeholder */
$e = $e ?? static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$defaultVisibility = in_array(($defaultVisibility ?? 'friends'), ['friends', 'public'], true)
    ? $defaultVisibility
    : 'friends';
$placeholder = (string) ($placeholder ?? "What's on your mind?");
?>
<form method="post"
      action="<?= $e(url('community/post/create')) ?>"
      enctype="multipart/form-data"
      class="cm-compose__form"
      data-cm-compose>
  <input type="hidden" name="_csrf_token" value="<?= $e($csrf) ?>">
  <textarea name="body" rows="3" maxlength="5000" placeholder="<?= $e($placeholder) ?>" data-cm-body></textarea>
  <div class="cm-preview" data-cm-preview hidden>
    <img src="" alt="" data-cm-preview-img>
    <button type="button" class="cm-preview__clear" data-cm-preview-clear aria-label="Remove photo">×</button>
  </div>
  <div class="cm-compose__row">
    <label class="cm-file">
      <span>Photo</span>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" data-cm-photo>
    </label>
    <div class="cm-vis" role="group" aria-label="Visibility">
      <input type="hidden" name="visibility" value="<?= $e($defaultVisibility) ?>" data-cm-visibility>
      <button type="button" class="cm-vis__btn <?= $defaultVisibility === 'friends' ? 'is-on' : '' ?>" data-cm-vis="friends">Friends</button>
      <button type="button" class="cm-vis__btn <?= $defaultVisibility === 'public' ? 'is-on' : '' ?>" data-cm-vis="public">Public</button>
    </div>
    <button type="submit" class="cm-btn is-accent" data-cm-submit disabled>Post</button>
  </div>
</form>

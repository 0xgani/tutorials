<?php
/**
 * @var string $email  what the visitor typed (the password is never shown again)
 * @var ?string $error
 * @var string $next   where to go after logging in; already checked to be a local path
 */
?>
<?php if ($error !== null): ?>
    <p class="error"><?= e($error) ?></p>
<?php endif; ?>
<form method="post" action="/login" class="stacked">
    <?= csrfField() ?>
    <input type="hidden" name="next" value="<?= e($next) ?>">
    <label>E-mail address
        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required>
    </label>
    <label>Password
        <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button>Log in</button>
</form>

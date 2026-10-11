<?php
/**
 * @var array<string, string> $values  what the visitor typed, to show again after an error
 * @var array<string, string> $errors  messages by field name
 */
$field = function (string $name, string $label, string $type = 'text') use ($values, $errors): string {
    $error = isset($errors[$name]) ? '<span class="error">' . e($errors[$name]) . '</span>' : '';

    return sprintf(
        '<label>%s <input type="%s" name="%s" value="%s"></label>%s',
        e($label), e($type), e($name), e($values[$name] ?? ''), $error,
    );
};
?>
<p>Missing a book? Tell us, and we'll consider buying it.</p>
<form method="post" action="/suggest" class="stacked">
    <?= $csrfField() ?>
    <?= $field('title', 'Title') ?>
    <?= $field('author', 'Author') ?>
    <?= $field('isbn', 'ISBN-13 (optional)') ?>
    <label>Why should we buy it?
        <textarea name="reason" rows="4"><?= e($values['reason'] ?? '') ?></textarea>
    </label>
    <button>Send suggestion</button>
</form>

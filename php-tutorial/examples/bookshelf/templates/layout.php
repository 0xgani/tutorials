<?php
/**
 * @var string $title
 * @var ?Bookshelf\Domain\Member $member  the logged-in member
 * @var ?string $flash
 * @var string $content  already-escaped HTML from the page template
 */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Bookshelf</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
<header>
    <a class="brand" href="/">Bookshelf</a>
    <nav>
        <a href="/">Catalog</a>
        <a href="/suggest">Suggest a book</a>
        <?php if ($member === null): ?>
            <a href="/login">Log in</a>
        <?php else: ?>
            <a href="/loans">My loans</a>
            <?php if ($member->isStaff()): ?>
                <a href="/suggestions">Suggestions</a>
            <?php endif; ?>
            <form method="post" action="/logout" class="inline">
                <?= $csrfField() ?>
                <button class="link">Log out <?= e($member->name) ?></button>
            </form>
        <?php endif; ?>
    </nav>
</header>
<main>
    <?php if ($flash !== null): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endif; ?>
    <h1><?= e($title) ?></h1>
    <?= $content ?>
</main>
</body>
</html>

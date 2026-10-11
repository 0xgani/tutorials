<?php
/**
 * @var Bookshelf\Domain\Book $book
 */
?>
<dl class="details">
    <dt>Author</dt><dd><?= e($book->author) ?></dd>
    <dt>ISBN</dt><dd><?= e($book->isbn->format()) ?></dd>
    <dt>Genre</dt><dd><?= e($book->genre?->label() ?? '–') ?></dd>
    <dt>Published</dt><dd><?= e($book->year ?? '–') ?></dd>
    <dt>Available</dt><dd><?= e($book->available) ?> of <?= e($book->copies) ?></dd>
</dl>
<p><a href="/">Back to the catalog</a></p>

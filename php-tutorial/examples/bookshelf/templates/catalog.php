<?php
use Bookshelf\Domain\Genre;

/**
 * @var list<Bookshelf\Domain\Book> $books
 * @var string $query
 * @var ?Genre $genre
 */
?>
<form method="get" action="/" class="search">
    <input type="search" name="q" value="<?= e($query) ?>" placeholder="Title or author" aria-label="Search">
    <select name="genre" aria-label="Genre">
        <option value="">All genres</option>
        <?php foreach (Genre::cases() as $case): ?>
            <option value="<?= e($case->value) ?>" <?= $case === $genre ? 'selected' : '' ?>><?= e($case->label()) ?></option>
        <?php endforeach; ?>
    </select>
    <button>Search</button>
</form>

<?php if ($books === []): ?>
    <p>No books match your search.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Title</th><th>Author</th><th>Genre</th><th>Year</th><th>Available</th></tr>
        </thead>
        <tbody>
        <?php foreach ($books as $book): ?>
            <tr>
                <td><?= e($book->title) ?></td>
                <td><?= e($book->author) ?></td>
                <td><?= e($book->genre?->label()) ?></td>
                <td><?= e($book->year) ?></td>
                <td><?= e($book->available) ?> of <?= e($book->copies) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

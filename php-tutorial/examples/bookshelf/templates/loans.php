<?php
/**
 * @var list<Bookshelf\Domain\Loan> $loans
 * @var array<int, Bookshelf\Domain\Book> $books  by id
 * @var DateTimeImmutable $today
 */
?>
<?php if ($loans === []): ?>
    <p>You have no books on loan.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Title</th><th>Due</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($loans as $loan): ?>
            <tr>
                <td><?= e($books[$loan->bookId]->title ?? 'Unknown book') ?></td>
                <td><?= e($loan->dueOn->format('D j M Y')) ?></td>
                <td><?= e($loan->status($today)->label()) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

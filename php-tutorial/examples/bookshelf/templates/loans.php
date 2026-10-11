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
        <tr><th>Title</th><th>Due</th><th>Status</th><th>Fine so far</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($loans as $loan): ?>
            <tr>
                <td><?= e($books[$loan->bookId]->title ?? 'Unknown book') ?></td>
                <td><?= e($loan->dueOn->format('D j M Y')) ?></td>
                <td><?= e($loan->status($today)->label()) ?></td>
                <td><?= e($loan->fine($today)->isZero() ? '–' : $loan->fine($today)->format()) ?></td>
                <td>
                    <?php if ($loan->canBeRenewed($today)): ?>
                        <form method="post" action="/loans/<?= e($loan->id) ?>/renew" class="inline">
                            <?= $csrfField() ?>
                            <button>Renew</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

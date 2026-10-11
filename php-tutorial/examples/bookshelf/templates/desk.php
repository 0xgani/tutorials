<?php
/**
 * @var string $email                                    the member being served, as typed
 * @var ?Bookshelf\Domain\Member $borrower               that member, if found
 * @var list<Bookshelf\Domain\Loan> $loans               the borrower's open loans
 * @var array<int, Bookshelf\Domain\Book> $books         their books, by id
 * @var string $isbn                                     the ISBN typed into the lending form, after an error
 * @var ?string $error
 * @var list<Bookshelf\Lending\OverdueLoan> $overdue     every late loan in the library
 * @var DateTimeImmutable $today
 */
?>
<form method="get" action="/desk" class="search">
    <input type="email" name="member" value="<?= e($email) ?>" placeholder="Member's e-mail address"
           aria-label="Member's e-mail address" required>
    <button>Find member</button>
</form>

<?php if ($error !== null): ?>
    <p class="error"><?= e($error) ?></p>
<?php endif; ?>

<?php if ($borrower !== null): ?>
    <h2><?= e($borrower->name) ?></h2>
    <p>
        <?= e($borrower->type->label()) ?> member · <?= e(count($loans)) ?> of <?= e($borrower->type->maxLoans()) ?> loans
        · <?= e($borrower->type->loanDays()) ?> days per loan
    </p>

    <form method="post" action="/desk/loans" class="search">
        <?= $csrfField() ?>
        <input type="hidden" name="member" value="<?= e($borrower->email) ?>">
        <input name="isbn" value="<?= e($isbn) ?>" placeholder="ISBN of the book" aria-label="ISBN" required autofocus>
        <button>Lend</button>
    </form>

    <?php if ($loans !== []): ?>
        <table>
            <thead>
            <tr><th>Title</th><th>Lent</th><th>Due</th><th>Status</th><th>Fine so far</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($loans as $loan): ?>
                <tr>
                    <td><?= e($books[$loan->bookId]->title ?? 'Unknown book') ?></td>
                    <td><?= e($loan->lentOn->format('j M Y')) ?></td>
                    <td><?= e($loan->dueOn->format('j M Y')) ?></td>
                    <td><?= e($loan->status($today)->label()) ?></td>
                    <td><?= e($loan->fine($today)->format()) ?></td>
                    <td>
                        <form method="post" action="/desk/loans/<?= e($loan->id) ?>/return" class="inline">
                            <?= $csrfField() ?>
                            <button>Take back</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>

<h2>Overdue loans</h2>
<?php if ($overdue === []): ?>
    <p>Nothing is overdue.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Title</th><th>Member</th><th>Due</th><th>Days late</th><th>Fine so far</th></tr>
        </thead>
        <tbody>
        <?php foreach ($overdue as $late): ?>
            <tr>
                <td><?= e($late->title) ?></td>
                <td><a href="/desk?member=<?= e(rawurlencode($late->memberEmail)) ?>"><?= e($late->memberName) ?></a></td>
                <td><?= e($late->dueOn->format('j M Y')) ?></td>
                <td><?= e($late->daysOverdue) ?></td>
                <td><?= e($late->fine->format()) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

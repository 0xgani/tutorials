<?php
/**
 * @var list<array{created_at: string, title: string, author: string, isbn: ?string, reason: string}> $suggestions
 *
 * Everything here was typed by visitors, so every value is escaped: a "title" such as
 * <script>...</script> must show up as text, not run in a librarian's browser.
 */
?>
<?php if ($suggestions === []): ?>
    <p>No suggestions yet.</p>
<?php else: ?>
    <table>
        <thead>
        <tr><th>Received (UTC)</th><th>Title</th><th>Author</th><th>ISBN</th><th>Reason</th></tr>
        </thead>
        <tbody>
        <?php foreach ($suggestions as $suggestion): ?>
            <tr>
                <td><?= e($suggestion['created_at']) ?></td>
                <td><?= e($suggestion['title']) ?></td>
                <td><?= e($suggestion['author']) ?></td>
                <td><?= e($suggestion['isbn']) ?></td>
                <td><?= e($suggestion['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

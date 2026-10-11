<?php

declare(strict_types=1);

namespace Bookshelf\Web;

use Bookshelf\Domain\Member;
use Bookshelf\Http\Response;
use RuntimeException;

/**
 * Renders PHP templates from the templates/ directory, and whole pages inside the layout.
 */
final class View
{
    private ?Member $member = null;

    public function __construct(
        private readonly string $directory,
        private readonly Session $session,
    ) {
    }

    /** The logged-in member, shown in the layout. Set by the authentication middleware. */
    public function setMember(?Member $member): void
    {
        $this->member = $member;
    }

    /** @param array<string, mixed> $variables */
    public function page(string $title, string $template, array $variables = [], int $status = 200): Response
    {
        $content = $this->render($template, $variables);

        return Response::html($this->render('layout', [
            'title' => $title,
            'member' => $this->member,
            'flash' => $this->session->takeFlash(),
            'content' => $content,
        ]), $status);
    }

    /**
     * Runs a template with the given variables and returns the HTML it printed. Every template can also call
     * $csrfField(), which prints the hidden CSRF field for a form (and starts the session only when used).
     *
     * @param array<string, mixed> $variables
     */
    public function render(string $template, array $variables = []): string
    {
        $file = "{$this->directory}/$template.php";
        if (!is_file($file)) {
            throw new RuntimeException("No template $template");
        }
        $variables['csrfField'] = fn (): string
            => '<input type="hidden" name="_csrf" value="' . e($this->session->csrfToken()) . "\">\n";

        return (static function (string $__file, array $__variables): string {
            extract($__variables);
            ob_start();
            try {
                require $__file;

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        })($file, $variables);
    }
}

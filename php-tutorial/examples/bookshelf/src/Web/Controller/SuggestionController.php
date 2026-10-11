<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Domain\Isbn;
use Bookshelf\Http\Access;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use Bookshelf\Persistence\SuggestionStore;
use Bookshelf\Web\Session;
use Bookshelf\Web\View;

final readonly class SuggestionController
{
    public function __construct(
        private SuggestionStore $suggestions,
        private View $view,
        private Session $session,
    ) {
    }

    #[Route('GET', '/suggest')]
    public function form(): Response
    {
        return $this->view->page('Suggest a book', 'suggest', ['values' => [], 'errors' => []]);
    }

    #[Route('POST', '/suggest')]
    public function submit(Request $request): Response
    {
        $values = [
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'isbn' => $request->input('isbn', 20),
            'reason' => $request->input('reason', 1000),
        ];

        $errors = [];
        if ($values['title'] === '') {
            $errors['title'] = 'Please enter the title.';
        }
        if ($values['author'] === '') {
            $errors['author'] = 'Please enter the author.';
        }
        $isbn = preg_replace('/[\s-]+/', '', $values['isbn']) ?? '';
        if ($isbn !== '' && !Isbn::isValid($isbn)) {
            $errors['isbn'] = 'That is not a valid ISBN-13. Leave it empty if you are unsure.';
        }

        if ($errors !== []) {
            return $this->view->page('Suggest a book', 'suggest', ['values' => $values, 'errors' => $errors], 422);
        }

        $this->suggestions->add($values['title'], $values['author'], $isbn === '' ? null : $isbn, $values['reason']);
        $this->session->flash("Thank you! We'll consider \"{$values['title']}\".");

        return Response::redirect('/');
    }

    #[Route('GET', '/suggestions', Access::Staff)]
    public function list(): Response
    {
        return $this->view->page('Suggestions', 'suggestions', ['suggestions' => $this->suggestions->latest()]);
    }
}

<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Domain\Book;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Genre;
use Bookshelf\Http\HttpException;
use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use Bookshelf\Web\View;

final readonly class CatalogController
{
    public function __construct(
        private BookRepository $books,
        private View $view,
    ) {
    }

    #[Route('GET', '/')]
    public function index(Request $request): Response
    {
        $query = $request->query('q');
        $genre = Genre::tryFrom($request->query('genre'));

        return $this->view->page('Catalog', 'catalog', [
            'books' => $this->books->search($query, $genre),
            'query' => $query,
            'genre' => $genre,
        ]);
    }

    #[Route('GET', '/books/{id:\d+}')]
    public function show(int $id): Response
    {
        $book = $this->find($id);

        return $this->view->page($book->title, 'book', ['book' => $book]);
    }

    #[Route('GET', '/api/books')]
    public function apiIndex(Request $request): Response
    {
        $genreParameter = $request->query('genre');
        $genre = Genre::tryFrom($genreParameter);
        if ($genreParameter !== '' && $genre === null) {
            throw new HttpException(400, "Unknown genre '$genreParameter'");
        }

        $books = $this->books->search($request->query('q'), $genre);

        return Response::json(['count' => count($books), 'data' => array_map(self::toJson(...), $books)]);
    }

    #[Route('GET', '/api/books/{id:\d+}')]
    public function apiShow(int $id): Response
    {
        return Response::json(['data' => self::toJson($this->find($id))]);
    }

    private function find(int $id): Book
    {
        try {
            return $this->books->get($id);
        } catch (EntityNotFound $e) {
            throw new HttpException(404, "There is no book with id $id.", previous: $e);
        }
    }

    /** @return array<string, mixed> */
    private static function toJson(Book $book): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'genre' => $book->genre,
            'year' => $book->year,
            'available' => $book->available,
        ];
    }
}

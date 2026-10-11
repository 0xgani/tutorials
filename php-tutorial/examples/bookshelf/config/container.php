<?php

declare(strict_types=1);

/*
 * The service wiring, shared by the web front controller and the command-line tool.
 * Classes whose constructors only need other classes are built automatically; this file lists the rest:
 * which implementation an interface means, and services that need settings.
 */

use Bookshelf\Container\Container;
use Bookshelf\Domain\BookRepository;
use Bookshelf\Domain\LoanRepository;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Http\Router;
use Bookshelf\Persistence\Database;
use Bookshelf\Persistence\Migrator;
use Bookshelf\Persistence\PdoBookRepository;
use Bookshelf\Persistence\PdoLoanRepository;
use Bookshelf\Persistence\PdoMemberRepository;
use Bookshelf\Support\Clock;
use Bookshelf\Support\SystemClock;
use Bookshelf\Web\Controller;
use Bookshelf\Web\Dispatcher;
use Bookshelf\Web\Kernel;
use Bookshelf\Web\Middleware;
use Bookshelf\Web\Session;
use Bookshelf\Web\View;

$root = dirname(__DIR__);
$settings = [
    'debug' => getenv('BOOKSHELF_DEBUG') === '1',
    'timezone' => getenv('BOOKSHELF_TIMEZONE') ?: 'Europe/Berlin',
    // Behind a proxy that terminates TLS, PHP sees plain HTTP; BOOKSHELF_HTTPS=1 says the site is HTTPS anyway.
    'https' => getenv('BOOKSHELF_HTTPS') === '1' || ($_SERVER['HTTPS'] ?? 'off') !== 'off',
];

$container = new Container();

// Settings and infrastructure
$container->set(Database::class, fn () => Database::fromEnvironment($root));
$container->set(PDO::class, fn (Container $c) => $c->get(Database::class)->pdo);
$container->set(DateTimeZone::class, fn () => new DateTimeZone($settings['timezone']));   // the library's time zone
$container->set(Clock::class, fn (Container $c) => new SystemClock($c->get(DateTimeZone::class)));
$container->set(Migrator::class, fn (Container $c) => new Migrator($c->get(Database::class), "$root/migrations"));

// Interfaces and the implementations they mean
$container->set(BookRepository::class, fn (Container $c) => $c->get(PdoBookRepository::class));
$container->set(MemberRepository::class, fn (Container $c) => $c->get(PdoMemberRepository::class));
$container->set(LoanRepository::class, fn (Container $c) => $c->get(PdoLoanRepository::class));

// The web application
$container->set(Session::class, fn () => new Session(secureCookie: $settings['https']));
$container->set(View::class, fn (Container $c) => new View("$root/templates", $c->get(Session::class)));

$container->set(Router::class, function (): Router {
    $router = new Router();
    $router->addController(Controller\CatalogController::class);
    $router->addController(Controller\SuggestionController::class);
    $router->addController(Controller\AuthController::class);
    $router->addController(Controller\LoanController::class);
    $router->addController(Controller\HealthController::class);

    return $router;
});

$container->set(Kernel::class, fn (Container $c) => new Kernel(
    [
        new Middleware\SecurityHeadersMiddleware($settings['https']),
        new Middleware\ErrorHandlerMiddleware($c->get(View::class), $settings['debug']),
        $c->get(Middleware\AuthenticationMiddleware::class),
        $c->get(Middleware\CsrfMiddleware::class),
    ],
    new Dispatcher($c->get(Router::class), $c),
));

return $container;

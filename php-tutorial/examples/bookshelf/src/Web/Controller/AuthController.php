<?php

declare(strict_types=1);

namespace Bookshelf\Web\Controller;

use Bookshelf\Http\Request;
use Bookshelf\Http\Response;
use Bookshelf\Http\Route;
use Bookshelf\Security\AuthenticationFailed;
use Bookshelf\Security\Authenticator;
use Bookshelf\Support\Clock;
use Bookshelf\Web\Session;
use Bookshelf\Web\View;

final readonly class AuthController
{
    public function __construct(
        private Authenticator $authenticator,
        private Session $session,
        private View $view,
        private Clock $clock,
    ) {
    }

    #[Route('GET', '/login')]
    public function form(Request $request): Response
    {
        return $this->view->page('Log in', 'login', [
            'email' => '',
            'error' => null,
            'next' => self::localPath($request->query('next', 2000)),
        ]);
    }

    #[Route('POST', '/login')]
    public function login(Request $request): Response
    {
        $email = $request->input('email');
        $password = $request->body['password'] ?? '';        // not trimmed: spaces are part of a password
        $next = self::localPath($request->input('next', 2000));

        try {
            $member = $this->authenticator->authenticate(
                $email,
                is_string($password) ? $password : '',
                $request->clientAddress(),
            );
        } catch (AuthenticationFailed $e) {
            return $this->view->page('Log in', 'login', ['email' => $email, 'error' => $e->getMessage(), 'next' => $next],
                $e->throttled ? 429 : 422);
        }

        $this->session->regenerate();           // a new ID for the new privilege level
        $this->session->set('member_id', $member->id);
        $this->session->set('last_seen', $this->clock->now()->getTimestamp());   // the clock the idle check uses
        $this->session->renewCsrfToken();
        $this->session->flash("Welcome back, {$member->name}.");

        return Response::redirect($next);
    }

    #[Route('POST', '/logout')]
    public function logout(): Response
    {
        $this->session->reset();
        $this->session->flash('You have been logged out.');

        return Response::redirect('/');
    }

    /**
     * $target if it is a path on this site, otherwise '/' (chapter 18: no open redirects through ?next=).
     */
    public static function localPath(string $target): string
    {
        return preg_match('~^/(?![/\\\\])[^\x00-\x1F\x7F]*$~', $target) === 1 ? $target : '/';
    }
}

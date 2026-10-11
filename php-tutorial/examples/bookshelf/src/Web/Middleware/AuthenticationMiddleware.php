<?php

declare(strict_types=1);

namespace Bookshelf\Web\Middleware;

use Bookshelf\Domain\EntityNotFound;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Http\Middleware;
use Bookshelf\Http\Request;
use Bookshelf\Http\RequestHandler;
use Bookshelf\Http\Response;
use Bookshelf\Support\Clock;
use Bookshelf\Web\Session;
use Bookshelf\Web\View;

/**
 * Finds the logged-in member, if any, and adds it to the request as the 'member' attribute.
 * Sessions idle for longer than the timeout are logged out.
 */
final readonly class AuthenticationMiddleware implements Middleware
{
    public function __construct(
        private Session $session,
        private MemberRepository $members,
        private View $view,
        private Clock $clock,
        private int $idleSeconds = 30 * 60,
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $member = $this->currentMember();
        $this->view->setMember($member);

        return $next->handle($request->withAttribute('member', $member));
    }

    private function currentMember(): ?Member
    {
        $id = $this->session->get('member_id');
        if (!is_int($id)) {
            return null;
        }

        $now = $this->clock->now()->getTimestamp();
        if ($now - (int) $this->session->get('last_seen', 0) > $this->idleSeconds) {
            $this->session->reset();
            return null;
        }
        $this->session->set('last_seen', $now);

        try {
            return $this->members->get($id);
        } catch (EntityNotFound) {
            $this->session->reset();               // the account was deleted meanwhile
            return null;
        }
    }
}

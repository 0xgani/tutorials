<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Cli\UsageError;
use Bookshelf\Domain\Member;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Domain\MemberType;

final readonly class AddMemberCommand implements Command
{
    public function __construct(
        private MemberRepository $members,
    ) {
    }

    public function name(): string
    {
        return 'member:add';
    }

    public function description(): string
    {
        return 'Register a new member';
    }

    public function help(): string
    {
        return <<<'TEXT'
            Usage: bookshelf member:add <name> <email> [--type=standard|premium|staff]
            TEXT;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $name = $input->requireArgument(0, 'name');
        $email = $input->requireArgument(1, 'email');
        $type = MemberType::from($input->option('type', 'standard', array_column(MemberType::cases(), 'value')));

        if ($this->members->findByEmail($email) !== null) {
            throw new UsageError("A member with the e-mail address $email already exists");
        }

        $member = new Member(null, $name, $email, $type);
        $this->members->save($member);
        $output->line((string) $member->id);
        $output->success("Added {$member->name} as a {$type->label()} member with id {$member->id}");

        return ExitCode::Success;
    }
}

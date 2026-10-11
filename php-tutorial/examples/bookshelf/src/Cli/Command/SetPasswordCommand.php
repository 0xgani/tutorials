<?php

declare(strict_types=1);

namespace Bookshelf\Cli\Command;

use Bookshelf\Cli\Command;
use Bookshelf\Cli\ExitCode;
use Bookshelf\Cli\Input;
use Bookshelf\Cli\Output;
use Bookshelf\Cli\UsageError;
use Bookshelf\Domain\MemberRepository;
use Bookshelf\Security\Passwords;

final readonly class SetPasswordCommand implements Command
{
    /** @param resource|null $stdin where the password is read from (STDIN by default) */
    public function __construct(
        private MemberRepository $members,
        private mixed $stdin = null,
    ) {
    }

    public function name(): string
    {
        return 'member:password';
    }

    public function description(): string
    {
        return "Set a member's password";
    }

    public function help(): string
    {
        return <<<'TEXT'
            Usage: bookshelf member:password <email>

            Reads the new password from standard input: typed (hidden) in a terminal, or piped in by a script.
            It is never taken as an argument, because arguments end up in the shell history and the process list.
            TEXT;
    }

    public function run(Input $input, Output $output): ExitCode
    {
        $email = $input->requireArgument(0, 'email');
        $member = $this->members->findByEmail($email)
            ?? throw new UsageError("There is no member with the e-mail address $email");

        $password = $this->readPassword($output);
        $problem = Passwords::problemWith($password);
        if ($problem !== null) {
            $output->error($problem);

            return ExitCode::Failure;
        }

        $member->changePasswordHash(Passwords::hash($password));
        $this->members->save($member);
        $output->success("Password set for {$member->name}");

        return ExitCode::Success;
    }

    private function readPassword(Output $output): string
    {
        $stdin = $this->stdin ?? STDIN;
        $interactive = stream_isatty($stdin) && PHP_OS_FAMILY !== 'Windows';

        if ($interactive) {
            $output->prompt('New password: ');
            shell_exec('stty -echo');            // stop the terminal from showing what is typed
        }
        try {
            $line = fgets($stdin);
        } finally {
            if ($interactive) {
                shell_exec('stty echo');
                $output->info('');
            }
        }

        return $line === false ? '' : rtrim($line, "\r\n");
    }
}

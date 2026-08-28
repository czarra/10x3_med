<?php

namespace App\Tests\Support\E2e\Command;

use App\Tests\Support\E2e\E2eFixtures;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Resets the browser E2E fixture state from the CLI.
 *
 * Test-support code (tests/), wired only for APP_ENV=e2e. The Playwright suite
 * normally resets via `POST /__e2e__/reset`; this command is the same operation
 * for manual debugging and for a CI step that wants to seed before the app is
 * even hit.
 *
 *   docker compose exec -e APP_ENV=e2e php bin/console app:e2e:seed
 */
#[When('e2e')]
#[AsCommand(
    name: 'app:e2e:seed',
    description: 'Reset and seed the canonical E2E fixture users (@e2e.test only).',
)]
final class E2eSeedCommand extends Command
{
    public function __construct(private readonly E2eFixtures $fixtures)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->fixtures->reset();

        $io->success('E2E fixtures reset.');
        $io->table(
            ['email', 'password', 'profile'],
            array_map(
                static fn (array $user): array => [
                    $user['email'],
                    E2eFixtures::PASSWORD,
                    $user['hasProfile'] ? 'yes' : 'no',
                ],
                $result['users'],
            ),
        );

        return Command::SUCCESS;
    }
}

<?php

namespace App\Command;

use App\Race\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:install', description: 'Creates the database tables (safe to run repeatedly).')]
final class InstallCommand extends Command
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->db->installSchema();
        $io->success(sprintf('Database schema is up to date (%s).', $this->db->driver()));

        return Command::SUCCESS;
    }
}

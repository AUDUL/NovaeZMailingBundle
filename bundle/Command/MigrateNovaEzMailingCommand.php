<?php

namespace CodeRhapsodie\IbexaMailingBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ibexamailing:migrate:novaezmailing', description: 'Migrate data from old structure')]
class MigrateNovaEzMailingCommand extends Command
{
    private const TABLES = [
        'novaezmailing_user' => 'mailing_user',
        'novaezmailing_mailing_list' => 'mailing_mailing_list',
        'novaezmailing_campaign' => 'mailing_campaign',
        'novaezmailing_mailing' => 'mailing_mailing',
        'novaezmailing_campaign_mailinglists_destination' => 'mailing_campaign_mailinglists_destination',
        'novaezmailing_confirmation_token' => 'mailing_confirmation_token',
        'novaezmailing_broadcast' => 'mailing_broadcast',
        'novaezmailing_stats_hit' => 'mailing_stats_hit',
        'novaezmailing_registrations' => 'mailing_registrations'
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (self::TABLES as $oldTable => $newTable) {
            try {
                $this->connection->executeQuery("SELECT * from $oldTable");
            } catch (\Exception) {
                $io->error("Missing table : $oldTable");
                return parent::FAILURE;
            }

            try {
                $this->connection->executeQuery("SELECT * from $newTable");
            } catch (\Exception) {
                $io->error("Missing table : $newTable (please execute ibexamailing:install)");
                return parent::FAILURE;
            }

            $this->connection->executeQuery("INSERT INTO $newTable SELECT * FROM $oldTable");
        }

        foreach (\array_reverse(self::TABLES) as $oldTable => $newTable) {
            $this->connection->executeQuery("DROP TABLE $oldTable");
        }

        return parent::SUCCESS;
    }
}
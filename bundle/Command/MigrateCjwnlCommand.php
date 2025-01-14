<?php

declare(strict_types=1);

namespace CodeRhapsodie\IbexaMailingBundle\Command;

use CodeRhapsodie\IbexaMailingBundle\Core\IOService;
use CodeRhapsodie\IbexaMailingBundle\Entity\Campaign;
use CodeRhapsodie\IbexaMailingBundle\Entity\Mailing;
use CodeRhapsodie\IbexaMailingBundle\Entity\MailingList;
use CodeRhapsodie\IbexaMailingBundle\Entity\Registration;
use CodeRhapsodie\IbexaMailingBundle\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Core\MVC\Symfony\SiteAccess;
use Ibexa\Core\MVC\Symfony\SiteAccess\SiteAccessServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @SuppressWarnings(PHPMD)
 */
#[AsCommand(name: 'ibexamailing:migrate:cjwnl', description: 'Import database from the old one.')]
class MigrateCjwnlCommand extends Command
{
    public const DEFAULT_FALLBACK_CONTENT_ID = 1;

    public const DUMP_FOLDER = 'migrate/cjwnl';
    private SymfonyStyle $io;

    /**
     * @var array<mixed>
     */
    private array $lists = [];

    /**
     * @var array<mixed>
     */
    private array $campaigns = [];

    public function __construct(
        private readonly IOService $ioService,
        private readonly EntityManagerInterface $entityManager,
        private readonly Repository $ezRepository,
        private readonly SiteAccessServiceInterface $siteAccessAware,
        private readonly Connection $connection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('export', null, InputOption::VALUE_NONE, 'Export from old DB to json files')
            ->addOption('import', null, InputOption::VALUE_NONE, 'Import from json files to new DB')
            ->addOption('clean', null, InputOption::VALUE_NONE, 'Clean the existing data')
            ->setHelp('Run ibexamailing:migrate:cjwnl --export|--import|--clean');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Update the Database with Custom IbexaMailing Tables');

        if ($input->getOption('export')) {
            $this->export();
        } elseif ($input->getOption('import')) {
            $this->import();
        } elseif ($input->getOption('clean')) {
            $this->clean();
        } else {
            $this->io->error('No export or import option found. Run ibexamailing:migrate:cjwnl --export|--import');
        }

        return Command::SUCCESS;
    }

    private function export(): void
    {
        // clean the 'ibexamailing' dir
        $this->ioService->cleanDir(self::DUMP_FOLDER);
        $this->io->section('Cleaned the folder with json files.');
        $this->io->section('Exporting from old database to json files.');

        $contentService = $this->ezRepository->getContentService();
        $contentLanguageService = $this->ezRepository->getContentLanguageService();
        $languages = $contentLanguageService->loadLanguages();
        $defaultLanguageCode = $contentLanguageService->getDefaultLanguageCode();

        $siteAccessList = array_map(function (SiteAccess $siteAccess) {
            return $siteAccess->name;
        }, iterator_to_array($this->siteAccessAware->getAll()));

        $mailingCounter = $registrationCounter = 0;

        // Lists, Campaigns with Mailings

        $this->io->writeln('Lists with Campaigns with Mailings:');

        $sql = 'SELECT contentobject_attribute_version, contentobject_id, auto_approve_registered_user,';

        $sql .= 'email_sender_name, email_sender, email_receiver_test FROM cjwnl_list ';
        $sql .= 'WHERE (contentobject_id ,contentobject_attribute_version) IN ';
        $sql .= '(SELECT contentobject_id, MAX(contentobject_attribute_version) ';
        $sql .= 'FROM cjwnl_list GROUP BY contentobject_id)';

        $list_rows = $this->runQuery($sql);

        $this->io->progressStart();

        $this->ezRepository->sudo(function () use ($list_rows, $contentService, $languages, $siteAccessList, $defaultLanguageCode, $mailingCounter) {
            foreach ($list_rows as $list_row) {
                try {
                    $listContent = $contentService->loadContent($list_row['contentobject_id']);
                } catch (\Exception) {
                    try {
                        $listContent = $contentService->loadContent(self::DEFAULT_FALLBACK_CONTENT_ID);
                    } catch (\Exception) {
                        continue;
                    }
                }

                $listNames = [];
                foreach ($languages as $language) {
                    $title = $listContent->getName($language->languageCode);
                    if ($title !== null) {
                        $listNames[$language->languageCode] = $title;
                    }
                }
                $fileName = $this->ioService->saveFile(
                    self::DUMP_FOLDER."/list/list_{$list_row['contentobject_id']}.json",
                    json_encode(
                        [
                            'names' => $listNames,
                            'withApproval' => $list_row['auto_approve_registered_user'],
                        ]
                    )
                );
                $this->lists[] = pathinfo($fileName)['filename'];

                $mailings = [];

                $sql = 'SELECT edition_contentobject_id, status, siteaccess, mailqueue_process_finished ';

                $sql .= 'FROM cjwnl_edition_send WHERE list_contentobject_id = ?';

                $mailing_rows = $this->runQuery($sql, [$list_row['contentobject_id']]);
                foreach ($mailing_rows as $mailing_row) {
                    $status = match ($mailing_row['status']) {
                        0, 1 => Mailing::PENDING,
                        2 => Mailing::PROCESSING,
                        3 => Mailing::SENT,
                        9 => Mailing::ABORTED,
                        default => Mailing::DRAFT,
                    };

                    $mailingContent = null;

                    try {
                        $mailingContent = $contentService->loadContent($mailing_row['edition_contentobject_id']);
                    } catch (\Exception) {
                        try {
                            $listContent = $contentService->loadContent(self::DEFAULT_FALLBACK_CONTENT_ID);
                        } catch (\Exception) {
                            continue;
                        }
                    }

                    if ($mailingContent === null) {
                        continue;
                    }

                    $mailingNames = [];
                    foreach ($languages as $language) {
                        $title = $mailingContent->getName($language->languageCode);
                        if ($title !== null) {
                            $mailingNames[$language->languageCode] = $title;
                        }
                    }
                    $siteAccess = \in_array(
                        $mailing_row['siteaccess'],
                        $siteAccessList,
                        true
                    ) ? $mailing_row['siteaccess'] : $siteAccessList[0];

                    $mailings[] = [
                        'names' => $mailingNames,
                        'status' => $status,
                        'siteAccess' => $siteAccess,
                        'locationId' => $mailingContent->contentInfo->mainLocationId,
                        'hoursOfDay' => (int) date('H', (int) $mailing_row['mailqueue_process_finished']),
                        'daysOfMonth' => (int) date('d', (int) $mailing_row['mailqueue_process_finished']),
                        'monthsOfYear' => (int) date('m', (int) $mailing_row['mailqueue_process_finished']),
                        'subject' => $mailingContent->getName($defaultLanguageCode) ?? array_shift($mailingNames),
                    ];
                    ++$mailingCounter;
                }

                $fileName = $this->ioService->saveFile(
                    self::DUMP_FOLDER."/campaign/campaign_{$list_row['contentobject_id']}.json",
                    json_encode(
                        [
                            'names' => $listNames,
                            'locationId' => $listContent->contentInfo->mainLocationId,
                            'senderName' => $list_row['email_sender_name'],
                            'senderEmail' => $list_row['email_sender'],
                            'reportEmail' => $list_row['email_receiver_test'],
                            'mailings' => $mailings,
                        ]
                    )
                );
                $this->campaigns[] = pathinfo($fileName)['filename'];
                $this->io->progressAdvance();
            }
        });

        $this->io->progressFinish();

        // Users
        $this->io->writeln('Users with Subscriptions:');

        $users = [];

        $sql = "SELECT max(id) as `id`,
       email,
       salutation,
       first_name,
       last_name,
       organisation,
       birthday,
       status
FROM cjwnl_user
WHERE removed = 0
GROUP BY email
union
SELECT 0 as `id`,
       cjwnl_blacklist_item.email as email,
       ''   as salutation,
       ''   as first_name,
       ''   as last_name,
       ''   as organisation,
       ''   as birthday,
       8   as status
from cjwnl_blacklist_item
         left join cjwnl_user cju on cjwnl_blacklist_item.email = cju.email
where cju.email is null";

        $maxId = $this->connection->fetchOne('SELECT max(id) as id from cjwnl_user');

        $user_rows = $this->runQuery($sql);

        $this->io->progressStart();

        foreach ($user_rows as $user_row) {
            $status = match ($user_row['status']) {
                1, 2 => User::CONFIRMED,
                3, 4 => User::REMOVED,
                6 => User::SOFT_BOUNCE,
                7 => User::HARD_BOUNCE,
                8 => User::BLACKLISTED,
                default => User::PENDING,
            };

            $birthdate = empty($user_row['birthday']) ? null : new \DateTime('2018-12-11');

            $userId = $user_row['id'];
            if ($userId === 0) {
                ++$maxId;
                $userId = $maxId;
            }

            // Registrations
            $sql = 'SELECT list_contentobject_id, approved, status FROM'
                .' cjwnl_subscription WHERE newsletter_user_id = ? and status not in (3, 4)';
            $subscription_rows = $this->runQuery($sql, [$user_row['id']]);

            $subscriptions = [];
            foreach ($subscription_rows as $subscription_row) {
                if ($status === User::REMOVED) {
                    continue;
                }
                $subscriptions[] = [
                    'list_contentobject_id' => $subscription_row['list_contentobject_id'],
                    'approved' => (bool) $subscription_row['approved'],
                ];
                ++$registrationCounter;
            }

            $gender = match ($user_row['salutation']) {
                '1' => 'Mr',
                '2' => 'Mme',
                default => null
            };

            $fileName = $this->ioService->saveFile(
                self::DUMP_FOLDER."/user/user_{$userId}.json",
                json_encode(
                    [
                        'email' => $user_row['email'],
                        'firstName' => $user_row['first_name'],
                        'gender' => $gender,
                        'lastName' => $user_row['last_name'],
                        'birthDate' => $birthdate,
                        'status' => $status,
                        'company' => $user_row['organisation'],
                        'subscriptions' => $subscriptions,
                    ]
                )
            );
            $users[] = pathinfo($fileName)['filename'];

            $this->io->progressAdvance();
        }

        $this->ioService->saveFile(
            self::DUMP_FOLDER.'/manifest.json',
            json_encode(['lists' => $this->lists, 'campaigns' => $this->campaigns, 'users' => $users])
        );

        $this->io->progressFinish();

        $this->io->section(
            'Total: '.\count($this->lists).' lists, '.\count($this->campaigns).' campaigns, '.$mailingCounter.' mailings, '
            .\count($users).' users, '.$registrationCounter.' registrations.'
        );
        $this->io->success('Export done.');
    }

    private function import(): void
    {
        // clear the tables, reset the IDs
        $this->clean();
        $this->io->section('Importing from json files to new database.');

        $manifest = $this->ioService->readFile(self::DUMP_FOLDER.'/manifest.json');
        $fileNames = json_decode($manifest);

        // Lists
        $this->io->writeln('Lists:');
        $this->io->progressStart(\count($fileNames->lists));

        $listCounter = $campaignCounter = $mailingCounter = $userCounter = $registrationCounter = 0;
        $listIds = [];

        $mailingListRepository = $this->entityManager->getRepository(MailingList::class);
        $userRepository = $this->entityManager->getRepository(User::class);

        $n = 0;
        foreach ($fileNames->lists as $listFile) {
            $listData = json_decode($this->ioService->readFile(self::DUMP_FOLDER.'/list/'.$listFile.'.json'));
            $mailingList = new MailingList();
            $mailingList->setNames((array) $listData->names);
            $mailingList->setWithApproval((bool) $listData->withApproval);
            $mailingList->setUpdated(new \DateTime());

            $this->entityManager->persist($mailingList);
            ++$listCounter;
            $this->entityManager->flush();
            $listIds[explode('_', $listFile)[1]] = $mailingList->getId();
            ++$n;
            if ($n % 100 === 0) {
                $this->entityManager->clear();
            }
            $this->io->progressAdvance();
        }
        $this->io->progressFinish();

        // Campaigns with Mailings
        $this->io->writeln('Campaigns with Mailings:');
        $this->io->progressStart(\count($fileNames->campaigns));

        $n = 0;
        foreach ($fileNames->campaigns as $campaignFile) {
            $campaignData = json_decode(
                $this->ioService->readFile(self::DUMP_FOLDER.'/campaign/'.$campaignFile.'.json')
            );
            $campaign = new Campaign();
            $campaign->setNames((array) $campaignData->names);
            $campaign->setReportEmail($campaignData->reportEmail);
            $campaign->setSenderEmail($campaignData->senderEmail);
            $campaign->setReturnPathEmail('');
            $campaign->setSenderName($campaignData->senderName);
            $campaign->setLocationId($campaignData->locationId);
            $campaign->setUpdated(new \DateTime());
            $campaignContentId = explode('_', $campaignFile)[1];
            if (\array_key_exists($campaignContentId, $listIds)) {
                /* @var MailingList $mailingList */
                $mailingList = $mailingListRepository->findOneBy(
                    ['id' => $listIds[$campaignContentId]]
                );
                if ($mailingList !== null) {
                    $campaign->addMailingList($mailingList);
                }
            }
            foreach ($campaignData->mailings as $mailingData) {
                $mailing = new Mailing();
                $mailing->setNames((array) $mailingData->names);
                $mailing->setStatus($mailingData->status);
                $mailing->setRecurring(false);
                $mailing->setHoursOfDay([$mailingData->hoursOfDay]);
                $mailing->setDaysOfMonth([$mailingData->daysOfMonth]);
                $mailing->setMonthsOfYear([$mailingData->monthsOfYear]);
                $mailing->setLocationId($mailingData->locationId);
                $mailing->setSiteAccess($mailingData->siteAccess);
                $mailing->setSubject($mailingData->subject);
                $mailing->setUpdated(new \DateTime());
                $this->entityManager->persist($mailing);
                $campaign->addMailing($mailing);
                ++$mailingCounter;
            }
            $this->entityManager->persist($campaign);
            ++$campaignCounter;
            ++$n;
            if ($n % 100 === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
            $this->io->progressAdvance();
        }
        $this->entityManager->flush();
        $this->io->progressFinish();

        // Users & Registrations
        $this->io->writeln('Users and Registrations:');
        $this->io->progressStart(\count($fileNames->users));

        $n = 0;
        foreach ($fileNames->users as $userFile) {
            $userData = json_decode($this->ioService->readFile(self::DUMP_FOLDER.'/user/'.$userFile.'.json'));

            // check if email already exists
            $existingUser = $userRepository->findOneBy(['email' => $userData->email]);

            if (!$existingUser) {
                $user = new User();
                $user
                    ->setEmail($userData->email)
                    ->setBirthDate($userData->birthDate)
                    ->setCompany($userData->company)
                    ->setFirstName($userData->firstName)
                    ->setLastName($userData->lastName)
                    ->setStatus($userData->status)
                    ->setOrigin('site');

                if ($userData->gender) {
                    $user->setGender($userData->gender);
                }

                foreach ($userData->subscriptions as $subscription) {
                    if (\array_key_exists($subscription->list_contentobject_id, $listIds)) {
                        $registration = new Registration();
                        /* @var MailingList $mailingList */
                        $mailingList = $mailingListRepository->findOneBy(
                            ['id' => $listIds[$subscription->list_contentobject_id]]
                        );
                        if ($mailingList !== null) {
                            $registration->setMailingList($mailingList);
                        }
                        $registration->setApproved($subscription->approved);
                        $user->addRegistration($registration);
                        ++$registrationCounter;
                    }
                }
                $this->entityManager->persist($user);
                ++$userCounter;
                ++$n;
                if ($n % 100 === 0) {
                    $this->entityManager->flush();
                    $this->entityManager->clear();
                }
            }
            $this->io->progressAdvance();
        }
        $this->entityManager->flush();

        $this->io->progressFinish();

        $this->io->section(
            'Total: '.$listCounter.' lists, '.$campaignCounter.' campaigns, '.$mailingCounter.' mailings, '
            .$userCounter.' users, '.$registrationCounter.' registrations.'
        );
        $this->io->success('Import done.');
    }

    private function clean(): void
    {
        // We don't run TRUNCATE command here because of foreign keys constraints
        $this->connection->executeQuery('DELETE FROM ibexamailing_stats_hit');
        $this->connection->executeQuery('ALTER TABLE ibexamailing_stats_hit AUTO_INCREMENT = 1');
        $this->connection->executeQuery('DELETE FROM ibexamailing_broadcast');
        $this->connection->executeQuery('ALTER TABLE ibexamailing_broadcast AUTO_INCREMENT = 1');
        $this->connection->executeQuery('DELETE FROM ibexamailing_mailing');
        $this->connection->executeQuery('ALTER TABLE ibexamailing_mailing AUTO_INCREMENT = 1');
        $this->connection->executeQuery('DELETE FROM ibexamailing_campaign_mailinglists_destination');
        $this->connection->executeQuery('DELETE FROM ibexamailing_campaign');
        $this->connection->executeQuery('ALTER TABLE ibexamailing_campaign AUTO_INCREMENT = 1');
        $this->connection->executeQuery('DELETE FROM ibexamailing_confirmation_token');
        $this->connection->executeQuery('DELETE FROM ibexamailing_registrations');
        $this->connection->executeQuery('ALTER TABLE ibexamailing_registrations AUTO_INCREMENT = 1');
        $this->connection->executeQuery('DELETE FROM ibexamailing_mailing_list');
        $this->connection->executeQuery('ALTER TABLE ibexamailing_mailing_list AUTO_INCREMENT = 1');
        $this->connection->executeQuery('DELETE FROM ibexamailing_user');
        $this->connection->executeQuery('ALTER TABLE ibexamailing_user AUTO_INCREMENT = 1');
        $this->io->section('Current tables in the new database have been cleaned.');
    }

    /**
     * @param array<mixed> $parameters
     *
     * @return \ArrayIterator<int, mixed>
     */
    private function runQuery(string $sql, array $parameters = []): \Traversable
    {
        $stmt = $this->connection->prepare($sql);
        for ($i = 1, $iMax = \count($parameters); $i <= $iMax; ++$i) {
            $stmt->bindValue($i, $parameters[$i - 1]);
        }
        $result = $stmt->executeQuery();

        return $result->iterateAssociative();
    }
}

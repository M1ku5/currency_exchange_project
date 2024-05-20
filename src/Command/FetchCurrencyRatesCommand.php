<?php

namespace App\Command;

use App\Entity\CurrencyRate;
use App\Service\CurrencyRateFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-currency-rates',
    description: 'Fetch currency rates from NBP and store them in the database',
)]
class FetchCurrencyRatesCommand extends Command
{
    private $fetcher;
    private $entityManager;

    public function __construct(CurrencyRateFetcher $fetcher, EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->fetcher = $fetcher;
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Fetch currency rates from NBP and store them in the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $currencies = ['EUR', 'USD'];
        $startDate = '2023-01-01';
        $endDate = '2024-01-01';

        foreach ($currencies as $currency) {
            $data = $this->fetcher->fetchRates($currency, $startDate, $endDate);

            foreach ($data['rates'] as $rateData) {
                $rate = new CurrencyRate();
                $rate->setCurrencyCode($currency);
                $rate->setRate($rateData['mid']);
                $rate->setDate(new \DateTime($rateData['effectiveDate']));

                $this->entityManager->persist($rate);
            }

            $this->entityManager->flush();
        }

        $io->success('Currency rates fetched and stored successfully.');

        return Command::SUCCESS;
    }
}

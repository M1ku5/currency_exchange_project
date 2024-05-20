<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\CurrencyRateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\CurrencyRate;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Query\ResultSetMapping;

class CurrencyRateController extends AbstractController
{
    private $currencyRateRepository;

    public function __construct(CurrencyRateRepository $currencyRateRepository)
    {
        $this->currencyRateRepository = $currencyRateRepository;
    }
    #[Route('/currency/search', name: 'currency_search', methods: ['GET'])]
public function search(Request $request, EntityManagerInterface $entityManager): Response
{
    $currencyCode = $request->query->get('currencyCode', 'USD');
    $startDateString = $request->query->get('startDate', '2023-01-01');
    $endDateString = $request->query->get('endDate', '2024-01-01');
    $searchType = $request->query->get('searchType', 'day');
    $sortOrder = $request->query->get('sortOrder', 'ASC');

    $startDate = new \DateTime($startDateString);
    $endDate = new \DateTime($endDateString);

    $currencyRates = $this->executeQuery($entityManager, $currencyCode, $startDate, $endDate, $searchType, $sortOrder);
    dump($currencyRates);

    return $this->render('currency/search.twig', [
        'currencyRates' => $currencyRates,
        'currencyCode' => $currencyCode,
        'startDate' => $startDateString,
        'endDate' => $endDateString,
        'searchType' => $searchType,
        'sortOrder' => $sortOrder,
    ]);
}

private function executeQuery(EntityManagerInterface $entityManager, string $currencyCode, \DateTime $startDate, \DateTime $endDate, string $searchType, string $sortOrder): array
{
    $repository = $entityManager->getRepository(CurrencyRate::class);

    switch ($searchType) {
        case 'day':
            $query = $repository->createQueryBuilder('cr')
                ->where('cr.currencyCode = :currencyCode')
                ->andWhere('cr.date BETWEEN :startDate AND :endDate')
                ->setParameter('currencyCode', $currencyCode)
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate)
                ->orderBy('cr.rate', $sortOrder)
                ->getQuery();
            break;

        case 'week':
            return $this->executeWeekQuery($entityManager, $currencyCode, $startDate, $endDate, $sortOrder);

        case 'month':
            return $this->executeMonthQuery($entityManager, $currencyCode, $startDate, $endDate, $sortOrder);

        case 'quarter':
            return $this->executeQuarterQuery($entityManager, $currencyCode, $startDate, $endDate, $sortOrder);

        default:
            throw new \InvalidArgumentException('Invalid search type.');
    }

    return $query->getResult();
}

private function executeWeekQuery(EntityManagerInterface $entityManager, string $currencyCode, \DateTime $startDate, \DateTime $endDate, string $sortOrder): array
{
    $rsm = new ResultSetMapping();
    $rsm->addScalarResult('week_number', 'date');
    $rsm->addScalarResult('average_rate', 'rate');
    $rsm->addScalarResult('currency_code', 'currencyCode');
    $connection = $entityManager->getConnection();
    $sql = "
        SELECT 
            WEEK(date) as week_number, 
            AVG(rate) as average_rate,
            currency_code as currency_code
        FROM 
            currency_rate 
        WHERE 
            currency_code = :currencyCode AND 
            date BETWEEN :startDate AND :endDate 
        GROUP BY 
            week_number 
        ORDER BY 
            average_rate $sortOrder
    ";
    $query = $entityManager->createNativeQuery($sql, $rsm);
    $query->setParameter('currencyCode', $currencyCode);
    $query->setParameter('startDate', $startDate->format('Y-m-d'));
    $query->setParameter('endDate', $endDate->format('Y-m-d'));
    return $query->getResult();
}

private function executeMonthQuery(EntityManagerInterface $entityManager, string $currencyCode, \DateTime $startDate, \DateTime $endDate, string $sortOrder): array
{
    $rsm = new ResultSetMapping();
    $rsm->addScalarResult('month', 'date');
    $rsm->addScalarResult('average_rate', 'rate');
    $rsm->addScalarResult('currency_code', 'currencyCode');
    $connection = $entityManager->getConnection();
    $sql = "
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            AVG(rate) as average_rate,
            currency_code as currency_code
        FROM 
            currency_rate 
        WHERE 
            currency_code = :currencyCode AND 
            date BETWEEN :startDate AND :endDate 
        GROUP BY 
            month 
        ORDER BY 
            average_rate $sortOrder
    ";
    $query = $entityManager->createNativeQuery($sql, $rsm);
    $query->setParameter('currencyCode', $currencyCode);
    $query->setParameter('startDate', $startDate->format('Y-m-d'));
    $query->setParameter('endDate', $endDate->format('Y-m-d'));
    return $query->getResult();
}

private function executeQuarterQuery(EntityManagerInterface $entityManager, string $currencyCode, \DateTime $startDate, \DateTime $endDate, string $sortOrder): array
{
    $rsm = new ResultSetMapping();
    $rsm->addScalarResult('quarter', 'date');
    $rsm->addScalarResult('average_rate', 'rate');
    $rsm->addScalarResult('currency_code', 'currencyCode');
    $connection = $entityManager->getConnection();
    $sql = "
        SELECT 
            CONCAT(YEAR(date), '-Q', QUARTER(date)) as quarter,
            AVG(rate) as average_rate,
            currency_code as currency_code
        FROM 
            currency_rate 
        WHERE 
            currency_code = :currencyCode AND 
            date BETWEEN :startDate AND :endDate 
        GROUP BY 
            quarter 
        ORDER BY 
            average_rate $sortOrder
    ";
    $query = $entityManager->createNativeQuery($sql, $rsm);
    $query->setParameter('currencyCode', $currencyCode);
    $query->setParameter('startDate', $startDate->format('Y-m-d'));
    $query->setParameter('endDate', $endDate->format('Y-m-d'));
    return $query->getResult();
}

#[Route('/currency/convert', name: 'currency_convert', methods: ['GET'])]
public function convert(Request $request): Response
{
    $amount = $request->query->get('amount');
    $currencyCode = $request->query->get('currencyCode');
    $dateString = $request->query->get('date');
    $rate = $request->query->get('rate', 0);

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateString);

    if ($date) {
        $currencyRate = $this->currencyRateRepository->findOneBy(['currencyCode' => $currencyCode, 'date' => $date]);

        if (!$currencyRate) {
            return $this->render('currency/convert.twig', [
                'notFoundMessage' => 'Rate not found for given date and currency.',
            ]);
        }
        $convertedAmount = $amount * $currencyRate->getRate();
        return $this->render('currency/convert.twig', [
            'amount' => $amount,
            'currencyCode' => $currencyCode,
            'date' => $date->format('Y-m-d'),
            'convertedAmount' => $convertedAmount,
            'rate' => $currencyRate->getRate()
        ]);
    } else {
        $convertedAmount = $amount * $rate;
        return $this->render('currency/convert.twig', [
            'amount' => $amount,
            'currencyCode' => $currencyCode,
            'convertedAmount' => $convertedAmount,
            'rate' => $rate
        ]);
    }
}
}
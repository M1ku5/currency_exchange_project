<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CurrencyRateFetcher
{
    private $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    public function fetchRates(string $currency, string $startDate, string $endDate): array
    {
        $url = sprintf('http://api.nbp.pl/api/exchangerates/rates/A/%s/%s/%s/', $currency, $startDate, $endDate);
        $response = $this->client->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        return $response->toArray();
    }
}
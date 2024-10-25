<?php

declare(strict_types=1);

namespace Tests;

use ViaDialog\Api\ViaDialogClient;
use ViaDialog\Api\Exception\ViaDialogException;
use ViaDialog\Api\Exception\AuthenticationException;
use DateTimeImmutable;

class ViaDialogClientTest
{
    private ViaDialogClient $client;

    public function __construct()
    {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();

        $this->client = new ViaDialogClient(
            $_ENV['VIAD_API_USERNAME'],
            $_ENV['VIAD_API_PASSWORD'],
            $_ENV['VIAD_API_COMPANY'],
            $_ENV['VIAD_API_GRANT_TYPE'],
            $_ENV['VIAD_API_SLUG']
        );
    }

    public function runTests()
    {
        $this->testGetSdaList();
        $this->testGetService();
    }

    private function testGetSdaList()
    {
        echo "Test de récupération des SDA :\n";
        try {
            $sdaList = $this->client->getSdaList();
            foreach ($sdaList as $sda) {
                echo "SDA: {$sda->getNumber()}, Statut: {$sda->getStatus()}\n";
            }
        } catch (ViaDialogException $e) {
            echo 'Erreur lors de la récupération des SDA : ' .
                $e->getMessage() .
                "\n";
        }
    }

    private function testGetService()
    {
        echo "\nTest de récupération d'un service spécifique :\n";
        try {
            $serviceId = '2001381'; // Remplacez par un ID de service valide
            $service = $this->client->getService($serviceId);

            echo "Service ID: {$service->getId()}\n";
            echo "Label: {$service->getLabel()}\n";
            echo "Product: {$service->getProduct()}\n";

            echo "SDA List:\n";
            foreach ($service->getSdaList() as $sda) {
                echo "  SDA ID: {$sda->getId()}, Number: {$sda->getNumber()}\n";
            }
        } catch (ViaDialogException $e) {
            echo 'Erreur lors de la récupération du service : ' .
                $e->getMessage() .
                "\n";
        }
    }
}

// Exécution des tests
$test = new ViaDialogClientTest();
$test->runTests();

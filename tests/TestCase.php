<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Garde-fou exécuté avant RefreshDatabase (qui vide la base) :
     * les tests ne tournent que sur kafo_test, jamais sur la base de dev.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database   = $app['config']->get("database.connections.{$connection}.database");

        if ($database !== 'kafo_test') {
            throw new \RuntimeException("Tests refusés sur la base « {$database} » : seule kafo_test est autorisée (voir phpunit.xml).");
        }

        return $app;
    }
}

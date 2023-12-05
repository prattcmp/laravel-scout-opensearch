<?php

declare(strict_types=1);

namespace Zing\LaravelScout\OpenSearch;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use OpenSearch\Client;
use OpenSearch\ClientBuilder;
use Zing\LaravelScout\OpenSearch\Engines\OpenSearchEngine;

class OpenSearchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        resolve(EngineManager::class)->extend(
            'opensearch',
            static fn (): OpenSearchEngine => new OpenSearchEngine(resolve(Client::class), config(
                'scout.soft_delete',
                false
            ))
        );
    }

    public function register(): void
    {
        $this->app->singleton(
            Client::class,
            static function ($app): Client { 
                $connection = $app['config']->get('scout.opensearch.connections.' . $app['config']->get('scout.opensearch.connection'));
                if ($connection['aws'] ?? false) {
                    $arn = $app['config']->get('scout.opensearch.connection.arn');
                    $sessionName = "laravel-sigv4-access-session";
                    
                    $assumeRoleCredentials = new \Aws\Credentials\AssumeRoleCredentialProvider([
                        'client' => new StsClient([
                            'region' => $app['config']->get('scout.opensearch.connection.sigV4Region'),
                            'version' => '2011-06-15',
                            'credentials' => $app['config']->get('scout.opensearch.connection.sigV4CredentialProvider')
                        ]),
                        'assume_role_params' => [
                            'RoleArn' => $arn,
                            'RoleSessionName' => $sessionName,
                        ],
                    ]);
                    
                    // To avoid unnecessarily fetching STS credentials on every API operation,
                    // the memoize function handles automatically refreshing the credentials when they expire
                    $provider = \Aws\Credentials\CredentialProvider::memoize($assumeRoleCredentials);
    
                    $client = (new \OpenSearch\ClientBuilder())
                        ->setSigV4Region($app['config']->get('scout.opensearch.connection.sigV4Region'))
                        ->setSigV4Service($app['config']->get('scout.opensearch.connection.sigV4Service'))
                        ->setSigV4CredentialProvider(true)
                        ->setSigV4CredentialProvider($provider)
                        ->build();
                }
                
                return ClientBuilder::fromConfig($connection); 
            }
        );
    }
}

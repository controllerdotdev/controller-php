<?php

namespace Controller\Tests\Unit;

use Controller\Client;
use Controller\Exceptions\ControllerException;
use GuzzleHttp\Client as HttpClient;
use Mockery;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_it_requires_api_key()
    {
        $this->expectException(ControllerException::class);
        new Client('');
    }

    public function test_it_can_set_context()
    {
        $client = new Client('test-key');
        $client->context('user_id', 1);

        $this->assertInstanceOf(Client::class, $client);
    }

    public function test_it_can_report_exception()
    {
        $httpClient = Mockery::mock(HttpClient::class);
        $httpClient->shouldReceive('post')
            ->once()
            ->andReturn();

        $client = new Client('test-key', null, $httpClient);

        $exception = new \Exception('Test exception');
        $client->reportException($exception);
    }
}

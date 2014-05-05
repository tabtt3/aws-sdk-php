<?php
namespace Aws\Test\Subscriber;

use Aws\Api\Service;
use Aws\AwsClient;
use Aws\Signature\SignatureV2;
use Aws\Subscriber\Error;
use Aws\Credentials\Credentials;
use GuzzleHttp\Client;
use GuzzleHttp\Command\Event\CommandErrorEvent;
use GuzzleHttp\Command\Event\PrepareEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Message\Response;

/**
 * @covers Aws\Subscriber\Error
 */
class ErrorTest extends \PHPUnit_Framework_TestCase
{
    public function testParsesErrors()
    {
        $api = new Service([
            'operations' => [
                'foo' => ['http' => ['httpMethod' => 'POST']]
            ]
        ]);

        $client = new Client();
        $client->getEmitter()->attach(new Mock([new Response(400)]));
        $awsClient = new AwsClient([
            'api'         => $api,
            'credentials' => new Credentials('foo', 'bar'),
            'client'      => $client,
            'signature'   => new SignatureV2(),
            'region'      => 'us-west-2'
        ]);

        $c = 0;
        $parser = function ($r) use (&$c) {
            return ['foo' => 'bar', 'called' => ++$c];
        };

        $awsClient->getEmitter()->attach(new Error($parser));

        $awsClient->getEmitter()->on('prepare', function (PrepareEvent $e) {
            $e->setRequest($e->getClient()
                ->getHttpClient()
                ->createRequest('GET', 'http://foo.com'));
        });

        $awsClient->getEmitter()->on('error', function (CommandErrorEvent $e) {
            $this->assertEquals([
                'foo'    => 'bar',
                'called' => 1
            ], $e['aws_error']);
            $e->setResult('foo');
        }, RequestEvents::LATE);

        $awsClient->foo();
        $this->assertEquals(1, $c);
    }

    public function testSkipsNetworkingErrors()
    {
        $api = new Service([
            'operations' => [
                'foo' => ['http' => ['httpMethod' => 'POST']]
            ]
        ]);

        $client = new Client(['defaults' => ['debug' => true]]);
        $request = $client->createRequest('GET', 'http://www.foo.com');
        $mock = new Mock();
        $request->getEmitter()->attach($mock);
        $mock->addException(new RequestException('foo', $request));

        $awsClient = new AwsClient([
            'api'         => $api,
            'credentials' => new Credentials('foo', 'bar'),
            'client'      => $client,
            'signature'   => new SignatureV2(),
            'region'      => 'us-west-2'
        ]);

        $awsClient->getEmitter()->attach(new Error(function () {
            $this->fail('Parser should not have been called');
        }));

        $awsClient->getEmitter()->on(
            'prepare',
            function (PrepareEvent $e) use ($request) {
                $e->setRequest($request);
            }
        );

        $awsClient->getEmitter()->on('error', function (CommandErrorEvent $e) {
            $this->assertNull($e['aws_error']);
            $e->setResult('foo');
        }, RequestEvents::LATE);

        $cmd = $awsClient->getCommand('foo');
        $this->assertEquals('foo', $awsClient->execute($cmd));
    }
}

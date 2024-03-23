<?php

namespace App\Tests\Integration\Api\Client\Server;

use App\Http\Controllers\Api\Client\Servers\CommandController;
use App\Http\Requests\Api\Client\Servers\SendCommandRequest;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Response;
use App\Models\Server;
use App\Models\Permission;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CommandControllerTest extends ClientApiIntegrationTestCase
{
    /**
     * Test that a validation error is returned if there is no command present in the
     * request.
     */
    public function testValidationErrorIsReturnedIfNoCommandIsPresent(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $response = $this->actingAs($user)->postJson("/api/client/servers/$server->uuid/command", [
            'command' => '',
        ]);

        $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $response->assertJsonPath('errors.0.meta.rule', 'required');
    }

    /**
     * Test that a subuser without the required permission receives an error when trying to
     * execute the command.
     */
    public function testSubuserWithoutPermissionReceivesError(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_WEBSOCKET_CONNECT]);

        $response = $this->actingAs($user)->postJson("/api/client/servers/$server->uuid/command", [
            'command' => 'say Test',
        ]);

        $response->assertStatus(Response::HTTP_FORBIDDEN);
    }

    /**
     * Test that a command can be sent to the server.
     */
    public function testCommandCanSendToServer(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_CONTROL_CONSOLE]);

        $server = \Mockery::mock($server)->makePartial();

        $this->instance(Server::class, $server);

        $server->expects('send')->with('say Test')->andReturn(new GuzzleResponse());

        $request = new SendCommandRequest(['command' => 'say Test']);
        $cc = resolve(CommandController::class);

        $response = $cc->index($request, $server);

        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    /**
     * Test that an error is returned when the server is offline that is more specific than the
     * regular daemon connection error.
     */
    public function testErrorIsReturnedWhenServerIsOffline(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $server = \Mockery::mock($server)->makePartial();
        $server->expects('send')->andThrows(
            new DaemonConnectionException(
                new BadResponseException('', new Request('GET', 'test'), new GuzzleResponse(Response::HTTP_BAD_GATEWAY))
            )
        );

        $this->instance(Server::class, $server);


        $request = new SendCommandRequest(['command' => 'say Test']);
        $cc = resolve(CommandController::class);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/Server must be online in order to send commands\./');

        $response = $cc->index($request, $server);

        $this->assertEquals(Response::HTTP_BAD_GATEWAY, $response->getStatusCode());
    }
}

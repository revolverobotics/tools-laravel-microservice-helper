<?php

namespace App\Submodules\ToolsLaravelMicroservice\App\Classes;

use App\Submodules\ToolsLaravelMicroservice\App\Classes\BackendRequest;

use Cache;

/**
 * Takes a user or client token as input and retrieves the associated
 * user_id or client_id.
 *
 * For backend services to check with api-service-users the access/scope
 * of a user/client token.
 */
class BackendAuthorizer
{
    /**
     * Connection to the api-service-users microservice
     *
     * @var BackendRequest
     */
    protected $connection;

    public function __construct()
    {
        $this->connection = new BackendRequest('users');
    }

    public function verify(string $OAuthToken)
    {
        $grant = Cache::tags(['grants'])->get($OAuthToken);

        if (is_null($grant)) {
            $response = $this->connection->post(
                'oauth',
                [
                    'access_token' => $OAuthToken
                ]
            );
        }
    }
}
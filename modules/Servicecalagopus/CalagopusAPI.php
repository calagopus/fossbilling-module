<?php

declare(strict_types=1);

namespace Box\Mod\Servicecalagopus;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CalagopusAPI
{
    private string $baseUrl;
    private string $apiKey;
    private HttpClientInterface $client;

    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->client = HttpClient::create(['bindto' => BIND_TO, 'timeout' => 30]);
    }

    /**
     * Make a GET request to the Calagopus API.
     */
    public function get(string $endpoint, array $query = []): array
    {
        return $this->request('GET', $endpoint, [], $query);
    }

    /**
     * Make a POST request to the Calagopus API.
     */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * Make a PATCH request to the Calagopus API.
     */
    public function patch(string $endpoint, array $data = []): array
    {
        return $this->request('PATCH', $endpoint, $data);
    }

    /**
     * Make a PUT request to the Calagopus API.
     */
    public function put(string $endpoint, array $data = []): array
    {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * Make a DELETE request to the Calagopus API.
     */
    public function delete(string $endpoint, array $data = []): array
    {
        return $this->request('DELETE', $endpoint, $data);
    }

    /**
     * @throws CalagopusAPIException
     */
    private function request(string $method, string $endpoint, array $data = [], array $query = []): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ],
        ];
        if (!empty($query)) {
            $options['query'] = $query;
        }
        if (!empty($data)) {
            $options['json'] = $data;
        }

        try {
            $response = $this->client->request($method, $this->baseUrl . $endpoint, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new CalagopusAPIException(0, 'Unable to connect to the Calagopus panel: ' . $e->getMessage());
        }

        $decoded = json_decode($body, true);

        if ($status >= 400) {
            // Calagopus returns a list of strings, Pterodactyl (used by the importer) a list of objects.
            $errors = array_map(
                fn ($error): string => is_array($error) ? (string) ($error['detail'] ?? $error['code'] ?? json_encode($error)) : (string) $error,
                is_array($decoded['errors'] ?? null) ? $decoded['errors'] : ['Unknown API error']
            );

            throw new CalagopusAPIException($status, 'API Error (HTTP ' . $status . '): ' . implode(', ', $errors));
        }

        if ($body !== '' && !is_array($decoded)) {
            throw new CalagopusAPIException($status, 'The panel returned an invalid response. Check the panel URL.');
        }

        return $decoded ?? [];
    }

    /**
     * Fetch every page of a paginated admin list endpoint.
     */
    public function getAll(string $endpoint, string $wrapper): array
    {
        $items = [];
        $page = 1;
        do {
            $response = $this->get($endpoint, ['page' => $page, 'per_page' => 100]);
            $data = $response[$wrapper]['data'] ?? [];
            array_push($items, ...$data);
            ++$page;
        } while (count($data) === 100 && $page <= 100);

        return $items;
    }

    /**
     * Find a user by their external ID.
     */
    public function getUserByExternalId(string $externalId): ?array
    {
        try {
            return $this->get('/api/admin/users/external/' . rawurlencode($externalId))['user'] ?? null;
        } catch (CalagopusAPIException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Create a new panel user.
     */
    public function createUser(array $data): array
    {
        return $this->post('/api/admin/users', $data)['user'];
    }

    /**
     * Search users by email or username.
     */
    public function searchUsers(string $search): array
    {
        $response = $this->get('/api/admin/users', [
            'page' => 1,
            'per_page' => 100,
            'search' => $search,
        ]);

        return $response['users']['data'] ?? [];
    }

    /**
     * Update a user by UUID.
     */
    public function updateUser(string $userUuid, array $data): array
    {
        return $this->patch('/api/admin/users/' . $userUuid, $data);
    }

    /**
     * Find or create a user, keyed by external ID.
     *
     * On a 409 conflict an existing panel user is only linked by email when the billing email is
     * verified, as an unverified email does not prove ownership of that panel account. Admin
     * panel users and users with a role are never linked, and users are never matched by username.
     */
    public function findOrCreateUser(string $externalId, string $email, string $firstName, string $lastName, string $username, string $language = 'en', bool $emailVerified = false): array
    {
        // 1. Try lookup by external ID first
        $existing = $this->getUserByExternalId($externalId);
        if ($existing) {
            return $existing;
        }

        // 2. Try creating the user
        try {
            return $this->createUser([
                'external_id' => $externalId,
                'username' => $username,
                'email' => $email,
                'name_first' => $firstName !== '' ? $firstName : null,
                'name_last' => $lastName !== '' ? $lastName : null,
                'admin' => false,
                'send_email' => true,
                'language' => $language,
            ]);
        } catch (CalagopusAPIException $e) {
            if ($e->status !== 409) {
                throw $e;
            }
        }

        // 3. The conflict may be this same user created concurrently
        $existing = $this->getUserByExternalId($externalId);
        if ($existing) {
            return $existing;
        }

        // 4. Link an existing panel user with the same email, only if the billing email is verified
        if ($emailVerified) {
            foreach ($this->searchUsers($email) as $user) {
                if (strcasecmp($user['email'] ?? '', $email) === 0 && empty($user['admin']) && empty($user['role']) && empty($user['external_id'])) {
                    $this->updateUser($user['uuid'], ['external_id' => $externalId]);

                    return $user;
                }
            }
        }

        throw new CalagopusAPIException(409, 'User with this email/username already exists on the panel. After verifying the client owns that panel account, set its external ID to ' . $externalId . ' to link it to this billing account.');
    }

    /**
     * Create a server with explicit node and allocations.
     */
    public function createServer(array $data): array
    {
        return $this->post('/api/admin/servers', $data)['server'];
    }

    /**
     * Deploy a server with automatic node/allocation selection.
     */
    public function deployServer(array $data): array
    {
        return $this->post('/api/admin/servers/deploy', $data)['server'];
    }

    /**
     * Get server details by UUID.
     */
    public function getServer(string $serverUuid): array
    {
        return $this->get('/api/admin/servers/' . $serverUuid)['server'];
    }

    /**
     * Get server details by external ID.
     */
    public function getServerByExternalId(string $externalId): ?array
    {
        try {
            return $this->get('/api/admin/servers/external/' . rawurlencode($externalId))['server'] ?? null;
        } catch (CalagopusAPIException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Update a server (name, limits, suspend, etc.).
     */
    public function updateServer(string $serverUuid, array $data): array
    {
        return $this->patch('/api/admin/servers/' . $serverUuid, $data);
    }

    /**
     * Suspend a server.
     */
    public function suspendServer(string $serverUuid): array
    {
        return $this->updateServer($serverUuid, ['suspended' => true]);
    }

    /**
     * Unsuspend a server.
     */
    public function unsuspendServer(string $serverUuid): array
    {
        return $this->updateServer($serverUuid, ['suspended' => false]);
    }

    /**
     * Delete a server.
     */
    public function deleteServer(string $serverUuid, bool $force = false, bool $deleteBackups = true): array
    {
        return $this->delete('/api/admin/servers/' . $serverUuid, [
            'force' => $force,
            'delete_backups' => $deleteBackups,
        ]);
    }

    /**
     * Update server variables.
     */
    public function updateServerVariables(string $serverUuid, array $variables): array
    {
        return $this->put('/api/admin/servers/' . $serverUuid . '/variables', [
            'variables' => $variables,
        ]);
    }

    /**
     * List all locations.
     */
    public function getLocations(): array
    {
        return $this->getAll('/api/admin/locations', 'locations');
    }

    /**
     * List all nodes.
     */
    public function getNodes(): array
    {
        return $this->getAll('/api/admin/nodes', 'nodes');
    }

    /**
     * List all backup configurations.
     */
    public function getBackupConfigurations(): array
    {
        return $this->getAll('/api/admin/backup-configurations', 'backup_configurations');
    }

    /**
     * List all nests with their eggs.
     */
    public function getNestsWithEggs(): array
    {
        return $this->get('/api/admin/nests/eggs')['nests'] ?? [];
    }

    /**
     * Get a single egg's details.
     */
    public function getEgg(string $nestUuid, string $eggUuid): array
    {
        return $this->get('/api/admin/nests/' . $nestUuid . '/eggs/' . $eggUuid)['egg'];
    }

    /**
     * Get egg variables.
     */
    public function getEggVariables(string $nestUuid, string $eggUuid): array
    {
        return $this->get('/api/admin/nests/' . $nestUuid . '/eggs/' . $eggUuid . '/variables')['variables'] ?? [];
    }

    /**
     * Live power state and usage of every server on a node, keyed by server UUID (cached ~15s by the panel).
     */
    public function getNodeServerResources(string $nodeUuid): array
    {
        return $this->get('/api/admin/nodes/' . $nodeUuid . '/servers/resources')['resources'] ?? [];
    }

    /**
     * List available allocations on a node.
     */
    public function getAvailableAllocations(string $nodeUuid): array
    {
        $response = $this->get('/api/admin/nodes/' . $nodeUuid . '/allocations/available', [
            'page' => 1,
            'per_page' => 10,
        ]);

        return $response['allocations']['data'] ?? [];
    }

    /**
     * Get the panel URL for a server (for "Go to Server" button).
     */
    public function getServerUrl(string $serverUuid): string
    {
        return $this->baseUrl . '/server/' . $serverUuid;
    }
}

<?php

declare(strict_types=1);

/**
 * Calagopus Pterodactyl Importer
 *
 * Migrates products and orders from the community Pterodactyl module (Servicepterodactyl) to the
 * Calagopus module. Nests, eggs, nodes and locations are matched by name between the two panels,
 * and servers are matched by their UUID, translated from the stored Pterodactyl server ID through
 * the old Pterodactyl API.
 */

namespace Box\Mod\Servicecalagopus;

use Box\Mod\Product\Entity\Product;
use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;

class PterodactylImporter implements InjectionAwareInterface
{
    private const PRODUCT_TYPE = 'pterodactyl';

    protected ?\Pimple\Container $di = null;

    private array $catalogs = [];

    public function __construct(private readonly Service $service)
    {
    }

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getStatus(): array
    {
        $dbal = $this->di['dbal'];
        $system = $this->di['mod_service']('system');

        return [
            'products' => (int) $dbal->fetchOne('SELECT COUNT(*) FROM product WHERE type = ?', [self::PRODUCT_TYPE]),
            'orders' => (int) $dbal->fetchOne('SELECT COUNT(*) FROM client_order WHERE service_type = ?', [self::PRODUCT_TYPE]),
            'pterodactyl_url' => (string) $system->getParamValue('servicepterodactyl_panel_url', ''),
            'has_pterodactyl_api_key' => (string) $system->getParamValue('servicepterodactyl_api_key', '') !== '',
        ];
    }

    /**
     * Imports all Pterodactyl products and their orders. Products whose nest, egg, or deployment
     * target cannot be matched are skipped, along with their orders.
     */
    public function import(string $pterodactylUrl, string $pterodactylApiKey): array
    {
        $system = $this->di['mod_service']('system');
        $storedUrl = (string) $system->getParamValue('servicepterodactyl_panel_url', '');
        if ($pterodactylUrl === '') {
            $pterodactylUrl = $storedUrl;
        }
        if ($pterodactylApiKey === '') {
            // The stored key is only reused for the stored panel, so it can't be sent to another host.
            if (rtrim($pterodactylUrl, '/') !== rtrim($storedUrl, '/')) {
                throw new InformationException('Enter the Pterodactyl application API key when using a different panel URL.');
            }
            $pterodactylApiKey = (string) $system->getParamValue('servicepterodactyl_api_key', '');
        }
        if ($pterodactylUrl === '' || $pterodactylApiKey === '') {
            throw new InformationException('The Pterodactyl panel URL and application API key are required to import.');
        }
        if (!preg_match('#^https?://#i', $pterodactylUrl)) {
            $pterodactylUrl = 'https://' . $pterodactylUrl;
        }

        $pterodactyl = new CalagopusAPI($pterodactylUrl, $pterodactylApiKey);
        $calagopus = $this->service->getApi();

        $results = [
            'products' => 0,
            'products_skipped' => 0,
            'orders' => 0,
            'orders_unmatched' => 0,
            'notes' => [],
        ];

        $em = $this->di['em'];
        $products = $em->getRepository(Product::class)->findBy(['type' => self::PRODUCT_TYPE]);

        foreach ($products as $product) {
            $config = $this->convertProductConfig($pterodactyl, $calagopus, $product, $results);
            if ($config === null) {
                ++$results['products_skipped'];

                continue;
            }

            $product->setType('calagopus');
            $product->setConfig(json_encode($config));
            $em->flush();
            ++$results['products'];
        }

        // Orders are picked up by their product's new type, so orders left behind by a failed run
        // are imported on the next one.
        $orders = $this->di['db']->find('ClientOrder', 'service_type = :type AND product_id IN (SELECT id FROM product WHERE type = :new_type)', [
            ':type' => self::PRODUCT_TYPE,
            ':new_type' => 'calagopus',
        ]);
        foreach ($orders as $order) {
            try {
                $this->importOrder($pterodactyl, $calagopus, $order, $results);
                ++$results['orders'];
            } catch (\Exception $e) {
                $results['notes'][] = sprintf('Order #%d was not imported: %s', $order->id, $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Read a Pterodactyl product setting. The module stores it either as "<key>" or as
     * "default_<key>", falling back to its global "servicepterodactyl_default_<key>" setting
     * unless $useGlobalDefault is off.
     */
    private function getPterodactylValue(array $config, string $key, bool $useGlobalDefault = true): string
    {
        foreach ([$key, 'default_' . $key] as $name) {
            if (isset($config[$name]) && is_scalar($config[$name]) && (string) $config[$name] !== '') {
                return trim((string) $config[$name]);
            }
        }

        if (!$useGlobalDefault) {
            return '';
        }

        return trim((string) $this->di['mod_service']('system')->getParamValue('servicepterodactyl_default_' . $key, ''));
    }

    private function convertProductConfig(CalagopusAPI $pterodactyl, CalagopusAPI $calagopus, Product $product, array &$results): ?array
    {
        $old = json_decode($product->getConfig() ?? '', true) ?? [];

        $eggId = $this->getPterodactylValue($old, 'egg_id') ?: $this->getPterodactylValue($old, 'egg');
        $nodeId = $this->getPterodactylValue($old, 'node_id') ?: $this->getPterodactylValue($old, 'node');
        $locationId = $this->getPterodactylValue($old, 'location_id');

        [$nestUuid, $eggUuid] = $this->matchEgg($pterodactyl, $calagopus, $eggId);
        $nodeUuid = $this->matchNode($pterodactyl, $calagopus, $nodeId);
        $locationUuid = $nodeUuid === null ? $this->matchLocation($pterodactyl, $calagopus, $locationId) : null;

        if ($eggUuid === null || ($nodeUuid === null && $locationUuid === null)) {
            $results['notes'][] = sprintf(
                'Product #%d (%s) was skipped: the %s could not be matched on the Calagopus panel.',
                $product->getId(),
                $product->getTitle(),
                $eggUuid === null ? 'egg' : 'node/location'
            );

            return null;
        }

        $variables = [];
        foreach (['environment', 'variables'] as $key) {
            if (isset($old[$key]) && is_array($old[$key])) {
                foreach ($old[$key] as $env => $value) {
                    if (is_string($env) && is_scalar($value)) {
                        $variables[$env] = (string) $value;
                    }
                }
            }
        }

        $new = [
            'nest_uuid' => $nestUuid,
            'egg_uuid' => $eggUuid,
            'node_uuid' => $nodeUuid ?? '',
            'location_uuids' => $locationUuid !== null ? [$locationUuid] : [],
            'memory' => $this->getPterodactylValue($old, 'memory'),
            'swap' => $this->getPterodactylValue($old, 'swap'),
            'disk' => $this->getPterodactylValue($old, 'disk'),
            'cpu' => $this->getPterodactylValue($old, 'cpu'),
            'io_weight' => $this->getPterodactylValue($old, 'io'),
            'allocations_limit' => $this->getPterodactylValue($old, 'allocations'),
            'database_limit' => $this->getPterodactylValue($old, 'databases'),
            'backup_limit' => $this->getPterodactylValue($old, 'backups'),
            // The Pterodactyl module provisions with the egg's image and startup unless the product sets
            // them, so its global defaults (always a Java image) are not carried over.
            'docker_image' => $this->getPterodactylValue($old, 'docker_image', false),
            'startup_command' => $this->getPterodactylValue($old, 'startup_command', false) ?: $this->getPterodactylValue($old, 'startup', false),
            'pinned_cpus' => $this->getPterodactylValue($old, 'cpu_pinning'),
            'variables' => $variables,
        ];

        foreach (Service::DEFAULTS as $key => $default) {
            if (!isset($new[$key]) || $new[$key] === '') {
                $new[$key] = $default;
            }
        }

        try {
            return $this->service->validateProductConfig($new);
        } catch (InformationException $e) {
            $results['notes'][] = sprintf('Product #%d (%s) was skipped: %s', $product->getId(), $product->getTitle(), $e->getMessage());

            return null;
        }
    }

    private function importOrder(CalagopusAPI $pterodactyl, CalagopusAPI $calagopus, \Model_ClientOrder $order, array &$results): void
    {
        $old = null;
        if (!empty($order->service_id)) {
            $old = $this->di['db']->findOne('service_pterodactyl', 'id = :id', [':id' => $order->service_id]);
        }

        $server = $old ? $this->findServer($pterodactyl, $calagopus, $old) : null;

        if ($server) {
            $calagopus->updateServer($server['uuid'], ['external_id' => Service::serverExternalId((int) $order->id)]);
            try {
                $this->linkOwner($calagopus, $server, (int) $order->client_id);
            } catch (CalagopusAPIException $e) {
                $results['notes'][] = sprintf('Order #%d: the server owner could not be linked to the client: %s', $order->id, $e->getMessage());
            }
        } elseif (!in_array($order->status, ['canceled', 'pending_setup', 'failed_setup'], true)) {
            ++$results['orders_unmatched'];
            $results['notes'][] = sprintf('Order #%d: no matching server was found on the Calagopus panel.', $order->id);
        }

        $model = $this->di['db']->dispense('service_calagopus');
        $model->client_id = $order->client_id;
        $model->server_uuid = $server['uuid'] ?? null;
        $model->created_at = $old->created_at ?? date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $serviceId = $this->di['db']->store($model);

        $order->service_type = 'calagopus';
        $order->service_id = $serviceId;
        $order->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($order);
    }

    /**
     * Translates the numeric Pterodactyl server ID to a UUID through the old Pterodactyl API,
     * then looks up that server on the Calagopus panel.
     */
    private function findServer(CalagopusAPI $pterodactyl, CalagopusAPI $calagopus, $old): ?array
    {
        if (empty($old->server_id)) {
            return null;
        }

        try {
            $uuid = $pterodactyl->get('/api/application/servers/' . (int) $old->server_id)['attributes']['uuid'] ?? null;
            if ($uuid) {
                return $calagopus->getServer($uuid);
            }
        } catch (CalagopusAPIException $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }

        return null;
    }

    /**
     * The Pterodactyl module links users by email only. Give the server owner this client's
     * external ID when the owner is that client's account and isn't linked to anything yet.
     */
    private function linkOwner(CalagopusAPI $calagopus, array $server, int $clientId): void
    {
        $owner = $server['owner'] ?? [];
        if (empty($owner['uuid']) || !empty($owner['external_id']) || !empty($owner['admin'])) {
            return;
        }

        $client = $this->di['db']->load('Client', $clientId);
        if (!$client || strcasecmp((string) $client->email, (string) ($owner['email'] ?? '')) !== 0) {
            return;
        }

        $calagopus->updateUser($owner['uuid'], ['external_id' => Service::userExternalId($clientId)]);
    }

    /**
     * Matches a Pterodactyl egg ID to a Calagopus nest and egg UUID. The panel migration keeps the
     * UUIDs, so those are tried first, then the nest and egg names.
     */
    private function matchEgg(CalagopusAPI $pterodactyl, CalagopusAPI $calagopus, string $eggId): array
    {
        if ($eggId === '') {
            return [null, null];
        }

        $source = null;
        foreach ($this->getPterodactylCatalog($pterodactyl, '/api/application/nests', ['include' => 'eggs']) as $nest) {
            foreach ($nest['relationships']['eggs']['data'] ?? [] as $egg) {
                if ((string) ($egg['attributes']['id'] ?? '') === $eggId) {
                    $source = ['nest' => $nest, 'egg' => $egg['attributes']];

                    break 2;
                }
            }
        }
        if ($source === null) {
            return [null, null];
        }

        $this->catalogs['calagopus_nests'] ??= $calagopus->getNestsWithEggs();
        foreach ([true, false] as $byUuid) {
            foreach ($this->catalogs['calagopus_nests'] as $entry) {
                foreach ($entry['eggs'] ?? [] as $egg) {
                    $matches = $byUuid
                        ? ($egg['uuid'] ?? null) === ($source['egg']['uuid'] ?? false)
                        : self::sameName($entry['nest']['name'] ?? '', $source['nest']['name'] ?? '') && self::sameName($egg['name'] ?? '', $source['egg']['name'] ?? '');
                    if ($matches) {
                        return [$entry['nest']['uuid'], $egg['uuid']];
                    }
                }
            }
        }

        return [null, null];
    }

    /**
     * Matches a Pterodactyl node ID to a Calagopus node UUID, by UUID first, then by name.
     */
    private function matchNode(CalagopusAPI $pterodactyl, CalagopusAPI $calagopus, string $nodeId): ?string
    {
        if ($nodeId === '') {
            return null;
        }

        $source = null;
        foreach ($this->getPterodactylCatalog($pterodactyl, '/api/application/nodes') as $node) {
            if ((string) ($node['id'] ?? '') === $nodeId) {
                $source = $node;

                break;
            }
        }
        if ($source === null) {
            return null;
        }

        $this->catalogs['calagopus_nodes'] ??= $calagopus->getNodes();
        foreach ([true, false] as $byUuid) {
            foreach ($this->catalogs['calagopus_nodes'] as $node) {
                if ($byUuid ? ($node['uuid'] ?? null) === ($source['uuid'] ?? false) : self::sameName($node['name'] ?? '', $source['name'] ?? '')) {
                    return $node['uuid'];
                }
            }
        }

        return null;
    }

    /**
     * Matches a Pterodactyl location ID to a Calagopus location UUID by name. The panel migration
     * names locations after their short name, so that is tried before the long name.
     */
    private function matchLocation(CalagopusAPI $pterodactyl, CalagopusAPI $calagopus, string $locationId): ?string
    {
        if ($locationId === '') {
            return null;
        }

        $names = [];
        foreach ($this->getPterodactylCatalog($pterodactyl, '/api/application/locations') as $location) {
            if ((string) ($location['id'] ?? '') === $locationId) {
                $names = array_filter([trim($location['short'] ?? ''), trim($location['long'] ?? '')]);

                break;
            }
        }

        $this->catalogs['calagopus_locations'] ??= $calagopus->getLocations();
        foreach ($names as $name) {
            foreach ($this->catalogs['calagopus_locations'] as $location) {
                if (self::sameName($location['name'] ?? '', $name)) {
                    return $location['uuid'];
                }
            }
        }

        return null;
    }

    private static function sameName(string $a, string $b): bool
    {
        return trim($a) !== '' && strcasecmp(trim($a), trim($b)) === 0;
    }

    /**
     * Fetches and caches a paginated list from the Pterodactyl application API.
     *
     * @return array list of item attributes
     */
    private function getPterodactylCatalog(CalagopusAPI $pterodactyl, string $endpoint, array $query = []): array
    {
        $cacheKey = 'pterodactyl_' . $endpoint;
        if (isset($this->catalogs[$cacheKey])) {
            return $this->catalogs[$cacheKey];
        }

        $items = [];
        $page = 1;
        do {
            $response = $pterodactyl->get($endpoint, $query + ['page' => $page, 'per_page' => 100]);
            foreach ($response['data'] ?? [] as $item) {
                $items[] = $item['attributes'] ?? [];
            }

            $totalPages = (int) ($response['meta']['pagination']['total_pages'] ?? 1);
            ++$page;
        } while ($page <= $totalPages && $page <= 100);

        return $this->catalogs[$cacheKey] = $items;
    }
}

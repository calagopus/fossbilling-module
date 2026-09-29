<?php

declare(strict_types=1);

/**
 * Calagopus FOSSBilling Provisioning Module
 *
 * A product type module for the Calagopus game server panel.
 * Similar to Pterodactyl but with support for custom/extension-added feature limits.
 *
 * @see https://calagopus.com
 */

namespace Box\Mod\Servicecalagopus;

use Box\Mod\Product\Entity\Product;
use FOSSBilling\InformationException;
use FOSSBilling\InjectionAwareInterface;
use RedBeanPHP\OODBBean;

class Service implements InjectionAwareInterface
{
    public const EXTENSION = 'mod_servicecalagopus';

    public const DEFAULTS = [
        'nest_uuid' => '',
        'egg_uuid' => '',
        'node_uuid' => '',
        'location_uuids' => [],
        'port_range' => '',
        'memory' => '1024',
        'swap' => '0',
        'disk' => '10240',
        'cpu' => '100',
        'memory_overhead' => '0',
        'io_weight' => '',
        'allocations_limit' => '1',
        'database_limit' => '0',
        'backup_limit' => '0',
        'schedule_limit' => '0',
        'custom_feature_limits' => '',
        'docker_image' => '',
        'startup_command' => '',
        'server_name_prefix' => '',
        'pinned_cpus' => '',
        'variables' => [],
        'skip_installer' => '0',
        'start_on_completion' => '1',
        'backup_configuration_uuid' => '',
        'hugepages_passthrough' => '0',
        'kvm_passthrough' => '0',
    ];

    /**
     * Product settings that a select or radio order form field may overwrite.
     */
    private const OVERRIDABLE_KEYS = [
        'nest_uuid', 'egg_uuid', 'node_uuid', 'location_uuids', 'port_range', 'memory', 'swap', 'disk', 'cpu',
        'memory_overhead', 'io_weight', 'allocations_limit', 'database_limit', 'backup_limit',
        'schedule_limit', 'docker_image', 'startup_command', 'pinned_cpus', 'skip_installer',
        'start_on_completion', 'backup_configuration_uuid', 'hugepages_passthrough', 'kvm_passthrough',
    ];

    private const CHOICE_FIELD_TYPES = ['select', 'radio'];

    /**
     * Order config keys the cart overwrites with product/pricing data at checkout, so order form
     * fields with these names never reach provisioning.
     */
    private const RESERVED_FIELD_NAMES = [
        'id', 'product_id', 'form_id', 'title', 'type', 'quantity', 'unit', 'price', 'setup_price',
        'discount', 'discount_price', 'discount_setup', 'total', 'period',
    ];

    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function getModulePermissions(): array
    {
        // Always accessible so the product configuration tab works for staff who can manage products;
        // every API method checks its own permission.
        return [
            'can_always_access' => true,
            'manage_servers' => [
                'type' => 'bool',
                'display_name' => __trans('Manage Calagopus servers'),
                'description' => __trans('Allows the staff member to sync Calagopus servers with their product configuration.'),
            ],
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        $this->di['db']->exec('
            CREATE TABLE IF NOT EXISTS `service_calagopus` (
                `id` bigint(20) NOT NULL AUTO_INCREMENT UNIQUE,
                `client_id` bigint(20) NOT NULL,
                `server_uuid` varchar(36),
                `created_at` datetime,
                `updated_at` datetime,
                PRIMARY KEY (`id`),
                KEY `client_id_idx` (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;');

        return true;
    }

    public function uninstall(): bool
    {
        // The service table is kept so existing orders can be recovered on reinstall.
        return true;
    }

    public function getSettings(): array
    {
        return $this->di['mod_service']('extension')->getConfig(self::EXTENSION);
    }

    /**
     * Save the module settings. A blank API key keeps the stored one.
     */
    public function saveSettings(array $data): bool
    {
        $current = $this->getSettings();

        $panelUrl = trim((string) ($data['panel_url'] ?? ''));
        if ($panelUrl !== '' && !preg_match('#^https?://#i', $panelUrl)) {
            $panelUrl = 'https://' . $panelUrl;
        }
        if ($panelUrl !== '' && filter_var($panelUrl, FILTER_VALIDATE_URL) === false) {
            throw new InformationException('Panel URL is not a valid URL.');
        }

        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($apiKey === '') {
            // The stored key is only reused for the same panel, so it can't be sent to another host.
            if (rtrim($panelUrl, '/') !== ($current['panel_url'] ?? '')) {
                throw new InformationException('Enter the API key again when changing the panel URL.');
            }
            $apiKey = (string) ($current['api_key'] ?? '');
        }

        $language = trim((string) ($data['user_language'] ?? ''));

        $settings = [
            'ext' => self::EXTENSION,
            'panel_url' => rtrim($panelUrl, '/'),
            'api_key' => $apiKey,
            'user_language' => $language !== '' ? $language : 'en',
        ];

        if ($settings['panel_url'] !== '' && $settings['api_key'] !== '') {
            (new CalagopusAPI($settings['panel_url'], $settings['api_key']))->get('/api/admin/locations', ['page' => 1, 'per_page' => 1]);
        }

        return $this->di['mod_service']('extension')->setConfig($settings);
    }

    public function getApi(): CalagopusAPI
    {
        $settings = $this->getSettings();
        if (empty($settings['panel_url']) || empty($settings['api_key'])) {
            throw new InformationException('The Calagopus module is not configured. Set the panel URL and API key in the module settings.');
        }

        return new CalagopusAPI($settings['panel_url'], $settings['api_key']);
    }

    public function testConnection(): bool
    {
        $this->getApi()->get('/api/admin/locations', ['page' => 1, 'per_page' => 1]);

        return true;
    }

    /**
     * Nests (with eggs), nodes, locations and backup configurations for the product configuration page.
     */
    public function getCatalog(): array
    {
        $api = $this->getApi();

        $nests = [];
        foreach ($api->getNestsWithEggs() as $entry) {
            $nests[] = [
                'uuid' => $entry['nest']['uuid'] ?? '',
                'name' => $entry['nest']['name'] ?? '',
                'eggs' => array_map(fn (array $egg): array => [
                    'uuid' => $egg['uuid'] ?? '',
                    'name' => $egg['name'] ?? '',
                ], $entry['eggs'] ?? []),
            ];
        }

        return [
            'nests' => $nests,
            'nodes' => array_map(fn (array $node): array => [
                'uuid' => $node['uuid'] ?? '',
                'name' => $node['name'] ?? '',
                'location' => $node['location']['name'] ?? '',
            ], $api->getNodes()),
            'locations' => array_map(fn (array $location): array => [
                'uuid' => $location['uuid'] ?? '',
                'name' => $location['name'] ?? '',
            ], $api->getLocations()),
            'backup_configurations' => array_map(fn (array $config): array => [
                'uuid' => $config['uuid'] ?? '',
                'name' => $config['name'] ?? '',
            ], $api->getBackupConfigurations()),
        ];
    }

    public function getEggVariables(string $nestUuid, string $eggUuid): array
    {
        if ($nestUuid === '' || $eggUuid === '') {
            return [];
        }

        return array_map(fn (array $variable): array => [
            'name' => $variable['name'] ?? '',
            'description' => $variable['description'] ?? '',
            'env_variable' => $variable['env_variable'] ?? '',
            'default_value' => $variable['default_value'] ?? '',
            'user_editable' => (bool) ($variable['user_editable'] ?? false),
            'rules' => $variable['rules'] ?? [],
        ], $this->getApi()->getEggVariables($nestUuid, $eggUuid));
    }

    public function validateProductConfig(array $config): array
    {
        $errors = [];

        if (empty($config['nest_uuid'])) {
            $errors[] = 'Nest is required.';
        }
        if (empty($config['egg_uuid'])) {
            $errors[] = 'Egg is required.';
        }
        if (empty($config['node_uuid']) && empty($config['location_uuids'])) {
            $errors[] = 'Either a node or at least one location (deploy mode) must be selected.';
        }

        $portRange = trim((string) ($config['port_range'] ?? ''));
        if ($portRange !== '' && self::parsePortRange($portRange) === null) {
            $errors[] = 'Port Range must be a port or a range like 25565-25665 (1-65535).';
        }

        $memory = (string) ($config['memory'] ?? '');
        if (!is_numeric($memory) || (int) $memory < 0) {
            $errors[] = 'Memory must be a non-negative integer (MB).';
        }

        $disk = (string) ($config['disk'] ?? '');
        if (!is_numeric($disk) || (int) $disk < 0) {
            $errors[] = 'Disk must be a non-negative integer (MB).';
        }

        $cpu = (string) ($config['cpu'] ?? '');
        if (!is_numeric($cpu) || (int) $cpu < 0) {
            $errors[] = 'CPU limit must be a non-negative integer.';
        }

        $swap = (string) ($config['swap'] ?? '');
        if ($swap !== '' && (!is_numeric($swap) || (int) $swap < -1)) {
            $errors[] = 'Swap must be -1 (unlimited) or a non-negative integer (MB).';
        }

        $memoryOverhead = (string) ($config['memory_overhead'] ?? '');
        if ($memoryOverhead !== '' && (!is_numeric($memoryOverhead) || (int) $memoryOverhead < 0)) {
            $errors[] = 'Memory Overhead must be a non-negative integer (MB).';
        }

        $ioWeight = (string) ($config['io_weight'] ?? '');
        if ($ioWeight !== '' && (!is_numeric($ioWeight) || (int) $ioWeight < 10 || (int) $ioWeight > 1000)) {
            $errors[] = 'IO Weight must be an integer between 10 and 1000.';
        }

        foreach (['allocations_limit' => 'Allocation Limit', 'database_limit' => 'Database Limit', 'backup_limit' => 'Backup Limit', 'schedule_limit' => 'Schedule Limit'] as $key => $label) {
            $value = (string) ($config[$key] ?? '');
            if ($value !== '' && (!is_numeric($value) || (int) $value < 0)) {
                $errors[] = $label . ' must be a non-negative integer.';
            }
        }

        $builtInLimits = array_intersect(array_keys(self::parseCustomFeatureLimits((string) ($config['custom_feature_limits'] ?? ''))), ['allocations', 'databases', 'backups', 'schedules']);
        if (!empty($builtInLimits)) {
            $errors[] = 'Custom Feature Limits cannot contain ' . implode(', ', $builtInLimits) . ', use the limit fields instead.';
        }

        if (!empty($errors)) {
            throw new InformationException(':errors', [':errors' => implode(' ', $errors)]);
        }

        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $config[$key] ?? $default;
            if (is_array($default)) {
                $value = is_array($value) ? $value : [];
                $clean[$key] = $key === 'variables'
                    ? array_map(fn ($v): string => trim((string) $v), $value)
                    : array_values(array_filter(array_map(fn ($v): string => trim((string) $v), $value)));
            } else {
                $clean[$key] = trim((string) $value);
            }
        }

        return $clean;
    }

    public function saveProductConfig(Product $product, array $config): bool
    {
        $clean = $this->validateProductConfig($config);

        // updateConfig() drops empty non-numeric values, which is fine as DEFAULTS fill them back in.
        return $this->di['mod_service']('product')->updateConfig($product, ['config' => $clean]);
    }

    /**
     * Only the billing period and the values of the product's own order form are kept from
     * client input. The product configuration itself is read live when provisioning, so it is
     * never copied into (client-visible, admin-editable) order config.
     */
    public function attachOrderConfig(Product|\Model_Product $product, array $data): array
    {
        $config = [];
        foreach (['period', 'quantity'] as $key) {
            if (isset($data[$key])) {
                $config[$key] = $data[$key];
            }
        }

        return array_merge($config, $this->validateFormValues($product, $data, false));
    }

    /**
     * Required order form fields are enforced here rather than in attachOrderConfig(), as admin
     * order creation calls this without a product (and not at all with skip_validation).
     */
    public function validateOrderData(array $data, Product|\Model_Product|null $product = null): void
    {
        if (isset($data['quantity']) && (int) $data['quantity'] > 1) {
            throw new InformationException('Only one Calagopus server can be ordered at a time.');
        }

        if ($product !== null) {
            $this->validateFormValues($product, $data);
        }
    }

    /**
     * Validate submitted order form values against the product's form (never a client-chosen form).
     *
     * @return array<string, mixed> field name => cleaned value
     */
    public function validateFormValues(Product|\Model_Product $product, array $data, bool $enforceRequired = true): array
    {
        $values = [];
        foreach ($this->getProductFormFields($product) as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || in_array(strtolower($name), self::RESERVED_FIELD_NAMES, true)) {
                continue;
            }

            if (!empty($field['readonly']) || !empty($field['hidden'])) {
                $value = $field['default_value'] ?? '';
            } else {
                $value = $data[$name] ?? '';
            }

            if ($field['type'] === 'checkbox') {
                $options = array_map('strval', array_values($field['options'] ?? []));
                $value = array_values(array_filter(array_map('strval', (array) $value), fn (string $v): bool => in_array($v, $options, true)));
            } else {
                if (!is_scalar($value)) {
                    throw new InformationException('Invalid value for :field.', [':field' => $field['label'] ?? $name]);
                }

                $value = trim((string) $value);
                if (mb_strlen($value) > 4096) {
                    throw new InformationException(':field is too long.', [':field' => $field['label'] ?? $name]);
                }

                if ($value !== '' && in_array($field['type'], self::CHOICE_FIELD_TYPES, true)) {
                    $options = array_map('strval', array_values($field['options'] ?? []));
                    if (!in_array($value, $options, true)) {
                        throw new InformationException('Invalid option selected for :field.', [':field' => $field['label'] ?? $name]);
                    }
                }
            }

            if ($enforceRequired && !empty($field['required']) && ($value === '' || $value === [])) {
                throw new InformationException(':field is required.', [':field' => $field['label'] ?? $name]);
            }

            $values[$name] = $value;
        }

        return $values;
    }

    private function getProductFormFields(Product|\Model_Product $product): array
    {
        $formId = $product instanceof Product ? $product->getFormId() : $product->form_id;
        if (empty($formId)) {
            return [];
        }

        try {
            return $this->di['mod_service']('formbuilder')->getForm((int) $formId)['fields'] ?? [];
        } catch (InformationException) {
            return [];
        }
    }

    private function getOrderProduct(\Model_ClientOrder $order): Product
    {
        return $this->di['mod_service']('product')->findProductById((int) $order->product_id);
    }

    /**
     * Resolve the effective settings for an order: the product configuration, overwritten by
     * choice (select/radio) order form fields named after a setting key.
     *
     * @return array{settings: array, fields: array, values: array}
     */
    private function resolveOrderSettings(\Model_ClientOrder $order): array
    {
        $product = $this->getOrderProduct($order);
        $settings = array_merge(self::DEFAULTS, json_decode($product->getConfig() ?? '', true) ?? []);

        $orderConfig = json_decode($order->config ?? '', true) ?? [];
        // Required fields are only enforced when ordering, so fields added to the form later don't break existing orders.
        $values = $this->validateFormValues($product, $orderConfig, false);

        // Formbuilder lowercases field names, so overrides are matched case-insensitively.
        $fields = [];
        foreach ($this->getProductFormFields($product) as $field) {
            $fields[strtolower((string) $field['name'])] = $field;
        }
        $values = array_change_key_case($values, CASE_LOWER);

        $resolved = ['settings' => $settings, 'fields' => $fields, 'values' => $values];

        foreach (self::OVERRIDABLE_KEYS as $key) {
            $value = $this->getOverride($resolved, $key, false);
            if ($value !== null) {
                $resolved['settings'][$key] = $key === 'location_uuids'
                    ? array_values(array_filter(array_map('trim', explode(',', $value))))
                    : $value;
            }
        }

        return $resolved;
    }

    /**
     * The order form value overriding the named setting, or null if there is none.
     * Free-text fields only count when $allowText is set; choice fields always do.
     */
    private function getOverride(array $resolved, string $name, bool $allowText): ?string
    {
        $name = strtolower($name);
        $field = $resolved['fields'][$name] ?? null;
        $value = $resolved['values'][$name] ?? null;

        if ($field === null || !is_string($value) || $value === '') {
            return null;
        }

        if (!$allowText && !in_array($field['type'], self::CHOICE_FIELD_TYPES, true)) {
            return null;
        }

        return $value;
    }

    private static function isEnabled(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['on', '1', 'yes', 'true'], true);
    }

    /**
     * Parse the custom feature limits from the config field.
     * Format: "key1:value1,key2:value2" e.g. "plugins:5,worlds:3"
     */
    public static function parseCustomFeatureLimits(string $raw): array
    {
        $limits = [];
        foreach (explode(',', trim($raw)) as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode(':', $pair, 2));
            if ($key !== '') {
                $limits[$key] = self::castFeatureLimit($value);
            }
        }

        return $limits;
    }

    private static function castFeatureLimit(string $value): int|bool|string
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (in_array(strtolower($value), ['true', 'false'], true)) {
            return strtolower($value) === 'true';
        }

        return $value;
    }

    /**
     * Parse "25565" or "25565-25665" into [start, end], or null if invalid.
     */
    public static function parsePortRange(string $raw): ?array
    {
        if (!preg_match('/^\s*(\d{1,5})\s*(?:-\s*(\d{1,5})\s*)?$/', $raw, $matches)) {
            return null;
        }

        $start = (int) $matches[1];
        $end = isset($matches[2]) ? (int) $matches[2] : $start;
        if ($start < 1 || $end > 65535 || $start > $end) {
            return null;
        }

        return [$start, $end];
    }

    public static function parsePinnedCpus(string $raw): array
    {
        return array_values(array_map('intval', array_filter(array_map('trim', explode(',', $raw)), 'is_numeric')));
    }

    private function buildLimits(array $settings): array
    {
        $limits = [
            'cpu' => (int) $settings['cpu'],
            'memory' => (int) $settings['memory'],
            'memory_overhead' => (int) $settings['memory_overhead'],
            'swap' => (int) $settings['swap'],
            'disk' => (int) $settings['disk'],
        ];

        if ((string) $settings['io_weight'] !== '') {
            $limits['io_weight'] = (int) $settings['io_weight'];
        }

        return $limits;
    }

    private function buildFeatureLimits(array $resolved): array
    {
        $settings = $resolved['settings'];

        $featureLimits = [
            'allocations' => (int) $settings['allocations_limit'],
            'databases' => (int) $settings['database_limit'],
            'backups' => (int) $settings['backup_limit'],
            'schedules' => (int) $settings['schedule_limit'],
        ];

        foreach (self::parseCustomFeatureLimits((string) $settings['custom_feature_limits']) as $key => $value) {
            if (array_key_exists($key, $featureLimits)) {
                continue;
            }

            $override = $this->getOverride($resolved, $key, false);
            $featureLimits[$key] = $override !== null ? self::castFeatureLimit($override) : $value;
        }

        return $featureLimits;
    }

    /**
     * Build the egg variable payload: the product's egg variables provide the base values, and each
     * egg variable can be overwritten by an order form field named after its environment variable.
     * Free-text fields may only overwrite variables the egg marks as user editable.
     */
    private function buildVariables(CalagopusAPI $api, array $resolved): array
    {
        $settings = $resolved['settings'];

        $variables = [];
        foreach ((array) $settings['variables'] as $env => $value) {
            if (is_string($env) && $env !== '' && (string) $value !== '') {
                $variables[$env] = (string) $value;
            }
        }

        foreach ($api->getEggVariables($settings['nest_uuid'], $settings['egg_uuid']) as $eggVariable) {
            $env = $eggVariable['env_variable'] ?? '';
            if ($env === '') {
                continue;
            }

            $override = $this->getOverride($resolved, $env, !empty($eggVariable['user_editable']));
            if ($override !== null) {
                $variables[$env] = $override;
            }
        }

        $payload = [];
        foreach ($variables as $env => $value) {
            $payload[] = ['env_variable' => $env, 'value' => $value];
        }

        return $payload;
    }

    public static function userExternalId(int $clientId): string
    {
        return 'fb-' . $clientId;
    }

    public static function serverExternalId(int $orderId): string
    {
        return 'fb-' . $orderId;
    }

    /**
     * Generate a panel-safe username from client details.
     */
    private function generateUsername(\Model_Client $client): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_]/', '', $client->first_name . $client->last_name);
        if (strlen($base) < 3) {
            $base = 'user';
        }

        $clientId = (string) $client->id;
        $truncatedBase = substr($base, 0, max(0, 14 - strlen($clientId)));

        return strtolower($truncatedBase) . '_' . $clientId;
    }

    private function findOrCreateUser(CalagopusAPI $api, \Model_Client $client): array
    {
        $settings = $this->getSettings();

        return $api->findOrCreateUser(
            self::userExternalId((int) $client->id),
            (string) $client->email,
            (string) $client->first_name,
            (string) $client->last_name,
            $this->generateUsername($client),
            $settings['user_language'] ?? 'en',
            (bool) $client->email_approved
        );
    }

    public function create(\Model_ClientOrder $order): OODBBean
    {
        $model = $this->di['db']->dispense('service_calagopus');
        $model->client_id = $order->client_id;
        $model->created_at = date('Y-m-d H:i:s');
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);

        return $model;
    }

    /**
     * Provision the server. Safe to retry: an existing server for this order is reused, and a
     * database lock keeps concurrent activations (e.g. payment callback + admin) from both creating one.
     */
    public function activate(\Model_ClientOrder $order, ?OODBBean $model): bool
    {
        $model = $this->requireModel($model);

        $lock = 'calagopus_order_' . (int) $order->id;
        if ((int) $this->di['db']->getCell('SELECT GET_LOCK(:lock, 120)', [':lock' => $lock]) !== 1) {
            throw new InformationException('This order is already being provisioned. Try again shortly.');
        }

        try {
            return $this->provision($order, $model);
        } finally {
            $this->di['db']->getCell('SELECT RELEASE_LOCK(:lock)', [':lock' => $lock]);
        }
    }

    private function provision(\Model_ClientOrder $order, OODBBean $model): bool
    {
        $api = $this->getApi();

        $existing = $api->getServerByExternalId(self::serverExternalId((int) $order->id));
        if ($existing) {
            $this->storeServerUuid($model, $existing['uuid']);

            return true;
        }

        $client = $this->di['db']->getExistingModelById('Client', $order->client_id, 'Client not found');
        $user = $this->findOrCreateUser($api, $client);

        $resolved = $this->resolveOrderSettings($order);
        $settings = $resolved['settings'];

        $egg = $api->getEgg($settings['nest_uuid'], $settings['egg_uuid']);

        $dockerImage = $settings['docker_image'] ?: (array_values($egg['docker_images'] ?? [])[0] ?? '');
        $startup = $settings['startup_command'] ?: ($egg['startup']
            ?? $egg['startup_commands']['Default']
            ?? (array_values($egg['startup_commands'] ?? [])[0] ?? ''));

        $serverName = $this->getOverride($resolved, 'server_name', true);
        if ($serverName === null || mb_strlen($serverName) < 3 || mb_strlen($serverName) > 255) {
            $serverName = ($settings['server_name_prefix'] ?: 'Server-') . $order->id;
        }

        $payload = [
            'owner_uuid' => $user['uuid'],
            'egg_uuid' => $settings['egg_uuid'],
            'start_on_completion' => self::isEnabled($settings['start_on_completion']),
            'skip_installer' => self::isEnabled($settings['skip_installer']),
            'external_id' => self::serverExternalId((int) $order->id),
            'name' => $serverName,
            'limits' => $this->buildLimits($settings),
            'pinned_cpus' => self::parsePinnedCpus((string) $settings['pinned_cpus']),
            'startup' => $startup,
            'image' => $dockerImage,
            'hugepages_passthrough_enabled' => self::isEnabled($settings['hugepages_passthrough']),
            'kvm_passthrough_enabled' => self::isEnabled($settings['kvm_passthrough']),
            'feature_limits' => $this->buildFeatureLimits($resolved),
            'variables' => $this->buildVariables($api, $resolved),
        ];

        if ($settings['backup_configuration_uuid'] !== '') {
            $payload['backup_configuration_uuid'] = $settings['backup_configuration_uuid'];
        }

        // Deploy mode (auto) vs explicit node
        if ($settings['node_uuid'] !== '') {
            $allocations = $api->getAvailableAllocations($settings['node_uuid']);
            if (empty($allocations)) {
                throw new InformationException('No available allocations on the selected node.');
            }

            $payload['node_uuid'] = $settings['node_uuid'];
            $payload['allocation_uuid'] = $allocations[0]['uuid'];
            $payload['allocation_uuids'] = [];

            $server = $api->createServer($payload);
        } else {
            $payload['deployment'] = [
                'location_uuids' => array_values((array) $settings['location_uuids']),
                'allow_overallocation' => false,
            ];

            // Without a port range the egg's configuration on the panel decides the allocations.
            $portRange = self::parsePortRange((string) $settings['port_range']);
            if ($portRange !== null) {
                $payload['deployment']['allocations'] = [
                    'dedicated' => false,
                    'primary' => ['start_port' => $portRange[0], 'end_port' => $portRange[1]],
                    'additional' => [],
                ];
            }

            $server = $api->deployServer($payload);
        }

        $this->storeServerUuid($model, $server['uuid']);

        return true;
    }

    public function renew(\Model_ClientOrder $order, ?OODBBean $model): bool
    {
        // Paid renewals unsuspend first, but a manual admin renewal of a suspended order doesn't.
        if ($order->status === 'suspended') {
            return $this->unsuspend($order, $model);
        }

        return true;
    }

    public function suspend(\Model_ClientOrder $order, ?OODBBean $model): bool
    {
        $this->getApi()->suspendServer($this->requireServerUuid($order, $model));

        return true;
    }

    public function unsuspend(\Model_ClientOrder $order, ?OODBBean $model): bool
    {
        $this->getApi()->unsuspendServer($this->requireServerUuid($order, $model));

        return true;
    }

    public function cancel(\Model_ClientOrder $order, ?OODBBean $model): bool
    {
        $api = $this->getApi();
        $serverUuid = $this->findServerUuid($api, $order, $model);
        if ($serverUuid === null) {
            // Nothing left to suspend, so the order can still be canceled.
            $this->di['logger']->info('Canceled order #%s has no server on the Calagopus panel.', $order->id);

            return true;
        }

        $api->suspendServer($serverUuid);

        return true;
    }

    public function uncancel(\Model_ClientOrder $order, ?OODBBean $model): bool
    {
        return $this->unsuspend($order, $model);
    }

    public function delete(\Model_ClientOrder $order, ?OODBBean $model): void
    {
        $api = $this->getApi();
        $serverUuid = $this->findServerUuid($api, $order, $model);
        if ($serverUuid !== null) {
            $api->deleteServer($serverUuid, false, true);
        }

        if ($model instanceof OODBBean && $model->id) {
            $this->di['db']->trash($model);
        }
    }

    /**
     * Push the product configuration (and order form overrides) to an existing server.
     * FOSSBilling has no product change hook, so this is triggered manually by an admin.
     */
    public function syncServer(\Model_ClientOrder $order): bool
    {
        $model = $this->requireModel($this->di['mod_service']('order')->getOrderService($order));
        $api = $this->getApi();
        $serverUuid = $this->requireServerUuid($order, $model);

        $resolved = $this->resolveOrderSettings($order);
        $settings = $resolved['settings'];

        // Variables first: the admin variables endpoint doesn't sync Wings, the server PATCH does.
        $variables = $this->buildVariables($api, $resolved);
        if (!empty($variables)) {
            $api->updateServerVariables($serverUuid, $variables);
        }

        $update = [
            'limits' => $this->buildLimits($settings),
            'feature_limits' => $this->buildFeatureLimits($resolved),
            'pinned_cpus' => self::parsePinnedCpus((string) $settings['pinned_cpus']),
            'hugepages_passthrough_enabled' => self::isEnabled($settings['hugepages_passthrough']),
            'kvm_passthrough_enabled' => self::isEnabled($settings['kvm_passthrough']),
        ];

        if ($settings['docker_image'] !== '') {
            $update['image'] = $settings['docker_image'];
        }

        $api->updateServer($serverUuid, $update);

        return true;
    }

    private function requireModel(mixed $model): OODBBean
    {
        if (!$model instanceof OODBBean || !$model->id) {
            throw new \FOSSBilling\Exception('Could not find the Calagopus service for this order.');
        }

        return $model;
    }

    private function storeServerUuid(OODBBean $model, string $serverUuid): void
    {
        $model->server_uuid = $serverUuid;
        $model->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($model);
    }

    /**
     * Resolve the UUID of the server that exists on the panel for this order, or null if none does.
     * external_id is the primary source of truth; the stored UUID is only a fallback and is
     * verified before use.
     */
    private function findServerUuid(CalagopusAPI $api, \Model_ClientOrder $order, ?OODBBean $model): ?string
    {
        $server = $api->getServerByExternalId(self::serverExternalId((int) $order->id));
        if (!empty($server['uuid'])) {
            return $server['uuid'];
        }

        if ($model instanceof OODBBean && !empty($model->server_uuid)) {
            try {
                return $api->getServer((string) $model->server_uuid)['uuid'] ?? null;
            } catch (CalagopusAPIException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }

        return null;
    }

    private function requireServerUuid(\Model_ClientOrder $order, ?OODBBean $model): string
    {
        $serverUuid = $this->findServerUuid($this->getApi(), $order, $model);
        if ($serverUuid === null) {
            throw new InformationException('Server not found on the Calagopus panel.');
        }

        return $serverUuid;
    }

    public function toApiArray(OODBBean $model, bool $deep = false, $identity = null): array
    {
        $result = [
            'id' => $model->id,
            'server_uuid' => $model->server_uuid,
            'created_at' => $model->created_at,
            'updated_at' => $model->updated_at,
        ];

        if (!$deep || empty($model->server_uuid)) {
            return $result;
        }

        try {
            $api = $this->getApi();
            $server = $api->getServer((string) $model->server_uuid);

            $result['server'] = [
                'uuid_short' => $server['uuid_short'] ?? null,
                'name' => $server['name'] ?? null,
                'is_suspended' => (bool) ($server['is_suspended'] ?? false),
                'address' => self::formatAddress($server['allocation'] ?? null),
                'limits' => $server['limits'] ?? [],
                'feature_limits' => $server['feature_limits'] ?? [],
                'egg' => $server['egg']['name'] ?? null,
                'nest' => $server['nest']['name'] ?? null,
                'location' => $server['node']['location']['name'] ?? null,
            ];

            $usage = $this->getServerUsage($api, $server);
            $result['server']['state'] = self::describeState($server, $usage);
            $result['server']['resources'] = self::describeResources($server['limits'] ?? [], $usage);
            $result['server']['includes'] = self::describeIncludes($server['feature_limits'] ?? []);
            $result['server']['uptime'] = ($usage['state'] ?? null) === 'running' ? self::formatUptime((int) ($usage['uptime'] ?? 0)) : null;
            $result['panel_url'] = $api->getServerUrl((string) $model->server_uuid);

            if ($identity instanceof \Model_Admin || $identity instanceof \Box\Mod\Staff\Entity\Admin) {
                $result['server']['node'] = $server['node']['name'] ?? null;
                $result['server']['owner'] = [
                    'uuid' => $server['owner']['uuid'] ?? null,
                    'username' => $server['owner']['username'] ?? null,
                    'email' => $server['owner']['email'] ?? null,
                ];
                $result['server']['external_id'] = $server['external_id'] ?? null;
            }
        } catch (\Exception $e) {
            $this->di['logger']->err('Unable to load Calagopus server %s: %s', $model->server_uuid, $e->getMessage());
            $result['error'] = __trans('Unable to load server information from the panel.');
        }

        return $result;
    }

    /**
     * Live power state and usage of the server from Wings, or null if the node can't be reached.
     */
    private function getServerUsage(CalagopusAPI $api, array $server): ?array
    {
        if (empty($server['node']['uuid']) || empty($server['uuid'])) {
            return null;
        }

        try {
            return $api->getNodeServerResources($server['node']['uuid'])[$server['uuid']] ?? null;
        } catch (\Exception $e) {
            $this->di['logger']->info('Unable to load live usage of Calagopus server %s: %s', $server['uuid'], $e->getMessage());

            return null;
        }
    }

    /**
     * The server state the panel shows its owner, in the panel's order of precedence:
     * suspended, transferring, node maintenance, install/restore state, then the live power state.
     *
     * @return array{key: string, label: string, badge: string}|null
     */
    private static function describeState(array $server, ?array $usage): ?array
    {
        $installStates = [
            'installing' => ['Installing', 'text-bg-info'],
            'install_failed' => ['Install Failed', 'text-bg-danger'],
            'restoring_backup' => ['Restoring Backup', 'text-bg-warning'],
            'backup_restore_failed' => ['Backup Restore Failed', 'text-bg-danger'],
        ];
        $powerStates = [
            'running' => ['Running', 'text-bg-success'],
            'starting' => ['Starting', 'text-bg-warning'],
            'stopping' => ['Stopping', 'text-bg-warning'],
            'offline' => ['Offline', 'text-bg-secondary'],
        ];

        [$key, $state] = match (true) {
            !empty($server['is_suspended']) => ['suspended', ['Suspended', 'text-bg-danger']],
            !empty($server['is_transferring']) => ['transferring', ['Transferring', 'text-bg-info']],
            !empty($server['node']['maintenance_enabled']) => ['maintenance', ['Node Maintenance', 'text-bg-danger']],
            isset($installStates[$server['status'] ?? '']) => [$server['status'], $installStates[$server['status']]],
            isset($powerStates[$usage['state'] ?? '']) => [$usage['state'], $powerStates[$usage['state']]],
            default => [null, null],
        };

        return $state === null ? null : ['key' => $key, 'label' => __trans($state[0]), 'badge' => $state[1]];
    }

    /**
     * Memory, disk and CPU as "used" (live, if known) and "limit" display strings, plus the used
     * percentage when both are known. Memory and CPU use only count while the server is up; disk
     * use is shown whatever the power state.
     */
    private static function describeResources(array $limits, ?array $usage): array
    {
        $isUp = in_array($usage['state'] ?? null, ['running', 'starting', 'stopping'], true);

        $memoryLimit = (int) ($limits['memory'] ?? 0);
        $diskLimit = (int) ($limits['disk'] ?? 0);
        $cpuLimit = (int) ($limits['cpu'] ?? 0);

        $resources = [
            'memory' => [$memoryLimit * 1048576, $isUp && isset($usage['memory_bytes']) ? (int) $usage['memory_bytes'] : null, fn (float $v): string => self::formatBytes($v)],
            'disk' => [$diskLimit * 1048576, isset($usage['disk_bytes']) ? (int) $usage['disk_bytes'] : null, fn (float $v): string => self::formatBytes($v)],
            'cpu' => [$cpuLimit, $isUp && isset($usage['cpu_absolute']) ? (float) $usage['cpu_absolute'] : null, fn (float $v): string => round($v, 1) . '%'],
        ];

        $result = [];
        foreach ($resources as $key => [$limit, $used, $format]) {
            $result[$key] = [
                'used' => $used !== null ? $format($used) : null,
                'limit' => $limit > 0 ? $format($limit) : __trans('Unlimited'),
                'percent' => $used !== null && $limit > 0 ? (int) min(100, round($used / $limit * 100)) : null,
            ];
        }

        return $result;
    }

    /**
     * Feature limits as label/value pairs: the built-in ones first, then any numeric limit an
     * extension adds, labelled from its key since the panel has no display names for them.
     *
     * @return list<array{label: string, value: int|float}>
     */
    private static function describeIncludes(array $featureLimits): array
    {
        $builtIn = [
            'databases' => __trans('Databases'),
            'backups' => __trans('Backups'),
            'allocations' => __trans('Ports'),
            'schedules' => __trans('Schedules'),
        ];

        $includes = [];
        foreach ($builtIn as $key => $label) {
            $includes[] = ['label' => $label, 'value' => (int) ($featureLimits[$key] ?? 0)];
        }

        foreach ($featureLimits as $key => $value) {
            if (!isset($builtIn[$key]) && (is_int($value) || is_float($value))) {
                $includes[] = ['label' => self::humanizeKey((string) $key), 'value' => $value];
            }
        }

        return $includes;
    }

    /**
     * "max_players", "max-players" and "maxPlayers" all become "Max players".
     */
    private static function humanizeKey(string $key): string
    {
        $words = preg_replace(['/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/', '/[_\-\s]+/'], ['$1 $2', '$1 $2', ' '], $key);

        return ucfirst(strtolower(trim($words)));
    }

    /**
     * The primary allocation as host:port, preferring the alias like the panel does. IPv6 hosts
     * are bracketed so the result can be pasted into a game client.
     */
    private static function formatAddress(?array $allocation): ?string
    {
        if (empty($allocation)) {
            return null;
        }

        $host = ($allocation['ip_alias'] ?? '') ?: ($allocation['ip'] ?? '');
        if (str_contains($host, ':')) {
            $host = '[' . $host . ']';
        }

        return $host . ':' . ($allocation['port'] ?? '');
    }

    private static function formatBytes(float $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            ++$i;
        }

        return rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
    }

    private static function formatUptime(int $milliseconds): string
    {
        $minutes = intdiv($milliseconds, 60000);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        return match (true) {
            $days > 0 => sprintf('%dd %dh', $days, $hours),
            $hours > 0 => sprintf('%dh %dm', $hours, $minutes % 60),
            default => sprintf('%dm', $minutes),
        };
    }
}

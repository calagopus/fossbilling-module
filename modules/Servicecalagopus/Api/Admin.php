<?php

declare(strict_types=1);

namespace Box\Mod\Servicecalagopus\Api;

use Box\Mod\Servicecalagopus\PterodactylImporter;
use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    /**
     * Get the module settings. The API key is never returned.
     */
    public function settings_get($data): array
    {
        $this->checkPermissions('servicecalagopus', 'manage_settings');

        $settings = $this->getService()->getSettings();

        return [
            'panel_url' => $settings['panel_url'] ?? '',
            'has_api_key' => !empty($settings['api_key']),
            'user_language' => $settings['user_language'] ?? 'en',
        ];
    }

    /**
     * Save the module settings. The connection is verified before saving.
     *
     * @optional string $panel_url     Calagopus panel URL
     * @optional string $api_key       Calagopus admin API key, leave blank to keep the current key
     * @optional string $user_language Language assigned to newly created panel users
     */
    public function settings_save($data): bool
    {
        $this->checkPermissions('servicecalagopus', 'manage_settings');

        return $this->getService()->saveSettings($data);
    }

    public function test_connection($data): bool
    {
        $this->checkPermissions('servicecalagopus', 'manage_settings');

        return $this->getService()->testConnection();
    }

    /**
     * Nests (with eggs), nodes, locations and backup configurations available on the panel, plus the
     * variables of the given egg. Panel errors are returned as "error" so the product page still renders.
     *
     * @optional string $nest_uuid
     * @optional string $egg_uuid
     */
    public function catalog($data): array
    {
        $this->checkPermissions('product', 'manage_products');

        try {
            $catalog = $this->getService()->getCatalog();
            $catalog['variables'] = $this->getService()->getEggVariables((string) ($data['nest_uuid'] ?? ''), (string) ($data['egg_uuid'] ?? ''));

            return $catalog;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Validate and save a Calagopus product's configuration.
     */
    #[RequiredParams(['id' => 'Product ID was not passed'])]
    public function product_config_save($data): bool
    {
        $this->checkPermissions('product', 'manage_products');

        $product = $this->di['mod_service']('product')->findProductById((int) $data['id']);
        if ($product->getType() !== 'calagopus') {
            throw new \FOSSBilling\InformationException('Product is not a Calagopus product.');
        }

        return $this->getService()->saveProductConfig($product, is_array($data['config'] ?? null) ? $data['config'] : []);
    }

    /**
     * Push the product configuration to the order's server (limits, feature limits, variables).
     */
    #[RequiredParams(['order_id' => 'Order ID was not passed'])]
    public function sync($data): bool
    {
        $this->checkPermissions('servicecalagopus', 'manage_servers');

        return $this->getService()->syncServer($this->getOrder($data));
    }

    /**
     * Summary of the Pterodactyl module data that can be imported.
     */
    public function import_status($data): array
    {
        $this->checkPermissions('servicecalagopus', 'manage_settings');

        return $this->getImporter()->getStatus();
    }

    /**
     * Import products and orders from the Pterodactyl module.
     *
     * @optional string $pterodactyl_url     Pterodactyl panel URL, defaults to the Pterodactyl module setting
     * @optional string $pterodactyl_api_key Pterodactyl application API key, defaults to the Pterodactyl module setting
     */
    public function import_run($data): array
    {
        $this->checkPermissions('servicecalagopus', 'manage_settings');

        return $this->getImporter()->import(
            trim((string) ($data['pterodactyl_url'] ?? '')),
            trim((string) ($data['pterodactyl_api_key'] ?? ''))
        );
    }

    private function getImporter(): PterodactylImporter
    {
        $importer = new PterodactylImporter($this->getService());
        $importer->setDi($this->di);

        return $importer;
    }

    private function getOrder($data): \Model_ClientOrder
    {
        $order = $this->di['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found');
        if ($order->service_type !== 'calagopus') {
            throw new \FOSSBilling\InformationException('Order is not a Calagopus order.');
        }

        return $order;
    }
}

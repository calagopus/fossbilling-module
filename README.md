![Calagopus Logo](https://calagopus.com/fulllogo.svg)

# Calagopus FOSSBilling Module

<https://calagopus.com>

A product type module for [FOSSBilling](https://fossbilling.org) (0.8+) that provisions and
manages servers on the [Calagopus](https://calagopus.com) game server panel, with support for
nests/eggs, location- or node-based deployment, egg variables, and custom (extension-added)
feature limits.

## Installation

1. Upload the `modules/Servicecalagopus/` directory to your FOSSBilling installation at:

   ```sh
   /path/to/fossbilling/modules/Servicecalagopus/
   ```

2. In FOSSBilling Admin, go to **Extensions → Overview**, find **Calagopus**, and click the activate
   (▷) button.

3. Open the module settings (the ⚙ button next to **Calagopus** in **Extensions → Overview**) and
   configure:
   - **Panel URL**: Your Calagopus panel URL (e.g. `https://panel.example.com`)
   - **API Key**: Your Calagopus admin API key
   - **Default User Language**: The language assigned to created panel users (e.g. `en`)

   Saving verifies the connection against the panel.

4. Create a **Product** with the type **Calagopus**, then open its **Configuration** tab to choose
   the egg, deployment target (a specific node, or one or more locations), resource limits,
   feature limits and egg variables. Save after changing the egg to load its variables.

## Lifecycle

| FOSSBilling action | Panel action |
| --- | --- |
| Activate | Creates the panel user (if needed) and the server |
| Suspend / Cancel | Suspends the server |
| Unsuspend / Uncancel | Unsuspends the server |
| Delete | Deletes the server (including backups) |

Panel users are linked by the external ID `fb-<client id>` and servers by `fb-<order id>`, so
activation is safe to retry. If a panel user with the same email already exists, it is only
linked when the client's email address is verified in FOSSBilling and the panel user is not an
admin and has no role; otherwise activation fails and the panel user must be linked manually by
setting its external ID.

Canceling an order whose server no longer exists on the panel succeeds without changes. Renewing a
suspended order unsuspends its server.

FOSSBilling has no hook for product changes. After changing a product's configuration or moving
an order to a different product, use **Sync with Panel** on the order's
**Service Management** tab to push the limits, feature limits and egg variables to the server.

## Deployment Modes

- **Node**: Select a specific node and the module will use the first available allocation on it.
- **Auto (locations)**: Leave the node as *Auto* and select one or more locations; Calagopus will
  pick a node automatically. Set a **Port Range** to pick the primary allocation from it; without
  one, allocations are assigned according to the egg's configuration on the panel, and a server may
  be created without an allocation if the egg has none.

## Overwriting Values Through the Order Form

Create a form under **System → Settings → Form Builder** and select it as the **Order Form** on a
Calagopus product's **General Settings** tab to let clients customise their server during checkout. The form's field names decide what they overwrite:

- **Dropdown or radio fields** named after a setting key overwrite that setting, e.g. a `memory`
  dropdown with options `2048` and `4096`. The submitted value must be one of the field's options.
- **Any field type** named `server_name` sets the server name (3-255 characters).
- **Egg variables** are overwritten by a field named after the variable's environment variable,
  in any case (e.g. `minecraft_version` for `MINECRAFT_VERSION`). Text fields only work for
  variables the egg marks as user editable; dropdown and radio fields work for any variable.
- **Custom feature limits**: each key defined in the product's *Custom Feature Limits* (e.g.
  `plugins:5,worlds:3`) can be overwritten by a dropdown or radio field with the same name.

Free-text fields never overwrite resource or placement settings. Form fields do not affect the
price, so priced tiers should be separate products. Fields named `id`, `product_id`, `form_id`,
`title`, `type`, `quantity`, `unit`, `price`, `setup_price`, `discount`, `discount_price`,
`discount_setup`, `total` or `period` are ignored, as FOSSBilling overwrites them at checkout.

### Overridable settings

| Key | Setting |
| --- | --- |
| `nest_uuid` | Nest UUID |
| `egg_uuid` | Egg UUID |
| `node_uuid` | Node UUID |
| `location_uuids` | Location UUIDs, comma-separated |
| `port_range` | Port Range (deploy mode), e.g. `25565-25665` |
| `memory` | Memory (MB) |
| `swap` | Swap (MB) |
| `disk` | Disk (MB) |
| `cpu` | CPU Limit (%) |
| `memory_overhead` | Memory Overhead (MB) |
| `io_weight` | IO Weight (10-1000) |
| `allocations_limit` | Allocation Limit |
| `database_limit` | Database Limit |
| `backup_limit` | Backup Limit |
| `schedule_limit` | Schedule Limit |
| `docker_image` | Docker Image |
| `startup_command` | Startup Command |
| `pinned_cpus` | Pinned CPUs, comma-separated core ids |
| `skip_installer` | Skip Installer |
| `start_on_completion` | Start on Completion |
| `backup_configuration_uuid` | Backup Configuration UUID |
| `hugepages_passthrough` | Hugepages Passthrough |
| `kvm_passthrough` | KVM Passthrough |

For yes/no settings, values of `on`, `1`, `yes` or `true` enable the setting; anything else
disables it.

## Migrating from the Pterodactyl Module

If you previously sold servers through the community FOSSBilling **Pterodactyl** module
(`Servicepterodactyl`) and have migrated your panel to Calagopus, the module can import your
existing products and orders.

Prerequisites:

1. Your servers, users, nests, eggs, nodes and locations have already been migrated to the
   Calagopus panel. Eggs and nodes are matched by UUID (kept by the panel migration), then by
   name; locations are matched by name.
2. The Pterodactyl panel is still reachable. Servers are matched by translating the stored
   Pterodactyl server ID to its UUID through the Pterodactyl application API.

The **Import from Pterodactyl** section appears on the module settings page while Pterodactyl
products or orders exist. The Pterodactyl panel URL and application API key default to the
Pterodactyl module's settings.

The import:

- Converts each Pterodactyl product to a Calagopus product, mapping its egg and node by UUID (or
  by name if the UUID isn't found), or its location by name, and its limits (`io` → `IO Weight`, `databases`/`allocations`/`backups` limits, docker image,
  startup command and environment variables). Both `<key>` and `default_<key>` settings are read.
- Converts each order of an imported product, linking it to the matching server on the Calagopus
  panel and setting the server's external ID to `fb-<order id>`.
- Links the server owner to the client (`fb-<client id>`) when the owner's email matches the
  client's and the owner isn't linked to anything yet.

Anything that cannot be matched is skipped and reported in the results, so the import can be
run again after fixing it on the panel.

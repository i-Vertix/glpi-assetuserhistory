# Asset-User History Plugin for GLPI

This plugin introduces a dedicated history for asset/user relations.

## 📋 Functionalities

- New user-history tab for asset
- New asset-history tab for user

## 📌 Information

This plugin is not reliant on the default in-built history. The plugin introduces a new table to store the user relation
changes of assets. Therefore, the plugin is also not affected by any *Logs purge settings* done in *Setup/General/Logs
purge*.

Once installed and activated, the plugin hooks (using database triggers due to missing hook-support on inventory
updates) into asset creations/updates/deletes and stores every user relation change separately.

By default, the history is gathered and activated for the following assets:

* **Computers**
* **Monitors**
* **Network devices**
* **Devices**
* **Printers**
* **Phones**

> [!NOTE]
> Since version `1.2.1` it is possible to configure, for which assets the asset-user history is enabled.
> The new config tab is located in `Setup > General`.

### 🆕 Changes and new features in 1.2.0 (for GLPI 11)

- added a new profile right to view asset-user and/or user-asset history

> [!NOTE]
> During the installation of version `1.2.0`, the right to view the asset history (in user) is automatically set to
> every profile
> with *READ* permissions for *User*. The right to view the user history (in asset) is automatically set to every
> profile with *READ* permissions for **any** *Asset*.

- history entry is no longer deleted when a user gets deleted (shows as *User deleted* in asset-user history)
- ~~added the possibility to inject custom asset types **before** installing the
  plugin ([see the instructions](#-changes-and-new-features-in-120-for-glpi-11))~~

### 🆕 Changes and new features in 1.2.1 (for GLPI 11)

- added a new config page to configure for which asset types the asset-user history should be enabled

  > [!NOTE]
  > Asset-user history can only be enabled for *assignable* assets (also includes assets from custom asset definitions)

### ♻️ Lifecycle

* *Asset* **created**, *User* **set**: a history entry gets **created**
* *Asset* **updated**, *User* **changed**: the latest history entry for the asset is **marked as *revoked***, a new
  entry is **created**
* **Asset deleted** (permanently): asset history is cleared
* **User deleted** (permanently): all history entries for deleted user **~~are deleted~~ stay** to provide a full
  asset-user history (deleted users are shown as *User deleted*)

> [!NOTE]
> ~~When an asset or a user gets **permanently** deleted, all history depending on the deleted item is deleted too.~~
> ~~This is done because from our point of view, history for a deleted user or asset is no longer in anyone's
interest.~~
>
> After using the plugin a bit more, we decided that it makes more sense to keep deleted users still in the history
> to keep the timeline complete.

## 🔧 Installation

1. Download the latest version
   from [https://github.com/i-Vertix/glpi-assetuserhistory/releases](https://github.com/i-Vertix/glpi-assetuserhistory/releases).
2. Extract the archive into the GLPI `plugins` folder (when updating, make sure to delete the current `assetuserhistory`
   folder
   first)
3. The new folder inside of `plugins` must be named `assetuserhistory`

> [!IMPORTANT]
> Before installing the plugin, make sure the GLPI database user can manage (create/update/execute/drop) triggers!
>
> When using MySQL instead of MariaDB you must enable `log_bin_trust_function_creators` to allow the creation of
> triggers.

4. Log into GLPI with a super-admin account and install the plugin
5. Activate the plugin after installation

> [!NOTE]
> In case the plugin is freshly installed, the current relations between assets and users are automatically imported
> into the history.

## 🔎 View the history

### 🖥️ For Assets

A new tab called *User history* is available on the mentioned asset forms:

| Login  	 | Assigned            	 | Revoked             	 |
|----------|-----------------------|-----------------------|
| User 1 	 | 2023-05-03 15:00:00 	 | 2023-05-04 10:00:00 	 |
| User 2 	 | 2023-05-04 10:00:00 	 | 2023-05-04 18:00:00 	 |
| User 1 	 | 2023-05-04 18:00:00 	 | 	                     |

### 🙋‍♂️ For Users

A new tab called *Asset history* is available in the user form:

| Name       | Type     | Assigned            | Revoked             |
|------------|----------|---------------------|---------------------|
| PC1      	 | Computer | 2023-05-03 15:00:00 | 2023-05-04 18:00:00 |
| Monitor1 	 | Monitor  | 2023-05-04 10:00:00 |                     |
| PC1      	 | Computer | 2023-05-04 18:00:00 |                     |
| ...        | ...      | ...                 | ...                 |

### 🗿 Troubleshooting

#### Asset history/User history tabs are not visible

On version 1.2.0 the plugin introduced a new *profile right* which handles the permission of a user-profile to view the
history.
Please configure the right

#### Migration form 1.2.0

Please open an issue with detailed log information (PHP and SQL errors) in case the migration from `1.1.0` to `1.2.0`
fails.

#### No history written

In case no history is present for any asset and the user got recently changed, something may be wrong with the database
triggers.
Verify any active triggers with the following database query:

```sql
SHOW TRIGGERS from `glpi`;
```

Replace *\`glpi\`* with your GLPI database name.

The results should show something like this:

| Trigger                                         | Event  | Table                  | Statement     | Timing | Created                | sql_mode                                                                                  | Definer | character_set_client | collation_connection | Database Collation |
|-------------------------------------------------|--------|------------------------|---------------|--------|------------------------|-------------------------------------------------------------------------------------------|---------|----------------------|----------------------|--------------------|
| plugin_assetuserhistory_computer_add            | INSERT | glpi_computers         | begin ... end | AFTER  | 2025-10-22 14:00:38.61 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_computer_update         | UPDATE | glpi_computers         | begin ... end | AFTER  | 2025-10-22 14:00:38.65 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_monitor_add             | INSERT | glpi_monitors          | begin ... end | AFTER  | 2025-10-22 14:00:38.72 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_monitor_update          | UPDATE | glpi_monitors          | begin ... end | AFTER  | 2025-10-22 14:00:38.74 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_networkequipment_add    | INSERT | glpi_networkequipments | begin ... end | AFTER  | 2025-10-22 14:00:38.77 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_networkequipment_update | UPDATE | glpi_networkequipments | begin ... end | AFTER  | 2025-10-22 14:00:38.79 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_peripheral_add          | INSERT | glpi_peripherals       | begin ... end | AFTER  | 2025-10-22 14:00:38.82 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_peripheral_update       | UPDATE | glpi_peripherals       | begin ... end | AFTER  | 2025-10-22 14:00:38.83 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_phone_add               | INSERT | glpi_phones            | begin ... end | AFTER  | 2025-10-22 14:00:38.87 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_phone_update            | UPDATE | glpi_phones            | begin ... end | AFTER  | 2025-10-22 14:00:38.90 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_printer_add             | INSERT | glpi_printers          | begin ... end | AFTER  | 2025-10-22 14:00:38.94 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |
| plugin_assetuserhistory_printer_update          | UPDATE | glpi_printers          | begin ... end | AFTER  | 2025-10-22 14:00:38.96 | STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION | glpi@%  | utf8mb4              | utf8mb4_unicode_ci   | latin1_swedish_ci  |

In case you are missing these triggers, please reinstall the plugin completely. Create a backup of the following table
if you want to restore your current history afterward: `glpi_plugin_assetuserhistory_histories`.

#### Error while updating asset or user

It's possible that these errors are coming from our beloved triggers, which listen for *INSERT* and *UPDATE* changes on
all common asset tables and the user table.
Please verify the triggers created by the plugin using the [instructions above](#no-history-written).

## 📢 CONTRIBUTING

Open a ticket for any encountered bug or some useful features, so it can be discussed.
We will answer as soon as possible to your request or problem.

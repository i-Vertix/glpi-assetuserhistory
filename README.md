# Asset-User History Plugin for GLPI

## INFO

This plugin provides a more readable history for asset/user relations.
Once installed and activated the plugin hooks into asset updates and stores every user-relation change separately.

**Currently supported asset types:**

* **Computers**
* **Monitors**
* **Network devices**
* **Devices**
* **Printers**
* **Phones**

> **Note:**
> When an asset or a user gets **permanently** deleted, all history depending on the deleted item is deleted too.
> This is done because in our point of view, history for a deleted user or asset is no longer in anyone's interest.

### LIFECYCLE

* **Asset created**: a history item **is written** after asset creation **if a user id is assigned** to the asset
* **Asset updated**: previous history item **is updated** after asset update **if the user id changes** - a new history
  item **is written** after asset update **if a new user id was provided**
* **Asset deleted**: all history items for corresponding asset **are deleted** after asset deletion (permanently)
* **User deleted**: all history items for corresponding user **are deleted** after user deletion (permanently)

## INSTALLATION

Download plugin archive from https://github.com/i-Vertix/glpi-modifications/releases for the required GLPI version and
unzip the archive to the **glpi plugins folder**. After unzipping a new folder called **"assetuserhistory"** should
appear in your plugin folder.
If not, make sure the unzipped folder is located in the glpi plugins folder (**glpi/plugins**) and is renamed to
"assetuserhistory".

## VIEWS

### ASSETS

A new table is available in the form view for every above-mentioned asset-type:

| Login  	 | Assigned            	 | Revoked             	 |
|----------|-----------------------|-----------------------|
| User 1 	 | 2023-05-03 15:00:00 	 | 2023-05-04 10:00:00 	 |
| User 2 	 | 2023-05-04 10:00:00 	 | 2023-05-04 18:00:00 	 |
| User 1 	 | 2023-05-04 18:00:00 	 | 	                     |
| ...      | ...                   | ...                   |

### USER

A new table is available in the form view of a user:

| Name     	 | Type     	 | Assigned            	 | Revoked             	 |
|------------|------------|-----------------------|-----------------------|
| PC1      	 | Computer 	 | 2023-05-03 15:00:00 	 | 2023-05-04 18:00:00 	 |
| Monitor1 	 | Monitor  	 | 2023-05-04 10:00:00 	 | 	                     |
| PC1      	 | Computer 	 | 2023-05-04 18:00:00 	 | 	                     |
| ...        | ...        | ...                   | ...                   |

### DATA ACCESS & PERMISSIONS

> The above-mentioned views are always visible (as tabs in asset or user form). Visible history items depend on the
> current entities selected by the user and general profile permissions (for example if the user-profile is permitted to
> view an asset type). If the history item's asset type or related user are not available for the current user, the
> history item won't be shown.

## CONTRIBUTING

Open a ticket for each bug/feature, so it can be discussed.
We will answer as soon as possible to your request or problem.

# Modrinth Integration

A [Pelican Panel](https://pelican.dev) plugin to install, update and manage Minecraft **mods, plugins and datapacks**
from [Modrinth](https://modrinth.com) right in the server panel: one page per server, automatic dependencies,
"Update all", and import/export of lists between servers.

> [!NOTE]
> **This project was implemented with the help of AI.** Large parts of the code, the tests and this documentation
> were written with an AI assistant (Claude by Anthropic). Treat it like any other third-party plugin and try it on a
> test server before using it on servers you care about.

Author: MaxGojko. Based on [Minecraft Modrinth](https://hub.pelican.dev/plugins/minecraft-modrinth) by Boy132 &
H1ghSyst3m and, like it, licensed under the **GPL-3.0** (see [`LICENSE`](LICENSE)).

## Installation

1. Download this repository as a zip (Code → Download ZIP) or take a release zip.
2. In the panel go to **Admin → Plugins → Import** and upload the zip.
3. Set the egg tags described below.

The plugin id is `modrinth-integration`. Pelican runs `yarn` when installing any plugin; this plugin has no frontend
assets, so a missing `yarn` only leaves a warning in the log (unless `PANEL_PLUGIN_DEV_MODE` is on, which turns it
into an error).

### Switching from "Minecraft Modrinth"

This plugin has its own id, so importing it doesn't replace the original. Uninstall "Minecraft Modrinth" first,
otherwise both show up in the menu. Nothing on the game servers is lost: both keep their list of installed projects in
the same `.modrinth-metadata.json`, so everything installed with the original shows up here as installed and can be
updated.

The original was switched on with the egg _features_ `modrinth_mods` / `modrinth_plugins` (or the `minecraft` tag
plus `mods` / `plugins` features). Features are no longer read: move them to the egg's _tags_ as shown below. The
`minecraft` tag is no longer needed.

## Setup: egg tags

Everything is switched on with **tags on the egg** (Admin → Eggs → your egg → Tags). Each server gets one
**Modrinth** page; a dropdown at the top switches between the types the egg's tags turn on, and only those appear in
it. Without any of the type tags the page is hidden.

| Tag                  | What it does                         | Installs into        |
|----------------------|--------------------------------------|----------------------|
| `modrinth_mods`      | Adds **Mods** to the dropdown        | `mods/`              |
| `modrinth_plugins`   | Adds **Plugins** to the dropdown     | `plugins/`           |
| `modrinth_datapacks` | Adds **Datapacks** to the dropdown   | `<world>/datapacks/` |
| loader name          | Which Modrinth loader(s) to look for | -                    |

Mods and plugins also need a tag with the name of the **Modrinth loader** the server runs, so the list only shows
projects that work on it. Every tag that is a Modrinth loader for the type counts (in the egg's order), so a Paper egg
tagged `paper`, `spigot` and `bukkit` finds plugins published for any of them, and a hybrid server can have a mod
loader and a plugin loader at the same time.

| Type    | Common loader tags (any Modrinth loader name for that type works)                               |
|---------|-------------------------------------------------------------------------------------------------|
| Mods    | `fabric`, `quilt`, `forge`, `neoforge`                                                          |
| Plugins | `paper`, `purpur`, `folia`, `spigot`, `bukkit`, `sponge`, `velocity`, `bungeecord`, `waterfall` |

Datapacks don't need a loader tag, they work on any server. Tags are case-insensitive. Examples:

| Server                     | Tags                                                      |
|----------------------------|-----------------------------------------------------------|
| Fabric                     | `modrinth_mods`, `fabric`, `modrinth_datapacks`           |
| Paper                      | `modrinth_plugins`, `paper`, `modrinth_datapacks`         |
| NeoForge + Bukkit (hybrid) | `modrinth_mods`, `neoforge`, `modrinth_plugins`, `bukkit` |
| Vanilla                    | `modrinth_datapacks`                                      |

The Minecraft version comes from the server's `MINECRAFT_VERSION` or `MC_VERSION` variable, or the egg's default for
it when the server never changed it. `latest` (or no value at all) means the newest Minecraft release on Modrinth.

## Permissions

Owners and admins can do everything. Subusers need these file permissions:

| What                                                     | Needs                            |
|----------------------------------------------------------|----------------------------------|
| See the page                                             | `file.read`                      |
| Install, import                                          | `file.create`                    |
| Update, Update all, replacing a hand-uploaded copy       | `file.create` and `file.delete`  |
| Uninstall                                                | `file.delete`                    |
| Export (reads hand-uploaded files)                       | `file.read-content`              |

Buttons a user isn't allowed to use are hidden, and the actions check again on the server. The page is also closed
while a server is suspended, installing, restoring a backup or being transferred, like Pelican's own pages.

## Using the page

- The line under the title shows the Minecraft version, the loader (for datapacks: the world) and how many files of
  the selected type are installed.
- At the top right: the type dropdown, **Import / export**, **Update all** (only while updates are pending) and a
  button that opens the folder in the file manager.
- **All** searches Modrinth, **Installed** lists what this page manages. The filter button next to the search field
  holds the "Show client-side mods" checkbox.
- Each row has the version picker, install / update / installed and uninstall.

## How it behaves

### Versions and updates

Modrinth's versions are filtered by the server's Minecraft version and loader(s). Updates stay in the stability
channel of what is installed: a release only updates to releases, a beta to releases and betas, an alpha to anything.
A fresh install takes the newest release, or the newest beta or alpha if there is no release. The version picker
installs any version you choose; from then on updates follow that version's channel.

**Update all** shows what will change and asks for confirmation. Updates run one after another and one failing
project doesn't stop the others. To stay within the web server's request time limit it stops starting new updates
after about 50 seconds; the button stays visible while updates are pending, so click it again to continue.

### Dependencies

Dependencies Modrinth marks as _required_ are installed together with a project, on install and on update, including
their own dependencies. All of them are resolved before anything is downloaded, so if one can't be satisfied (e.g. no
version for your Minecraft version) the project is left alone instead of ending up on a server that refuses to start.
If a download fails half way, the dependencies installed before it stay installed and the message says so.

A dependency counts as present when it's installed through this page or when one of its files is already in the folder
(see below). Datapacks that list a mod (like Fabric API) as required for their mod variant get that dependency
skipped, since it has no datapack version.

### Files uploaded by hand

A project is recognized in the folder by the file name of one of its versions for this server. When you install a
project that is already there that way, the existing file is taken over (same version) or replaced (other version)
instead of adding a second copy, which would make the loader refuse to start. Import does the same. From then on the
project is managed here and gets updates.

A hand-uploaded file that was renamed, or one of a version for another Minecraft version, can't be recognized this
way.

### Client-side mods

Mods that Modrinth marks as unsupported on servers (client-only, e.g. minimaps or shader loaders) are hidden when
browsing mods. Tick **Show client-side mods** in the filter next to the search field to include them; they are then
marked with a desktop icon. The **Installed** tab always lists everything, client-side mods included (with the same
icon), so one that ended up on the server by mistake is easy to spot and remove.

### Datapacks

Datapacks go into the `datapacks` folder of the world named by `level-name` in `server.properties` (`world` if it isn't
set yet). Start the server once so the world exists, and run `/reload` or restart after installing. Versions that ship
a mod jar as well get their zip installed.

### Downloads and the list of installed projects

Files are downloaded by the node (Wings) from Modrinth's CDN under a temporary name (`.name.jar.part`) and only renamed
into place once they have the size Modrinth reports. An interrupted download never leaves a broken jar behind or
damages the file it should replace.

What this page installed is recorded per folder in `.modrinth-metadata.json`. If that file can't be read (daemon
unreachable, invalid JSON after editing it by hand) nothing is written to it, so the list can't get lost.

## Import / export

**Import / export → Export list** shows everything installed on the server as text: every type the egg enables, not
only the one selected in the dropdown. Copy it, change it if you like, and paste it into **Import / export → Import
list** on another server. Only the project names travel, no files: the importing server looks every project up on
Modrinth and installs the version that fits _its own_ Minecraft version and loaders (see "Versions and updates"),
with dependencies.

```
# Modrinth list
# Exported from "Survival" on 2026-10-13 (Minecraft 26.3).
# ...

[mods]
fabric-api                      # Fabric API
lithium                         # Lithium
# Files Modrinth does not know, copy them by hand if you need them:
#   my-private-mod.jar

[datapacks]
terralith                       # Terralith
```

- One project per line: its slug (`modrinth.com/mod/<slug>`), its ID or a modrinth.com link. Everything after `#` is
  ignored. Sections are `[mods]`, `[plugins]` and `[datapacks]`.
- The export includes files uploaded by hand: they are recognized by their SHA-1 hash, the way launchers do it. Files
  Modrinth doesn't know are listed as comments, because they have to be copied by hand. The first export reads every
  such file once (big jars take a moment); hashes are cached, so later exports only read new or changed files.
- Projects that are already installed are skipped on import, and hand-uploaded copies are taken over (see above).
- After an import a report above the list shows what was installed, what came in as a dependency, what was skipped and
  everything that could not be imported with the reason: the type isn't enabled on this server, there is no version
  for its Minecraft version or loader, the project doesn't exist on Modrinth, a dependency failed, or a line wasn't
  understood.
- Like "Update all", an import stops starting new installs after about 50 seconds. Open "Import list" again (the text
  is still filled in until you reload the page) and run it once more; everything installed by then is skipped.

## Limitations

- Only _required_ dependencies are handled. A dependency that is already installed isn't updated when a new version
  of a project asks for a specific newer version of it; update it from its own row.
- When Modrinth can't be reached for a single project during an import, it is reported as having no version for the
  server.
- Modrinth's API must be reachable from the panel, and its CDN from the node.

## Development

`tests/` holds a self-contained test suite that runs the plugin's real code against small stand-ins for Pelican,
Filament, Laravel and a fake Modrinth, without installing anything:

```
php tests/run.php .
```

It needs PHP 8.2 or newer.

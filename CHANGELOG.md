# Changelog

All notable changes to this plugin. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [semantic versioning](https://semver.org/).

## [1.0.0] - 2026-10-14

First release of Modrinth Integration, a rework of [Minecraft Modrinth](https://hub.pelican.dev/plugins/minecraft-modrinth) 1.1.2 by Boy132 & H1ghSyst3m.

### Added

- One Modrinth page per server with a dropdown for mods, plugins and datapacks, switched on by egg tags (`modrinth_mods`, `modrinth_plugins`, `modrinth_datapacks`) and one or more loader tags.
- Datapacks, installed into the world's `datapacks` folder.
- Required dependencies are resolved and installed together with a project.
- **Update all**, with version selection that respects the stability channel.
- Import and export of project lists between servers, including hand-uploaded jars identified by their hash.
- Client-side mods are hidden from the mod search unless asked for.
- English and German translations.
- Self-contained test suite (`php tests/run.php .`).
- `update_url`, so panels are offered new versions. Release zips are built from the release tag, carry a SHA-256 checksum the panel verifies, and the tests must pass before a release is packaged.

### Changed

- New plugin id, namespace and name: `modrinth-integration`, `MaxGojko\ModrinthIntegration`, "Modrinth Integration". It installs next to the original instead of replacing it; see the README for switching over.
- The page is switched on by egg tags instead of egg features; the `minecraft` tag is no longer needed.
- Hand-uploaded copies of a project are taken over instead of installed a second time.
- Downloads go through a temporary file and are size checked before they replace anything.
- The list of installed projects is never overwritten after a failed read.
- Subuser file permissions and Pelican's conflict state are respected.
- Compact page header and translated labels.
- Requires Pelican Panel 1.0.0-rc.1 or newer.

[1.0.0]: https://github.com/MaxGojko/pelican-modrinth-integration-plugin/releases/tag/v1.0.0

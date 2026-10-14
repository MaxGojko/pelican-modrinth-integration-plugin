<?php

namespace MaxGojko\ModrinthIntegration\Facades;

use App\Models\Server;
use MaxGojko\ModrinthIntegration\Enums\ModrinthProjectType;
use MaxGojko\ModrinthIntegration\Services\ModrinthService;
use Illuminate\Support\Facades\Facade;

/**
 * @phpstan-type InstalledModMetadata array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}
 * @phpstan-type ModrinthVersion array{name: string, version_number: string, changelog: ?string, dependencies: array<mixed>, game_version: array<string>, version_type: string, loaders: array<string>, featured: bool, status: string, requested_status: ?string, id: string, project_id: string, author_id: string, date_published: string, downloads: int, changelog_url: ?string, files: array<mixed>}
 *
 * @method static ?string getMinecraftVersion(Server $server)
 * @method static ?string getLatestMinecraftVersion()
 * @method static array{name: string, names: array<int, string>, display_name: string}|null getLoaderFromServer(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static string getProjectFolder(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static string getLevelName(Server $server)
 * @method static string parseLevelName(string $properties)
 * @method static array{primary: bool, filename: string, url: string, size?: int}|null getInstallFile(array<string, mixed> $version, ModrinthProjectType $modrinthProjectType)
 * @method static array<int, array{icon: string, name: string, supported_project_types: string[]}> getLoaders()
 * @method static array{hits: array<int, array<string, mixed>>, total_hits: int} getProjects(Server $server, ModrinthProjectType $modrinthProjectType, int $page = 1, ?string $search = null, bool $hideClientOnly = false)
 * @method static bool isClientOnly(array<string, mixed> $project)
 * @method static array<int, array<string, mixed>> getInstalledModsFromModrinth(array<int, InstalledModMetadata> $installedMods, int $page = 1)
 * @method static array<int, ModrinthVersion> getProjectVersions(string $projectId, Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static array<string, array<int, ModrinthVersion>> getProjectVersionsBulk(array<int, string> $projectIds, Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static ModrinthVersion|null getLatestVersion(array<int, ModrinthVersion> $versions, ?string $installedVersionId = null)
 * @method static array<string, mixed>|null getProject(string $projectId)
 * @method static array<string, mixed>|null getVersion(string $versionId)
 * @method static ?string getProjectAuthor(string $projectId)
 * @method static array<int, array{project_id: ?string, version_id: ?string}> getRequiredDependencies(array<string, mixed> $version)
 * @method static array<int, array{record: array{project_id: string, slug: string, title: string, author: ?string}, version: array<string, mixed>, file: array<string, mixed>}> planDependencies(Server $server, ModrinthProjectType $modrinthProjectType, array<string, mixed> $versionData)
 * @method static array<int, InstalledModMetadata> getInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static bool saveModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId, string $projectSlug, string $projectTitle, string $versionId, string $versionNumber, string $filename, ?string $author = null)
 * @method static bool removeModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId)
 * @method static bool downloadFile(Server $server, string $url, string $folder, string $filename, ?int $expectedSize = null)
 * @method static int|null getFileSize(Server $server, string $folder, string $filename)
 * @method static void deleteFile(Server $server, string $folder, string $filename)
 * @method static bool fileExists(Server $server, string $folder, string $filename)
 * @method static array<int, array<string, mixed>> listFolder(Server $server, string $folder)
 * @method static InstalledModMetadata|null getInstalledMod(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId)
 * @method static bool isUpdateAvailable(array{version_id: string, version_number: string} $installedMod, array<int, array{id: string, version_number: string}> $availableVersions)
 * @method static array<string> getInstalledMods(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static array<string, string> getFolderFileNames(Server $server, ModrinthProjectType $modrinthProjectType)
 * @method static array{version: array<string, mixed>, filename: string}|null findVersionOnDisk(array<int, array<string, mixed>> $versions, array<string, string> $fileNames)
 * @method static bool versionsMatchFiles(array<int, array<string, mixed>> $versions, array<string, string> $fileNames)
 * @method static array<string, array<string, mixed>> getProjectsBulk(array<int, string|int> $refs)
 * @method static array<string, string> getProjectIdsByHashes(array<int, string> $sha1Hashes)
 * @method static array{found: array<string, string>, unknown: array<int, string>, unreadable: array<int, string>} identifyFiles(Server $server, string $folder, array<int, array<string, mixed>> $files)
 * @method static string buildModList(Server $server)
 * @method static array{entries: array<int, array{type: ModrinthProjectType, ref: string, line: int}>, errors: array<int, array{line: int, text: string, reason: string}>} parseModList(string $text)
 *
 * @see ModrinthService
 */
class Modrinth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ModrinthService::class;
    }
}

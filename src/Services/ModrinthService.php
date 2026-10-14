<?php

namespace MaxGojko\ModrinthIntegration\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use MaxGojko\ModrinthIntegration\Enums\ModrinthProjectType;
use MaxGojko\ModrinthIntegration\Exceptions\DependencyInstallException;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ModrinthService
{
    protected const API_URL = 'https://api.modrinth.com/v2';

    // Modrinth asks every client to identify itself and may throttle or block generic user agents.
    protected const USER_AGENT = 'MaxGojko/modrinth-integration/1.0 (Pelican panel plugin)';

    // Seconds to wait for the daemon while it downloads a file for us.
    protected const DOWNLOAD_TIMEOUT = 120;

    // Version list cache TTL (minutes); kept short so a transient empty Modrinth response can't hide updates for long.
    protected const VERSIONS_CACHE_MINUTES = 30;

    protected const EMPTY_VERSIONS_CACHE_MINUTES = 5;

    // How many version lookups run at the same time, well below Modrinth's rate limit.
    protected const BULK_CHUNK_SIZE = 20;

    // Lower is more stable.
    protected const VERSION_TYPE_RANK = [
        'release' => 0,
        'beta' => 1,
        'alpha' => 2,
    ];

    protected function request(int $timeout = 5): PendingRequest
    {
        return $this->configureRequest(Http::asJson(), $timeout);
    }

    protected function configureRequest(PendingRequest $request, int $timeout = 5): PendingRequest
    {
        return $request
            ->baseUrl(self::API_URL)
            ->withUserAgent(self::USER_AGENT)
            ->timeout($timeout)
            ->connectTimeout(5);
    }

    public function getMinecraftVersion(Server $server): ?string
    {
        $variable = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first();

        // A server that never changed the variable has no value of its own: the egg's default applies.
        $version = $variable?->server_value ?? $variable?->default_value;

        if (!$version || $version === 'latest') {
            return $this->getLatestMinecraftVersion();
        }

        return $version;
    }

    public function getLatestMinecraftVersion(): ?string
    {
        return cache()->remember('modrinth:latest_minecraft_version', now()->addHour(), function () {
            try {
                /** @var array<int, mixed> $versions */
                $versions = $this->request()
                    ->throw()
                    ->get('tag/game_version')
                    ->json();

                return collect($versions)->filter(fn ($version) => $version['version_type'] === 'release')->first()['version'] ?? null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /**
     * The Modrinth loaders used to filter projects of a type on this server.
     *
     * Mods and plugins take them from egg tags naming Modrinth loaders that support the type
     * ("fabric" for mods, "paper" for plugins, ...), so a hybrid server can carry one of each. All
     * matching tags count, in the egg's order: a Paper egg tagged paper, spigot and bukkit finds
     * plugins published for any of the three. Datapacks are loader independent and always use
     * Modrinth's "datapack" loader.
     *
     * @return array{name: string, names: array<int, string>, display_name: string}|null
     */
    public function getLoaderFromServer(Server $server, ModrinthProjectType $modrinthProjectType): ?array
    {
        if (!$modrinthProjectType->needsLoaderTag()) {
            return ['name' => 'datapack', 'names' => ['datapack'], 'display_name' => $modrinthProjectType->getLabel()];
        }

        $supported = [];
        foreach ($this->getLoaders() as $loader) {
            if (is_string($loader['name'] ?? null) && in_array($modrinthProjectType->value, (array) ($loader['supported_project_types'] ?? []), true)) {
                $supported[$loader['name']] = true;
            }
        }

        $names = array_values(array_unique(array_filter(
            ModrinthProjectType::getEggTags($server),
            fn (string $tag) => isset($supported[$tag])
        )));

        if (empty($names)) {
            return null;
        }

        return [
            'name' => $names[0],
            'names' => $names,
            'display_name' => implode(', ', array_map(fn (string $name) => str($name)->title()->toString(), $names)),
        ];
    }

    /**
     * The folder a type installs into, relative to the server root.
     *
     * @throws Exception
     */
    public function getProjectFolder(Server $server, ModrinthProjectType $modrinthProjectType): string
    {
        return match ($modrinthProjectType) {
            ModrinthProjectType::Mod => 'mods',
            ModrinthProjectType::Plugin => 'plugins',
            ModrinthProjectType::Datapack => join_paths($this->getLevelName($server), 'datapacks'),
        };
    }

    /** @var array<int|string, string> level name per server id, read once per request */
    protected array $levelNames = [];

    /**
     * The world folder, from "level-name" in server.properties ("world" if it isn't set yet).
     *
     * Any other read error is passed on: guessing "world" for a server whose world has another
     * name would put datapacks, and a fresh list of what is installed, into the wrong folder.
     *
     * @throws Exception
     */
    public function getLevelName(Server $server): string
    {
        if (isset($this->levelNames[$server->id])) {
            return $this->levelNames[$server->id];
        }

        try {
            $levelName = $this->parseLevelName($this->readServerProperties($server));
        } catch (FileNotFoundException) {
            // Not started yet, so there's no server.properties. Minecraft will use "world".
            $levelName = 'world';
        }

        return $this->levelNames[$server->id] = $levelName;
    }

    /**
     * @throws Exception
     */
    protected function readServerProperties(Server $server): string
    {
        return app(DaemonFileRepository::class)->setServer($server)->getContent('server.properties');
    }

    public function parseLevelName(string $properties): string
    {
        $levelName = null;

        foreach (preg_split('/\R/', $properties) ?: [] as $line) {
            $line = ltrim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === '!') {
                continue;
            }

            if (preg_match('/^level-name\s*[=:]\s*(.*)$/', $line, $matches)) {
                // Undo the simple escapes Java writes into .properties files ("\:" or "\ ").
                $levelName = trim(preg_replace('/\\\\(.)/', '$1', $matches[1]));
            }
        }

        // Anything that could point outside the server folder falls back to the default.
        if ($levelName === null || $levelName === '' || str_contains($levelName, "\0") || str_contains($levelName, '\\') || str_starts_with($levelName, '/')
            || in_array('..', explode('/', $levelName), true)) {
            return 'world';
        }

        return trim($levelName, '/');
    }

    /**
     * The file of a version that gets installed.
     *
     * The primary file if there is one; Modrinth says that without one the first file is meant.
     * Datapack versions sometimes ship a mod jar as well, so for them it has to be a zip.
     *
     * @param  array<string, mixed>  $version
     * @return array{primary: bool, filename: string, url: string, size?: int}|null
     */
    public function getInstallFile(array $version, ModrinthProjectType $modrinthProjectType): ?array
    {
        $files = array_values(array_filter(
            is_array($version['files'] ?? null) ? $version['files'] : [],
            fn ($file) => is_array($file) && is_string($file['filename'] ?? null) && is_string($file['url'] ?? null)
        ));

        $extension = '.'.$modrinthProjectType->getFileExtension();
        $hasExtension = fn (array $file) => str_ends_with(strtolower($file['filename']), $extension);
        $mustMatch = $modrinthProjectType === ModrinthProjectType::Datapack;

        foreach ($files as $file) {
            if (!empty($file['primary']) && (!$mustMatch || $hasExtension($file))) {
                return $file;
            }
        }

        $hasPrimary = !empty(array_filter($files, fn (array $file) => !empty($file['primary'])));

        // No usable primary file: prefer one of the right kind, else (if nothing is marked primary
        // at all) the first file, which is what Modrinth defines the primary one to be then.
        foreach ($files as $file) {
            if ($hasExtension($file)) {
                return $file;
            }
        }

        return !$mustMatch && !$hasPrimary ? ($files[0] ?? null) : null;
    }

    /**
     * Modrinth's loader list. A failed request is not cached, otherwise one hiccup would hide every
     * mod and plugin for an hour.
     *
     * @return array<int, array{icon: string, name: string, supported_project_types: string[]}>
     */
    public function getLoaders(): array
    {
        $cached = cache()->get('modrinth:loaders');
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }

        try {
            $loaders = $this->request()
                ->throw()
                ->get('tag/loader')
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        if (!is_array($loaders) || empty($loaders)) {
            return [];
        }

        cache()->put('modrinth:loaders', $loaders, now()->addHour());

        return $loaders;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getProjects(Server $server, ModrinthProjectType $modrinthProjectType, int $page = 1, ?string $search = null, bool $hideClientOnly = false): array
    {
        $minecraftLoader = $this->getLoaderFromServer($server, $modrinthProjectType);
        $modrinthProjectType = $modrinthProjectType->value;
        $minecraftVersion = $this->getMinecraftVersion($server);

        if (!$minecraftLoader || !$minecraftVersion) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $loaderNames = $minecraftLoader['names'];
        $minecraftLoader = implode(',', $loaderNames);

        // Loaders inside one group are OR'ed by Modrinth, the groups are AND'ed.
        $facets = [
            array_map(fn (string $loader) => "categories:$loader", $loaderNames),
            ["versions:$minecraftVersion"],
            ["project_type:$modrinthProjectType"],
        ];

        if ($hideClientOnly) {
            // Client-only projects are the ones Modrinth marks as unsupported on the server.
            $facets[] = ['server_side!=unsupported'];
        }

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => json_encode($facets, JSON_THROW_ON_ERROR),
        ];

        $key = "modrinth_projects:{$modrinthProjectType}:$minecraftVersion:$minecraftLoader:$page".($hideClientOnly ? ':server' : '');

        if ($search) {
            $data['query'] = $search;

            $key .= ':'.md5($search);
        }

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->request()
                ->throw()
                ->get('search', $data)
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not caching on purpose since caching a failure would keep the page empty for 30 minutes
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        // An unexpected body must neither be cached nor break the table.
        if (!is_array($response) || !is_array($response['hits'] ?? null)) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $response['total_hits'] = (int) ($response['total_hits'] ?? count($response['hits']));

        cache()->put($key, $response, now()->addMinutes(30));

        return $response;
    }

    /**
     * Whether Modrinth marks a project (search hit or project object) as client-only.
     *
     * @param  array<string, mixed>  $project
     */
    public function isClientOnly(array $project): bool
    {
        return ($project['server_side'] ?? null) === 'unsupported';
    }

    /**
     * @param  array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
    {
        if (empty($installedMods)) {
            return [];
        }

        $installedModsById = [];
        foreach ($installedMods as $mod) {
            if (!isset($installedModsById[$mod['project_id']])) {
                $installedModsById[$mod['project_id']] = $mod;
            }
        }

        $projectIds = array_keys($installedModsById);

        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $pageIds = array_slice($projectIds, $offset, $perPage);

        if (empty($pageIds)) {
            return [];
        }

        $idsParam = json_encode($pageIds, JSON_THROW_ON_ERROR);
        $cacheKey = 'modrinth_bulk:'.md5($idsParam);

        $modrinthProjects = cache()->get($cacheKey);

        if (!is_array($modrinthProjects)) {
            try {
                $modrinthProjects = $this->request(10)
                    ->throw()
                    ->get('projects', [
                        'ids' => $idsParam,
                    ])
                    ->json();

                if (!is_array($modrinthProjects)) {
                    $modrinthProjects = [];
                }

                cache()->put($cacheKey, $modrinthProjects, now()->addMinutes(30));
            } catch (Exception $exception) {
                report($exception);

                $modrinthProjects = [];
            }
        }

        $modrinthMap = [];
        foreach ($modrinthProjects as $project) {
            if (isset($project['id'])) {
                $modrinthMap[$project['id']] = $project;
            }
        }

        $results = [];
        foreach ($pageIds as $projectId) {
            $installedMod = $installedModsById[$projectId];

            if (isset($modrinthMap[$projectId])) {
                $project = $modrinthMap[$projectId];
                $project['project_id'] = $project['id'];
                if (isset($project['updated']) && !isset($project['date_modified'])) {
                    $project['date_modified'] = $project['updated'];
                }
                if (isset($installedMod['author']) && !isset($project['author'])) {
                    $project['author'] = $installedMod['author'];
                }
                $results[] = $project;
            } else {
                $results[] = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'description' => trans('modrinth-integration::strings.page.mod_unavailable'),
                    'icon_url' => null,
                    'author' => $installedMod['author'] ?? '',
                    'downloads' => 0,
                    'date_modified' => $installedMod['installed_at'],
                    'project_type' => '',
                    'unavailable' => true,
                ];
            }
        }

        return $results;
    }

    protected function getVersionsCacheKey(string $projectId, ?string $minecraftVersion, string $minecraftLoader): string
    {
        return "modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader";
    }

    /**
     * @param  array<int, string>  $loaderNames
     * @return array{game_versions: string, loaders: string}
     */
    protected function getVersionsQuery(string $minecraftVersion, array $loaderNames): array
    {
        return [
            'game_versions' => json_encode([$minecraftVersion], JSON_THROW_ON_ERROR),
            'loaders' => json_encode(array_values($loaderNames), JSON_THROW_ON_ERROR),
        ];
    }

    /** @param  array<int, mixed>  $versions */
    protected function cacheVersions(string $key, array $versions): void
    {
        cache()->put($key, $versions, now()->addMinutes(empty($versions) ? self::EMPTY_VERSIONS_CACHE_MINUTES : self::VERSIONS_CACHE_MINUTES));
    }

    /**
     * @param  array<int, mixed>  $versions
     * @return array<int, mixed>
     */
    protected function sortVersions(array $versions): array
    {
        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        return $versions;
    }

    /** @return array<array{name: string, version_number: string, changelog: ?string, dependencies: array<mixed>, game_version: string[], version_type: string, loaders: string[], featured: bool, status: string, requested_status: ?string, id: string, project_id: string, author_id: string, date_published: string, downloads: int, changelog_url: ?string, files: array<mixed>}> */
    public function getProjectVersions(string $projectId, Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $minecraftLoader = $this->getLoaderFromServer($server, $modrinthProjectType);
        $minecraftVersion = $this->getMinecraftVersion($server);

        if (!$minecraftLoader || !$minecraftVersion) {
            return [];
        }

        $loaderNames = $minecraftLoader['names'];
        $minecraftLoader = implode(',', $loaderNames);

        $key = $this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader);

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $versions = $this->request()
                ->throw()
                ->get('project/'.rawurlencode($projectId).'/version', $this->getVersionsQuery($minecraftVersion, $loaderNames))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not cached on purpose since an empty list would hide the update action for 30 minutes.
            return [];
        }

        $versions = is_array($versions) ? $this->sortVersions($versions) : [];

        $this->cacheVersions($key, $versions);

        return $versions;
    }

    /**
     * fetch compatible versions of several projects at once, a full installed
     * tab used to cost up to 20 sequential Modrinth round trips whilst this fetches in bulk.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<int, mixed>>
     */
    public function getProjectVersionsBulk(array $projectIds, Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if (empty($projectIds)) {
            return [];
        }

        $minecraftLoader = $this->getLoaderFromServer($server, $modrinthProjectType);
        $minecraftVersion = $this->getMinecraftVersion($server);

        if (!$minecraftLoader || !$minecraftVersion) {
            return array_fill_keys($projectIds, []);
        }

        $loaderNames = $minecraftLoader['names'];
        $minecraftLoader = implode(',', $loaderNames);
        $query = $this->getVersionsQuery($minecraftVersion, $loaderNames);

        $results = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader));

            if (is_array($cached)) {
                $results[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        $responses = [];

        // Chunked so "update all" on a big mods folder doesn't fire dozens of requests at once.
        foreach (array_chunk($missing, self::BULK_CHUNK_SIZE) as $chunk) {
            try {
                $responses += Http::pool(fn (Pool $pool) => array_map(
                    fn (string $projectId) => $this->configureRequest($pool->as($projectId)->asJson(), 10)
                        ->get('project/'.rawurlencode($projectId).'/version', $query),
                    $chunk
                ));
            } catch (Exception $exception) {
                report($exception);
            }
        }

        foreach ($missing as $projectId) {
            $response = $responses[$projectId] ?? null;

            if ($response instanceof Response && $response->successful()) {
                $versions = $response->json();
                $versions = is_array($versions) ? $this->sortVersions($versions) : [];

                $this->cacheVersions($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader), $versions);

                $results[$projectId] = $versions;

                continue;
            }

            if ($response instanceof Exception) {
                report($response);
            }

            $results[$projectId] = [];
        }

        return $results;
    }

    protected function getVersionTypeRank(mixed $versionType): int
    {
        return is_string($versionType) ? (self::VERSION_TYPE_RANK[$versionType] ?? 2) : 2;
    }

    /**
     * Pick the version a project should be installed at, or updated to.
     *
     * Without an installed version this is the newest build of the most stable channel that has
     * one (release, then beta, then alpha). With one, an install never leaves its own channel
     * downwards: a release only updates to releases, a beta to releases and betas and an alpha to
     * anything. Otherwise "update" would swap a stable mod for an alpha that happened to be newer.
     *
     * @param  array<int, array<string, mixed>>  $versions  newest first
     * @return array<string, mixed>|null
     */
    public function getLatestVersion(array $versions, ?string $installedVersionId = null): ?array
    {
        if (empty($versions)) {
            return null;
        }

        $ceiling = null;
        if ($installedVersionId !== null) {
            foreach ($versions as $version) {
                if (($version['id'] ?? null) === $installedVersionId) {
                    $ceiling = $this->getVersionTypeRank($version['version_type'] ?? null);

                    break;
                }
            }
        }

        // The installed version isn't in the compatible list (e.g. the server changed Minecraft
        // version), so it says nothing about the channel; treat it like a fresh install.
        if ($ceiling === null) {
            $best = null;
            $bestRank = PHP_INT_MAX;

            foreach ($versions as $version) {
                $rank = $this->getVersionTypeRank($version['version_type'] ?? null);

                if ($rank < $bestRank) {
                    $best = $version;
                    $bestRank = $rank;
                }
            }

            return $best;
        }

        foreach ($versions as $version) {
            if ($this->getVersionTypeRank($version['version_type'] ?? null) <= $ceiling) {
                return $version;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function getProject(string $projectId): ?array
    {
        $key = "modrinth_project:$projectId";

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $project = $this->request()
                ->throw()
                ->get('project/'.rawurlencode($projectId))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        if (!is_array($project) || !isset($project['id'], $project['slug'], $project['title'])) {
            return null;
        }

        cache()->put($key, $project, now()->addMinutes(self::VERSIONS_CACHE_MINUTES));

        return $project;
    }

    /** @return array<string, mixed>|null */
    public function getVersion(string $versionId): ?array
    {
        $key = "modrinth_version:$versionId";

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $version = $this->request()
                ->throw()
                ->get('version/'.rawurlencode($versionId))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        if (!is_array($version) || !isset($version['id'], $version['project_id'])) {
            return null;
        }

        cache()->put($key, $version, now()->addMinutes(self::VERSIONS_CACHE_MINUTES));

        return $version;
    }

    /** The owner of a project; falls back to the first team member. */
    public function getProjectAuthor(string $projectId): ?string
    {
        try {
            $members = $this->request()
                ->throw()
                ->get('project/'.rawurlencode($projectId).'/members')
                ->json();
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        if (!is_array($members)) {
            return null;
        }

        $names = [];
        foreach ($members as $member) {
            $username = $member['user']['username'] ?? null;

            if (!is_string($username)) {
                continue;
            }

            if (($member['role'] ?? null) === 'Owner') {
                return $username;
            }

            $names[] = $username;
        }

        return $names[0] ?? null;
    }

    /**
     * The dependencies a version can't work without.
     *
     * Modrinth lists a dependency by project, by one specific version or both. "optional",
     * "incompatible" and "embedded" (already bundled inside the jar) ones are not our business.
     *
     * @param  array<string, mixed>  $version
     * @return array<int, array{project_id: ?string, version_id: ?string}>
     */
    public function getRequiredDependencies(array $version): array
    {
        $required = [];

        foreach ($version['dependencies'] ?? [] as $dependency) {
            if (!is_array($dependency) || ($dependency['dependency_type'] ?? null) !== 'required') {
                continue;
            }

            $projectId = is_string($dependency['project_id'] ?? null) && $dependency['project_id'] !== '' ? $dependency['project_id'] : null;
            $versionId = is_string($dependency['version_id'] ?? null) && $dependency['version_id'] !== '' ? $dependency['version_id'] : null;

            if ($projectId === null && $versionId === null) {
                continue;
            }

            $required[] = [
                'project_id' => $projectId,
                'version_id' => $versionId,
            ];
        }

        return $required;
    }

    /**
     * Work out which required dependencies (including theirs, deepest first) are missing for a
     * version, without installing anything.
     *
     * A dependency counts as present when it was installed through this plugin, or when a file
     * with the name of any of its known releases already sits in the folder, which is what
     * manually uploaded jars look like. Installing a second copy of those would make the loader
     * refuse to start because of a duplicate mod.
     *
     * Nothing is installed here on purpose: if one dependency can't be satisfied the caller can
     * stop before touching the server, instead of leaving it with something that won't start.
     *
     * @param  array<string, mixed>  $versionData
     * @return array<int, array{record: array{project_id: string, slug: string, title: string, author: ?string}, version: array<string, mixed>, file: array<string, mixed>}>
     *
     * @throws DependencyInstallException
     */
    public function planDependencies(Server $server, ModrinthProjectType $modrinthProjectType, array $versionData): array
    {
        $context = [
            // project id => true, every project that was looked at already; also what stops circular dependencies
            'handled' => [],
            // project id => true, read on first use
            'installed' => null,
            // lowercased file name => true, read on first use
            'files' => null,
            'plan' => [],
        ];

        if (is_string($versionData['project_id'] ?? null)) {
            $context['handled'][$versionData['project_id']] = true;
        }

        $this->collectDependencies($server, $modrinthProjectType, $versionData, $context);

        return $context['plan'];
    }

    /**
     * @param  array<string, mixed>  $versionData
     * @param  array{handled: array<string, true>, installed: ?array<string, true>, files: ?array<string, string>, plan: array<int, mixed>}  $context
     *
     * @throws DependencyInstallException
     */
    protected function collectDependencies(Server $server, ModrinthProjectType $modrinthProjectType, array $versionData, array &$context): void
    {
        foreach ($this->getRequiredDependencies($versionData) as $dependency) {
            $projectId = $dependency['project_id'];
            $label = $projectId ?? $dependency['version_id'];

            try {
                if ($projectId === null) {
                    $pinned = $this->getVersion($dependency['version_id']);

                    if (!$pinned) {
                        throw new Exception("Could not look up version {$dependency['version_id']}");
                    }

                    $projectId = $label = $pinned['project_id'];
                }

                if (isset($context['handled'][$projectId])) {
                    continue;
                }
                $context['handled'][$projectId] = true;

                $context['installed'] ??= array_fill_keys(array_column($this->getInstalledModsMetadata($server, $modrinthProjectType), 'project_id'), true);
                if (isset($context['installed'][$projectId])) {
                    continue;
                }

                $project = $this->getProject($projectId);
                if (!$project) {
                    throw new Exception("Could not look up project $projectId");
                }
                $label = $project['title'];

                $versions = $this->getProjectVersions($projectId, $server, $modrinthProjectType);
                if (empty($versions)) {
                    // Multi-loader datapacks sometimes list the mod side's dependencies (Fabric API, ...)
                    // as required. They have no datapack versions and are not needed by the datapack.
                    if ($modrinthProjectType === ModrinthProjectType::Datapack) {
                        continue;
                    }

                    throw new Exception("No compatible version of $projectId");
                }

                if ($this->isDependencyOnDisk($server, $modrinthProjectType, $versions, $context)) {
                    continue;
                }

                $version = $this->pickDependencyVersion($versions, $dependency['version_id']);

                $file = $this->getInstallFile($version, $modrinthProjectType);
                if (!$file) {
                    throw new Exception("No downloadable file for $projectId");
                }

                // Its own dependencies have to be in place before it is.
                $this->collectDependencies($server, $modrinthProjectType, $version, $context);

                $context['plan'][] = [
                    'record' => [
                        'project_id' => $projectId,
                        'slug' => $project['slug'],
                        'title' => $project['title'],
                        'author' => $this->getProjectAuthor($projectId),
                    ],
                    'version' => $version,
                    'file' => $file,
                ];
            } catch (DependencyInstallException $exception) {
                throw $exception;
            } catch (Exception $exception) {
                throw new DependencyInstallException((string) $label, $exception);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $versions
     * @param  array{files: ?array<string, string>}  $context
     */
    protected function isDependencyOnDisk(Server $server, ModrinthProjectType $modrinthProjectType, array $versions, array &$context): bool
    {
        $context['files'] ??= $this->getFolderFileNames($server, $modrinthProjectType);

        return $this->versionsMatchFiles($versions, $context['files']);
    }

    /**
     * The files in a type's folder: the actual name, keyed by its lowercased version.
     *
     * @return array<string, string>
     *
     * @throws Exception
     */
    public function getFolderFileNames(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $names = [];

        foreach ($this->listFolder($server, $this->getProjectFolder($server, $modrinthProjectType)) as $file) {
            if (is_array($file) && is_string($file['name'] ?? null) && empty($file['directory'])) {
                $names[strtolower($file['name'])] = $file['name'];
            }
        }

        return $names;
    }

    /**
     * The version whose file is already in the folder (uploaded by hand), and that file's name.
     *
     * @param  array<int, array<string, mixed>>  $versions
     * @param  array<string, string>  $fileNames  as returned by getFolderFileNames()
     * @return array{version: array<string, mixed>, filename: string}|null
     */
    public function findVersionOnDisk(array $versions, array $fileNames): ?array
    {
        foreach ($versions as $version) {
            foreach ($version['files'] ?? [] as $file) {
                $filename = $file['filename'] ?? null;

                if (is_string($filename) && isset($fileNames[strtolower($filename)])) {
                    return ['version' => $version, 'filename' => $fileNames[strtolower($filename)]];
                }
            }
        }

        return null;
    }

    /**
     * Whether a file of any of these versions is among the given file names, which is how a
     * project that was uploaded by hand is recognized.
     *
     * @param  array<int, array<string, mixed>>  $versions
     * @param  array<string, string>  $fileNames  as returned by getFolderFileNames()
     */
    public function versionsMatchFiles(array $versions, array $fileNames): bool
    {
        return $this->findVersionOnDisk($versions, $fileNames) !== null;
    }

    /**
     * The version a dependency asks for if it is available for this server, else the newest.
     *
     * @param  array<int, array<string, mixed>>  $versions
     * @return array<string, mixed>
     */
    protected function pickDependencyVersion(array $versions, ?string $pinnedVersionId): array
    {
        if ($pinnedVersionId !== null) {
            foreach ($versions as $version) {
                if (($version['id'] ?? null) === $pinnedVersionId) {
                    return $version;
                }
            }
        }

        return $this->getLatestVersion($versions) ?? $versions[0];
    }

    // ---------------------------------------------------------------- lists for export / import

    // Hand-uploaded files bigger than this aren't read to identify them (hashing streams, so this
    // only bounds the time an export can take, not memory).
    protected const MAX_IDENTIFY_FILE_SIZE = 256 * 1024 * 1024;

    // A Modrinth project slug or ID.
    protected const PROJECT_REF_PATTERN = '/^[A-Za-z0-9_.!@$()+,\'"`-]{2,64}$/';

    /**
     * Look up several projects at once, by ID and/or slug.
     *
     * @param  array<int, string|int>  $refs
     * @return array<string, array<string, mixed>> project by ID
     *
     * @throws Exception when Modrinth can't be reached, so callers don't mistake that for "not found"
     */
    public function getProjectsBulk(array $refs): array
    {
        $refs = array_values(array_unique(array_map(
            fn ($ref) => (string) $ref,
            array_filter($refs, fn ($ref) => (is_string($ref) || is_int($ref)) && $ref !== '')
        )));

        $projects = [];

        foreach (array_chunk($refs, 100) as $chunk) {
            $result = $this->request(10)
                ->throw()
                ->get('projects', ['ids' => json_encode($chunk, JSON_THROW_ON_ERROR)])
                ->json();

            foreach (is_array($result) ? $result : [] as $project) {
                if (is_array($project) && is_string($project['id'] ?? null)) {
                    $projects[$project['id']] = $project;
                }
            }
        }

        return $projects;
    }

    /**
     * Which Modrinth project each file belongs to, by the file's SHA-1.
     *
     * @param  array<int, string>  $sha1Hashes
     * @return array<string, string> project ID by lowercased hash
     *
     * @throws Exception
     */
    public function getProjectIdsByHashes(array $sha1Hashes): array
    {
        $projectIds = [];

        foreach (array_chunk(array_values(array_unique($sha1Hashes)), 100) as $chunk) {
            $versions = $this->request(15)
                ->throw()
                ->post('version_files', ['hashes' => $chunk, 'algorithm' => 'sha1'])
                ->json();

            foreach (is_array($versions) ? $versions : [] as $hash => $version) {
                if (is_array($version) && is_string($version['project_id'] ?? null)) {
                    $projectIds[strtolower((string) $hash)] = $version['project_id'];
                }
            }
        }

        return $projectIds;
    }

    /**
     * Find out which Modrinth projects hand-uploaded files are, by hashing them like launchers do.
     *
     * Hashes are cached per file name, size and modification time, so a later export only reads
     * files that are new or changed.
     *
     * @param  array<int, array<string, mixed>>  $files  entries of a folder listing
     * @return array{found: array<string, string>, unknown: array<int, string>, unreadable: array<int, string>} found is project ID by file name
     *
     * @throws Exception when Modrinth can't be reached
     */
    public function identifyFiles(Server $server, string $folder, array $files): array
    {
        $hashes = [];
        $unreadable = [];

        foreach ($files as $file) {
            $name = (string) $file['name'];
            $path = join_paths($folder, $name);
            $key = 'modrinth_file_sha1:'.$server->uuid.':'.md5($path.'|'.($file['size'] ?? '').'|'.($file['modified'] ?? ''));

            $hash = cache()->get($key);

            if (!is_string($hash)) {
                if ((int) ($file['size'] ?? 0) > self::MAX_IDENTIFY_FILE_SIZE) {
                    $unreadable[] = $name;

                    continue;
                }

                try {
                    $hash = $this->hashFile($server, $path);
                } catch (Exception $exception) {
                    report($exception);

                    $unreadable[] = $name;

                    continue;
                }

                cache()->put($key, $hash, now()->addDay());
            }

            $hashes[$name] = $hash;
        }

        $projectIds = empty($hashes) ? [] : $this->getProjectIdsByHashes(array_values($hashes));

        $found = [];
        $unknown = [];

        foreach ($hashes as $name => $hash) {
            if (isset($projectIds[strtolower($hash)])) {
                $found[(string) $name] = $projectIds[strtolower($hash)];
            } else {
                $unknown[] = (string) $name;
            }
        }

        return ['found' => $found, 'unknown' => $unknown, 'unreadable' => $unreadable];
    }

    /**
     * SHA-1 of a file on the server, read from the daemon as a stream so even big jars don't have
     * to fit into PHP's memory.
     *
     * @throws Exception
     */
    protected function hashFile(Server $server, string $path): string
    {
        $response = app(DaemonFileRepository::class)
            ->setServer($server)
            ->getHttpClient()
            ->withOptions(['stream' => true])
            ->get("/api/servers/{$server->uuid}/files/contents", ['file' => $path]);

        $body = $response->toPsrResponse()->getBody();
        $context = hash_init('sha1');

        while (!$body->eof()) {
            hash_update($context, $body->read(1024 * 1024));
        }

        return hash_final($context);
    }

    /**
     * Everything on the server as an editable list: one Modrinth project per line, a section per
     * type the egg enables. Hand-uploaded files are included when Modrinth knows them; the ones it
     * doesn't are listed as comments, because they have to be copied by hand.
     */
    public function buildModList(Server $server): string
    {
        $lines = [
            '# '.trans('modrinth-integration::strings.export.title'),
            '# '.trans('modrinth-integration::strings.export.source', [
                'server' => trim((string) preg_replace('/\s+/', ' ', (string) $server->name)),
                'date' => now()->toDateString(),
                'version' => $this->getMinecraftVersion($server) ?? '?',
            ]),
            '# '.trans('modrinth-integration::strings.export.help_lines'),
            '# '.trans('modrinth-integration::strings.export.help_edit'),
            '# '.trans('modrinth-integration::strings.export.help_versions'),
        ];

        foreach (ModrinthProjectType::fromServer($server) as $type) {
            $lines[] = '';
            $lines[] = '['.$type->getSectionName().']';

            foreach ($this->buildModListSection($server, $type) as $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @return array<int, string> */
    protected function buildModListSection(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $installed = $this->getInstalledModsMetadata($server, $modrinthProjectType);

        // project ID => slug and title as saved at install time, refreshed from Modrinth below
        $entries = [];
        foreach ($installed as $mod) {
            $entries[$mod['project_id']] = ['slug' => $mod['project_slug'], 'title' => $mod['project_title']];
        }

        $managedFiles = array_fill_keys(array_map('strtolower', array_column($installed, 'filename')), true);
        $extension = '.'.$modrinthProjectType->getFileExtension();
        $notes = [];
        $unknown = [];
        $unreadable = [];

        try {
            $folder = $this->getProjectFolder($server, $modrinthProjectType);

            $unmanaged = array_values(array_filter(
                $this->listFolder($server, $folder),
                fn ($file) => is_array($file) && is_string($file['name'] ?? null) && empty($file['directory'])
                    && str_ends_with(strtolower($file['name']), $extension)
                    && !isset($managedFiles[strtolower($file['name'])])
            ));

            if (!empty($unmanaged)) {
                try {
                    $identified = $this->identifyFiles($server, $folder, $unmanaged);

                    foreach ($identified['found'] as $projectId) {
                        $entries[$projectId] ??= ['slug' => $projectId, 'title' => ''];
                    }

                    $unknown = $identified['unknown'];
                    $unreadable = $identified['unreadable'];
                } catch (Exception $exception) {
                    report($exception);

                    $unreadable = array_map(fn ($file) => (string) $file['name'], $unmanaged);
                }
            }
        } catch (Exception $exception) {
            report($exception);

            $notes[] = '# '.trans('modrinth-integration::strings.export.folder_unreadable');
        }

        if (!empty($entries)) {
            try {
                foreach ($this->getProjectsBulk(array_keys($entries)) as $projectId => $project) {
                    if (isset($entries[$projectId])) {
                        $entries[$projectId] = [
                            'slug' => is_string($project['slug'] ?? null) ? $project['slug'] : $entries[$projectId]['slug'],
                            'title' => is_string($project['title'] ?? null) ? $project['title'] : $entries[$projectId]['title'],
                        ];
                    }
                }
            } catch (Exception $exception) {
                // Modrinth unreachable: the slugs saved at install time still work.
                report($exception);
            }
        }

        uasort($entries, fn (array $a, array $b) => strcasecmp($a['title'] !== '' ? $a['title'] : $a['slug'], $b['title'] !== '' ? $b['title'] : $b['slug']));

        $lines = [];
        foreach ($entries as $entry) {
            $title = trim((string) preg_replace('/\s+/', ' ', $entry['title']));
            $lines[] = $title !== '' ? str_pad($entry['slug'], 31).' # '.$title : $entry['slug'];
        }

        if (!empty($unknown)) {
            $lines[] = '# '.trans('modrinth-integration::strings.export.not_on_modrinth');

            foreach ($unknown as $name) {
                $lines[] = '#   '.$name;
            }
        }

        if (!empty($unreadable)) {
            $lines[] = '# '.trans('modrinth-integration::strings.export.unreadable');

            foreach ($unreadable as $name) {
                $lines[] = '#   '.$name;
            }
        }

        return array_merge($lines, $notes);
    }

    /**
     * Read a list as written by buildModList(), possibly edited by hand.
     *
     * Each line is one project: a slug, an ID or a modrinth.com link. "[mods]", "[plugins]" and
     * "[datapacks]" start a section and "#" starts a comment. A project listed twice in the same
     * section counts once.
     *
     * @return array{entries: array<int, array{type: ModrinthProjectType, ref: string, line: int}>, errors: array<int, array{line: int, text: string, reason: string}>}
     */
    public function parseModList(string $text): array
    {
        $entries = [];
        $errors = [];
        $seen = [];
        $type = null;
        $inUnknownSection = false;

        foreach (preg_split('/\R/', $text) ?: [] as $index => $raw) {
            $lineNumber = $index + 1;
            $line = trim(explode('#', $raw, 2)[0]);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\[(.*)\]$/', $line, $matches)) {
                $type = ModrinthProjectType::fromSectionName($matches[1]);
                $inUnknownSection = $type === null;

                if ($inUnknownSection) {
                    $errors[] = ['line' => $lineNumber, 'text' => $line, 'reason' => 'unknown_section'];
                }

                continue;
            }

            // The section was reported once already; its lines aren't errors of their own.
            if ($inUnknownSection) {
                continue;
            }

            if ($type === null) {
                $errors[] = ['line' => $lineNumber, 'text' => $line, 'reason' => 'no_section'];

                continue;
            }

            $ref = $this->parseProjectRef($line);

            if ($ref === null) {
                $errors[] = ['line' => $lineNumber, 'text' => $line, 'reason' => 'invalid'];

                continue;
            }

            $key = $type->value.':'.strtolower($ref);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $entries[] = ['type' => $type, 'ref' => $ref, 'line' => $lineNumber];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    /** A slug or ID, also taken out of a modrinth.com link; null for anything else. */
    protected function parseProjectRef(string $line): ?string
    {
        if (preg_match('~^(?:https?://)?(?:www\.)?modrinth\.com/[a-z]+/([^/?\s]+)~i', $line, $matches)) {
            $line = $matches[1];
        }

        return preg_match(self::PROJECT_REF_PATTERN, $line) ? $line : null;
    }

    /**
     * @throws Exception
     */
    protected function getMetadataFilePath(Server $server, ModrinthProjectType $modrinthProjectType): string
    {
        return join_paths($this->getProjectFolder($server, $modrinthProjectType), '.modrinth-metadata.json');
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    public function getInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        try {
            $metadata = json_decode($this->readMetadataFile($server, $modrinthProjectType), true);
        } catch (FileNotFoundException) {
            return [];
        } catch (Exception $exception) {
            report($exception);

            return [];
        }

        if (!is_array($metadata) || !isset($metadata['installed_mods']) || !is_array($metadata['installed_mods'])) {
            return [];
        }

        $requiredKeysFlipped = array_flip([
            'project_id',
            'project_slug',
            'project_title',
            'version_id',
            'version_number',
            'filename',
            'installed_at',
        ]);

        $validInstalledMods = [];

        foreach ($metadata['installed_mods'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (!empty(array_diff_key($requiredKeysFlipped, $entry))) {
                continue;
            }

            if (isset($validInstalledMods[$entry['project_id']])) {
                continue;
            }

            $validInstalledMods[$entry['project_id']] = $entry;
        }

        return array_values($validInstalledMods);
    }

    /**
     * @throws Exception
     */
    protected function readMetadataFile(Server $server, ModrinthProjectType $modrinthProjectType): string
    {
        return app(DaemonFileRepository::class)->setServer($server)->getContent($this->getMetadataFilePath($server, $modrinthProjectType));
    }

    /**
     * @param  array<int, mixed>  $entries
     *
     * @throws Exception
     */
    protected function writeMetadataEntries(Server $server, ModrinthProjectType $modrinthProjectType, array $entries): bool
    {
        $response = app(DaemonFileRepository::class)->setServer($server)->putContent(
            $this->getMetadataFilePath($server, $modrinthProjectType),
            json_encode(['installed_mods' => array_values($entries)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return !$response->failed();
    }

    /**
     * The metadata file's entries as they are, for rewriting it.
     *
     * Unlike getInstalledModsMetadata() this fails instead of returning nothing when the file can't
     * be read or isn't valid JSON: writing back an empty list would forget everything installed so
     * far. Entries it doesn't understand are kept untouched.
     *
     * @return array<int, mixed>
     *
     * @throws Exception
     */
    protected function readMetadataEntriesForWrite(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        try {
            $content = $this->readMetadataFile($server, $modrinthProjectType);
        } catch (FileNotFoundException) {
            return [];
        }

        if (trim($content) === '') {
            return [];
        }

        $metadata = json_decode($content, true);

        if (!is_array($metadata) || (array_key_exists('installed_mods', $metadata) && !is_array($metadata['installed_mods']))) {
            throw new Exception('.modrinth-metadata.json is not valid JSON, refusing to overwrite it');
        }

        return array_values($metadata['installed_mods'] ?? []);
    }

    /**
     * Run a read-modify-write of the metadata file under a per-server lock. The lock outlives two
     * daemon round trips (each may take up to the daemon timeout), so it can't expire half way.
     */
    protected function withMetadataLock(Server $server, callable $callback): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 60)->block(30, $callback) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function saveModMetadata(
        Server $server,
        ModrinthProjectType $modrinthProjectType,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null
    ): bool {
        return $this->withMetadataLock($server, function () use ($server, $modrinthProjectType, $projectId, $projectSlug, $projectTitle, $versionId, $versionNumber, $filename, $author) {
            $entries = $this->readMetadataEntriesForWrite($server, $modrinthProjectType);

            $existingIndex = null;
            foreach ($entries as $index => $entry) {
                if (is_array($entry) && ($entry['project_id'] ?? null) === $projectId) {
                    $existingIndex = $index;

                    break;
                }
            }

            $existing = $existingIndex !== null ? $entries[$existingIndex] : null;

            $modEntry = [
                'project_id' => $projectId,
                'project_slug' => $projectSlug,
                'project_title' => $projectTitle,
                'version_id' => $versionId,
                'version_number' => $versionNumber,
                'filename' => $filename,
                'installed_at' => is_string($existing['installed_at'] ?? null) ? $existing['installed_at'] : now()->toIso8601String(),
            ];

            if ($existing !== null) {
                $modEntry['updated_at'] = now()->toIso8601String();
            }

            if ($author !== null) {
                $modEntry['author'] = $author;
            }

            if ($existingIndex !== null) {
                // Replace in place. Removing and re-appending pushed the entry to the end of the
                // list, which moved the row to the bottom of the installed tab on every update.
                $entries[$existingIndex] = $modEntry;
            } else {
                $entries[] = $modEntry;
            }

            return $this->writeMetadataEntries($server, $modrinthProjectType, $entries);
        });
    }

    public function removeModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId): bool
    {
        return $this->withMetadataLock($server, function () use ($server, $modrinthProjectType, $projectId) {
            $entries = array_values(array_filter(
                $this->readMetadataEntriesForWrite($server, $modrinthProjectType),
                fn ($entry) => !(is_array($entry) && ($entry['project_id'] ?? null) === $projectId)
            ));

            return $this->writeMetadataEntries($server, $modrinthProjectType, $entries);
        });
    }

    /**
     * Download a file into $folder/$filename, replacing a file of that name only once the new one
     * is complete.
     *
     * The daemon writes downloads in place, so a broken connection would leave a truncated jar (or
     * truncate the old one of the same name). The file is therefore fetched under a temporary name
     * and renamed when complete; the rename replaces an existing file in one step. Complete means
     * as big as Modrinth says, or, with no size to compare against, the daemon reporting success.
     *
     * `foreground` makes the daemon hold the request until the download is done (otherwise it never
     * reports failures), and the explicit file name keeps it from deriving one from the URL.
     *
     * @return bool whether a file of that name was there before and has been replaced
     *
     * @throws Exception
     */
    public function downloadFile(Server $server, string $url, string $folder, string $filename, ?int $expectedSize = null): bool
    {
        $temporary = '.'.$filename.'.part';
        $existedBefore = $this->fileExists($server, $folder, $filename);

        // Left over from an attempt that died half way.
        $this->deleteFile($server, $folder, $temporary);

        $pullError = null;

        try {
            $this->pullFile($server, $url, $folder, $temporary);
        } catch (Exception $exception) {
            // Our request can time out while the daemon still finishes; the size check decides.
            $pullError = $exception;
        }

        $size = $this->getFileSize($server, $folder, $temporary);
        $complete = $size !== null && ($expectedSize !== null ? $size === $expectedSize : $pullError === null);

        if (!$complete) {
            try {
                $this->deleteFile($server, $folder, $temporary);
            } catch (Exception $cleanupException) {
                report($cleanupException);
            }

            throw $pullError ?? new Exception("Download of $url is incomplete: ".($size ?? 'no file').' of '.($expectedSize ?? '?').' bytes');
        }

        $this->renameFile($server, $folder, $temporary, $filename);

        if (!$this->fileExists($server, $folder, $filename)) {
            throw new Exception("$folder/$filename is missing after moving the finished download of $url into place");
        }

        return $existedBefore;
    }

    /**
     * @throws Exception
     */
    protected function pullFile(Server $server, string $url, string $folder, string $filename): void
    {
        app(DaemonFileRepository::class)
            ->setServer($server)
            ->getHttpClient()
            // The daemon holds the request open for the whole download, which easily outlives
            // the timeout used for the small file operations everything else here does.
            ->timeout(max((int) config('panel.guzzle.timeout'), self::DOWNLOAD_TIMEOUT))
            ->post("/api/servers/{$server->uuid}/files/pull", [
                'url' => $url,
                'root' => $folder,
                'file_name' => $filename,
                'foreground' => true,
            ]);
    }

    /**
     * @throws Exception
     */
    protected function renameFile(Server $server, string $folder, string $from, string $to): void
    {
        app(DaemonFileRepository::class)
            ->setServer($server)
            ->renameFiles($folder, [['from' => $from, 'to' => $to]]);
    }

    /**
     * @throws Exception
     */
    public function getFileSize(Server $server, string $folder, string $filename): ?int
    {
        foreach ($this->listFolder($server, $folder) as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename && empty($file['directory'])) {
                return (int) ($file['size'] ?? 0);
            }
        }

        return null;
    }

    /**
     * Delete a file; one that is already gone (e.g. deleted by hand) counts as deleted.
     *
     * @throws Exception
     */
    public function deleteFile(Server $server, string $folder, string $filename): void
    {
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->deleteFiles('/', [join_paths($folder, $filename)])
                ->throw();
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return;
            }

            throw $exception;
        }
    }

    public function fileExists(Server $server, string $folder, string $filename): bool
    {
        foreach ($this->listFolder($server, $folder) as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function listFolder(Server $server, string $folder): array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory($folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                // The folder simply doesn't exist yet.
                return [];
            }

            throw $exception;
        }

        if (isset($files['error'])) {
            throw new Exception("Daemon returned an error while listing $folder: ".(is_string($files['error']) ? $files['error'] : 'unknown error'));
        }

        return $files;
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    public function getInstalledMod(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata($server, $modrinthProjectType);

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * @param  array{version_id: string, version_number: string}  $installedMod
     * @param  array<int, array{id: string, version_number: string}>  $availableVersions
     */
    public function isUpdateAvailable(array $installedMod, array $availableVersions): bool
    {
        $latestVersion = $this->getLatestVersion($availableVersions, $installedMod['version_id']);

        return $latestVersion !== null && $installedMod['version_id'] !== $latestVersion['id'];
    }

    /**
     * @return array<string>
     */
    public function getInstalledMods(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $metadata = $this->getInstalledModsMetadata($server, $modrinthProjectType);

        return collect($metadata)
            ->pluck('filename')
            ->map(fn ($name) => strtolower($name))
            ->toArray();
    }
}

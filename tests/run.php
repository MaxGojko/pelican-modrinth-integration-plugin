<?php

// Usage: php tests/run.php <plugin directory>   (from the repository root: php tests/run.php .)

use App\Models\Server;
use MaxGojko\ModrinthIntegration\Enums\ModrinthProjectType;
use MaxGojko\ModrinthIntegration\Exceptions\DependencyInstallException;
use MaxGojko\ModrinthIntegration\Facades\Modrinth;
use MaxGojko\ModrinthIntegration\Filament\Server\Pages\ModrinthPage;
use MaxGojko\ModrinthIntegration\Services\ModrinthService;
use App\Enums\SubuserPermission as P;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

error_reporting(E_ALL);
ini_set('display_errors', '1');

$plugin = $argv[1] ?? exit("plugin path missing\n");

require __DIR__.'/stubs.php';
require "$plugin/src/Enums/ModrinthProjectType.php";
require "$plugin/src/Exceptions/DependencyInstallException.php";
require "$plugin/src/Services/ModrinthService.php";
require "$plugin/src/Facades/Modrinth.php";
require "$plugin/src/Filament/Server/Pages/ModrinthPage.php";

// ---------------------------------------------------------------- helpers

$failures = 0;
$passes = 0;

function t(string $name, bool $condition, string $detail = ''): void
{
    global $failures, $passes;

    if ($condition) {
        $passes++;
        echo "  ok    $name\n";
    } else {
        $failures++;
        echo "  FAIL  $name".($detail !== '' ? "  -> $detail" : '')."\n";
    }
}

function ver(string $id, string $project, string $type = 'release', array $deps = [], ?string $file = null, string $date = '2026-01-01T00:00:00Z'): array
{
    $file ??= "$project-$id.jar";

    return [
        'id' => $id,
        'project_id' => $project,
        'version_number' => "v$id",
        'version_type' => $type,
        'date_published' => $date,
        'dependencies' => $deps,
        'files' => [
            ['primary' => false, 'filename' => "$file.sources", 'url' => "https://cdn/$file.sources"],
            ['primary' => true, 'filename' => $file, 'url' => "https://cdn/$file"],
        ],
    ];
}

function dep(?string $project, ?string $version = null, string $type = 'required'): array
{
    return ['project_id' => $project, 'version_id' => $version, 'dependency_type' => $type];
}

function proj(string $id, ?string $title = null): array
{
    return ['id' => $id, 'slug' => $id, 'title' => $title ?? ucfirst($id)];
}

function installedEntry(string $project, string $versionId, string $file, string $title): array
{
    return [
        'project_id' => $project,
        'project_slug' => $project,
        'project_title' => $title,
        'version_id' => $versionId,
        'version_number' => "v$versionId",
        'filename' => $file,
        'installed_at' => '2026-01-01T00:00:00+00:00',
    ];
}

class FakeService extends ModrinthService
{
    public array $projects = [];

    /** @var array<string, array<int, array>> newest first */
    public array $versions = [];

    public array $installed = [];

    public array $files = [];

    public array $authors = [];

    public array $failDownloads = [];

    public int $downloadDelayUs = 0;

    public array $downloadOrder = [];

    public function getProject(string $projectId): ?array
    {
        return $this->projects[$projectId] ?? null;
    }

    public function getVersion(string $versionId): ?array
    {
        foreach ($this->versions as $list) {
            foreach ($list as $version) {
                if ($version['id'] === $versionId) {
                    return $version;
                }
            }
        }

        return null;
    }

    public array $loaders = [];

    public ?string $serverProperties = null;

    public array $downloadFolders = [];

    public function getLoaders(): array
    {
        return $this->loaders;
    }

    protected function readServerProperties(Server $server): string
    {
        if ($this->serverProperties === null) {
            throw new \Illuminate\Contracts\Filesystem\FileNotFoundException('server.properties');
        }

        return $this->serverProperties;
    }

    public function getProjectVersions(string $projectId, Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        return $this->versions[$projectId] ?? [];
    }

    public function getProjectVersionsBulk(array $projectIds, Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $out = [];
        foreach ($projectIds as $id) {
            $out[$id] = $this->versions[$id] ?? [];
        }

        return $out;
    }

    public function getProjectAuthor(string $projectId): ?string
    {
        return $this->authors[$projectId] ?? null;
    }

    public function getInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        return array_values($this->installed);
    }

    public function listFolder(Server $server, string $folder): array
    {
        return array_map(fn (string $name) => ['name' => $name], array_values($this->files));
    }

    public array $downloadSizes = [];

    public function downloadFile(Server $server, string $url, string $folder, string $filename, ?int $expectedSize = null): bool
    {
        if ($this->downloadDelayUs) {
            usleep($this->downloadDelayUs);
        }

        if (in_array($filename, $this->failDownloads, true)) {
            throw new Exception("download of $filename failed");
        }

        $existed = isset($this->files[$filename]);
        $this->files[$filename] = $filename;
        $this->downloadOrder[] = $filename;
        $this->downloadFolders[$filename] = $folder;
        $this->downloadSizes[$filename] = $expectedSize;

        return $existed;
    }

    public function deleteFile(Server $server, string $folder, string $filename): void
    {
        unset($this->files[$filename]);
    }

    public function saveModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId, string $projectSlug, string $projectTitle, string $versionId, string $versionNumber, string $filename, ?string $author = null): bool
    {
        $this->installed[$projectId] = [
            'project_id' => $projectId,
            'project_slug' => $projectSlug,
            'project_title' => $projectTitle,
            'version_id' => $versionId,
            'version_number' => $versionNumber,
            'filename' => $filename,
            'installed_at' => '2026-01-01T00:00:00+00:00',
        ] + ($author !== null ? ['author' => $author] : []);

        return true;
    }

    /** Mark a mod as installed with files on disk, like a previous install through the plugin. */
    public function seedInstalled(string $project, string $title, string $versionId): void
    {
        $file = "$project-$versionId.jar";
        $this->installed[$project] = installedEntry($project, $versionId, $file, $title);
        $this->files[$file] = $file;
        $this->projects[$project] ??= proj($project, $title);
    }
}

class FakePage extends ModrinthPage
{
    public ?string $projectType = 'mod';

    protected const UPDATE_ALL_TIME_BUDGET = 50;

    public function call(string $method, ...$args)
    {
        return $this->$method(...$args);
    }
}

class SlowFakePage extends FakePage
{
    protected const UPDATE_ALL_TIME_BUDGET = 0.05;
}

function boot(string $page = FakePage::class, array $tags = ['modrinth_mods', 'fabric']): array
{
    $service = new FakeService;
    \Illuminate\Support\Facades\Facade::$instance = $service;
    Filament::$tenant = new Server($tags);
    Notification::$sent = [];
    $GLOBALS['reported'] = [];
    $GLOBALS['user_permissions'] = null;

    return [$service, new $page];
}

function lastNotification(): array
{
    return Notification::$sent[array_key_last(Notification::$sent)] ?? [];
}

function server(): Server
{
    return Filament::$tenant;
}

// ---------------------------------------------------------------- getRequiredDependencies

echo "getRequiredDependencies\n";
{
    [$s] = boot();

    $deps = $s->getRequiredDependencies(['dependencies' => [
        dep('a'),
        dep('b', null, 'optional'),
        dep('c', null, 'embedded'),
        dep('d', null, 'incompatible'),
        dep(null, 'ver1'),
        dep(null, null),
        dep('', ''),
        ['dependency_type' => 'required', 'project_id' => 123],
        'garbage',
        dep('e', 'ver2'),
    ]]);

    t('only required ones with an id are kept', $deps === [
        ['project_id' => 'a', 'version_id' => null],
        ['project_id' => null, 'version_id' => 'ver1'],
        ['project_id' => 'e', 'version_id' => 'ver2'],
    ], json_encode($deps));

    t('missing dependencies key is fine', $s->getRequiredDependencies([]) === []);
}

// ---------------------------------------------------------------- getLatestVersion

echo "getLatestVersion (stability channels)\n";
{
    [$s] = boot();

    $list = [
        ver('a3', 'p', 'alpha'),
        ver('b2', 'p', 'beta'),
        ver('r2', 'p', 'release'),
        ver('r1', 'p', 'release'),
    ];

    t('fresh install prefers newest release over newer beta/alpha', $s->getLatestVersion($list)['id'] === 'r2');
    t('fresh install: beta only -> beta', $s->getLatestVersion([ver('a3', 'p', 'alpha'), ver('b2', 'p', 'beta')])['id'] === 'b2');
    t('fresh install: alpha only -> alpha', $s->getLatestVersion([ver('a3', 'p', 'alpha')])['id'] === 'a3');
    t('release installed -> newest release, not the alpha', $s->getLatestVersion($list, 'r1')['id'] === 'r2');
    t('newest release installed -> itself (no update)', $s->getLatestVersion($list, 'r2')['id'] === 'r2');
    t('beta installed -> newest beta or release', $s->getLatestVersion($list, 'b2')['id'] === 'b2');
    t('alpha installed -> newest of all', $s->getLatestVersion($list, 'a3')['id'] === 'a3');
    t('alpha installed, newer beta exists -> the beta', $s->getLatestVersion([ver('b9', 'p', 'beta'), ver('a1', 'p', 'alpha')], 'a1')['id'] === 'b9');
    t('installed version not in list behaves like fresh install', $s->getLatestVersion($list, 'unknown')['id'] === 'r2');
    t('empty list -> null', $s->getLatestVersion([], 'x') === null);
    t('unknown version_type counts as least stable', $s->getLatestVersion([ver('x1', 'p', 'weird'), ver('r1', 'p', 'release')])['id'] === 'r1');

    t('isUpdateAvailable: release behind a newer release', $s->isUpdateAvailable(['version_id' => 'r1', 'version_number' => 'v'], $list) === true);
    t('isUpdateAvailable: release with only newer alpha/beta', $s->isUpdateAvailable(['version_id' => 'r2', 'version_number' => 'v'], $list) === false);
    t('isUpdateAvailable: empty', $s->isUpdateAvailable(['version_id' => 'r2', 'version_number' => 'v'], []) === false);
}

// ---------------------------------------------------------------- planDependencies

echo "planDependencies\n";
{
    // simple: fabric-api missing
    [$s] = boot();
    $s->projects = ['fabric-api' => proj('fabric-api', 'Fabric API')];
    $s->versions = ['fabric-api' => [ver('f2', 'fabric-api'), ver('f1', 'fabric-api')]];
    $s->authors = ['fabric-api' => 'modmuss50'];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('fabric-api')]));
    t('missing dependency is planned with newest version', count($plan) === 1 && $plan[0]['version']['id'] === 'f2');
    t('plan carries record with title, slug, author', $plan[0]['record'] === ['project_id' => 'fabric-api', 'slug' => 'fabric-api', 'title' => 'Fabric API', 'author' => 'modmuss50'], json_encode($plan[0]['record'] ?? null));
    t('plan carries the primary file, not the sources jar', $plan[0]['file']['filename'] === 'fabric-api-f2.jar');

    // no dependencies at all must not touch the network or folder
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod'));
    t('no dependencies -> empty plan', $plan === []);

    // already installed through the plugin
    [$s] = boot();
    $s->projects = ['fabric-api' => proj('fabric-api')];
    $s->versions = ['fabric-api' => [ver('f2', 'fabric-api')]];
    $s->seedInstalled('fabric-api', 'Fabric API', 'f1');
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('fabric-api')]));
    t('dependency in metadata is skipped', $plan === []);

    // uploaded by hand, matching an older compatible release's file name
    [$s] = boot();
    $s->projects = ['fabric-api' => proj('fabric-api')];
    $s->versions = ['fabric-api' => [ver('f2', 'fabric-api'), ver('f1', 'fabric-api')]];
    $s->files = ['Fabric-API-F1.JAR' => 'Fabric-API-F1.JAR', 'fabric-api-f1.jar' => 'fabric-api-f1.jar'];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('fabric-api')]));
    t('manually uploaded jar (older release filename) counts as present', $plan === []);

    // unrelated files do not count
    [$s] = boot();
    $s->projects = ['fabric-api' => proj('fabric-api')];
    $s->versions = ['fabric-api' => [ver('f2', 'fabric-api')]];
    $s->files = ['something-else.jar' => 'something-else.jar'];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('fabric-api')]));
    t('unrelated jar does not hide a missing dependency', count($plan) === 1);

    // transitive: A -> B -> C, deepest first
    [$s] = boot();
    $s->projects = ['b' => proj('b'), 'c' => proj('c')];
    $s->versions = ['b' => [ver('b1', 'b', 'release', [dep('c')])], 'c' => [ver('c1', 'c')]];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('a1', 'a', 'release', [dep('b')]));
    t('transitive dependencies come deepest first', array_map(fn ($p) => $p['record']['project_id'], $plan) === ['c', 'b']);

    // diamond: A -> B,C ; B -> D ; C -> D
    [$s] = boot();
    $s->projects = ['b' => proj('b'), 'c' => proj('c'), 'd' => proj('d')];
    $s->versions = [
        'b' => [ver('b1', 'b', 'release', [dep('d')])],
        'c' => [ver('c1', 'c', 'release', [dep('d')])],
        'd' => [ver('d1', 'd')],
    ];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('a1', 'a', 'release', [dep('b'), dep('c')]));
    t('shared dependency planned once and before its dependents', array_map(fn ($p) => $p['record']['project_id'], $plan) === ['d', 'b', 'c'], json_encode(array_map(fn ($p) => $p['record']['project_id'], $plan)));

    // cycle: A -> B -> A, and B -> B
    [$s] = boot();
    $s->projects = ['a' => proj('a'), 'b' => proj('b')];
    $s->versions = ['a' => [ver('a1', 'a', 'release', [dep('b')])], 'b' => [ver('b1', 'b', 'release', [dep('a'), dep('b')])]];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('a1', 'a', 'release', [dep('b')]));
    t('circular dependency terminates and does not reinstall the mod itself', array_map(fn ($p) => $p['record']['project_id'], $plan) === ['b']);

    // pinned version only (no project id)
    [$s] = boot();
    $s->projects = ['lib' => proj('lib')];
    $s->versions = ['lib' => [ver('l2', 'lib'), ver('l1', 'lib')]];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep(null, 'l1')]));
    t('dependency given only by version id resolves its project and uses that version', count($plan) === 1 && $plan[0]['record']['project_id'] === 'lib' && $plan[0]['version']['id'] === 'l1');

    // pinned version that is not available for this server falls back to newest
    [$s] = boot();
    $s->projects = ['lib' => proj('lib')];
    $s->versions = ['lib' => [ver('l2', 'lib')]];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('lib', 'l-not-compatible')]));
    t('pinned version not compatible -> newest compatible', $plan[0]['version']['id'] === 'l2');

    // pinned and compatible beats a newer one
    $s->versions = ['lib' => [ver('l2', 'lib'), ver('l1', 'lib')]];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('lib', 'l1')]));
    t('pinned and compatible -> pinned', $plan[0]['version']['id'] === 'l1');

    // newest *release* is preferred for a dependency
    [$s] = boot();
    $s->projects = ['lib' => proj('lib')];
    $s->versions = ['lib' => [ver('l3', 'lib', 'alpha'), ver('l2', 'lib', 'release')]];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('lib')]));
    t('unpinned dependency prefers a release over a newer alpha', $plan[0]['version']['id'] === 'l2');

    // optional / embedded ignored
    $plan = $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('lib', null, 'optional'), dep('lib', null, 'embedded')]));
    t('optional and embedded dependencies are ignored', $plan === []);

    // failure: no compatible version -> exception naming the project, nothing partial
    [$s] = boot();
    $s->projects = ['ok' => proj('ok'), 'gone' => proj('gone', 'Gone Lib')];
    $s->versions = ['ok' => [ver('o1', 'ok')], 'gone' => []];
    try {
        $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('ok'), dep('gone')]));
        t('dependency without compatible version throws', false);
    } catch (DependencyInstallException $e) {
        t('dependency without compatible version throws, naming it', $e->getDependencyName() === 'Gone Lib', $e->getDependencyName());
    }

    // failure: unknown project
    [$s] = boot();
    try {
        $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('nope')]));
        t('unknown dependency project throws', false);
    } catch (DependencyInstallException $e) {
        t('unknown dependency project throws, naming its id', $e->getDependencyName() === 'nope', $e->getDependencyName());
    }

    // failure inside a transitive dependency is reported with the failing project
    [$s] = boot();
    $s->projects = ['b' => proj('b'), 'c' => proj('c', 'Deep Lib')];
    $s->versions = ['b' => [ver('b1', 'b', 'release', [dep('c')])], 'c' => []];
    try {
        $s->planDependencies(server(), ModrinthProjectType::Mod, ver('a1', 'a', 'release', [dep('b')]));
        t('failing transitive dependency throws', false);
    } catch (DependencyInstallException $e) {
        t('failing transitive dependency throws, naming the deep one', $e->getDependencyName() === 'Deep Lib', $e->getDependencyName());
    }

    // failure: version id that can't be resolved
    [$s] = boot();
    try {
        $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep(null, 'ghost')]));
        t('unresolvable version id throws', false);
    } catch (DependencyInstallException $e) {
        t('unresolvable version id throws, naming it', $e->getDependencyName() === 'ghost');
    }

    // failure: no primary file
    [$s] = boot();
    $s->projects = ['lib' => proj('lib', 'Lib')];
    $noPrimary = ver('l1', 'lib');
    $noPrimary['files'] = [];
    $s->versions = ['lib' => [$noPrimary]];
    try {
        $s->planDependencies(server(), ModrinthProjectType::Mod, ver('m1', 'mod', 'release', [dep('lib')]));
        t('dependency without downloadable file throws', false);
    } catch (DependencyInstallException $e) {
        t('dependency without downloadable file throws, naming it', $e->getDependencyName() === 'Lib');
    }
}

// ---------------------------------------------------------------- page: installVersion

echo "page: install with dependencies\n";
{
    [$s, $page] = boot();
    $s->projects = ['b' => proj('b', 'Lib B'), 'c' => proj('c', 'Lib C'), 'mod' => proj('mod', 'The Mod')];
    $s->authors = ['b' => 'authorB', 'c' => 'authorC'];
    $s->versions = [
        'mod' => [ver('m1', 'mod', 'release', [dep('b')])],
        'b' => [ver('b1', 'b', 'release', [dep('c')])],
        'c' => [ver('c1', 'c')],
    ];

    $record = ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod', 'author' => 'me'];
    $page->call('installVersion', $record, $s->versions['mod'][0]);

    t('downloads in dependency order, mod last', $s->downloadOrder === ['c-c1.jar', 'b-b1.jar', 'mod-m1.jar'], json_encode($s->downloadOrder));
    t('all three tracked in metadata', array_keys($s->installed) === ['c', 'b', 'mod'], json_encode(array_keys($s->installed)));
    t('dependency author saved', ($s->installed['b']['author'] ?? null) === 'authorB');
    t('success notification', (lastNotification()['success'] ?? null) === true && str_contains(lastNotification()['title'], 'install_success'));
    t('notification mentions installed dependencies', str_contains(lastNotification()['body'], 'dependencies_installed') && str_contains(lastNotification()['body'], 'Lib C, Lib B'), lastNotification()['body'] ?? '');
    t('page state refreshed', count($page->jsCalls) === 1);
}

echo "page: dependency that can't be installed aborts the install\n";
{
    [$s, $page] = boot();
    $s->projects = ['ok' => proj('ok', 'Fine Lib'), 'bad' => proj('bad', 'Missing Lib')];
    $s->versions = [
        'mod' => [ver('m1', 'mod', 'release', [dep('ok'), dep('bad')])],
        'ok' => [ver('o1', 'ok')],
        'bad' => [],
    ];
    $record = ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod', 'author' => null];
    $page->call('installVersion', $record, $s->versions['mod'][0]);

    t('nothing downloaded at all', $s->downloadOrder === [] && $s->files === [] && $s->installed === []);
    t('failure notification names the dependency', (lastNotification()['danger'] ?? null) === true && str_contains(lastNotification()['body'], 'dependency_failed_body') && str_contains(lastNotification()['body'], 'Missing Lib'), json_encode(lastNotification()));
}

echo "page: dependency download failing aborts before the mod\n";
{
    [$s, $page] = boot();
    $s->projects = ['lib' => proj('lib', 'Lib')];
    $s->versions = ['mod' => [ver('m1', 'mod', 'release', [dep('lib')])], 'lib' => [ver('l1', 'lib')]];
    $s->failDownloads = ['lib-l1.jar'];
    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod', 'author' => null], $s->versions['mod'][0]);

    t('mod not installed when its dependency download fails', !isset($s->installed['mod']) && !isset($s->files['mod-m1.jar']));
    t('failure names the dependency', str_contains(lastNotification()['body'] ?? '', 'Lib'));
}

echo "page: manually uploaded dependency is not installed twice\n";
{
    [$s, $page] = boot();
    $s->projects = ['fabric-api' => proj('fabric-api', 'Fabric API')];
    $s->versions = ['mod' => [ver('m1', 'mod', 'release', [dep('fabric-api')])], 'fabric-api' => [ver('f2', 'fabric-api'), ver('f1', 'fabric-api')]];
    $s->files = ['fabric-api-f1.jar' => 'fabric-api-f1.jar'];
    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod', 'author' => null], $s->versions['mod'][0]);

    t('only the mod is downloaded', $s->downloadOrder === ['mod-m1.jar'], json_encode($s->downloadOrder));
    t('no dependency line in the notification', !str_contains(lastNotification()['body'], 'dependencies_installed'));
}

echo "page: single update\n";
{
    [$s, $page] = boot();
    $s->seedInstalled('mod', 'The Mod', '1');
    $s->versions = [
        'mod' => [ver('3', 'mod', 'alpha'), ver('2', 'mod', 'release', [dep('lib')]), ver('1', 'mod')],
        'lib' => [ver('l1', 'lib')],
    ];
    $s->projects['lib'] = proj('lib', 'Lib');
    $installed = $s->installed['mod'];
    $latest = $s->getLatestVersion($s->versions['mod'], '1');
    t('update target is the release, not the newer alpha', $latest['id'] === '2');

    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod', 'author' => null], $latest, $installed, 'update');
    t('old file removed, new file in place', !isset($s->files['mod-1.jar']) && isset($s->files['mod-2.jar']));
    t('metadata points to the new version', $s->installed['mod']['version_id'] === '2');
    t('new dependency installed on update', isset($s->installed['lib']) && isset($s->files['lib-l1.jar']));
    t('update notification keys', str_contains(lastNotification()['title'], 'update_success'));

    // update without installed metadata and without a version
    [$s, $page] = boot();
    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'T'], ['id' => 'x'], null, 'update');
    t('update without installed entry fails cleanly', (lastNotification()['danger'] ?? null) === true && str_contains(lastNotification()['title'], 'update_failed'));
    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'T'], null);
    t('install without any compatible version fails cleanly', str_contains(lastNotification()['title'], 'install_failed'));
}

// ---------------------------------------------------------------- page: pending updates & update all

echo "page: pending updates\n";
{
    [$s, $page] = boot();
    $s->seedInstalled('zeta', 'Zeta', '1');
    $s->seedInstalled('alpha', 'alpha mod', '1');
    $s->seedInstalled('stable', 'Stable', '5');
    $s->seedInstalled('lonely', 'Lonely', '1');
    $s->versions = [
        'zeta' => [ver('2', 'zeta'), ver('1', 'zeta')],
        'alpha' => [ver('2', 'alpha'), ver('1', 'alpha')],
        'stable' => [ver('6', 'stable', 'beta'), ver('5', 'stable')],   // only a beta is newer
        'lonely' => [],                                                   // Modrinth had nothing / failed
    ];

    $pending = $page->call('getPendingUpdates');
    t('only mods with a newer version in their channel are pending', array_map(fn ($p) => $p['mod']['project_id'], $pending) === ['alpha', 'zeta'], json_encode(array_map(fn ($p) => $p['mod']['project_id'], $pending)));
    t('sorted by title, case-insensitively', $pending[0]['mod']['project_title'] === 'alpha mod');

    [$s, $page] = boot();
    t('nothing installed -> nothing pending', $page->call('getPendingUpdates') === []);
}

echo "page: update all\n";
{
    // everything works, one needs a new dependency
    [$s, $page] = boot();
    $s->seedInstalled('a', 'Mod A', '1');
    $s->seedInstalled('b', 'Mod B', '1');
    $s->seedInstalled('c', 'Mod C', '1');
    $s->projects['lib'] = proj('lib', 'Shared Lib');
    $s->versions = [
        'a' => [ver('2', 'a', 'release', [dep('lib')]), ver('1', 'a')],
        'b' => [ver('2', 'b', 'release', [dep('lib')]), ver('1', 'b')],
        'c' => [ver('1', 'c')],
        'lib' => [ver('l1', 'lib')],
    ];
    $page->call('updateAll');
    $n = lastNotification();
    t('both outdated mods updated', $s->installed['a']['version_id'] === '2' && $s->installed['b']['version_id'] === '2');
    t('up-to-date mod untouched', $s->installed['c']['version_id'] === '1' && isset($s->files['c-1.jar']));
    t('old files deleted', !isset($s->files['a-1.jar']) && !isset($s->files['b-1.jar']));
    t('shared dependency installed exactly once', count(array_keys($s->downloadOrder, 'lib-l1.jar', true)) === 1, json_encode($s->downloadOrder));
    t('success notification with count and dependency', ($n['success'] ?? null) === true && str_contains($n['body'], '"count":2') && str_contains($n['body'], 'Shared Lib'), json_encode($n));

    // nothing to do
    $page->call('updateAll');
    $n = lastNotification();
    t('nothing pending -> info notification, nothing downloaded', ($n['info'] ?? null) === true && str_contains($n['title'], 'update_all_none'));

    // one download fails, the others still go through
    [$s, $page] = boot();
    foreach (['a', 'b', 'c'] as $id) {
        $s->seedInstalled($id, "Mod ".strtoupper($id), '1');
        $s->versions[$id] = [ver('2', $id), ver('1', $id)];
    }
    $s->failDownloads = ['b-2.jar'];
    $page->call('updateAll');
    $n = lastNotification();
    t('failing mod keeps its old version and file', $s->installed['b']['version_id'] === '1' && isset($s->files['b-1.jar']) && !isset($s->files['b-2.jar']));
    t('other mods still updated', $s->installed['a']['version_id'] === '2' && $s->installed['c']['version_id'] === '2');
    t('partial result is a warning naming the failure', ($n['warning'] ?? null) === true && str_contains($n['body'], 'Mod B') && str_contains($n['body'], '"count":2'), json_encode($n));

    // everything fails
    [$s, $page] = boot();
    $s->seedInstalled('a', 'Mod A', '1');
    $s->versions['a'] = [ver('2', 'a'), ver('1', 'a')];
    $s->failDownloads = ['a-2.jar'];
    $page->call('updateAll');
    t('all failing is a danger notification', (lastNotification()['danger'] ?? null) === true);

    // dependency can't be satisfied -> that mod stays on its old version and the reason is shown
    [$s, $page] = boot();
    $s->seedInstalled('a', 'Mod A', '1');
    $s->seedInstalled('b', 'Mod B', '1');
    $s->projects['okdep'] = proj('okdep', 'Ok Dep');
    $s->projects['baddep'] = proj('baddep', 'Bad Dep');
    $s->versions = [
        'a' => [ver('2', 'a', 'release', [dep('okdep'), dep('baddep')]), ver('1', 'a')],
        'b' => [ver('2', 'b'), ver('1', 'b')],
        'okdep' => [ver('o1', 'okdep')],
        'baddep' => [],
    ];
    $page->call('updateAll');
    $n = lastNotification();
    t('mod with unsatisfiable dependency is left alone', $s->installed['a']['version_id'] === '1' && isset($s->files['a-1.jar']));
    t('its other dependency was not installed either', !isset($s->installed['okdep']) && !isset($s->files['okdep-o1.jar']));
    t('independent mod still updated', $s->installed['b']['version_id'] === '2');
    t('notification explains which dependency is the problem', str_contains($n['body'], 'update_all_failed_dependency') && str_contains($n['body'], 'Bad Dep'), json_encode($n));

    // time budget
    [$s, $page] = boot(SlowFakePage::class);
    $s->downloadDelayUs = 80000; // 80 ms per download > 50 ms budget
    foreach (['a', 'b', 'c'] as $id) {
        $s->seedInstalled($id, "Mod ".strtoupper($id), '1');
        $s->versions[$id] = [ver('2', $id), ver('1', $id)];
    }
    $page->call('updateAll');
    $n = lastNotification();
    $updatedCount = count(array_filter($s->installed, fn ($m) => $m['version_id'] === '2'));
    t('stops starting new updates once the budget is spent', $updatedCount === 1, "updated: $updatedCount");
    t('tells the user to continue', str_contains($n['body'], 'update_all_remaining') && str_contains($n['body'], '"count":2'), json_encode($n));
    t('button still has work left', count($page->call('getPendingUpdates')) === 2);

    // second click finishes the rest (fresh budget)
    $s->downloadDelayUs = 0;
    $page2 = new FakePage;
    $page2->call('updateAll');
    t('a further run completes the remaining updates', count($page2->call('getPendingUpdates')) === 0);
}

// ---------------------------------------------------------------- header action

echo "page: Update all header action\n";
{
    [$s, $page] = boot();
    $s->seedInstalled('x', '<script>alert(1)</script>', '1');
    $s->versions['x'] = [ver('2', 'x'), ver('1', 'x')];

    $actions = $page->call('getHeaderActions');
    $names = array_map(fn ($a) => $a->calls['name'][0] ?? 'group', $actions);
    t('type dropdown, import/export, update_all and open_folder present', $names === ['group', 'group', 'update_all', 'open_folder'], json_encode($names));

    $update = $actions[2];
    t('visible while updates are pending', ($update->calls['visible'][0])() === true);
    t('asks for confirmation', isset($update->calls['requiresConfirmation']));

    $description = ($update->calls['modalDescription'][0])();
    $html = $description->toHtml();
    t('confirmation lists the update with versions', str_contains($html, 'v1 &rarr; v2'));
    t('mod names are escaped in the confirmation', !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), $html);

    // many pending: list is capped
    [$s, $page] = boot();
    for ($i = 1; $i <= 20; $i++) {
        $s->seedInstalled("m$i", "Mod $i", '1');
        $s->versions["m$i"] = [ver('2', "m$i"), ver('1', "m$i")];
    }
    $html = ($page->call('getHeaderActions')[2]->calls['modalDescription'][0])()->toHtml();
    t('long list is capped with an "and N more" row', substr_count($html, '<li>') === 16 && str_contains($html, 'update_all_more') && str_contains($html, '&quot;count&quot;:5'), "li count ".substr_count($html, '<li>').' tail: '.substr($html, -140));

    // nothing pending -> hidden
    [$s, $page] = boot();
    $s->seedInstalled('x', 'X', '2');
    $s->versions['x'] = [ver('2', 'x'), ver('1', 'x')];
    t('hidden when everything is up to date', ($page->call('getHeaderActions')[2]->calls['visible'][0])() === false);

    // the action closure actually runs updateAll
    [$s, $page] = boot();
    $s->seedInstalled('x', 'X', '1');
    $s->versions['x'] = [ver('2', 'x'), ver('1', 'x')];
    ($page->call('getHeaderActions')[2]->calls['action'][0])();
    t('clicking the action performs the update', $s->installed['x']['version_id'] === '2');
}

// ---------------------------------------------------------------- tags, loaders, folders, files

echo "egg tags -> project types\n";
{
    $types = fn (array $tags) => array_map(fn ($t) => $t->value, ModrinthProjectType::fromServer(new Server($tags)));
    t('no tags -> nothing', $types([]) === []);
    t('only the tagged types, in display order', $types(['modrinth_datapacks', 'fabric', 'modrinth_mods']) === ['mod', 'datapack']);
    t('all three', $types(['modrinth_plugins', 'modrinth_datapacks', 'modrinth_mods']) === ['mod', 'plugin', 'datapack']);
    t('tags are case-insensitive and trimmed', $types([' Modrinth_Plugins ', 'PAPER']) === ['plugin']);
    t('old features are no longer read', (function () {
        $server = new Server(['minecraft']);
        $server->egg->features = ['modrinth_mods', 'mods'];

        return ModrinthProjectType::fromServer($server) === [];
    })());
    t('non-string tags are ignored', $types([null, 5, 'modrinth_mods']) === ['mod']);
}

echo "loader per type\n";
{
    [$s] = boot();
    $s->loaders = [
        ['name' => 'fabric', 'icon' => '<svg/>', 'supported_project_types' => ['mod', 'modpack']],
        ['name' => 'neoforge', 'icon' => '', 'supported_project_types' => ['mod']],
        ['name' => 'paper', 'icon' => '', 'supported_project_types' => ['plugin']],
        ['name' => 'bukkit', 'icon' => '', 'supported_project_types' => ['plugin']],
        ['name' => 'datapack', 'icon' => '<dp/>', 'supported_project_types' => ['datapack']],
    ];

    $loader = fn (array $tags, ModrinthProjectType $type) => $s->getLoaderFromServer(new Server($tags), $type)['name'] ?? null;

    t('mods use the mod loader tag', $loader(['modrinth_mods', 'Fabric'], ModrinthProjectType::Mod) === 'fabric');
    t('plugins ignore a mod loader tag', $loader(['modrinth_plugins', 'fabric'], ModrinthProjectType::Plugin) === null);
    t('hybrid: each type gets its own loader', $loader(['neoforge', 'bukkit'], ModrinthProjectType::Mod) === 'neoforge' && $loader(['neoforge', 'bukkit'], ModrinthProjectType::Plugin) === 'bukkit');
    t('datapacks need no loader tag', $loader([], ModrinthProjectType::Datapack) === 'datapack');
    $s->loaders = [];
    t('datapack loader works even if Modrinth is unreachable', $loader([], ModrinthProjectType::Datapack) === 'datapack');
    t('mods without reachable loader list -> unknown', $loader(['fabric'], ModrinthProjectType::Mod) === null);
}

echo "world folder from server.properties\n";
{
    [$s] = boot();
    t('plain level-name', $s->parseLevelName("motd=hi\nlevel-name=survival\n") === 'survival');
    t('spaces around separator and CRLF', $s->parseLevelName("level-name = My World\r\nmotd=x\r\n") === 'My World');
    t('colon separator and escapes', $s->parseLevelName('level-name:my\ world\:2') === 'my world:2');
    t('comments ignored', $s->parseLevelName("#level-name=nope\n!level-name=nope\nlevel-name=yes") === 'yes');
    t('missing -> world', $s->parseLevelName("motd=x") === 'world');
    t('empty -> world', $s->parseLevelName("level-name=") === 'world');
    t('traversal -> world', $s->parseLevelName("level-name=../../etc") === 'world' && $s->parseLevelName("level-name=a/../../b") === 'world');
    t('absolute -> world', $s->parseLevelName("level-name=/etc") === 'world');
    t('nested folder allowed', $s->parseLevelName("level-name=worlds/main/") === 'worlds/main');
    t('last definition wins', $s->parseLevelName("level-name=a\nlevel-name=b") === 'b');

    [$s] = boot();
    t('no server.properties yet -> world/datapacks', $s->getProjectFolder(server(), ModrinthProjectType::Datapack) === 'world/datapacks');
    [$s] = boot();
    $s->serverProperties = "level-name=hardcore\n";
    t('datapacks go into the configured world', $s->getProjectFolder(server(), ModrinthProjectType::Datapack) === 'hardcore/datapacks');
    t('mods and plugins folders', $s->getProjectFolder(server(), ModrinthProjectType::Mod) === 'mods' && $s->getProjectFolder(server(), ModrinthProjectType::Plugin) === 'plugins');
}

echo "install file per type\n";
{
    [$s] = boot();
    $file = fn (array $files, ModrinthProjectType $type) => $s->getInstallFile(['files' => $files], $type)['filename'] ?? null;
    $f = fn (string $name, bool $primary = false) => ['filename' => $name, 'url' => "https://cdn/$name", 'primary' => $primary];

    t('mod: primary file', $file([$f('a-sources.jar'), $f('a.jar', true)], ModrinthProjectType::Mod) === 'a.jar');
    t('mod: nothing marked primary -> the first file (as Modrinth defines it)', $file([$f('a.jar'), $f('b.jar')], ModrinthProjectType::Mod) === 'a.jar');
    t('mod: no primary -> a jar is preferred over other files', $file([$f('a-sources.zip'), $f('a.jar')], ModrinthProjectType::Mod) === 'a.jar');
    t('no files at all -> none', $file([], ModrinthProjectType::Mod) === null);
    t('datapack: primary zip', $file([$f('pack.zip', true)], ModrinthProjectType::Datapack) === 'pack.zip');
    t('datapack: primary is a mod jar -> take the zip', $file([$f('pack-mod.jar', true), $f('Pack.ZIP')], ModrinthProjectType::Datapack) === 'Pack.ZIP');
    t('datapack: no zip at all -> none', $file([$f('pack-mod.jar', true)], ModrinthProjectType::Datapack) === null);
    t('malformed file entries are skipped', $file([['filename' => 'x.jar', 'primary' => true], 'junk', $f('ok.jar', true)], ModrinthProjectType::Mod) === 'ok.jar');
}

echo "page: type selection\n";
{
    [$s, $page] = boot(FakePage::class, ['modrinth_mods', 'fabric', 'modrinth_datapacks']);

    $page->projectType = 'plugin';
    t('a type the egg does not enable falls back to the first enabled one', $page->call('getProjectType') === ModrinthProjectType::Mod);
    $page->projectType = 'nonsense';
    t('garbage falls back too', $page->call('getProjectType') === ModrinthProjectType::Mod);
    $page->projectType = 'datapack';
    t('an enabled type is used', $page->call('getProjectType') === ModrinthProjectType::Datapack);

    $group = $page->call('getHeaderActions')[0];
    $entries = array_map(fn ($a) => $a->calls['name'][0], $group->actions);
    t('dropdown lists only enabled types', $entries === ['type_mod', 'type_datapack'], json_encode($entries));
    t('dropdown is labelled with the current type', ($group->calls['label'][0] ?? null) === 'modrinth-integration::strings.types.datapack');
    t('current type has the check mark', ($group->actions[1]->calls['icon'][0] ?? null) === 'tabler-check' && array_key_exists('icon', $group->actions[0]->calls) && $group->actions[0]->calls['icon'][0] === null);
    t('entries link to the page with ?type=', ($group->actions[0]->calls['url'][0] ?? null) === '/server/modrinth?type=mod');

    $open = $page->call('getHeaderActions')[3];
    t('open folder points at the world datapacks folder', str_contains(($open->calls['tooltip'][0])(), '"folder":"world\\/datapacks"'));
}

echo "page: datapack install\n";
{
    [$s, $page] = boot(FakePage::class, ['modrinth_datapacks']);
    $page->projectType = 'datapack';
    $s->serverProperties = "level-name=smp\n";
    $version = ver('d1', 'terralith', 'release', [], 'terralith.zip');
    $version['files'] = [
        ['primary' => true, 'filename' => 'terralith-mod.jar', 'url' => 'https://cdn/terralith-mod.jar'],
        ['primary' => false, 'filename' => 'terralith.zip', 'url' => 'https://cdn/terralith.zip'],
    ];
    $s->versions['terralith'] = [$version];

    $page->call('installVersion', ['project_id' => 'terralith', 'slug' => 'terralith', 'title' => 'Terralith'], $version);

    t('zip downloaded into the world datapacks folder', ($s->downloadFolders['terralith.zip'] ?? null) === 'smp/datapacks', json_encode($s->downloadFolders));
    t('the mod jar is not installed', !isset($s->files['terralith-mod.jar']));
    t('tracked as installed', ($s->installed['terralith']['filename'] ?? null) === 'terralith.zip');
}

echo "page: access\n";
{
    boot(FakePage::class, ['modrinth_mods']);
    t('page open when a type tag is set', ModrinthPage::canAccess() === true);
    boot(FakePage::class, ['minecraft', 'fabric']);
    t('page hidden without any type tag', ModrinthPage::canAccess() === false);
    boot(FakePage::class, ['modrinth_mods']);
}

echo "search: client-only facet\n";
{
    $searchService = new class extends FakeService
    {
        public function getMinecraftVersion(Server $server): ?string
        {
            return '26.3';
        }

        protected function request(int $timeout = 5): \Illuminate\Http\Client\PendingRequest
        {
            return new \Illuminate\Http\Client\PendingRequest;
        }
    };
    $searchService->loaders = [['name' => 'fabric', 'icon' => '', 'supported_project_types' => ['mod']]];
    $GLOBALS['stub_cache'] = new StubCache;
    \Illuminate\Http\Client\PendingRequest::$requests = [];
    \Illuminate\Http\Client\PendingRequest::$response = ['hits' => [['project_id' => 'a']], 'total_hits' => 1];
    $fabric = new Server(['modrinth_mods', 'fabric']);

    $searchService->getProjects($fabric, ModrinthProjectType::Mod, 1, 'map', true);
    $facets = json_decode(\Illuminate\Http\Client\PendingRequest::$requests[0]['query']['facets'], true);
    t('hiding adds server_side!=unsupported as its own AND group', $facets === [['categories:fabric'], ['versions:26.3'], ['project_type:mod'], ['server_side!=unsupported']], json_encode($facets));

    $searchService->getProjects($fabric, ModrinthProjectType::Mod, 1, 'map', false);
    t('showing client mods is a separate request (not served from the hidden cache)', count(\Illuminate\Http\Client\PendingRequest::$requests) === 2);
    $facets = json_decode(\Illuminate\Http\Client\PendingRequest::$requests[1]['query']['facets'], true);
    t('without hiding there is no server_side facet', $facets === [['categories:fabric'], ['versions:26.3'], ['project_type:mod']], json_encode($facets));

    $searchService->getProjects($fabric, ModrinthProjectType::Mod, 1, 'map', true);
    t('repeating a search is served from cache', count(\Illuminate\Http\Client\PendingRequest::$requests) === 2);
}

echo "page: client-side checkbox\n";
{
    $pageService = null;
    $makeTable = function (array $tags, string $type, ?string $tab) use (&$pageService) {
        [$pageService, $page] = boot(FakePage::class, $tags);
        $page->projectType = $type;
        $page->activeTab = $tab;
        $pageService = new class extends FakeService
        {
            public array $searches = [];

            public function getProjects(Server $server, ModrinthProjectType $type, int $page = 1, ?string $search = null, bool $hideClientOnly = false): array
            {
                $this->searches[] = compact('page', 'search', 'hideClientOnly') + ['type' => $type->value];

                return ['hits' => [], 'total_hits' => 0];
            }

            public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
            {
                return array_map(fn ($m) => ['project_id' => $m['project_id'], 'title' => $m['project_title'], 'server_side' => 'unsupported'], $installedMods);
            }
        };
        \Illuminate\Support\Facades\Facade::$instance = $pageService;

        return [$page, $page->table(new \Filament\Tables\Table)];
    };

    [$page, $table] = $makeTable(['modrinth_mods', 'fabric'], 'mod', 'all');
    $filter = $table->calls['filters'][0][0];
    t('one filter named show_client_only', count($table->calls['filters'][0]) === 1 && $filter->calls['name'][0] === 'show_client_only');
    t('filter sits in the search row (default layout, no extra row)', !array_key_exists('layout', $table->calls['filters']) && count($table->calls['filters']) === 1);
    t('applies immediately', ($table->calls['deferFilters'][0] ?? null) === false);
    t('not active by default (no ->default())', !isset($filter->calls['default']));
    t('visible on Mods / All', ($filter->calls['visible'][0])() === true);

    $records = $table->calls['records'][0];
    $records(null, 1, null);
    t('first load (no filter state yet) hides client-only mods', $pageService->searches[0]['hideClientOnly'] === true);
    $records('jei', 2, ['show_client_only' => ['isActive' => false]]);
    t('unticked hides them', $pageService->searches[1]['hideClientOnly'] === true && $pageService->searches[1]['page'] === 2 && $pageService->searches[1]['search'] === 'jei');
    $records(null, 1, ['show_client_only' => ['isActive' => true]]);
    t('ticked shows them', $pageService->searches[2]['hideClientOnly'] === false);

    $page->activeTab = 'installed';
    t('hidden on the Installed tab', ($filter->calls['visible'][0])() === false);
    $pageService->installed = ['x' => installedEntry('x', '1', 'x-1.jar', 'X')];
    $page->call('forgetInstalledState', false); // a new request would start with an empty per-request cache
    $result = $records(null, 1, null);
    t('installed tab still lists installed mods, filter or not', $pageService->searches === array_slice($pageService->searches, 0, 3) && $result instanceof \Illuminate\Pagination\LengthAwarePaginator && $result->total === 1 && ($result->items[0]['server_side'] ?? null) === 'unsupported', json_encode($result->items ?? null));

    [$page, $table] = $makeTable(['modrinth_plugins', 'paper'], 'plugin', 'all');
    t('hidden for plugins', ($table->calls['filters'][0][0]->calls['visible'][0])() === false);
    ($table->calls['records'][0])(null, 1, null);
    t('plugins are never filtered by side', $pageService->searches[0]['hideClientOnly'] === false);

    [$page, $table] = $makeTable(['modrinth_datapacks'], 'datapack', 'all');
    t('hidden for datapacks', ($table->calls['filters'][0][0]->calls['visible'][0])() === false);

    // client-only marker on the title column
    [$page, $table] = $makeTable(['modrinth_mods', 'fabric'], 'mod', 'all');
    $title = null;
    foreach ($table->calls['columns'][0] as $column) {
        if (($column->calls['name'][0] ?? null) === 'title') {
            $title = $column;
        }
    }
    $icon = $title->calls['icon'][0];
    $tooltip = $title->calls['tooltip'][0];
    t('client-only rows get a desktop icon', $icon(['server_side' => 'unsupported', 'client_side' => 'required']) === 'tabler-device-desktop');
    t('server-capable rows get none', $icon(['server_side' => 'optional']) === null && $icon(['server_side' => 'required']) === null && $icon([]) === null);
    t('tooltip explains it', $tooltip(['server_side' => 'unsupported']) === 'modrinth-integration::strings.page.client_only_tooltip' && $tooltip(['server_side' => 'required']) === null);
}

// ---------------------------------------------------------------- import / export

/** Keeps installs and files apart per type/folder, like a real server. */
class ListService extends FakeService
{
    /** @var array<string, array<string, array>> type => project id => metadata entry */
    public array $installedByType = [];

    /** @var array<string, array<string, string>> folder => file name => content */
    public array $filesByFolder = [];

    /** @var array<int, array> extra raw listing entries per folder */
    public array $extraListing = [];

    public array $hashToProject = [];

    public int $reads = 0;

    public bool $modrinthDown = false;

    public bool $hashLookupDown = false;

    public function getMinecraftVersion(Server $server): ?string
    {
        return '26.3';
    }

    public function getProjectsBulk(array $refs): array
    {
        if ($this->modrinthDown) {
            throw new Exception('Modrinth down');
        }

        $out = [];
        foreach ($refs as $ref) {
            foreach ($this->projects as $id => $project) {
                if ((string) $id === (string) $ref || strtolower($project['slug']) === strtolower((string) $ref)) {
                    $out[$id] = $project;
                }
            }
        }

        return $out;
    }

    public function getProjectIdsByHashes(array $sha1Hashes): array
    {
        if ($this->hashLookupDown) {
            throw new Exception('Modrinth down');
        }

        return array_intersect_key($this->hashToProject, array_flip($sha1Hashes));
    }

    protected function hashFile(Server $server, string $path): string
    {
        $this->reads++;
        $content = $this->filesByFolder[dirname($path)][basename($path)] ?? null;

        if ($content === null) {
            throw new Exception("cannot read $path");
        }

        return sha1($content);
    }

    public function listFolder(Server $server, string $folder): array
    {
        $entries = [];
        foreach ($this->filesByFolder[$folder] ?? [] as $name => $content) {
            $entries[] = ['name' => (string) $name, 'size' => strlen($content), 'modified' => 't1', 'directory' => false];
        }

        return array_merge($entries, $this->extraListing[$folder] ?? []);
    }

    public function getInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        return array_values($this->installedByType[$modrinthProjectType->value] ?? []);
    }

    public function saveModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId, string $projectSlug, string $projectTitle, string $versionId, string $versionNumber, string $filename, ?string $author = null): bool
    {
        $this->installedByType[$modrinthProjectType->value][$projectId] = installedEntry($projectId, $versionId, $filename, $projectTitle) + ['project_slug' => $projectSlug] + ($author ? ['author' => $author] : []);
        $this->installedByType[$modrinthProjectType->value][$projectId]['project_slug'] = $projectSlug;

        return true;
    }

    public function downloadFile(Server $server, string $url, string $folder, string $filename, ?int $expectedSize = null): bool
    {
        if ($this->downloadDelayUs) {
            usleep($this->downloadDelayUs);
        }

        if (in_array($filename, $this->failDownloads, true)) {
            throw new Exception("download of $filename failed");
        }

        $existed = isset($this->filesByFolder[$folder][$filename]);
        $this->filesByFolder[$folder][$filename] = "downloaded:$url";
        $this->downloadOrder[] = $filename;

        return $existed;
    }

    public function deleteFile(Server $server, string $folder, string $filename): void
    {
        unset($this->filesByFolder[$folder][$filename]);
    }

    public function addProject(string $id, string $slug, string $title, array $versions = []): void
    {
        $this->projects[$id] = ['id' => $id, 'slug' => $slug, 'title' => $title];
        $this->versions[$id] = $versions;
    }
}

function bootList(array $tags = ['modrinth_mods', 'fabric', 'modrinth_datapacks'], string $page = FakePage::class): array
{
    [, $p] = boot($page, $tags);
    $service = new ListService;
    $service->loaders = [
        ['name' => 'fabric', 'icon' => '', 'supported_project_types' => ['mod']],
        ['name' => 'paper', 'icon' => '', 'supported_project_types' => ['plugin']],
        ['name' => 'datapack', 'icon' => '', 'supported_project_types' => ['datapack']],
    ];
    \Illuminate\Support\Facades\Facade::$instance = $service;
    $GLOBALS['stub_cache'] = new StubCache;

    return [$service, $p];
}

function failedFor(array $report, string $name): ?string
{
    foreach ($report['failed'] as $line) {
        if (str_contains($line, '"name":'.json_encode($name, JSON_UNESCAPED_UNICODE))) {
            return $line;
        }
    }

    return null;
}

echo "list format: parsing\n";
{
    [$s] = bootList();
    $text = "# header\n\nlithium\n[Mods]\nfabric-api   # Fabric API\nhttps://modrinth.com/mod/sodium/versions\nP7dR8mSH\nFABRIC-API\nnot valid entry\n[resourcepacks]\nfaithful\nalso-ignored\n[ datapack ]\nterralith\n";
    $parsed = $s->parseModList($text);
    $entries = array_map(fn ($e) => $e['type']->value.':'.$e['ref'].'@'.$e['line'], $parsed['entries']);
    $errors = array_map(fn ($e) => $e['reason'].'@'.$e['line'], $parsed['errors']);

    t('entries with type, ref and line; links reduced to the slug; duplicates (any case) dropped', $entries === ['mod:fabric-api@5', 'mod:sodium@6', 'mod:P7dR8mSH@7', 'datapack:terralith@14'], json_encode($entries));
    t('errors: line before a section, garbage line, unknown section reported once', $errors === ['no_section@3', 'invalid@9', 'unknown_section@10'], json_encode($errors));
    t('error keeps the offending text', $parsed['errors'][1]['text'] === 'not valid entry');
    t('Windows line endings', count($s->parseModList("[mods]\r\nlithium\r\nsodium\r\n")['entries']) === 2);
    t('empty text', $s->parseModList("") === ['entries' => [], 'errors' => []]);
    t('same slug in two sections is two entries', count($s->parseModList("[mods]\nx-lib\n[datapacks]\nx-lib")['entries']) === 2);
}

echo "export\n";
{
    $setup = function () {
        [$s, $page] = bootList();
        $s->addProject('gvQqBUqZ', 'lithium', 'Lithium');
        $s->addProject('P7dR8mSH', 'fabric-api', 'Fabric API');
        $s->addProject('terra1', 'terralith', 'Terralith');
        // installed through the plugin, saved with a slug that has changed since
        $s->installedByType['mod']['gvQqBUqZ'] = installedEntry('gvQqBUqZ', 'l1', 'lithium-1.jar', 'Lithium');
        $s->installedByType['mod']['gvQqBUqZ']['project_slug'] = 'lithium-old';
        $s->filesByFolder['mods'] = [
            'lithium-1.jar' => 'LITHIUM',
            'fabric-api-0.162.jar' => 'FAPI',     // uploaded by hand, Modrinth knows it
            'custom.jar' => 'mine',               // uploaded by hand, unknown to Modrinth
            'config.txt' => 'not a mod',
        ];
        $s->extraListing['mods'] = [
            ['name' => 'huge.jar', 'size' => 300 * 1024 * 1024, 'modified' => 't', 'directory' => false],
            ['name' => 'folder.jar', 'size' => 0, 'modified' => 't', 'directory' => true],
        ];
        $s->filesByFolder['world/datapacks'] = ['terralith.zip' => 'TERRA', 'readme.txt' => 'x'];
        $s->hashToProject = [sha1('FAPI') => 'P7dR8mSH', sha1('TERRA') => 'terra1'];

        return [$s, $page];
    };

    [$s] = $setup();
    $text = $s->buildModList(server());
    $lines = explode("\n", $text);
    $at = fn (string $needle) => array_key_first(array_filter($lines, fn ($l) => str_starts_with($l, $needle)));

    t('sections only for enabled types', str_contains($text, "\n[mods]\n") && str_contains($text, "\n[datapacks]\n") && !str_contains($text, '[plugins]'));
    t('hand-uploaded file found by its hash is listed', $at('fabric-api ') !== null);
    t('managed project uses its current slug from Modrinth', $at('lithium ') !== null && !str_contains($text, 'lithium-old'));
    t('sorted by title within a section', $at('fabric-api ') < $at('lithium ') && $at('lithium ') < $at('[datapacks]') && $at('terralith') > $at('[datapacks]'));
    t('title kept as a comment', str_contains($lines[$at('lithium ')], '# Lithium'));
    t('unknown file listed as a comment to copy by hand', str_contains($text, "export.not_on_modrinth\n#   custom.jar"));
    t('oversized file is not read but mentioned', str_contains($text, "export.unreadable\n#   huge.jar") && $s->reads === 3, "reads {$s->reads}");
    t('other files and directories ignored', !str_contains($text, 'config.txt') && !str_contains($text, 'folder.jar') && !str_contains($text, 'readme.txt'));
    t('header names the source server on one line', str_contains($text, '"server":"Old Survival"') && str_contains($text, '"version":"26.3"'));

    $parsed = $s->parseModList($text);
    t('round trip: the export parses without errors', $parsed['errors'] === [], json_encode($parsed['errors']));
    t('round trip: every project comes back', array_map(fn ($e) => $e['type']->value.':'.$e['ref'], $parsed['entries']) === ['mod:fabric-api', 'mod:lithium', 'datapack:terralith']);

    $s->buildModList(server());
    t('second export reads no files again (hashes cached)', $s->reads === 3, "reads {$s->reads}");

    [$s] = $setup();
    $s->modrinthDown = true;
    $text = $s->buildModList(server());
    t('Modrinth down for lookups: saved slug, ID for hand-uploaded ones', str_contains($text, "\nlithium-old") && str_contains($text, "\nP7dR8mSH"));

    [$s] = $setup();
    $s->hashLookupDown = true;
    $text = $s->buildModList(server());
    t('hash lookup down: hand-uploaded files become "could not be checked", managed ones stay', str_contains($text, "\nlithium ") && str_contains($text, '#   fabric-api-0.162.jar') && str_contains($text, '#   custom.jar'));
}

echo "import\n";
{
    $setup = function (array $tags = ['modrinth_mods', 'fabric'], string $page = FakePage::class) {
        [$s, $p] = bootList($tags, $page);
        $s->addProject('aaa', 'already', 'Already There', [ver('a1', 'aaa')]);
        $s->addProject('bbb', 'byhand', 'By Hand', [ver('b2', 'bbb'), ver('b1', 'bbb', 'release', [], 'byhand-1.jar')]);
        $s->addProject('ccc', 'newmod', 'New Mod', [ver('c1', 'ccc', 'release', [dep('ddd')])]);
        $s->addProject('ddd', 'libd', 'Lib D', [ver('d1', 'ddd')]);
        $s->addProject('eee', 'oldmod', 'Old Mod', []);
        $s->addProject('fff', 'needsbad', 'Needs Bad', [ver('f1', 'fff', 'release', [dep('zzz')])]);
        $s->addProject('zzz', 'zlib', 'Z Lib', []);
        $s->addProject('ggg', 'twice', 'Twice', [ver('g1', 'ggg')]);
        $s->addProject('ppp', 'someplugin', 'Some Plugin', [ver('p1', 'ppp')]);
        $s->addProject('ttt', 'terralith', 'Terralith', [ver('t1', 'ttt', 'release', [], 'terralith.zip')]);
        $s->authors = ['ccc' => 'newauthor'];
        $s->installedByType['mod']['aaa'] = installedEntry('aaa', 'a1', 'aaa-a1.jar', 'Already There');
        $s->filesByFolder['mods'] = ['aaa-a1.jar' => 'A', 'byhand-1.jar' => 'B'];

        return [$s, $p];
    };

    $list = "[mods]\nalready\nbyhand\nnewmod\nlibd   # comes in as newmod's dependency first\nmissing-mod\noldmod\nneedsbad\ntwice\nggg    # same project by ID\n\n[plugins]\nsomeplugin\n\n[datapacks]\nterralith\nbad line here\n";

    [$s, $page] = $setup();
    $report = $page->call('importModList', $list);

    t('installs what is missing (not the dependency entry twice)', $report['installed'] === ['New Mod', 'Twice'], json_encode($report['installed']));
    t('dependency listed separately', $report['dependencies'] === ['Lib D']);
    t('already installed and hand-uploaded ones are skipped', $report['skipped'] === ['Already There', 'By Hand'], json_encode($report['skipped']));
    t('files landed in mods/', isset($s->filesByFolder['mods']['ccc-c1.jar'], $s->filesByFolder['mods']['ddd-d1.jar'], $s->filesByFolder['mods']['ggg-g1.jar']) && !isset($s->filesByFolder['mods']['fff-f1.jar']));
    t('byhand not downloaded a second time', !isset($s->filesByFolder['mods']['bbb-b2.jar']));
    t('imported project keeps its author', ($s->installedByType['mod']['ccc']['author'] ?? null) === 'newauthor');
    t('not on Modrinth', str_contains((string) failedFor($report, 'missing-mod'), 'reasons.not_found'));
    t('no version for this server, says which', str_contains((string) failedFor($report, 'Old Mod'), 'reasons.unsupported') && str_contains((string) failedFor($report, 'Old Mod'), '26.3') && str_contains((string) failedFor($report, 'Old Mod'), 'Fabric'));
    t('dependency problem names the dependency', str_contains((string) failedFor($report, 'Needs Bad'), 'reasons.dependency') && str_contains((string) failedFor($report, 'Needs Bad'), 'Z Lib'));
    t('plugins not enabled here', str_contains((string) failedFor($report, 'Some Plugin'), 'reasons.type_disabled') && str_contains((string) failedFor($report, 'Some Plugin'), 'types.plugin'));
    t('datapacks not enabled here', str_contains((string) failedFor($report, 'Terralith'), 'reasons.type_disabled'));
    t('every problem is listed exactly once', count($report['failed']) === 5, json_encode($report['failed']));
    t('bad line reported with its number', count($report['invalid']) === 1 && str_contains($report['invalid'][0], 'invalid.invalid') && str_contains($report['invalid'][0], '"line":17'), json_encode($report['invalid']));
    t('nothing left over', $report['remaining'] === 0);

    // importing the same list again changes nothing and says so
    $before = $s->downloadOrder;
    $again = $page->call('importModList', $list);
    t('second run installs nothing', $s->downloadOrder === $before && $again['installed'] === []);
    t('second run skips everything installed', $again['skipped'] === ['Already There', 'By Hand', 'New Mod', 'Lib D', 'Twice'], json_encode($again['skipped']));

    // datapacks go to the world folder when enabled
    [$s, $page] = $setup(['modrinth_mods', 'fabric', 'modrinth_datapacks']);
    $report = $page->call('importModList', "[datapacks]\nterralith\n");
    t('datapack imported into world/datapacks', $report['installed'] === ['Terralith'] && isset($s->filesByFolder['world/datapacks']['terralith.zip']) && isset($s->installedByType['datapack']['ttt']));

    // mods enabled but no loader tag
    [$s, $page] = $setup(['modrinth_mods']);
    $report = $page->call('importModList', "[mods]\nnewmod\n");
    t('no loader tag is reported, nothing installed', str_contains((string) failedFor($report, 'New Mod'), 'reasons.no_loader') && $s->downloadOrder === []);

    // Modrinth unreachable
    [$s, $page] = $setup();
    $s->modrinthDown = true;
    $page->call('runImport', $list);
    $n = lastNotification();
    t('Modrinth down: import stops with an error, nothing installed', ($n['danger'] ?? null) === true && str_contains($n['title'], 'import.failed') && $s->downloadOrder === [] && $page->importReport === null);
    t('the text is kept for the next try', $page->lastImportText === $list);

    // successful run through the action: report stored, notification
    [$s, $page] = $setup();
    $page->call('runImport', "[mods]\nnewmod\ntwice\n");
    $n = lastNotification();
    t('clean import -> success notification and stored report', ($n['success'] ?? null) === true && str_contains($n['title'], 'import.done') && $page->importReport['installed'] === ['New Mod', 'Twice']);
    $page->call('runImport', $list);
    t('import with problems -> warning', (lastNotification()['warning'] ?? null) === true);

    // time budget
    [$s, $page] = $setup(['modrinth_mods', 'fabric'], SlowFakePage::class);
    $s->downloadDelayUs = 80000;
    $report = $page->call('importModList', "[mods]\nnewmod\ntwice\noldmod\nalready\n");
    t('stops after the time budget, the rest is counted as remaining', $report['installed'] === ['New Mod'] && $report['remaining'] === 2 && $report['skipped'] === ['Already There'], json_encode($report));
    $s->downloadDelayUs = 0;
    $page2 = new FakePage;
    $report = $page2->call('importModList', "[mods]\nnewmod\ntwice\noldmod\nalready\n");
    t('running it again finishes the rest', $report['installed'] === ['Twice'] && in_array('New Mod', $report['skipped'], true) && $report['remaining'] === 0 && failedFor($report, 'Old Mod') !== null);
}

echo "page: import/export actions and report\n";
{
    [$s, $page] = bootList(['modrinth_mods', 'fabric']);
    $s->addProject('gvQqBUqZ', 'lithium', 'Lithium', [ver('l1', 'gvQqBUqZ')]);
    $s->installedByType['mod']['gvQqBUqZ'] = installedEntry('gvQqBUqZ', 'l1', 'lithium-1.jar', 'Lithium');

    $group = $page->call('getHeaderActions')[1];
    t('import/export dropdown has both actions', array_map(fn ($a) => $a->calls['name'][0], $group->actions) === ['export_list', 'import_list']);

    [$export, $import] = $group->actions;
    $textarea = $export->calls['schema'][0][0];
    $exportText = ($textarea->calls['default'][0])();
    t('export shows the list in a read-only text area', str_contains($exportText, "[mods]\nlithium") && isset($textarea->calls['readOnly']));
    t('export has no submit (copy instead of download)', ($export->calls['modalSubmitAction'][0] ?? null) === false);
    $copy = ($export->calls['extraModalFooterActions'][0])()[0];
    $handler = $copy->calls['alpineClickHandler'][0];
    t('copy button writes the list to the clipboard in the browser', str_contains($handler, 'navigator.clipboard.writeText(') && str_contains($handler, \Illuminate\Support\Js::from($exportText)) && str_contains($handler, '$tooltip('));
    t('copy falls back to a hint without clipboard access', substr_count($handler, 'export.copy_failed') === 2);

    $importField = $import->calls['schema'][0][0];
    t('import field required', isset($importField->calls['required']));
    ($import->calls['action'][0])(['list' => "[mods]\nlithium\n"]);
    t('import action runs the import and stores the report', $page->importReport['skipped'] === ['Lithium'] && $page->lastImportText === "[mods]\nlithium\n");
    t('import field is prefilled with the last text', ($importField->calls['default'][0])() === "[mods]\nlithium\n");

    $section = $page->call('getImportReportSection');
    t('report section visible with a report', ($section->calls['visible'][0])() === true);
    $entries = [];
    foreach ($section->calls['schema'][0] as $entry) {
        $entries[$entry->calls['name'][0]] = $entry;
    }
    t('only non-empty lists are shown', ($entries['import_report_skipped']->calls['visible'][0])() === true && ($entries['import_report_failed']->calls['visible'][0])() === false);
    t('lists are bulleted', isset($entries['import_report_skipped']->calls['bulleted']));
    t('lists show the report items', ($entries['import_report_skipped']->calls['state'][0])() === ['Lithium']);
    ($section->calls['headerActions'][0][0]->calls['action'][0])();
    t('dismiss hides the report', $page->importReport === null && ($section->calls['visible'][0])() === false);
}

// ---------------------------------------------------------------- review fixes

echo "access and permissions\n";
{

    [$s, $page] = boot(FakePage::class, ['modrinth_mods', 'fabric']);
    t('page open for a user with file access', ModrinthPage::canAccess() === true);
    server()->inConflict = true;
    t('page closed while the server is suspended/installing/transferring', ModrinthPage::canAccess() === false);
    server()->inConflict = false;
    $GLOBALS['user_permissions'] = [P::FileCreate];
    t('page closed without file.read', ModrinthPage::canAccess() === false);

    $GLOBALS['user_permissions'] = [P::FileRead];
    $actions = $page->call('getHeaderActions');
    t('read-only user: no update all', ($actions[2]->calls['authorize'][0])() === false);
    t('read-only user: no export (it reads file contents)', ($actions[1]->actions[0]->calls['authorize'][0])() === false);
    t('read-only user: no import', ($actions[1]->actions[1]->calls['authorize'][0])() === false);
    $GLOBALS['user_permissions'] = [P::FileRead, P::FileReadContent, P::FileCreate];
    t('export and import allowed with read-content and create', ($actions[1]->actions[0]->calls['authorize'][0])() === true && ($actions[1]->actions[1]->calls['authorize'][0])() === true);
    t('update all needs delete as well (old files are removed)', ($actions[2]->calls['authorize'][0])() === false);

    $table = $page->table(new \Filament\Tables\Table);
    $byName = [];
    foreach ($table->calls['recordActions'][0] as $action) {
        $byName[$action->calls['name'][0]] = $action;
    }
    t('install allowed with create', ($byName['install_latest']->calls['authorize'][0])() === true);
    t('update and uninstall need delete', ($byName['update']->calls['authorize'][0])() === false && ($byName['uninstall']->calls['authorize'][0])() === false);

    // the work itself is guarded too, not only the buttons
    $GLOBALS['user_permissions'] = [P::FileRead];
    $s->projects = ['mod' => proj('mod', 'The Mod')];
    $s->versions = ['mod' => [ver('m1', 'mod')]];
    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod'], $s->versions['mod'][0]);
    t('install without create permission downloads nothing', $s->downloadOrder === [] && (lastNotification()['danger'] ?? null) === true);
    t('export text is empty without read-content permission', $page->call('getExportText') === '');
    $s->seedInstalled('x', 'X', '1');
    $s->versions['x'] = [ver('2', 'x'), ver('1', 'x')];
    $page->call('updateAll');
    t('update all without permission changes nothing', $s->installed['x']['version_id'] === '1');
    $page->call('runImport', "[mods]\nx\n");
    t('import without permission does nothing', $page->importReport === null);
}

echo "header\n";
{
    [$s, $page] = boot(FakePage::class, ['modrinth_mods', 'fabric', 'modrinth_datapacks']);
    $actions = $page->call('getHeaderActions');
    t('type entries render as dropdown items (not icon buttons)', isset($actions[0]->actions[0]->calls['grouped'], $actions[0]->actions[1]->calls['grouped']));
    t('import/export entries render as dropdown items', isset($actions[1]->actions[0]->calls['grouped'], $actions[1]->actions[1]->calls['grouped']));

    $s->loaders = [['name' => 'fabric', 'supported_project_types' => ['mod']]];
    $s->files = ['a.jar' => 'a.jar', 'b.jar' => 'b.jar', 'notes.txt' => 'notes.txt'];
    $sub = $page->call('getSubheading');
    t('subheading: version, loader and count on one line', str_contains($sub, 'subheading_version') && str_contains($sub, ' · Fabric · ') && str_contains($sub, '"count":2'), $sub);
    $page->projectType = 'datapack';
    $sub = $page->call('getSubheading');
    t('datapacks: subheading shows the world instead of a loader', str_contains($sub, 'subheading_world') && str_contains($sub, '"world":"world"'), $sub);
}

echo "table rows and version picker\n";
{
    [$s, $page] = boot(FakePage::class, ['modrinth_mods', 'fabric']);
    $s->loaders = [['name' => 'fabric', 'supported_project_types' => ['mod']]];
    $pageService = new class extends FakeService
    {
        public function getProjects(Server $server, ModrinthProjectType $type, int $page = 1, ?string $search = null, bool $hideClientOnly = false): array
        {
            return ['hits' => [['project_id' => 'zzz', 'title' => 'Z'], ['project_id' => 'aaa', 'title' => 'A']], 'total_hits' => 2];
        }

        public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
        {
            return array_map(fn ($m) => ['project_id' => $m['project_id'], 'title' => $m['project_title']], $installedMods);
        }
    };
    \Illuminate\Support\Facades\Facade::$instance = $pageService;
    $table = $page->table(new \Filament\Tables\Table);
    $result = ($table->calls['records'][0])(null, 1, null);
    t('search rows are keyed by project id, not position', array_column($result->items, '__key') === ['zzz', 'aaa']);
    $pageService->seedInstalled('lith', 'Lithium', '1');
    $page->activeTab = 'installed';
    $page->call('forgetInstalledState', false);
    $result = ($table->calls['records'][0])(null, 1, null);
    t('installed rows are keyed by project id', array_column($result->items, '__key') === ['lith']);

    $pageService->versions['lith'] = [ver('v2abc', 'lith'), ver('1', 'lith')];
    $versionsAction = null;
    foreach ($table->calls['recordActions'][0] as $action) {
        if ($action->calls['name'][0] === 'versions') {
            $versionsAction = $action;
        }
    }
    $sections = ($versionsAction->calls['schema'][0])(['project_id' => 'lith', 'title' => 'Lithium', 'slug' => 'lith']);
    $names = array_map(fn ($section) => $section->calls['headerActions'][0][0]->calls['name'][0], $sections);
    t('version buttons are named after the version, not the list position', $names === ['install_version_v2abc', 'installed_1'], json_encode($names));
}

echo "hand-uploaded copies\n";
{
    // the exact version is there by hand: take it over, no download
    [$s, $page] = boot();
    $s->versions = ['sod' => [ver('s2', 'sod'), ver('s1', 'sod')]];
    $s->files = ['sod-s2.jar' => 'sod-s2.jar'];
    $page->call('installVersion', ['project_id' => 'sod', 'slug' => 'sodium', 'title' => 'Sodium'], $s->versions['sod'][0]);
    t('same version uploaded by hand is adopted, not downloaded again', $s->downloadOrder === [] && ($s->installed['sod']['filename'] ?? null) === 'sod-s2.jar' && ($s->installed['sod']['version_id'] ?? null) === 's2');
    t('user is told it was already there', str_contains(lastNotification()['title'] ?? '', 'notifications.adopted'));

    // an older version is there by hand: replace it, no second copy
    [$s, $page] = boot();
    $s->versions = ['sod' => [ver('s2', 'sod'), ver('s1', 'sod')]];
    $s->files = ['sod-s1.jar' => 'sod-s1.jar'];
    $page->call('installVersion', ['project_id' => 'sod', 'slug' => 'sodium', 'title' => 'Sodium'], $s->versions['sod'][0]);
    t('older hand-uploaded copy is replaced instead of duplicated', isset($s->files['sod-s2.jar']) && !isset($s->files['sod-s1.jar']) && $s->installed['sod']['version_id'] === 's2');

    // a tracked project's file is not mistaken for a hand-uploaded copy of another project
    [$s, $page] = boot();
    $s->seedInstalled('other', 'Other', '1');
    $s->versions = ['sod' => [ver('s2', 'sod', 'release', [], 'other-1.jar')]];
    $page->call('installVersion', ['project_id' => 'sod', 'slug' => 'sodium', 'title' => 'Sodium'], $s->versions['sod'][0]);
    t('tracked files of other projects are left alone', isset($s->installed['other']) && isset($s->installed['sod']));

    // import adopts too
    [$s, $page] = bootList(['modrinth_mods', 'fabric']);
    $s->addProject('sod', 'sodium', 'Sodium', [ver('s1', 'sod', 'release', [], 'sodium-1.jar')]);
    $s->filesByFolder['mods'] = ['sodium-1.jar' => 'S'];
    $report = $page->call('importModList', "[mods]\nsodium\n");
    t('import adopts a hand-uploaded copy (skipped, now tracked)', $report['skipped'] === ['Sodium'] && ($s->installedByType['mod']['sod']['filename'] ?? null) === 'sodium-1.jar' && $s->downloadOrder === []);
}

echo "partial dependency installs\n";
{
    [$s, $page] = boot();
    $s->projects = ['liba' => proj('liba', 'Lib A'), 'libb' => proj('libb', 'Lib B')];
    $s->versions = ['mod' => [ver('m1', 'mod', 'release', [dep('liba'), dep('libb')])], 'liba' => [ver('a1', 'liba')], 'libb' => [ver('b1', 'libb')]];
    $s->failDownloads = ['libb-b1.jar'];
    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod'], $s->versions['mod'][0]);
    $body = lastNotification()['body'] ?? '';
    t('failure names the dependency and says the project was not installed', str_contains($body, 'dependency_failed_body') && str_contains($body, 'Lib B'));
    t('and says which dependencies stay installed', str_contains($body, 'dependencies_kept') && str_contains($body, 'Lib A'), $body);
    t('the kept dependency really is installed', isset($s->installed['liba']) && !isset($s->installed['mod']));
}

echo "downloads\n";
{
    $dl = new class extends ModrinthService
    {
        public array $files = [];          // name => content
        public ?string $pullContent = null; // what the daemon writes
        public ?Exception $pullError = null;
        public array $log = [];

        public function listFolder(Server $server, string $folder): array
        {
            return array_map(fn ($name, $content) => ['name' => (string) $name, 'size' => strlen($content), 'directory' => false], array_keys($this->files), $this->files);
        }

        protected function pullFile(Server $server, string $url, string $folder, string $filename): void
        {
            $this->log[] = "pull $filename";
            if ($this->pullContent !== null) {
                $this->files[$filename] = $this->pullContent;
            }
            if ($this->pullError) {
                throw $this->pullError;
            }
        }

        protected function renameFile(Server $server, string $folder, string $from, string $to): void
        {
            $this->log[] = "rename $from $to";
            $this->files[$to] = $this->files[$from];
            unset($this->files[$from]);
        }

        public function deleteFile(Server $server, string $folder, string $filename): void
        {
            unset($this->files[$filename]);
        }
    };

    $dl->pullContent = 'NEWJAR';
    $existed = $dl->downloadFile(server(), 'https://cdn/x.jar', 'mods', 'x.jar', 6);
    t('complete download lands under its real name via a temporary file', $dl->files === ['x.jar' => 'NEWJAR'] && $dl->log === ['pull .x.jar.part', 'rename .x.jar.part x.jar'] && $existed === false, json_encode($dl->log));

    $dl->files = ['x.jar' => 'OLDJAR'];
    $dl->log = [];
    $dl->pullContent = 'NEW';
    try {
        $dl->downloadFile(server(), 'https://cdn/x.jar', 'mods', 'x.jar', 6);
        t('truncated download is rejected', false);
    } catch (Exception $e) {
        t('truncated download is rejected and the old file stays intact', $dl->files === ['x.jar' => 'OLDJAR'], json_encode($dl->files));
    }

    $dl->files = ['x.jar' => 'OLDJAR'];
    $dl->pullContent = 'NEWJAR';
    $dl->pullError = new Exception('timeout');
    $existed = $dl->downloadFile(server(), 'https://cdn/x.jar', 'mods', 'x.jar', 6);
    t('our request timing out after the full file arrived still counts', $dl->files === ['x.jar' => 'NEWJAR'] && $existed === true);

    $dl->files = [];
    $dl->pullContent = 'NE';
    try {
        $dl->downloadFile(server(), 'https://cdn/x.jar', 'mods', 'x.jar', 6);
        t('partial download after a timeout fails', false);
    } catch (Exception $e) {
        t('partial download after a timeout fails with that error and leaves nothing behind', $e->getMessage() === 'timeout' && $dl->files === []);
    }

    $dl->files = [];
    $dl->pullError = null;
    $dl->pullContent = 'ANY';
    $dl->downloadFile(server(), 'https://cdn/x.jar', 'mods', 'x.jar');
    t('without a known size the daemon\'s success decides', $dl->files === ['x.jar' => 'ANY']);
    $dl->files = [];
    $dl->pullError = new Exception('timeout');
    try {
        $dl->downloadFile(server(), 'https://cdn/x.jar', 'mods', 'x.jar');
        t('without a known size a failed request fails', false);
    } catch (Exception $e) {
        t('without a known size a failed request fails', $dl->files === []);
    }

    // the page passes Modrinth's file size along
    [$s, $page] = boot();
    $v = ver('m1', 'mod');
    $v['files'][1]['size'] = 1234;
    $page->call('installVersion', ['project_id' => 'mod', 'slug' => 'mod', 'title' => 'The Mod'], $v);
    t('install passes the expected size to the download', ($s->downloadSizes['mod-m1.jar'] ?? null) === 1234);

    // deleting a file that is already gone
    $GLOBALS['app_bindings'][\App\Repositories\Daemon\DaemonFileRepository::class] = new class
    {
        public int $status = 404;

        public function setServer($server): static
        {
            return $this;
        }

        public function deleteFiles($root, $files): object
        {
            $status = $this->status;

            return new class($status)
            {
                public function __construct(private int $status) {}

                public function throw(): static
                {
                    $response = new class($this->status)
                    {
                        public function __construct(private int $code) {}

                        public function status(): int
                        {
                            return $this->code;
                        }
                    };
                    throw new \Illuminate\Http\Client\RequestException($response);
                }
            };
        }
    };
    $real = new ModrinthService;
    try {
        $real->deleteFile(server(), 'mods', 'gone.jar');
        t('deleting a file that is already gone counts as deleted', true);
    } catch (Exception $e) {
        t('deleting a file that is already gone counts as deleted', false, $e->getMessage());
    }
    $GLOBALS['app_bindings'][\App\Repositories\Daemon\DaemonFileRepository::class]->status = 500;
    try {
        $real->deleteFile(server(), 'mods', 'x.jar');
        t('other delete errors still fail', false);
    } catch (Exception $e) {
        t('other delete errors still fail', true);
    }
}

echo "metadata file\n";
{
    $meta = new class extends ModrinthService
    {
        public ?string $content = null;      // null = file not found
        public ?Exception $readError = null;
        public ?array $written = null;

        protected function readMetadataFile(Server $server, ModrinthProjectType $modrinthProjectType): string
        {
            if ($this->readError) {
                throw $this->readError;
            }
            if ($this->content === null) {
                throw new \Illuminate\Contracts\Filesystem\FileNotFoundException;
            }

            return $this->content;
        }

        protected function writeMetadataEntries(Server $server, ModrinthProjectType $modrinthProjectType, array $entries): bool
        {
            $this->written = $entries;

            return true;
        }
    };

    $meta->content = null;
    t('first install creates the list', $meta->saveModMetadata(server(), ModrinthProjectType::Mod, 'a', 'a', 'A', 'v1', '1', 'a.jar') && count($meta->written) === 1);
    t('lock lives longer than two daemon round trips', \Illuminate\Support\Facades\Cache::$lastTtl >= 60);

    $meta->written = null;
    $meta->readError = new Exception('daemon timeout');
    t('unreadable list: save refuses instead of overwriting', $meta->saveModMetadata(server(), ModrinthProjectType::Mod, 'b', 'b', 'B', 'v1', '1', 'b.jar') === false && $meta->written === null);
    t('unreadable list: remove refuses too', $meta->removeModMetadata(server(), ModrinthProjectType::Mod, 'a') === false && $meta->written === null);

    $meta->readError = null;
    $meta->content = '{"installed_mods": [ {"project_id": "a"}, ]}';
    t('invalid JSON: save refuses instead of overwriting', $meta->saveModMetadata(server(), ModrinthProjectType::Mod, 'b', 'b', 'B', 'v1', '1', 'b.jar') === false && $meta->written === null);

    $meta->content = json_encode(['installed_mods' => [
        ['project_id' => 'a', 'project_slug' => 'a', 'project_title' => 'A', 'version_id' => 'v1', 'version_number' => '1', 'filename' => 'a.jar', 'installed_at' => '2020-01-01'],
        ['some' => 'entry from a newer version'],
        'garbage',
    ]]);
    $meta->saveModMetadata(server(), ModrinthProjectType::Mod, 'a', 'a', 'A', 'v2', '2', 'a2.jar');
    t('update replaces in place, keeps install date and unknown entries', $meta->written[0]['version_id'] === 'v2' && $meta->written[0]['installed_at'] === '2020-01-01' && $meta->written[1] === ['some' => 'entry from a newer version'] && $meta->written[2] === 'garbage');
    $meta->removeModMetadata(server(), ModrinthProjectType::Mod, 'a');
    t('remove keeps everything else', $meta->written === [['some' => 'entry from a newer version'], 'garbage']);
}

echo "Minecraft version\n";
{
    $mc = new ModrinthService;
    $srv = new Server(['modrinth_mods']);
    t('a server that never changed the variable uses the egg default', $mc->getMinecraftVersion($srv) === '26.3');
    $srv->versionValue = '1.21.1';
    t('the server\'s own value wins', $mc->getMinecraftVersion($srv) === '1.21.1');
}

echo "Modrinth outages and loaders\n";
{
    $svc = new class extends ModrinthService
    {
        public function getMinecraftVersion(Server $server): ?string
        {
            return '26.3';
        }

        protected function request(int $timeout = 5): \Illuminate\Http\Client\PendingRequest
        {
            return new \Illuminate\Http\Client\PendingRequest;
        }
    };
    $GLOBALS['stub_cache'] = new StubCache;
    \Illuminate\Http\Client\PendingRequest::$fail = new Exception('Modrinth down');
    t('loader list: failure returns nothing', $svc->getLoaders() === []);
    \Illuminate\Http\Client\PendingRequest::$fail = null;
    \Illuminate\Http\Client\PendingRequest::$response = [['name' => 'paper', 'supported_project_types' => ['plugin']], ['name' => 'bukkit', 'supported_project_types' => ['plugin']], ['name' => 'fabric', 'supported_project_types' => ['mod']]];
    t('loader list: the failure was not cached, the next call works', count($svc->getLoaders()) === 3);

    $paper = new Server(['modrinth_plugins', 'bukkit', 'paper', 'fabric']);
    $loader = $svc->getLoaderFromServer($paper, ModrinthProjectType::Plugin);
    t('all matching loader tags are used, in the egg\'s order', $loader['names'] === ['bukkit', 'paper'] && $loader['display_name'] === 'Bukkit, Paper', json_encode($loader));

    \Illuminate\Http\Client\PendingRequest::$requests = [];
    \Illuminate\Http\Client\PendingRequest::$response = ['hits' => [], 'total_hits' => 0];
    $svc->getProjects($paper, ModrinthProjectType::Plugin, 1, null);
    $facets = json_decode(\Illuminate\Http\Client\PendingRequest::$requests[0]['query']['facets'], true);
    t('search ORs the loaders', $facets[0] === ['categories:bukkit', 'categories:paper'], json_encode($facets));
    \Illuminate\Http\Client\PendingRequest::$requests = [];
    \Illuminate\Http\Client\PendingRequest::$response = [];
    $svc->getProjectVersions('proj', $paper, ModrinthProjectType::Plugin);
    t('version lookups ask for all loaders', \Illuminate\Http\Client\PendingRequest::$requests[0]['query']['loaders'] === '["bukkit","paper"]');
    \Illuminate\Http\Client\PendingRequest::$response = ['hits' => [], 'total_hits' => 0];

    // the world folder is not guessed when server.properties can't be read
    $props = new class extends ModrinthService
    {
        protected function readServerProperties(Server $server): string
        {
            throw new Exception('daemon timeout');
        }
    };
    try {
        $props->getProjectFolder(server(), ModrinthProjectType::Datapack);
        t('unreadable server.properties stops a datapack install instead of guessing "world"', false);
    } catch (Exception $e) {
        t('unreadable server.properties stops a datapack install instead of guessing "world"', $e->getMessage() === 'daemon timeout');
    }

    // datapacks listing mod-side dependencies
    [$s] = boot(FakePage::class, ['modrinth_datapacks']);
    $s->projects = ['fapi' => proj('fapi', 'Fabric API'), 'dplib' => proj('dplib', 'DP Lib')];
    $s->versions = ['fapi' => [], 'dplib' => [ver('d1', 'dplib', 'release', [], 'dplib.zip')]];
    $plan = $s->planDependencies(server(), ModrinthProjectType::Datapack, ver('t1', 'terra', 'release', [dep('fapi'), dep('dplib')], 'terra.zip'));
    t('datapack: a mod-only dependency is skipped, datapack dependencies still come along', array_map(fn ($p) => $p['record']['project_id'], $plan) === ['dplib']);
}

// ---------------------------------------------------------------- summary

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);

<?php

namespace MaxGojko\ModrinthIntegration\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use MaxGojko\ModrinthIntegration\Enums\ModrinthProjectType;
use MaxGojko\ModrinthIntegration\Exceptions\DependencyInstallException;
use MaxGojko\ModrinthIntegration\Facades\Modrinth;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * One page for everything from Modrinth; a dropdown switches between the project types the
 * server's egg turns on with tags (see the README).
 */
class ModrinthPage extends Page implements HasTable
{
    // canAccess() below replaces the trait's, so its conflict check (suspended, installing,
    // transferring, ...) is kept under another name and called from there.
    use BlockAccessInConflict {
        canAccess as canAccessWhenNotInConflict;
    }
    use HasTabs;
    use InteractsWithTable;

    // "Update all" stops starting new updates after this many seconds so the request doesn't run
    // into the web server's timeout; what is left stays pending and the button simply continues.
    protected const UPDATE_ALL_TIME_BUDGET = 50;

    // Rows listed in the "Update all" confirmation before it says "and N more".
    protected const UPDATE_ALL_LIST_LIMIT = 15;

    // Name of the checkbox that brings client-only mods back into the search results.
    protected const SHOW_CLIENT_ONLY_FILTER = 'show_client_only';

    /** @var array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>|null */
    protected ?array $installedModsMetadata = null;

    /** @var array<string, array<int, mixed>> Cache for version data by project_id */
    protected array $versionsCache = [];

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth';

    protected static ?int $navigationSort = 30;

    /**
     * The project type being shown (a ModrinthProjectType value). Set from the "type" query
     * parameter on mount and locked, so the browser can't switch it to a type the egg doesn't
     * allow; getProjectType() checks it against the egg's tags anyway.
     */
    #[Locked]
    public ?string $projectType = null;

    /**
     * Result of the last import, shown above the list until dismissed.
     *
     * @var array{installed: array<int, string>, dependencies: array<int, string>, skipped: array<int, string>, failed: array<int, string>, invalid: array<int, string>, remaining: int}|null
     */
    #[Locked]
    public ?array $importReport = null;

    // The last imported text, so running the import again (to finish it) needs no pasting.
    public string $lastImportText = '';

    protected ?string $exportText = null;

    /** @var array<int, string> dependencies installed by the last installWithDependencies() call, also when it failed later */
    protected array $installedDependencies = [];

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return static::canAccessWhenNotInConflict()
            && user()?->can(SubuserPermission::FileRead, $server)
            && !empty(ModrinthProjectType::fromServer($server));
    }

    /** Whether the current user (owner, admin or subuser) has all of these file permissions on this server. */
    protected function can(SubuserPermission ...$permissions): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        foreach ($permissions as $permission) {
            if (!user()?->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    /** Installing writes files; updating also deletes the version it replaces. */
    protected function canInstall(bool $replaces = false): bool
    {
        return $replaces
            ? $this->can(SubuserPermission::FileCreate, SubuserPermission::FileDelete)
            : $this->can(SubuserPermission::FileCreate);
    }

    public static function getNavigationLabel(): string
    {
        return trans('modrinth-integration::strings.plugin_name');
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $requested = request()->query('type');

        $this->projectType = $this->resolveProjectType(is_string($requested) ? $requested : null)->value;

        $this->loadDefaultActiveTab();
    }

    /** @return ModrinthProjectType[] */
    protected function getAvailableProjectTypes(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return ModrinthProjectType::fromServer($server);
    }

    /** The requested type if the egg allows it, otherwise the first one it does. */
    protected function resolveProjectType(?string $requested): ModrinthProjectType
    {
        $available = $this->getAvailableProjectTypes();
        $type = $requested !== null ? ModrinthProjectType::tryFrom($requested) : null;

        if ($type !== null && in_array($type, $available, true)) {
            return $type;
        }

        // canAccess() keeps the page closed without any type, the fallback only avoids a crash.
        return $available[0] ?? ModrinthProjectType::Mod;
    }

    protected function getProjectType(): ModrinthProjectType
    {
        return $this->resolveProjectType($this->projectType);
    }

    /**
     * The "show client-side mods" checkbox only makes sense when browsing mods. The installed tab
     * always lists everything, a client-only mod sitting in the folder should never be hidden.
     */
    protected function isClientOnlyFilterAvailable(): bool
    {
        return $this->getProjectType() === ModrinthProjectType::Mod && $this->activeTab !== 'installed';
    }

    /** @param  array<string, mixed>|null  $filters */
    protected function shouldHideClientOnly(?array $filters): bool
    {
        return $this->getProjectType() === ModrinthProjectType::Mod
            && !($filters[static::SHOW_CLIENT_ONLY_FILTER]['isActive'] ?? false);
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(trans('modrinth-integration::strings.page.view_all')),
            'installed' => Tab::make(trans('modrinth-integration::strings.page.view_installed')),
        ];
    }

    /** @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}> */
    protected function getInstalledModsMetadata(): array
    {
        if ($this->installedModsMetadata === null) {
            /** @var Server $server */
            $server = Filament::getTenant();

            $this->installedModsMetadata = Modrinth::getInstalledModsMetadata($server, $this->getProjectType());
        }

        return $this->installedModsMetadata;
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    protected function getInstalledMod(string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata();

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /** @return array<int, mixed> */
    protected function getCachedVersions(string $projectId): array
    {
        if (!isset($this->versionsCache[$projectId])) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->versionsCache[$projectId] = Modrinth::getProjectVersions($projectId, $server, $this->getProjectType());
        }

        return $this->versionsCache[$projectId];
    }

    /**
     * The version an installed mod would be updated to, if there is a newer one.
     *
     * @param  array{project_id: string, version_id: string}  $installedMod
     * @return array<string, mixed>|null
     */
    protected function getAvailableUpdate(array $installedMod): ?array
    {
        $latest = Modrinth::getLatestVersion($this->getCachedVersions($installedMod['project_id']), $installedMod['version_id']);

        return $latest !== null && $latest['id'] !== $installedMod['version_id'] ? $latest : null;
    }

    /**
     * Every installed mod that has a newer compatible version, sorted by name.
     *
     * @return array<int, array{mod: array<string, mixed>, latest: array<string, mixed>}>
     */
    protected function getPendingUpdates(): array
    {
        $installedMods = $this->getInstalledModsMetadata();

        if (empty($installedMods)) {
            return [];
        }

        $this->primeVersionsCache(array_column($installedMods, 'project_id'));

        $pending = [];
        foreach ($installedMods as $installedMod) {
            $latest = $this->getAvailableUpdate($installedMod);

            if ($latest !== null) {
                $pending[] = ['mod' => $installedMod, 'latest' => $latest];
            }
        }

        usort($pending, fn (array $a, array $b) => strcasecmp($a['mod']['project_title'], $b['mod']['project_title']));

        return $pending;
    }

    /**
     * Fetch the versions of every project on the current page up front.
     *
     * Each installed row asks for its versions to decide between the "installed" and "update"
     * action, so without this the installed tab does one Modrinth request per row, one after
     * the other, and a full page can take longer than the request is allowed to run.
     *
     * @param  array<int|string, string>  $projectIds
     */
    protected function primeVersionsCache(array $projectIds): void
    {
        $projectIds = array_values(array_diff(array_unique(array_filter($projectIds)), array_keys($this->versionsCache)));

        if (count($projectIds) < 2) {
            return;
        }

        /** @var Server $server */
        $server = Filament::getTenant();

        $this->versionsCache += Modrinth::getProjectVersionsBulk($projectIds, $server, $this->getProjectType());
    }

    /**
     * Drop everything read from the server so the next render reflects what is actually on disk.
     */
    protected function forgetInstalledState(bool $refresh = true): void
    {
        $this->installedModsMetadata = null;
        $this->versionsCache = [];

        if ($refresh) {
            $this->js('$wire.$refresh()');
        }
    }

    /**
     * @throws Exception
     */
    protected function validateFilename(string $filename): string
    {
        if ($filename === '' || $filename === '.' || str_contains($filename, "\0") || str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            throw new Exception('Invalid filename: potential path traversal detected');
        }

        return basename($filename);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     *
     * @throws Exception
     */
    private function performInstallOrUpdate(
        Server $server,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null,
        ?ModrinthProjectType $modrinthProjectType = null
    ): void {
        $modrinthProjectType ??= $this->getProjectType();

        $safeNewFilename = $this->validateFilename($primaryFile['filename']);
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $folder = Modrinth::getProjectFolder($server, $modrinthProjectType);

        // True if a file of that name was already there: it is replaced now, so a rollback below
        // must not delete it (it may be the user's own copy of the same version).
        $replacedExisting = Modrinth::downloadFile(
            $server,
            $primaryFile['url'],
            $folder,
            $safeNewFilename,
            isset($primaryFile['size']) && is_numeric($primaryFile['size']) ? (int) $primaryFile['size'] : null
        );

        $saved = Modrinth::saveModMetadata(
            $server,
            $modrinthProjectType,
            $record['project_id'],
            $record['slug'],
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? null
        );

        if (!$saved) {
            if (!$replacedExisting && (!$oldFilename || $oldFilename !== $safeNewFilename)) {
                try {
                    Modrinth::deleteFile($server, $folder, $safeNewFilename);
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                Modrinth::deleteFile($server, $folder, $oldFilename);
            } catch (Exception $deleteException) {
                if (!$replacedExisting) {
                    try {
                        Modrinth::deleteFile($server, $folder, $safeNewFilename);
                    } catch (Exception $rollbackException) {
                        report($rollbackException);
                    }
                }

                if ($installedMod && !Modrinth::saveModMetadata(
                    $server,
                    $modrinthProjectType,
                    $record['project_id'],
                    $installedMod['project_slug'],
                    $installedMod['project_title'],
                    $installedMod['version_id'],
                    $installedMod['version_number'],
                    $oldFilename,
                    $installedMod['author'] ?? null
                )) {
                    report(new Exception('Failed to restore old mod metadata during rollback'));
                }

                throw $deleteException;
            }
        }
    }

    /**
     * Install or update a version together with the required dependencies it is missing.
     *
     * Every dependency is resolved before anything is downloaded, so one that can't be satisfied
     * stops the operation with the server untouched. Installing the mod without it would only
     * move the failure to the next start, where the loader refuses to boot.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile
     * @param  array<string, mixed>|null  $installedMod
     * @param  ModrinthProjectType|null  $modrinthProjectType  defaults to the type the page shows
     * @return array<int, string> titles of the dependencies that were installed
     *
     * @throws Exception
     */
    protected function installWithDependencies(
        Server $server,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null,
        ?ModrinthProjectType $modrinthProjectType = null
    ): array {
        $modrinthProjectType ??= $this->getProjectType();

        $this->installedDependencies = [];

        $plan = Modrinth::planDependencies($server, $modrinthProjectType, $versionData);

        foreach ($plan as $step) {
            try {
                $this->performInstallOrUpdate($server, $step['record'], $step['version'], $step['file'], null, $modrinthProjectType);
            } catch (Exception $exception) {
                throw new DependencyInstallException($step['record']['title'], $exception);
            }

            // Kept on the page as well: if a later step fails, these stay installed and the
            // error message has to say so.
            $this->installedDependencies[] = $step['record']['title'];
        }

        $this->performInstallOrUpdate($server, $record, $versionData, $primaryFile, $installedMod, $modrinthProjectType);

        return $this->installedDependencies;
    }

    /**
     * Install (or update, when $type is "update") one version and tell the user how it went.
     *
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>|null  $versionData
     * @param  array<string, mixed>|null  $installedMod
     */
    protected function installVersion(array $record, ?array $versionData, ?array $installedMod = null, string $type = 'install'): void
    {
        $this->installedDependencies = [];

        try {
            /** @var Server $server */
            $server = Filament::getTenant();

            if ($type === 'update' && !$installedMod) {
                throw new Exception('Mod not found in metadata');
            }

            if ($versionData === null) {
                throw new Exception('No compatible versions found');
            }

            $primaryFile = Modrinth::getInstallFile($versionData, $this->getProjectType());

            if (!$primaryFile) {
                throw new Exception('No downloadable file found');
            }

            // A copy uploaded by hand is taken over instead of installing a second one next to it,
            // which would make the loader refuse to start because of a duplicate mod.
            if ($installedMod === null && ($copy = $this->findHandUploadedCopy($server, $record['project_id'], $this->getProjectType())) !== null) {
                if (($copy['version']['id'] ?? null) === $versionData['id']) {
                    if (!$this->can(SubuserPermission::FileCreate)) {
                        throw new Exception('Missing file permissions');
                    }

                    if (!Modrinth::saveModMetadata($server, $this->getProjectType(), $record['project_id'], $record['slug'], $record['title'], $versionData['id'], $versionData['version_number'], $copy['filename'], $record['author'] ?? null)) {
                        throw new Exception('Failed to save mod metadata');
                    }

                    $this->forgetInstalledState();

                    Notification::make()
                        ->title(trans('modrinth-integration::strings.notifications.adopted'))
                        ->body(trans('modrinth-integration::strings.notifications.adopted_body', ['name' => $record['title'], 'file' => $copy['filename']]))
                        ->success()
                        ->send();

                    return;
                }

                $installedMod = [
                    'project_slug' => $record['slug'],
                    'project_title' => $record['title'],
                    'version_id' => $copy['version']['id'],
                    'version_number' => $copy['version']['version_number'] ?? '?',
                    'filename' => $copy['filename'],
                    'author' => $record['author'] ?? null,
                ];
            }

            if (!$this->canInstall($installedMod !== null)) {
                throw new Exception('Missing file permissions');
            }

            $dependencies = $this->installWithDependencies($server, $record, $versionData, $primaryFile, $installedMod);

            $this->forgetInstalledState();

            Notification::make()
                ->title(trans("modrinth-integration::strings.notifications.{$type}_success"))
                ->body($this->withDependencies(trans("modrinth-integration::strings.notifications.{$type}_success_body", [
                    'name' => $record['title'],
                    'version' => $versionData['version_number'],
                ]), $dependencies))
                ->success()
                ->send();
        } catch (Exception $exception) {
            report($exception);

            $this->forgetInstalledState();

            Notification::make()
                ->title(trans("modrinth-integration::strings.notifications.{$type}_failed"))
                ->body($this->withKeptDependencies($this->getFailureBody($exception, "modrinth-integration::strings.notifications.{$type}_failed_body")))
                ->danger()
                ->send();
        }
    }

    /**
     * Update everything that has a newer version. One failing mod doesn't stop the others.
     */
    protected function updateAll(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if (!$this->canInstall(replaces: true)) {
            return;
        }

        $pending = $this->getPendingUpdates();

        if (empty($pending)) {
            Notification::make()
                ->title(trans('modrinth-integration::strings.notifications.update_all_none'))
                ->body(trans('modrinth-integration::strings.notifications.update_all_none_body'))
                ->info()
                ->send();

            return;
        }

        $deadline = microtime(true) + static::UPDATE_ALL_TIME_BUDGET;

        $updated = 0;
        $remaining = 0;
        $failed = [];
        $dependencies = [];

        foreach ($pending as $index => $item) {
            if (microtime(true) >= $deadline) {
                $remaining = count($pending) - $index;

                break;
            }

            $installedMod = $item['mod'];
            $latest = $item['latest'];

            try {
                $primaryFile = Modrinth::getInstallFile($latest, $this->getProjectType());

                if (!$primaryFile) {
                    throw new Exception('No downloadable file found');
                }

                $record = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'author' => $installedMod['author'] ?? null,
                ];

                $dependencies = array_merge($dependencies, $this->installWithDependencies($server, $record, $latest, $primaryFile, $installedMod));

                $updated++;
            } catch (Exception $exception) {
                report($exception);

                // Dependencies that went in before the failure stay installed.
                $dependencies = array_merge($dependencies, $this->installedDependencies);

                $failed[] = $exception instanceof DependencyInstallException
                    ? trans('modrinth-integration::strings.notifications.update_all_failed_dependency', ['name' => $installedMod['project_title'], 'dependency' => $exception->getDependencyName()])
                    : $installedMod['project_title'];
            }
        }

        $this->forgetInstalledState();

        $parts = [];

        if ($updated > 0) {
            $parts[] = trans('modrinth-integration::strings.notifications.update_all_updated', ['count' => $updated]);
        }

        $parts[] = trim($this->withDependencies('', $dependencies));

        if (!empty($failed)) {
            $parts[] = trans('modrinth-integration::strings.notifications.update_all_failed_list', ['names' => implode(', ', $failed)]);
        }

        if ($remaining > 0) {
            $parts[] = trans('modrinth-integration::strings.notifications.update_all_remaining', ['count' => $remaining]);
        }

        $notification = Notification::make()->body(implode(' ', array_filter($parts)));

        if (empty($failed)) {
            $notification->title(trans('modrinth-integration::strings.notifications.update_all_success'))->success();
        } elseif ($updated > 0) {
            $notification->title(trans('modrinth-integration::strings.notifications.update_all_partial'))->warning();
        } else {
            $notification->title(trans('modrinth-integration::strings.notifications.update_all_failed'))->danger();
        }

        $notification->send();
    }

    protected function getExportText(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        // The export reads hand-uploaded files to identify them.
        if (!$this->can(SubuserPermission::FileReadContent)) {
            return '';
        }

        return $this->exportText ??= Modrinth::buildModList($server);
    }

    /**
     * Install what a list asks for, using this server's Minecraft version and loaders.
     *
     * Already installed projects are skipped, so running the same import again only does what is
     * still missing; that is also how an import cut short by the time budget is finished.
     *
     * @return array{installed: array<int, string>, dependencies: array<int, string>, skipped: array<int, string>, failed: array<int, string>, invalid: array<int, string>, remaining: int}
     *
     * @throws Exception when Modrinth can't be reached at all
     */
    protected function importModList(string $text): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $parsed = Modrinth::parseModList($text);

        $report = ['installed' => [], 'dependencies' => [], 'skipped' => [], 'failed' => [], 'invalid' => [], 'remaining' => 0];

        foreach ($parsed['errors'] as $error) {
            $report['invalid'][] = trans('modrinth-integration::strings.import.invalid.'.$error['reason'], [
                'line' => $error['line'],
                'text' => Str::limit($error['text'], 60),
            ]);
        }

        if (empty($parsed['entries'])) {
            return $report;
        }

        // One lookup for every slug and ID in the list. If it fails the import stops here, rather
        // than reporting every single entry as "not found".
        $byRef = [];
        foreach (Modrinth::getProjectsBulk(array_column($parsed['entries'], 'ref')) as $projectId => $project) {
            $byRef[(string) $projectId] = $project;

            if (is_string($project['slug'] ?? null)) {
                $byRef[strtolower($project['slug'])] = $project;
            }
        }

        $available = $this->getAvailableProjectTypes();
        $minecraftVersion = Modrinth::getMinecraftVersion($server) ?? '?';
        $deadline = microtime(true) + static::UPDATE_ALL_TIME_BUDGET;

        foreach (ModrinthProjectType::cases() as $type) {
            $entries = array_values(array_filter($parsed['entries'], fn (array $entry) => $entry['type'] === $type));

            if (empty($entries)) {
                continue;
            }

            $fail = function (array $entry, ?array $project, string $reason, array $parameters = []) use (&$report, $type): void {
                $report['failed'][] = trans('modrinth-integration::strings.import.failed_item', [
                    'name' => $project['title'] ?? $entry['ref'],
                    'type' => $type->getLabel(),
                    'reason' => trans('modrinth-integration::strings.import.reasons.'.$reason, $parameters),
                ]);
            };

            $lookup = fn (array $entry): ?array => $byRef[$entry['ref']] ?? $byRef[strtolower($entry['ref'])] ?? null;

            if (!in_array($type, $available, true)) {
                foreach ($entries as $entry) {
                    $fail($entry, $lookup($entry), 'type_disabled', ['type' => $type->getLabel()]);
                }

                continue;
            }

            $loader = Modrinth::getLoaderFromServer($server, $type);

            if (!$loader) {
                foreach ($entries as $entry) {
                    $fail($entry, $lookup($entry), 'no_loader', ['type' => $type->getLabel()]);
                }

                continue;
            }

            // A project can be listed by slug and by ID; it still counts once.
            $resolved = [];
            foreach ($entries as $entry) {
                $project = $lookup($entry);

                if (!$project) {
                    $fail($entry, null, 'not_found');

                    continue;
                }

                $resolved[(string) $project['id']] ??= ['entry' => $entry, 'project' => $project];
            }

            if (empty($resolved)) {
                continue;
            }

            $tracked = Modrinth::getInstalledModsMetadata($server, $type);
            $installedBefore = array_fill_keys(array_column($tracked, 'project_id'), true);
            $versionsById = Modrinth::getProjectVersionsBulk(array_map('strval', array_keys($resolved)), $server, $type);

            // Files nobody tracks, i.e. uploaded by hand.
            try {
                $handUploaded = Modrinth::getFolderFileNames($server, $type);

                foreach ($tracked as $installed) {
                    unset($handUploaded[strtolower($installed['filename'])]);
                }
            } catch (Exception $exception) {
                report($exception);

                $handUploaded = [];
            }

            foreach ($resolved as $projectId => ['entry' => $entry, 'project' => $project]) {
                $projectId = (string) $projectId;
                $title = is_string($project['title'] ?? null) ? $project['title'] : $entry['ref'];
                $versions = $versionsById[$projectId] ?? [];

                if (isset($installedBefore[$projectId])) {
                    $report['skipped'][] = $title;

                    continue;
                }

                // Uploaded by hand: take it over (so updates work from now on) instead of installing it twice.
                if (($copy = Modrinth::findVersionOnDisk($versions, $handUploaded)) !== null) {
                    Modrinth::saveModMetadata(
                        $server,
                        $type,
                        $projectId,
                        is_string($project['slug'] ?? null) ? $project['slug'] : $projectId,
                        $title,
                        (string) $copy['version']['id'],
                        (string) ($copy['version']['version_number'] ?? '?'),
                        $copy['filename'],
                    );

                    $report['skipped'][] = $title;

                    continue;
                }

                if (microtime(true) >= $deadline) {
                    $report['remaining']++;

                    continue;
                }

                // Came in a moment ago as a dependency of an earlier entry.
                if (in_array($projectId, array_column(Modrinth::getInstalledModsMetadata($server, $type), 'project_id'), true)) {
                    continue;
                }

                $latest = Modrinth::getLatestVersion($versions);

                if ($latest === null) {
                    $fail($entry, $project, 'unsupported', ['version' => $minecraftVersion, 'loader' => $loader['display_name']]);

                    continue;
                }

                $file = Modrinth::getInstallFile($latest, $type);

                if (!$file) {
                    $fail($entry, $project, 'no_file');

                    continue;
                }

                try {
                    $record = [
                        'project_id' => $projectId,
                        'slug' => is_string($project['slug'] ?? null) ? $project['slug'] : $projectId,
                        'title' => $title,
                        'author' => Modrinth::getProjectAuthor($projectId),
                    ];

                    $dependencies = $this->installWithDependencies($server, $record, $latest, $file, null, $type);

                    $report['installed'][] = $title;
                    $report['dependencies'] = array_merge($report['dependencies'], $dependencies);
                } catch (DependencyInstallException $exception) {
                    report($exception);

                    $report['dependencies'] = array_merge($report['dependencies'], $this->installedDependencies);
                    $fail($entry, $project, 'dependency', ['dependency' => $exception->getDependencyName()]);
                } catch (Exception $exception) {
                    report($exception);

                    $report['dependencies'] = array_merge($report['dependencies'], $this->installedDependencies);
                    $fail($entry, $project, 'error');
                }
            }
        }

        $report['dependencies'] = array_values(array_unique($report['dependencies']));

        return $report;
    }

    protected function runImport(string $text): void
    {
        if (!$this->canInstall()) {
            return;
        }

        $this->lastImportText = $text;

        try {
            $report = $this->importModList($text);
        } catch (Exception $exception) {
            report($exception);

            $this->forgetInstalledState();

            Notification::make()
                ->title(trans('modrinth-integration::strings.import.failed'))
                ->body(trans('modrinth-integration::strings.import.failed_body'))
                ->danger()
                ->send();

            return;
        }

        $this->importReport = $report;

        $this->forgetInstalledState();

        $problems = count($report['failed']) + count($report['invalid']);

        $notification = Notification::make()
            ->body(trans('modrinth-integration::strings.import.summary', [
                'installed' => count($report['installed']),
                'skipped' => count($report['skipped']),
                'problems' => $problems,
            ]));

        if ($problems > 0 || $report['remaining'] > 0) {
            $notification->title(trans('modrinth-integration::strings.import.done_with_problems'))->warning();
        } else {
            $notification->title(trans('modrinth-integration::strings.import.done'))->success();
        }

        $notification->send();
    }

    /** The section listing the last import's result, hidden until there is one. */
    protected function getImportReportSection(): Section
    {
        $list = fn (string $key, string $label, ?string $color = null) => TextEntry::make('import_report_'.$key)
            ->label(trans('modrinth-integration::strings.import.report.'.$label))
            ->state(fn () => $this->importReport[$key] ?? [])
            ->visible(fn () => !empty($this->importReport[$key] ?? []))
            ->color($color)
            ->bulleted();

        return Section::make(trans('modrinth-integration::strings.import.report.heading'))
            ->visible(fn () => $this->importReport !== null)
            ->headerActions([
                Action::make('dismiss_import_report')
                    ->label(trans('modrinth-integration::strings.import.report.dismiss'))
                    ->icon('tabler-x')
                    ->color('gray')
                    ->action(function () {
                        $this->importReport = null;
                    }),
            ])
            ->schema([
                TextEntry::make('import_report_remaining')
                    ->hiddenLabel()
                    ->state(fn () => trans('modrinth-integration::strings.import.report.remaining', ['count' => $this->importReport['remaining'] ?? 0]))
                    ->visible(fn () => ($this->importReport['remaining'] ?? 0) > 0)
                    ->color('warning'),
                TextEntry::make('import_report_nothing')
                    ->hiddenLabel()
                    ->state(fn () => trans('modrinth-integration::strings.import.report.nothing'))
                    ->visible(fn () => $this->importReport !== null && empty(array_filter($this->importReport))),
                $list('failed', 'failed', 'danger'),
                $list('invalid', 'invalid', 'danger'),
                $list('installed', 'installed'),
                $list('dependencies', 'dependencies'),
                $list('skipped', 'skipped'),
            ]);
    }

    /** @return array<int, Action> */
    protected function getTransferActions(): array
    {
        $monospace = ['style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem;', 'spellcheck' => 'false'];

        return [
            Action::make('export_list')
                ->label(trans('modrinth-integration::strings.actions.export_list'))
                ->icon('tabler-file-export')
                ->grouped()
                ->authorize(fn () => $this->can(SubuserPermission::FileReadContent))
                ->modalHeading(trans('modrinth-integration::strings.export.heading'))
                ->modalDescription(trans('modrinth-integration::strings.export.description'))
                ->modalWidth(Width::ThreeExtraLarge)
                ->schema([
                    Textarea::make('list')
                        ->hiddenLabel()
                        ->default(fn () => $this->getExportText())
                        ->readOnly()
                        ->rows(20)
                        ->extraInputAttributes($monospace + ['wrap' => 'off']),
                ])
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(trans('modrinth-integration::strings.export.close'))
                ->extraModalFooterActions(fn () => [
                    Action::make('copy_list')
                        ->label(trans('modrinth-integration::strings.export.copy'))
                        ->icon('tabler-copy')
                        // Copies in the browser, no round trip. The clipboard API needs HTTPS; if it
                        // isn't there the tooltip says to select the text instead.
                        ->alpineClickHandler(sprintf(
                            'window.navigator.clipboard'
                            .' ? window.navigator.clipboard.writeText(%1$s).then(() => $tooltip(%2$s, { theme: $store.theme, timeout: 2000 }), () => $tooltip(%3$s, { theme: $store.theme, timeout: 5000 }))'
                            .' : $tooltip(%3$s, { theme: $store.theme, timeout: 5000 })',
                            Js::from($this->getExportText()),
                            Js::from(trans('modrinth-integration::strings.export.copied')),
                            Js::from(trans('modrinth-integration::strings.export.copy_failed')),
                        )),
                ]),
            Action::make('import_list')
                ->label(trans('modrinth-integration::strings.actions.import_list'))
                ->icon('tabler-file-import')
                ->grouped()
                ->authorize(fn () => $this->canInstall())
                ->modalHeading(trans('modrinth-integration::strings.import.heading'))
                ->modalDescription(trans('modrinth-integration::strings.import.description'))
                ->modalWidth(Width::ThreeExtraLarge)
                ->schema([
                    Textarea::make('list')
                        ->label(trans('modrinth-integration::strings.import.field'))
                        ->helperText(trans('modrinth-integration::strings.import.help'))
                        ->default(fn () => $this->lastImportText)
                        ->required()
                        ->maxLength(100000)
                        ->rows(16)
                        ->extraInputAttributes($monospace),
                ])
                ->modalSubmitActionLabel(trans('modrinth-integration::strings.import.submit'))
                ->action(fn (array $data) => $this->runImport((string) ($data['list'] ?? ''))),
        ];
    }

    protected function getUpdateAllDescription(): HtmlString
    {
        $pending = $this->getPendingUpdates();

        $items = '';
        foreach (array_slice($pending, 0, static::UPDATE_ALL_LIST_LIMIT) as $item) {
            $items .= '<li>'.e($item['mod']['project_title']).' ('.e($item['mod']['version_number']).' &rarr; '.e($item['latest']['version_number'] ?? '?').')</li>';
        }

        if (count($pending) > static::UPDATE_ALL_LIST_LIMIT) {
            $items .= '<li>'.e(trans('modrinth-integration::strings.modals.update_all_more', ['count' => count($pending) - static::UPDATE_ALL_LIST_LIMIT])).'</li>';
        }

        return new HtmlString(
            '<p>'.e(trans('modrinth-integration::strings.modals.update_all_description', ['count' => count($pending)])).'</p>'
            .'<ul style="list-style: disc; padding-inline-start: 1.5rem; margin-top: 0.75rem; text-align: start;">'.$items.'</ul>'
        );
    }

    /**
     * @param  array<int, string>  $dependencies
     */
    protected function withDependencies(string $body, array $dependencies): string
    {
        if (empty($dependencies)) {
            return $body;
        }

        return trim($body.' '.trans('modrinth-integration::strings.notifications.dependencies_installed', ['dependencies' => implode(', ', array_unique($dependencies))]));
    }

    /**
     * A copy of the project in the folder that was uploaded by hand rather than installed here,
     * recognized by the file name of one of its versions for this server.
     *
     * @param  array<int, array<string, mixed>>|null  $versions
     * @return array{version: array<string, mixed>, filename: string}|null
     */
    protected function findHandUploadedCopy(Server $server, string $projectId, ModrinthProjectType $modrinthProjectType, ?array $versions = null): ?array
    {
        try {
            $files = Modrinth::getFolderFileNames($server, $modrinthProjectType);
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        // Files that belong to tracked projects aren't anybody's hand-uploaded copy.
        foreach (Modrinth::getInstalledModsMetadata($server, $modrinthProjectType) as $installed) {
            unset($files[strtolower($installed['filename'])]);
        }

        if (empty($files)) {
            return null;
        }

        return Modrinth::findVersionOnDisk($versions ?? Modrinth::getProjectVersions($projectId, $server, $modrinthProjectType), $files);
    }

    /** Adds which dependencies were installed before something failed (they stay installed). */
    protected function withKeptDependencies(string $body): string
    {
        if (empty($this->installedDependencies)) {
            return $body;
        }

        return trim($body.' '.trans('modrinth-integration::strings.notifications.dependencies_kept', ['dependencies' => implode(', ', array_unique($this->installedDependencies))]));
    }

    protected function getFailureBody(Exception $exception, string $fallbackKey): string
    {
        if ($exception instanceof DependencyInstallException) {
            return trans('modrinth-integration::strings.notifications.dependency_failed_body', ['name' => $exception->getDependencyName()]);
        }

        return trans($fallbackKey);
    }

    /**
     * Gives every row the Modrinth project ID as its table key. Without one Filament keys rows by
     * their position on the page, and an action confirmed after the list changed (another tab
     * installed something, the search cache expired) would hit whatever project is at that
     * position now.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    protected function withRecordKeys(array $records): array
    {
        return array_map(fn (array $record) => ['__key' => (string) ($record['project_id'] ?? $record['id'] ?? '')] + $record, $records);
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page, ?array $filters) {
                /** @var Server $server */
                $server = Filament::getTenant();

                if ($this->activeTab === 'installed') {
                    $installedMods = $this->getInstalledModsMetadata();

                    if ($search) {
                        $searchLower = strtolower($search);
                        $installedMods = array_values(array_filter($installedMods, function (array $mod) use ($searchLower) {
                            return str_contains(strtolower($mod['project_title']), $searchLower)
                                || str_contains(strtolower($mod['project_slug']), $searchLower);
                        }));
                    }

                    // Sort by title so a row keeps its place instead of moving around whenever its
                    // metadata entry is rewritten by an install, update or failed update.
                    usort($installedMods, fn (array $a, array $b) => strcasecmp($a['project_title'], $b['project_title']));

                    $projects = $this->withRecordKeys(Modrinth::getInstalledModsFromModrinth($installedMods, $page));

                    $this->primeVersionsCache(array_column($projects, 'project_id'));

                    return new LengthAwarePaginator($projects, count($installedMods), 20, $page);
                } else {
                    $response = Modrinth::getProjects($server, $this->getProjectType(), $page, $search, $this->shouldHideClientOnly($filters));

                    $installedIds = array_column($this->getInstalledModsMetadata(), 'project_id');
                    $this->primeVersionsCache(array_intersect(array_column($response['hits'], 'project_id'), $installedIds));

                    return new LengthAwarePaginator($this->withRecordKeys($response['hits']), $response['total_hits'], 20, $page);
                }
            })
            ->paginated([20])
            // The default layout puts the filter button next to the search field.
            ->filters([
                Filter::make(static::SHOW_CLIENT_ONLY_FILTER)
                    ->label(trans('modrinth-integration::strings.page.show_client_only'))
                    ->visible(fn () => $this->isClientOnlyFilterAvailable()),
            ])
            // A single checkbox: apply it right away instead of behind an "Apply" button.
            ->deferFilters(false)
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->label(trans('modrinth-integration::strings.table.columns.title'))
                    ->searchable()
                    ->icon(fn (array $record) => Modrinth::isClientOnly($record) ? 'tabler-device-desktop' : null)
                    ->iconColor('warning')
                    ->tooltip(fn (array $record) => Modrinth::isClientOnly($record) ? trans('modrinth-integration::strings.page.client_only_tooltip') : null)
                    // Str::limit counts characters, not bytes: cutting a multibyte description in
                    // the middle of a character leaves invalid UTF-8 that breaks the Livewire payload.
                    ->description(fn (array $record) => Str::limit((string) ($record['description'] ?? ''), 120)),
                TextColumn::make('author')
                    ->label(trans('modrinth-integration::strings.table.columns.author'))
                    ->url(fn ($state) => filled($state) ? 'https://modrinth.com/user/'.rawurlencode((string) $state) : null, true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->label(trans('modrinth-integration::strings.table.columns.downloads'))
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->label(trans('modrinth-integration::strings.table.columns.date_modified'))
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state, 'UTC')->diffForHumans() : '')
                    ->tooltip(fn ($state) => $state ? Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()) : '')
                    ->toggleable(),
            ])
            ->recordUrl(function (array $record) {
                if (!empty($record['unavailable'])) {
                    return null;
                }

                return "https://modrinth.com/{$record['project_type']}/{$record['slug']}";
            }, true)
            ->recordActions([
                Action::make('versions')
                    ->iconButton()
                    ->icon('tabler-list')
                    ->color('info')
                    ->tooltip(trans('modrinth-integration::strings.actions.versions'))
                    ->visible(fn (array $record) => empty($record['unavailable']))
                    ->modalSubmitAction(false)
                    ->schema(function (array $record) {
                        $versions = $this->getCachedVersions($record['project_id']);

                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $installedVersionId = $installedMod['version_id'] ?? null;

                        $sections = [];
                        foreach ($versions as $versionIndex => $versionData) {
                            // Named after the version, not its position: a version published while
                            // the modal is open must not shift which one the button installs.
                            $versionIndex = preg_replace('/[^A-Za-z0-9]/', '_', (string) ($versionData['id'] ?? $versionIndex));

                            $primaryFile = Modrinth::getInstallFile($versionData, $this->getProjectType());

                            $sectionComponents = [
                                TextEntry::make('type_' . $versionIndex)
                                    ->label(trans('modrinth-integration::strings.version.type'))
                                    ->state($versionData['version_type'] ?? '')
                                    ->badge()
                                    ->color(match ($versionData['version_type'] ?? '') {
                                        'release' => 'success',
                                        'beta' => 'warning',
                                        'alpha' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('downloads_' . $versionIndex)
                                    ->label(trans('modrinth-integration::strings.version.downloads'))
                                    ->state($versionData['downloads'] ?? 0)
                                    ->icon('tabler-download')
                                    ->numeric(),
                                TextEntry::make('published_' . $versionIndex)
                                    ->label(trans('modrinth-integration::strings.version.published'))
                                    ->state(fn () => isset($versionData['date_published']) ? Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans() : ''),
                            ];

                            if (!empty($versionData['changelog'])) {
                                $sectionComponents[] = TextEntry::make('changelog_' . $versionIndex)
                                    ->label(trans('modrinth-integration::strings.version.changelog'))
                                    ->state($versionData['changelog'])
                                    ->markdown();
                            }

                            if (($versionData['id'] ?? null) === $installedVersionId) {
                                $headerAction = Action::make('installed_' . $versionIndex)
                                    ->label(trans('modrinth-integration::strings.actions.installed'))
                                    ->icon('tabler-check')
                                    ->color('success')
                                    ->disabled();
                                $sectionIcon = 'tabler-check';
                                $sectionIconColor = 'success';
                            } else {
                                $headerAction = Action::make('install_version_' . $versionIndex)
                                    ->label(trans('modrinth-integration::strings.actions.install'))
                                    ->icon('tabler-download')
                                    ->visible($primaryFile !== null)
                                    ->authorize(fn () => $this->canInstall($this->getInstalledMod($record['project_id']) !== null))
                                    ->action(fn () => $this->installVersion($record, $versionData, $this->getInstalledMod($record['project_id'])));
                                $sectionIcon = null;
                                $sectionIconColor = null;
                            }

                            $section = Section::make($versionData['version_number'] ?? '')
                                ->headerActions([$headerAction])
                                ->schema($sectionComponents)
                                ->collapsible()
                                ->collapsed(!($versionData['featured'] ?? false));

                            if ($sectionIcon !== null) {
                                $section = $section->icon($sectionIcon)->iconColor($sectionIconColor);
                            }

                            $sections[] = $section;
                        }

                        return $sections;
                    }),
                Action::make('install_latest')
                    ->authorize(fn () => $this->canInstall())
                    ->iconButton()
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(trans('modrinth-integration::strings.actions.install_latest'))
                    ->visible(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        return is_null($installedMod);
                    })
                    ->action(function (array $record) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        $latest = Modrinth::getLatestVersion(Modrinth::getProjectVersions($record['project_id'], $server, $this->getProjectType()));

                        $this->installVersion($record, $latest);
                    }),
                Action::make('update')
                    ->authorize(fn () => $this->canInstall(replaces: true))
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(trans('modrinth-integration::strings.actions.update'))
                    ->visible(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        return $installedMod !== null && $this->getAvailableUpdate($installedMod) !== null;
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('modrinth-integration::strings.modals.update_heading', ['name' => $record['title']]))
                    ->modalDescription(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);
                        $latest = $installedMod ? $this->getAvailableUpdate($installedMod) : null;

                        return trans('modrinth-integration::strings.modals.update_description', [
                            'old_version' => $installedMod['version_number'] ?? 'unknown',
                            'new_version' => $latest['version_number'] ?? 'unknown',
                        ]);
                    })
                    ->action(function (array $record) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        $installedMod = $this->getInstalledMod($record['project_id']);

                        $latest = $installedMod
                            ? Modrinth::getLatestVersion(Modrinth::getProjectVersions($record['project_id'], $server, $this->getProjectType()), $installedMod['version_id'])
                            : null;

                        $this->installVersion($record, $latest, $installedMod, 'update');
                    }),
                Action::make('installed')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('modrinth-integration::strings.actions.installed'))
                    ->disabled()
                    ->visible(function (array $record) {
                        $installedMod = $this->getInstalledMod($record['project_id']);

                        return $installedMod !== null && $this->getAvailableUpdate($installedMod) === null;
                    }),
                Action::make('uninstall')
                    ->authorize(fn () => $this->can(SubuserPermission::FileDelete))
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('modrinth-integration::strings.actions.uninstall'))
                    ->visible(function (array $record) {
                        return !is_null($this->getInstalledMod($record['project_id']));
                    })
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => trans('modrinth-integration::strings.modals.uninstall_heading', ['name' => $record['title']]))
                    ->modalDescription(fn (array $record) => trans('modrinth-integration::strings.modals.uninstall_description', ['name' => $record['title']]))
                    ->action(function (array $record) {
                        try {
                            /** @var Server $server */
                            $server = Filament::getTenant();

                            if (!$this->can(SubuserPermission::FileDelete)) {
                                throw new Exception('Missing file permissions');
                            }

                            $installedMod = $this->getInstalledMod($record['project_id']);

                            if (!$installedMod) {
                                throw new Exception('Mod not found in metadata');
                            }

                            $safeFilename = $this->validateFilename($installedMod['filename']);

                            $folder = Modrinth::getProjectFolder($server, $this->getProjectType());

                            Modrinth::deleteFile($server, $folder, $safeFilename);

                            $metadataRemoved = Modrinth::removeModMetadata($server, $this->getProjectType(), $record['project_id']);

                            $this->forgetInstalledState();

                            if (!$metadataRemoved) {
                                Log::warning('Failed to remove mod metadata after successful file deletion', [
                                    'project_id' => $record['project_id'],
                                    'server_id' => $server->id,
                                ]);

                                // The jar is gone but the metadata file still lists it, so the row
                                // stays. Say so instead of reporting a clean uninstall.
                                Notification::make()
                                    ->title(trans('modrinth-integration::strings.notifications.uninstall_partial'))
                                    ->body(trans('modrinth-integration::strings.notifications.uninstall_partial_body', [
                                        'name' => $record['title'],
                                    ]))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(trans('modrinth-integration::strings.notifications.uninstall_success'))
                                ->body(trans('modrinth-integration::strings.notifications.uninstall_success_body', [
                                    'name' => $record['title'],
                                ]))
                                ->success()
                                ->send();
                        } catch (Exception $exception) {
                            report($exception);

                            $this->forgetInstalledState();

                            Notification::make()
                                ->title(trans('modrinth-integration::strings.notifications.uninstall_failed'))
                                ->body(trans('modrinth-integration::strings.notifications.uninstall_failed_body'))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $current = $this->getProjectType();

        try {
            $folder = Modrinth::getProjectFolder($server, $current);
        } catch (Exception $exception) {
            // The datapack folder needs server.properties; without it there's no folder to open.
            report($exception);

            $folder = null;
        }

        // Switching reloads the page with ?type=..., which also resets search, pagination and the
        // tab, all of which belong to the previous type.
        // grouped(): Pelican turns every action into an icon button when the user picked that
        // button style, which inside a dropdown would leave the entries without their text.
        $typeEntries = array_map(
            fn (ModrinthProjectType $type) => Action::make('type_'.$type->value)
                ->label($type->getLabel())
                ->icon($type === $current ? 'tabler-check' : null)
                ->url(static::getUrl(['type' => $type->value]))
                ->grouped(),
            $this->getAvailableProjectTypes()
        );

        return [
            ActionGroup::make($typeEntries)
                ->label($current->getLabel())
                ->icon('tabler-selector')
                ->color('gray')
                ->button(),
            ActionGroup::make($this->getTransferActions())
                ->label(trans('modrinth-integration::strings.actions.transfer'))
                ->icon('tabler-transfer')
                ->color('gray')
                ->button(),
            Action::make('update_all')
                ->label(trans('modrinth-integration::strings.actions.update_all'))
                ->icon('tabler-refresh')
                ->color('warning')
                ->authorize(fn () => $this->canInstall(replaces: true))
                ->visible(fn () => !empty($this->getPendingUpdates()))
                ->requiresConfirmation()
                ->modalHeading(trans('modrinth-integration::strings.modals.update_all_heading'))
                ->modalDescription(fn () => $this->getUpdateAllDescription())
                ->action(fn () => $this->updateAll()),
            Action::make('open_folder')
                ->tooltip(fn () => trans('modrinth-integration::strings.page.open_folder', ['folder' => $folder]))
                ->icon('tabler-folder-open')
                ->visible($folder !== null)
                ->url(fn () => ListFiles::getUrl(['path' => $folder]), true),
        ];
    }

    /**
     * One line under the title instead of a row of boxes: Minecraft version, the loader (or for
     * datapacks the world) and how many files of this type are installed.
     */
    public function getSubheading(): ?string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $type = $this->getProjectType();
        $unknown = trans('modrinth-integration::strings.page.unknown');

        $parts = [trans('modrinth-integration::strings.page.subheading_version', ['version' => Modrinth::getMinecraftVersion($server) ?? $unknown])];

        try {
            if ($type->needsLoaderTag()) {
                $parts[] = Modrinth::getLoaderFromServer($server, $type)['display_name'] ?? trans('modrinth-integration::strings.page.no_loader');
            } else {
                $parts[] = trans('modrinth-integration::strings.page.subheading_world', ['world' => Modrinth::getLevelName($server)]);
            }

            $extension = '.'.$type->getFileExtension();
            $count = count(array_filter(
                Modrinth::listFolder($server, Modrinth::getProjectFolder($server, $type)),
                fn ($file) => is_array($file) && empty($file['directory']) && str_ends_with(strtolower((string) ($file['name'] ?? '')), $extension)
            ));
        } catch (Exception $exception) {
            report($exception);

            // Don't pass off an unreachable daemon as an empty folder.
            $count = $unknown;
        }

        $parts[] = trans('modrinth-integration::strings.page.subheading_installed', ['count' => $count, 'type' => $type->getLabel()]);

        return implode(' · ', $parts);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getImportReportSection(),
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }
}

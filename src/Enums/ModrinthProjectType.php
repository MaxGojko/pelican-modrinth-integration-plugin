<?php

namespace MaxGojko\ModrinthIntegration\Enums;

use App\Models\Server;
use Filament\Support\Contracts\HasLabel;

enum ModrinthProjectType: string implements HasLabel
{
    case Mod = 'mod';
    case Plugin = 'plugin';
    case Datapack = 'datapack';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mod => trans('modrinth-integration::strings.types.mod'),
            self::Plugin => trans('modrinth-integration::strings.types.plugin'),
            self::Datapack => trans('modrinth-integration::strings.types.datapack'),
        };
    }

    /** The egg tag that turns this type on. */
    public function getTag(): string
    {
        return match ($this) {
            self::Mod => 'modrinth_mods',
            self::Plugin => 'modrinth_plugins',
            self::Datapack => 'modrinth_datapacks',
        };
    }

    /** Extension of the files this type installs, without the dot. */
    public function getFileExtension(): string
    {
        return match ($this) {
            self::Mod, self::Plugin => 'jar',
            self::Datapack => 'zip',
        };
    }

    /** Section name in an exported list, e.g. "[mods]". */
    public function getSectionName(): string
    {
        return match ($this) {
            self::Mod => 'mods',
            self::Plugin => 'plugins',
            self::Datapack => 'datapacks',
        };
    }

    /** Reads a section name back; singular, plural and any casing are accepted. */
    public static function fromSectionName(string $name): ?self
    {
        return match (str_replace([' ', '-', '_'], '', strtolower(trim($name)))) {
            'mods', 'mod' => self::Mod,
            'plugins', 'plugin' => self::Plugin,
            'datapacks', 'datapack' => self::Datapack,
            default => null,
        };
    }

    /** Whether the server needs an egg tag naming its Modrinth loader (e.g. "fabric" or "paper"). */
    public function needsLoaderTag(): bool
    {
        return $this !== self::Datapack;
    }

    /** @return string[] the egg's tags, lowercased */
    public static function getEggTags(Server $server): array
    {
        $server->loadMissing('egg');

        return array_values(array_map(
            fn ($tag) => strtolower(trim((string) $tag)),
            array_filter((array) ($server->egg->tags ?? []), fn ($tag) => is_string($tag))
        ));
    }

    /** @return ModrinthProjectType[] the types switched on by the egg's tags, in display order */
    public static function fromServer(Server $server): array
    {
        $tags = self::getEggTags($server);

        return array_values(array_filter(self::cases(), fn (self $type) => in_array($type->getTag(), $tags, true)));
    }
}

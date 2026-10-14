<?php

namespace MaxGojko\ModrinthIntegration;

use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Panel;

class ModrinthIntegrationPlugin implements Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'modrinth-integration';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "MaxGojko\\ModrinthIntegration\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}
}

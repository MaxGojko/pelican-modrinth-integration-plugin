<?php

// Minimal stand-ins for Pelican/Filament/Laravel so the plugin's own code can run in isolation.

namespace App\Models {
    class Server
    {
        public int $id = 1;

        public string $uuid = 'uuid-1';

        public string $name = "Old\nSurvival";

        public $egg;

        public function __construct(array $tags = [])
        {
            $this->egg = (object) ['tags' => $tags, 'features' => []];
        }

        public bool $inConflict = false;

        public function loadMissing($relation): static
        {
            return $this;
        }

        public function isInConflictState(): bool
        {
            return $this->inConflict;
        }

        // MINECRAFT_VERSION: the server's own value (null = never changed) and the egg default.
        public ?string $versionValue = null;

        public ?string $versionDefault = '26.3';

        public function variables(): object
        {
            $server = $this;

            return new class($server)
            {
                public function __construct(private $server) {}

                public function where($callback): static
                {
                    return $this;
                }

                public function first(): ?object
                {
                    return (object) ['server_value' => $this->server->versionValue, 'default_value' => $this->server->versionDefault];
                }
            };
        }
    }
}

namespace App\Enums {
    enum SubuserPermission: string
    {
        case FileRead = 'file.read';
        case FileReadContent = 'file.read-content';
        case FileCreate = 'file.create';
        case FileUpdate = 'file.update';
        case FileDelete = 'file.delete';
    }
}

namespace App\Repositories\Daemon {
    class DaemonFileRepository {}
}

namespace App\Traits\Filament {
    // Same as Pelican's trait.
    trait BlockAccessInConflict
    {
        public static function canAccess(): bool
        {
            $server = \Filament\Facades\Filament::getTenant();

            if ($server->isInConflictState()) {
                return false;
            }

            return parent::canAccess();
        }
    }
}

namespace Filament\Support\Contracts {
    interface HasLabel
    {
        public function getLabel(): string|null|\Stringable;
    }
}

namespace Filament\Facades {
    class Filament
    {
        public static $tenant;

        public static function getTenant()
        {
            return self::$tenant;
        }
    }
}

namespace Filament\Notifications {
    class Notification
    {
        public static array $sent = [];

        public array $data = [];

        public static function make(): static
        {
            return new static;
        }

        public function __call($name, $args)
        {
            $this->data[$name] = $args[0] ?? true;

            return $this;
        }

        public function send(): static
        {
            self::$sent[] = $this->data;

            return $this;
        }
    }
}

namespace Filament\Pages {
    class Page
    {
        public array $jsCalls = [];

        public function js(string $script): void
        {
            $this->jsCalls[] = $script;
        }

        public static function canAccess(): bool
        {
            return true;
        }

        public static function getUrl(array $parameters = []): string
        {
            return '/server/modrinth?'.http_build_query($parameters);
        }
    }
}

namespace Filament\Tables\Contracts {
    interface HasTable {}
}

namespace Filament\Resources\Concerns {
    trait HasTabs
    {
        public ?string $activeTab = null;
    }
}

namespace Filament\Tables\Concerns {
    trait InteractsWithTable {}
}

namespace Filament\Actions {
    // Records the fluent calls so a test can pull out the closures it was configured with.
    class Action
    {
        public array $calls = [];

        public static function make(string $name): static
        {
            $action = new static;
            $action->calls['name'] = [$name];

            return $action;
        }

        public function __call($method, $args)
        {
            $this->calls[$method] = $args;

            return $this;
        }
    }
}

namespace Filament\Actions {
    class ActionGroup
    {
        public array $calls = [];

        public function __construct(public array $actions = []) {}

        public static function make(array $actions): static
        {
            return new static($actions);
        }

        public function __call($method, $args)
        {
            $this->calls[$method] = $args;

            return $this;
        }
    }
}

namespace Illuminate\Contracts\Filesystem {
    class FileNotFoundException extends \Exception {}
}

namespace Illuminate\Support {
    class HtmlString implements \Stringable
    {
        public function __construct(public string $html = '') {}

        public function toHtml(): string
        {
            return $this->html;
        }

        public function __toString(): string
        {
            return $this->html;
        }
    }
}

namespace Illuminate\Support\Facades {
    class Facade
    {
        public static $instance;

        public static function __callStatic($method, $args)
        {
            return static::$instance->$method(...$args);
        }
    }
}

namespace {
    $GLOBALS['reported'] = [];

    function report($exception): void
    {
        $GLOBALS['reported'][] = $exception;
    }

    function trans(string $key, array $params = []): string
    {
        return $params ? $key.' '.json_encode($params, JSON_UNESCAPED_UNICODE) : $key;
    }

    function join_paths(string $base, string ...$paths): string
    {
        return implode('/', array_filter([rtrim($base, '/'), ...array_map(fn ($p) => trim($p, '/'), $paths)], fn ($p) => $p !== ''));
    }

    final class StubStringable
    {
        public function __construct(private string $value) {}

        public function title(): static
        {
            return new static(mb_convert_case($this->value, MB_CASE_TITLE, 'UTF-8'));
        }

        public function lower(): static
        {
            return new static(mb_strtolower($this->value));
        }

        public function endsWith(string $needle): bool
        {
            return str_ends_with($this->value, $needle);
        }

        public function toString(): string
        {
            return $this->value;
        }
    }

    function str(?string $value = ''): StubStringable
    {
        return new StubStringable((string) $value);
    }

    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

namespace Filament\Tables {
    // Records the fluent calls so a test can pull out the closures it was configured with.
    class Table
    {
        public array $calls = [];

        public function __call($method, $args)
        {
            $this->calls[$method] = $args;

            return $this;
        }
    }
}

namespace Filament\Tables\Enums {
    enum FiltersLayout
    {
        case AboveContent;
        case Dropdown;
    }
}

namespace Filament\Tables\Filters {
    class Filter
    {
        public array $calls = [];

        public static function make(string $name): static
        {
            $filter = new static;
            $filter->calls['name'] = [$name];

            return $filter;
        }

        public function __call($method, $args)
        {
            $this->calls[$method] = $args;

            return $this;
        }
    }
}

namespace Filament\Tables\Columns {
    class TextColumn
    {
        public array $calls = [];

        public static function make(string $name): static
        {
            $column = new static;
            $column->calls['name'] = [$name];

            return $column;
        }

        public function __call($method, $args)
        {
            $this->calls[$method] = $args;

            return $this;
        }
    }

    class ImageColumn extends TextColumn {}
}

namespace Illuminate\Pagination {
    class LengthAwarePaginator
    {
        public function __construct(public $items, public $total, public $perPage, public $currentPage) {}
    }
}

namespace Illuminate\Http\Client {
    // Stands in for Laravel's HTTP client: remembers the last request instead of sending it.
    class PendingRequest
    {
        public static array $requests = [];

        public static array $response = ['hits' => [], 'total_hits' => 0];

        public static ?\Exception $fail = null;

        public function throw(): static
        {
            return $this;
        }

        public function get(string $url, array $query = []): static
        {
            self::$requests[] = ['url' => $url, 'query' => $query];

            if (self::$fail) {
                throw self::$fail;
            }

            return $this;
        }

        public function post(string $url, array $data = []): static
        {
            self::$requests[] = ['url' => $url, 'post' => $data];

            return $this;
        }

        public function json(): mixed
        {
            return self::$response;
        }
    }
}

namespace {
    final class StubCache
    {
        public array $items = [];

        public function get(string $key)
        {
            return $this->items[$key] ?? null;
        }

        public function put(string $key, $value, $ttl = null): void
        {
            $this->items[$key] = $value;
        }
    }

    $GLOBALS['stub_cache'] = new StubCache;

    function cache(): StubCache
    {
        return $GLOBALS['stub_cache'];
    }

    function now(): object
    {
        return new class
        {
            public function addMinutes(int $m): int
            {
                return $m;
            }

            public function addHour(): int
            {
                return 60;
            }

            public function addDay(): int
            {
                return 1440;
            }

            public function toDateString(): string
            {
                return '2026-10-13';
            }

            public function toIso8601String(): string
            {
                return '2026-10-13T12:00:00+00:00';
            }
        };
    }
}

namespace Filament\Forms\Components {
    class Textarea extends \Filament\Tables\Columns\TextColumn {}
}

namespace Filament\Infolists\Components {
    class TextEntry extends \Filament\Tables\Columns\TextColumn {}
}

namespace Filament\Schemas\Components {
    class Section extends \Filament\Tables\Columns\TextColumn {}
}

namespace Filament\Support\Enums {
    enum Width: string
    {
        case ThreeExtraLarge = '3xl';
    }
}

namespace Illuminate\Support {
    class Js
    {
        public static function from($value): string
        {
            return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        }
    }

    class Str
    {
        public static function limit($value, $limit = 100, $end = '...'): string
        {
            return mb_strlen((string) $value) > $limit ? rtrim(mb_substr((string) $value, 0, $limit)).$end : (string) $value;
        }
    }
}

namespace Illuminate\Support\Facades {
    class Cache
    {
        public static int $locks = 0;

        public static ?int $lastTtl = null;

        public static function lock(string $name, int $seconds)
        {
            self::$locks++;
            self::$lastTtl = $seconds;

            return new class
            {
                public function block(int $wait, callable $callback)
                {
                    return $callback();
                }
            };
        }
    }
}

namespace Illuminate\Http\Client {
    class RequestException extends \Exception
    {
        public function __construct(public object $response)
        {
            parent::__construct('HTTP '.$response->status());
        }
    }
}

namespace {
    /** Permissions of the fake logged-in user; null = all allowed. */
    $GLOBALS['user_permissions'] = null;

    function user(): object
    {
        return new class
        {
            public string $timezone = 'UTC';

            public function can($permission, $server): bool
            {
                $allowed = $GLOBALS['user_permissions'];

                return $allowed === null || in_array($permission, $allowed, true);
            }
        };
    }
}

namespace {
    $GLOBALS['app_bindings'] = [];

    function app(string $class)
    {
        return $GLOBALS['app_bindings'][$class] ?? throw new \RuntimeException("no fake bound for $class");
    }
}

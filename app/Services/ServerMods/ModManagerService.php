<?php

namespace Pterodactyl\Services\ServerMods;

use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Server;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

class ModManagerService
{
    public function __construct(
        private DaemonFileRepository $fileRepository,
        private EggModSupportService $eggModSupportService,
    ) {
    }

    public function overview(Server $server): array
    {
        $context = $this->detectContext($server);
        $providers = $this->detectProviderSupport($context);
        $directories = [];
        $installed = [];

        foreach ($this->candidateDirectories($context, $providers) as $directory) {
            $path = $directory['path'];

            try {
                $entries = $this->fileRepository->setServer($server)->getDirectory($path);
                $directory['exists'] = true;
                $directory['entry_count'] = count($entries);
                $directories[] = $directory;

                foreach ($entries as $entry) {
                    $item = $this->mapInstalledEntry($directory, $entry, $path);
                    if ($item !== null) {
                        $installed[] = $item;
                    }
                }
            } catch (Throwable) {
                $directory['exists'] = false;
                $directory['entry_count'] = 0;
                $directories[] = $directory;
            }
        }

        usort($installed, fn (array $left, array $right) => [$left['kind'], $left['display_name']] <=> [$right['kind'], $right['display_name']]);

        return [
            'context' => $context,
            'directories' => $directories,
            'installed' => $installed,
            'counts' => [
                'installed' => count($installed),
                'mods' => count(array_filter($installed, fn (array $item) => $item['kind'] === 'mod')),
                'plugins' => count(array_filter($installed, fn (array $item) => $item['kind'] === 'plugin')),
                'datapacks' => count(array_filter($installed, fn (array $item) => $item['kind'] === 'datapack')),
            ],
            'providers' => $providers,
        ];
    }

    public function search(Server $server, array $filters): array
    {
        $context = $this->detectContext($server);
        $query = trim((string) ($filters['query'] ?? ''));
        $contentType = strtolower((string) ($filters['content_type'] ?? 'mod'));
        $loader = strtolower(trim((string) ($filters['loader'] ?? $context['loader'] ?? '')));
        $gameVersion = trim((string) ($filters['game_version'] ?? $context['game_version'] ?? ''));
        $limit = max(1, min(12, (int) ($filters['limit'] ?? 8)));
        $providers = $this->detectProviderSupport($context);
        $provider = strtolower(trim((string) ($filters['provider'] ?? ($providers['catalog_search_provider'] ?? 'modrinth'))));

        if ($query === '') {
            throw new \InvalidArgumentException('A search query is required.');
        }

        $supportedProviders = collect($providers['sources'] ?? [])
            ->pluck('key')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!in_array($provider, $supportedProviders, true) && ($providers['catalog_search_provider'] ?? null) !== $provider) {
            return [
                'provider' => $provider !== '' ? $provider : 'modrinth',
                'supported' => false,
                'context' => $context,
                'results' => [],
                'message' => $this->providerSearchMessage($provider ?: 'modrinth'),
                'search_url' => $this->providerSearchUrl($provider ?: 'modrinth', $query, $contentType, $context),
            ];
        }

        $results = match ($provider) {
            'modrinth' => $this->searchModrinth($query, $contentType, $loader, $gameVersion, $limit),
            'nexus' => $this->searchNexus($query, $contentType, $context, $limit),
            'thunderstore' => $this->searchThunderstore($query, $contentType, $context, $limit),
            default => null,
        };

        if ($results === null) {
            return [
                'provider' => $provider !== '' ? $provider : 'modrinth',
                'supported' => false,
                'context' => $context,
                'results' => [],
                'message' => $this->providerSearchMessage($provider ?: 'modrinth'),
                'search_url' => $this->providerSearchUrl($provider ?: 'modrinth', $query, $contentType, $context),
            ];
        }

        return [
            'provider' => $provider,
            'supported' => true,
            'context' => $context,
            'filters' => [
                'query' => $query,
                'content_type' => $contentType,
                'loader' => $loader,
                'game_version' => $gameVersion,
            ],
            'results' => $results,
        ];
    }

    public function projectVersions(Server $server, string $projectId, array $filters): array
    {
        $context = $this->detectContext($server);
        $loader = strtolower(trim((string) ($filters['loader'] ?? $context['loader'] ?? '')));
        $gameVersion = trim((string) ($filters['game_version'] ?? $context['game_version'] ?? ''));

        $versions = $this->fetchProjectVersions($projectId, $loader, $gameVersion);

        return [
            'project_id' => $projectId,
            'context' => $context,
            'versions' => $versions,
        ];
    }

    public function install(Server $server, array $payload): array
    {
        $context = $this->detectContext($server);
        $contentType = strtolower((string) ($payload['content_type'] ?? 'mod'));
        $downloadUrl = trim((string) ($payload['download_url'] ?? ''));
        $filename = trim((string) ($payload['filename'] ?? ''));
        $targetDirectory = trim((string) ($payload['target_directory'] ?? ''));
        $providers = $this->detectProviderSupport($context);

        if ($downloadUrl === '') {
            throw new \InvalidArgumentException('A download URL is required.');
        }

        if (!($providers['supported'] ?? false) || !($providers['direct_url'] ?? false)) {
            throw new \InvalidArgumentException('Direct installs are not enabled for this server type.');
        }

        if ($targetDirectory === '') {
            $targetDirectory = $this->defaultDirectoryForKind($context, $contentType);
        }

        if ($filename === '') {
            $filename = basename(parse_url($downloadUrl, PHP_URL_PATH) ?: 'download.jar');
        }

        $this->fileRepository->setServer($server)->pull($downloadUrl, $targetDirectory, [
            'filename' => $filename,
            'foreground' => true,
        ]);

        return [
            'target_directory' => $targetDirectory,
            'filename' => $filename,
            'path' => rtrim($targetDirectory, '/') . '/' . ltrim($filename, '/'),
            'download_url' => $downloadUrl,
            'context' => $context,
        ];
    }

    private function detectContext(Server $server): array
    {
        $server->loadMissing(['variables', 'egg.nest']);

        $modsOverride = $this->eggModSupportService->getOverride((int) optional($server->egg)->id);
        $eggName = (string) optional($server->egg)->name;
        $nestName = (string) optional(optional($server->egg)->nest)->name;

        $values = [
            (string) ($server->startup ?? ''),
            (string) ($server->image ?? ''),
            $eggName,
            $nestName,
        ];

        foreach ($server->variables as $variable) {
            $values[] = (string) ($variable->server_value ?? $variable->default_value ?? '');
            $values[] = (string) ($variable->env_variable ?? '');
        }

        $haystack = strtolower(implode("\n", $values));

        $game = 'generic';
        if (Str::contains($haystack, ['minecraft', 'paper', 'spigot', 'purpur', 'fabric', 'forge', 'quilt', 'neoforge', 'bukkit', 'velocity', 'waterfall', 'bungeecord'])) {
            $game = 'minecraft';
        } elseif (Str::contains($haystack, ['hytale'])) {
            $game = 'hytale';
        } elseif (Str::contains($haystack, ['ark survival ascended'])) {
            $game = 'ark-survival-ascended';
        } elseif (Str::contains($haystack, ['ark: survival evolved', 'ark survival evolved', 'arkse'])) {
            $game = 'ark-survival-evolved';
        } elseif (Str::contains($haystack, ['garrys mod', 'garry\'s mod', 'gmod'])) {
            $game = 'garrys-mod';
        } elseif (Str::contains($haystack, ['arma reforger'])) {
            $game = 'arma-reforger';
        } elseif (Str::contains($haystack, ['arma 3'])) {
            $game = 'arma-3';
        } elseif (Str::contains($haystack, ['tmodloader', 'terraria'])) {
            $game = 'tmodloader';
        } elseif (Str::contains($haystack, ['valheim'])) {
            $game = 'valheim';
        } elseif (Str::contains($haystack, ['v rising', 'vrising'])) {
            $game = 'v-rising';
        } elseif (Str::contains($haystack, ['unturned'])) {
            $game = 'unturned';
        } elseif (Str::contains($haystack, ['windrose'])) {
            $game = 'windrose';
        }

        if (($modsOverride['enabled'] ?? false) && !empty($modsOverride['game'])) {
            $game = (string) $modsOverride['game'];
        }

        $loader = null;
        foreach (['fabric', 'quilt', 'neoforge', 'forge', 'purpur', 'paper', 'spigot', 'bukkit', 'velocity', 'waterfall', 'bungeecord'] as $candidate) {
            if (Str::contains($haystack, $candidate)) {
                $loader = $candidate;
                break;
            }
        }

        $gameVersion = $this->detectGameVersion($server, $haystack, $game);

        return [
            'game' => $game,
            'loader' => $loader,
            'game_version' => $gameVersion,
            'server_name' => $server->name,
            'egg_name' => $eggName,
            'nest_name' => $nestName,
            'egg_id' => (int) optional($server->egg)->id,
            'mods_override' => $modsOverride,
        ];
    }

    private function detectProviderSupport(array $context): array
    {
        $game = $context['game'] ?? 'generic';
        $loader = strtolower((string) ($context['loader'] ?? ''));
        $eggName = strtolower((string) ($context['egg_name'] ?? ''));
        $nestName = strtolower((string) ($context['nest_name'] ?? ''));
        $modsOverride = $context['mods_override'] ?? [];

        $supportedKinds = [];
        $catalogSearchProvider = null;
        $sources = [];

        if ($game === 'minecraft') {
            if (in_array($loader, ['paper', 'spigot', 'bukkit', 'purpur', 'velocity', 'waterfall', 'bungeecord'], true)) {
                $catalogSearchProvider = 'modrinth';
                $supportedKinds = ['plugin'];
                $sources[] = $this->providerSource('modrinth', 'Modrinth', true, true, 'https://modrinth.com/plugins', 'Best fit for Paper, Bukkit, Spigot, Purpur, and proxy plugins.');
            } elseif (in_array($loader, ['forge', 'neoforge', 'fabric', 'quilt'], true)) {
                $catalogSearchProvider = 'modrinth';
                $supportedKinds = ['mod'];
                $sources[] = $this->providerSource('modrinth', 'Modrinth', true, true, 'https://modrinth.com/mods', 'Supports modern Minecraft mod loaders.');
                $sources[] = $this->providerSource('curseforge', 'CurseForge', false, true, 'https://www.curseforge.com/minecraft', 'Useful for packs or projects not published on Modrinth.');
            } elseif (Str::contains($eggName, ['bedrock'])) {
                $supportedKinds = [];
            } elseif (Str::contains($eggName, ['vanilla'])) {
                $catalogSearchProvider = 'modrinth';
                $supportedKinds = ['datapack'];
                $sources[] = $this->providerSource('modrinth', 'Modrinth', true, true, 'https://modrinth.com/datapacks', 'Best fit for vanilla datapacks.');
            } else {
                $catalogSearchProvider = 'modrinth';
                $supportedKinds = ['mod', 'plugin', 'datapack'];
                $sources[] = $this->providerSource('modrinth', 'Modrinth', true, true, 'https://modrinth.com', 'General Minecraft catalog support.');
            }
        } elseif ($game === 'hytale' || Str::contains($nestName, 'hytale')) {
            $catalogSearchProvider = 'nexus';
            $supportedKinds = ['mod', 'plugin'];
            $sources[] = $this->providerSource('nexus', 'Nexus Mods', true, true, 'https://www.nexusmods.com/', 'Native search is available, while direct downloads depend on Nexus permissions for the file.');
            $sources[] = $this->providerSource('curseforge', 'CurseForge', false, true, 'https://www.curseforge.com/', 'Primary external catalog to source Hytale content from.');
        } elseif (Str::contains($eggName, ['bepinex']) || in_array($game, ['valheim', 'v-rising'], true)) {
            $catalogSearchProvider = 'thunderstore';
            $supportedKinds = ['plugin', 'mod'];
            $sources[] = $this->providerSource('thunderstore', 'Thunderstore', true, true, 'https://thunderstore.io/', 'Native search is available for supported Thunderstore communities.');
            $sources[] = $this->providerSource('nexus', 'Nexus Mods', true, true, 'https://www.nexusmods.com/', 'Alternative source for BepInEx games when a Nexus game page exists.');
        } elseif (in_array($game, ['arma-3', 'arma-reforger', 'garrys-mod', 'unturned', 'ark-survival-evolved', 'tmodloader'], true)) {
            $supportedKinds = ['mod'];
            $sources[] = $this->providerSource('steam-workshop', 'Steam Workshop', false, false, 'https://steamcommunity.com/workshop/', 'These servers usually manage content through Workshop IDs or platform-specific installers.');
        } elseif ($game === 'ark-survival-ascended') {
            $supportedKinds = ['mod'];
            $sources[] = $this->providerSource('curseforge', 'CurseForge', false, true, 'https://www.curseforge.com/ark-survival-ascended', 'Ark Survival Ascended mods are commonly distributed through CurseForge.');
        } elseif ($game === 'windrose') {
            $catalogSearchProvider = 'nexus';
            $supportedKinds = ['mod'];
            $sources[] = $this->providerSource('nexus', 'Nexus Mods', true, true, 'https://www.nexusmods.com/', 'Windrose content is commonly distributed through Nexus Mods.');
        }

        if (($modsOverride['enabled'] ?? false) === true) {
            if (!empty($modsOverride['supported_kinds']) && is_array($modsOverride['supported_kinds'])) {
                $supportedKinds = array_values(array_unique(array_filter($modsOverride['supported_kinds'])));
            }

            if (!empty($modsOverride['catalog_search_provider'])) {
                $catalogSearchProvider = (string) $modsOverride['catalog_search_provider'];
            }

            if (!empty($modsOverride['sources']) && is_array($modsOverride['sources'])) {
                $sources = array_values(array_filter(array_map(function (string $source) {
                    return $this->providerTemplate($source);
                }, $modsOverride['sources'])));
            }
        }

        $supported = !empty($supportedKinds);

        return [
            'supported' => $supported,
            'direct_url' => $supported && collect($sources)->contains(fn (array $source) => (bool) ($source['supports_install'] ?? false)),
            'catalog_search_provider' => $catalogSearchProvider,
            'supported_kinds' => array_values(array_unique($supportedKinds)),
            'sources' => $sources,
        ];
    }

    private function providerSource(
        string $key,
        string $name,
        bool $supportsSearch,
        bool $supportsInstall,
        string $externalUrl,
        string $notes
    ): array {
        return [
            'key' => $key,
            'name' => $name,
            'supports_search' => $supportsSearch,
            'supports_install' => $supportsInstall,
            'external_url' => $externalUrl,
            'notes' => $notes,
        ];
    }

    private function providerTemplate(string $key): ?array
    {
        return match ($key) {
            'modrinth' => $this->providerSource('modrinth', 'Modrinth', true, true, 'https://modrinth.com', 'Integrated catalog search is available.'),
            'curseforge' => $this->providerSource('curseforge', 'CurseForge', false, true, 'https://www.curseforge.com/', 'Use direct downloads or open CurseForge search.'),
            'nexus' => $this->providerSource('nexus', 'Nexus Mods', true, true, 'https://www.nexusmods.com/', 'Native search is available; direct downloads depend on Nexus permissions.'),
            'thunderstore' => $this->providerSource('thunderstore', 'Thunderstore', true, true, 'https://thunderstore.io/', 'Native search is available for supported communities.'),
            'steam-workshop' => $this->providerSource('steam-workshop', 'Steam Workshop', false, false, 'https://steamcommunity.com/workshop/', 'Workshop content usually needs IDs or platform-specific installers.'),
            default => null,
        };
    }

    private function providerSearchUrl(string $provider, string $query, string $contentType, array $context): ?string
    {
        $encoded = rawurlencode($query);

        return match ($provider) {
            'curseforge' => 'https://www.curseforge.com/search?class=' . rawurlencode($contentType) . '&search=' . $encoded,
            'nexus' => 'https://www.nexusmods.com/search/?gsearch=' . $encoded,
            'thunderstore' => 'https://thunderstore.io/package/?q=' . $encoded,
            'steam-workshop' => 'https://steamcommunity.com/workshop/browse/?searchtext=' . $encoded,
            default => null,
        };
    }

    private function providerSearchMessage(string $provider): string
    {
        return match ($provider) {
            'curseforge' => 'CurseForge search is available as an external search link from the panel.',
            'nexus' => 'Nexus search is enabled, but some files still require manual site downloads depending on Nexus permissions.',
            'thunderstore' => 'Thunderstore search is enabled for supported communities.',
            'steam-workshop' => 'Steam Workshop search is available as an external search link from the panel.',
            default => 'Catalog search is currently supported only for servers that use Modrinth-backed content.',
        };
    }

    private function searchModrinth(string $query, string $contentType, string $loader, string $gameVersion, int $limit): array
    {
        $facets = [['project_type:mod']];
        $category = $this->resolveSearchCategory($contentType, $loader);
        if ($category !== null) {
            $facets[] = ["categories:{$category}"];
        }

        if ($gameVersion !== '') {
            $facets[] = ["versions:{$gameVersion}"];
        }

        $response = Http::timeout(30)
            ->retry(2, 250)
            ->get('https://api.modrinth.com/v2/search', [
                'query' => $query,
                'limit' => $limit,
                'index' => 'downloads',
                'facets' => json_encode($facets, JSON_UNESCAPED_SLASHES),
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Modrinth search failed: ' . $response->body());
        }

        $hits = Arr::get($response->json(), 'hits', []);
        $results = [];

        foreach ($hits as $hit) {
            $latest = $this->resolveLatestCompatibleVersion(
                (string) ($hit['project_id'] ?? ''),
                $loader,
                $gameVersion
            );

            $results[] = [
                'project_id' => $hit['project_id'] ?? null,
                'slug' => $hit['slug'] ?? null,
                'external_url' => !empty($hit['slug']) ? 'https://modrinth.com/project/' . $hit['slug'] : null,
                'title' => $hit['title'] ?? 'Unknown project',
                'description' => $hit['description'] ?? '',
                'author' => $hit['author'] ?? '',
                'icon_url' => $hit['icon_url'] ?? null,
                'downloads' => $hit['downloads'] ?? 0,
                'categories' => $hit['categories'] ?? [],
                'display_categories' => $hit['display_categories'] ?? [],
                'versions' => $hit['versions'] ?? [],
                'latest_version' => $hit['latest_version'] ?? null,
                'latest_compatible_version' => $latest,
                'installable' => !empty($latest['download_url']),
            ];
        }

        return $results;
    }

    private function searchNexus(string $query, string $contentType, array $context, int $limit): array
    {
        $game = $context['game'] ?? 'generic';
        $gameFilter = $this->nexusGameFilter($game);
        $raw = $this->nexusGraphql(
            <<<'GRAPHQL'
query SearchNexusMods($filter: ModsFilter, $sort: [ModsSort!], $count: Int) {
  mods(filter: $filter, sort: $sort, count: $count) {
    nodes {
      name
      summary
      thumbnailUrl
      pictureUrl
      version
      downloads
      endorsements
      directDownloadEnabled
      modId
      uid
      updatedAt
      game {
        id
        name
        domainName
      }
      uploader {
        name
      }
    }
  }
}
GRAPHQL,
            [
                'filter' => array_filter([
                    'op' => 'AND',
                    'name' => [
                        ['value' => $query, 'op' => 'WILDCARD'],
                    ],
                    ...$gameFilter,
                ]),
                'sort' => [
                    ['relevance' => ['direction' => 'DESC']],
                    ['endorsements' => ['direction' => 'DESC']],
                    ['updatedAt' => ['direction' => 'DESC']],
                ],
                'count' => $limit,
            ]
        );

        $nodes = Arr::get($raw, 'data.mods.nodes', []);
        $results = [];

        foreach ($nodes as $node) {
            $gameDomain = (string) Arr::get($node, 'game.domainName', '');
            $gameId = (string) Arr::get($node, 'game.id', '');
            $modId = (string) ($node['modId'] ?? '');
            $latest = $this->resolveLatestNexusVersion($gameDomain, $gameId, $modId);

            $results[] = [
                'project_id' => null,
                'slug' => null,
                'external_url' => ($gameDomain !== '' && $modId !== '') ? sprintf('https://www.nexusmods.com/%s/mods/%s', $gameDomain, $modId) : 'https://www.nexusmods.com/search/?gsearch=' . rawurlencode($query),
                'title' => $node['name'] ?? 'Unknown Nexus mod',
                'description' => $node['summary'] ?? '',
                'author' => Arr::get($node, 'uploader.name', ''),
                'icon_url' => $node['thumbnailUrl'] ?? ($node['pictureUrl'] ?? null),
                'downloads' => (int) ($node['downloads'] ?? 0),
                'categories' => [$contentType, 'nexus'],
                'display_categories' => array_values(array_filter([
                    (string) Arr::get($node, 'game.name', ''),
                    'Nexus Mods',
                ])),
                'versions' => array_values(array_filter([(string) ($node['version'] ?? '')])),
                'latest_version' => $latest['version_number'] ?? ($node['version'] ?? null),
                'latest_compatible_version' => $latest,
                'installable' => !empty($latest['download_url']),
            ];
        }

        return $results;
    }

    private function searchThunderstore(string $query, string $contentType, array $context, int $limit): array
    {
        $community = $this->thunderstoreCommunity($context['game'] ?? 'generic', $context['server_name'] ?? '');
        if ($community === null) {
            return [];
        }

        $response = Http::timeout(45)
            ->retry(2, 250)
            ->get(sprintf('https://thunderstore.io/c/%s/api/v1/package/', rawurlencode($community)));

        if ($response->failed()) {
            throw new \RuntimeException('Thunderstore search failed: ' . $response->body());
        }

        $items = $response->json();
        if (!is_array($items)) {
            return [];
        }

        $results = [];

        foreach ($items as $item) {
            if (!$this->matchesThunderstoreResult($item, $query, null)) {
                continue;
            }

            $versions = array_values(array_filter($item['versions'] ?? [], fn (array $version) => !empty($version['download_url']) && !empty($version['is_active'])));
            $latest = $versions[0] ?? [];

            $results[] = [
                'project_id' => null,
                'slug' => null,
                'external_url' => $item['package_url'] ?? null,
                'title' => $item['name'] ?? ($item['full_name'] ?? 'Unknown Thunderstore package'),
                'description' => $latest['description'] ?? '',
                'author' => $item['owner'] ?? '',
                'icon_url' => $latest['icon'] ?? null,
                'downloads' => isset($item['versions']) ? (int) array_sum(array_map(fn (array $version) => (int) ($version['downloads'] ?? 0), $item['versions'])) : 0,
                'categories' => (array) ($item['categories'] ?? []),
                'display_categories' => array_values(array_unique(array_merge((array) ($item['categories'] ?? []), ['Thunderstore']))),
                'versions' => array_values(array_filter(array_map(fn (array $version) => $version['version_number'] ?? null, array_slice($versions, 0, 5)))),
                'latest_version' => $latest['version_number'] ?? null,
                'latest_compatible_version' => [
                    'id' => $latest['uuid4'] ?? ($latest['full_name'] ?? null),
                    'name' => $item['name'] ?? null,
                    'version_number' => $latest['version_number'] ?? null,
                    'version_type' => null,
                    'loaders' => [],
                    'game_versions' => [$community],
                    'published' => $latest['date_created'] ?? ($item['date_updated'] ?? null),
                    'download_url' => $latest['download_url'] ?? null,
                    'filename' => (($item['owner'] ?? 'package') . '-' . ($item['name'] ?? 'mod') . '-' . ($latest['version_number'] ?? 'latest') . '.zip'),
                    'size' => isset($latest['file_size']) ? (int) $latest['file_size'] : null,
                ],
                'installable' => !empty($latest['download_url']),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    private function nexusGraphql(string $query, array $variables = []): array
    {
        $request = Http::timeout(30)
            ->retry(2, 250)
            ->acceptJson()
            ->asJson();

        if ($apiKey = trim((string) config('services.nexus.api_key'))) {
            $request = $request->withHeaders(['apikey' => $apiKey]);
        }

        $response = $request->post('https://api.nexusmods.com/v2/graphql', [
            'query' => $query,
            'variables' => (object) $variables,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Nexus GraphQL request failed: ' . $response->body());
        }

        $json = $response->json();
        if (!empty($json['errors'])) {
            throw new \RuntimeException('Nexus GraphQL returned errors: ' . json_encode($json['errors']));
        }

        return is_array($json) ? $json : [];
    }

    private function resolveLatestNexusVersion(string $gameDomain, string $gameId, string $modId): ?array
    {
        if ($gameId === '' || $modId === '') {
            return null;
        }

        $raw = $this->nexusGraphql(
            <<<'GRAPHQL'
query NexusModFiles($modId: ID!, $gameId: ID!) {
  modFiles(modId: $modId, gameId: $gameId) {
    fileId
    name
    version
    date
    description
    uri
    primary
    sizeInBytes
  }
}
GRAPHQL,
            [
                'modId' => $modId,
                'gameId' => $gameId,
            ]
        );

        $files = Arr::get($raw, 'data.modFiles', []);
        if (!is_array($files) || empty($files)) {
            return null;
        }

        usort($files, function (array $left, array $right) {
            return [(int) ($right['primary'] ?? 0), (int) ($right['date'] ?? 0), (int) ($right['fileId'] ?? 0)]
                <=> [(int) ($left['primary'] ?? 0), (int) ($left['date'] ?? 0), (int) ($left['fileId'] ?? 0)];
        });

        $file = $files[0];

        return [
            'id' => isset($file['fileId']) ? (string) $file['fileId'] : null,
            'name' => $file['name'] ?? null,
            'version_number' => $file['version'] ?? null,
            'version_type' => null,
            'loaders' => [],
            'game_versions' => [],
            'published' => isset($file['date']) ? date(DATE_ATOM, (int) $file['date']) : null,
            'download_url' => $this->resolveNexusDownloadUrl($gameDomain, $modId, (string) ($file['fileId'] ?? '')),
            'filename' => $file['name'] ?? null,
            'size' => isset($file['sizeInBytes']) ? (int) $file['sizeInBytes'] : null,
        ];
    }

    private function resolveNexusDownloadUrl(string $gameDomain, string $modId, string $fileId): ?string
    {
        if ($gameDomain === '' || $modId === '' || $fileId === '') {
            return null;
        }

        $request = Http::timeout(20)
            ->retry(1, 200)
            ->acceptJson();

        if ($apiKey = trim((string) config('services.nexus.api_key'))) {
            $request = $request->withHeaders(['apikey' => $apiKey]);
        }

        $response = $request->get(sprintf(
            'https://api.nexusmods.com/v1/games/%s/mods/%s/files/%s/download_link.json',
            rawurlencode($gameDomain),
            rawurlencode($modId),
            rawurlencode($fileId)
        ));

        if ($response->failed()) {
            return null;
        }

        $links = $response->json();
        if (!is_array($links)) {
            return null;
        }

        $first = Arr::first($links, fn (array $link) => !empty($link['URI'])) ?? Arr::first($links, fn (array $link) => !empty($link['uri']));

        return $first['URI'] ?? ($first['uri'] ?? null);
    }

    private function nexusGameFilter(string $game): array
    {
        $domain = $this->nexusGameDomain($game);
        if ($domain !== null) {
            return [
                'gameDomainName' => [
                    ['value' => $domain, 'op' => 'EQUALS'],
                ],
            ];
        }

        if ($game === 'generic') {
            return [];
        }

        return [
            'gameName' => [
                ['value' => Str::replace('-', ' ', $game), 'op' => 'WILDCARD'],
            ],
        ];
    }

    private function nexusGameDomain(string $game): ?string
    {
        return match ($game) {
            'windrose' => 'windrose',
            'valheim' => 'valheim',
            'v-rising' => 'vrising',
            'hytale' => 'hytale',
            default => null,
        };
    }

    private function thunderstoreCommunity(string $game, string $serverName = ''): ?string
    {
        return match ($game) {
            'valheim' => 'valheim',
            'v-rising' => 'v-rising',
            default => Str::contains(strtolower($serverName), 'valheim') ? 'valheim' : (Str::contains(strtolower($serverName), ['v rising', 'vrising']) ? 'v-rising' : null),
        };
    }

    private function matchesThunderstoreResult(array $item, string $query, ?string $community): bool
    {
        $haystack = strtolower(implode("\n", array_filter([
            (string) ($item['name'] ?? ''),
            (string) ($item['full_name'] ?? ''),
            (string) ($item['owner'] ?? ''),
            (string) Arr::get($item, 'latest.description', ''),
            (string) Arr::get($item, 'versions.0.description', ''),
            implode(' ', (array) ($item['categories'] ?? [])),
        ])));

        foreach (preg_split('/\s+/', strtolower(trim($query))) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (!Str::contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private function candidateDirectories(array $context, array $providers): array
    {
        $directories = [];
        $supportedKinds = $providers['supported_kinds'] ?? [];

        if (in_array('mod', $supportedKinds, true)) {
            $directories[] = ['kind' => 'mod', 'label' => 'Mods', 'path' => '/mods'];
        }

        if (in_array('plugin', $supportedKinds, true)) {
            $directories[] = ['kind' => 'plugin', 'label' => 'Plugins', 'path' => '/plugins'];
        }

        if ($context['game'] === 'hytale') {
            $directories[] = ['kind' => 'plugin', 'label' => 'Server Plugins', 'path' => '/Server/plugins'];
        }

        if (Str::contains(strtolower((string) ($context['egg_name'] ?? '')), 'bepinex') || in_array($context['game'], ['valheim', 'v-rising'], true)) {
            $directories[] = ['kind' => 'plugin', 'label' => 'BepInEx Plugins', 'path' => '/BepInEx/plugins'];
        }

        if (in_array('datapack', $supportedKinds, true)) {
            $directories[] = ['kind' => 'datapack', 'label' => 'Datapacks', 'path' => '/world/datapacks'];
            $directories[] = ['kind' => 'datapack', 'label' => 'Nether Datapacks', 'path' => '/world_nether/datapacks'];
            $directories[] = ['kind' => 'datapack', 'label' => 'End Datapacks', 'path' => '/world_the_end/datapacks'];
        }

        $unique = [];
        foreach ($directories as $directory) {
            $unique[$directory['kind'] . ':' . $directory['path']] = $directory;
        }

        return array_values($unique);
    }

    private function mapInstalledEntry(array $directory, array $entry, string $rootPath): ?array
    {
        $name = (string) ($entry['name'] ?? '');
        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        $isFile = (bool) ($entry['file'] ?? $entry['is_file'] ?? false);
        $kind = $directory['kind'];

        if ($kind === 'datapack') {
            if ($isFile && !Str::endsWith(strtolower($name), '.zip')) {
                return null;
            }
        } elseif (!$isFile || !Str::endsWith(strtolower($name), '.jar')) {
            return null;
        }

        return [
            'kind' => $kind,
            'directory' => $rootPath,
            'path' => rtrim($rootPath, '/') . '/' . ltrim($name, '/'),
            'filename' => $name,
            'display_name' => $this->inferDisplayName($name),
            'version' => $this->inferVersion($name),
            'size' => (int) ($entry['size'] ?? 0),
            'modified_at' => $entry['modified_at'] ?? null,
            'is_file' => $isFile,
        ];
    }

    private function inferDisplayName(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[-_+. ]?(?:mc)?\d[\w.\-+]*$/i', '', $name) ?: $name;
        $name = str_replace(['_', '.', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', trim($name)) ?: pathinfo($filename, PATHINFO_FILENAME);

        return Str::title($name);
    }

    private function inferVersion(string $filename): ?string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        if (preg_match('/((?:mc)?\d[\w.\-+]+)$/i', $name, $match)) {
            return $match[1];
        }

        return null;
    }

    private function resolveSearchCategory(string $contentType, string $loader): ?string
    {
        return match ($contentType) {
            'plugin' => $loader !== '' ? $loader : 'paper',
            'datapack' => 'datapack',
            default => $loader !== '' ? $loader : null,
        };
    }

    private function resolveLatestCompatibleVersion(string $projectId, string $loader, string $gameVersion): ?array
    {
        $versions = $this->fetchProjectVersions($projectId, $loader, $gameVersion);

        return $versions[0] ?? null;
    }

    private function fetchProjectVersions(string $projectId, string $loader, string $gameVersion): array
    {
        if ($projectId === '') {
            return [];
        }

        $query = ['include_changelog' => 'false'];
        if ($loader !== '') {
            $query['loaders'] = json_encode([$loader], JSON_UNESCAPED_SLASHES);
        }
        if ($gameVersion !== '') {
            $query['game_versions'] = json_encode([$gameVersion], JSON_UNESCAPED_SLASHES);
        }

        $response = Http::timeout(30)->retry(2, 250)->get("https://api.modrinth.com/v2/project/{$projectId}/version", $query);

        if ($response->failed()) {
            return [];
        }

        $versions = $response->json();
        if (!is_array($versions)) {
            return [];
        }

        if (empty($versions) && ($loader !== '' || $gameVersion !== '')) {
            return $this->fetchProjectVersions($projectId, '', '');
        }

        return array_values(array_filter(array_map(function (array $version) {
            $files = array_values(array_filter($version['files'] ?? [], fn (array $file) => !empty($file['url'])));
            $primary = Arr::first($files, fn (array $file) => !empty($file['primary'])) ?? Arr::first($files);
            if (!$primary) {
                return null;
            }

            return [
                'id' => $version['id'] ?? null,
                'name' => $version['name'] ?? null,
                'version_number' => $version['version_number'] ?? null,
                'version_type' => $version['version_type'] ?? null,
                'loaders' => $version['loaders'] ?? [],
                'game_versions' => $version['game_versions'] ?? [],
                'published' => $version['date_published'] ?? ($version['published'] ?? null),
                'download_url' => $primary['url'] ?? null,
                'filename' => $primary['filename'] ?? null,
                'size' => $primary['size'] ?? null,
            ];
        }, $versions)));
    }

    private function defaultDirectoryForKind(array $context, string $contentType): string
    {
        return match ($contentType) {
            'plugin' => '/plugins',
            'datapack' => '/world/datapacks',
            default => '/mods',
        };
    }

    private function detectGameVersion(Server $server, string $haystack, string $game): ?string
    {
        $priorityMatches = [];

        foreach ($server->variables as $variable) {
            $env = strtolower((string) ($variable->env_variable ?? ''));
            $name = strtolower((string) ($variable->name ?? ''));
            $value = trim((string) ($variable->server_value ?? $variable->default_value ?? ''));

            if ($value === '') {
                continue;
            }

            $version = $this->extractVersionString($value, $game);
            if ($version === null) {
                continue;
            }

            if (Str::contains($env, ['minecraft_version', 'mc_version', 'game_version', 'version', 'jarfile', 'server_jarfile', 'download_url', 'modpack', 'release'])
                || Str::contains($name, ['minecraft version', 'game version', 'version', 'jar', 'download'])) {
                return $version;
            }

            $priorityMatches[] = $version;
        }

        if ($haystackVersion = $this->extractVersionString($haystack, $game)) {
            return $haystackVersion;
        }

        return $priorityMatches[0] ?? null;
    }

    private function extractVersionString(string $value, string $game): ?string
    {
        if ($game === 'minecraft' && preg_match('/\b1\.\d{1,2}(?:\.\d{1,2})?\b/', $value, $match)) {
            return $match[0];
        }

        if (preg_match('/\b\d+(?:\.\d+){1,3}\b/', $value, $match)) {
            return $match[0];
        }

        return null;
    }
}

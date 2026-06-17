<?php

namespace Pterodactyl\Services\ServerMods;

use Pterodactyl\Models\Egg;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

class EggModSupportService
{
    private const KEY_PREFIX = 'mods:egg:';

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function getOverride(Egg|int $egg): array
    {
        $id = $egg instanceof Egg ? $egg->id : $egg;
        $raw = (string) $this->settings->get($this->key($id), '');
        if ($raw === '') {
            return $this->defaultOverride();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->defaultOverride();
        }

        return array_merge($this->defaultOverride(), [
            'enabled' => (bool) ($decoded['enabled'] ?? false),
            'game' => $this->normalizeNullableString($decoded['game'] ?? null),
            'catalog_search_provider' => $this->normalizeNullableString($decoded['catalog_search_provider'] ?? null),
            'supported_kinds' => $this->normalizeStringArray($decoded['supported_kinds'] ?? []),
            'sources' => $this->normalizeStringArray($decoded['sources'] ?? []),
        ]);
    }

    public function saveOverride(Egg $egg, array $data): array
    {
        $payload = [
            'enabled' => filter_var($data['mods_override_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'game' => $this->normalizeNullableString($data['mods_override_game'] ?? null),
            'catalog_search_provider' => $this->normalizeNullableString($data['mods_override_catalog_search_provider'] ?? null),
            'supported_kinds' => $this->normalizeStringArray($data['mods_override_supported_kinds'] ?? []),
            'sources' => $this->normalizeStringArray($data['mods_override_sources'] ?? []),
        ];

        $this->settings->set($this->key($egg->id), json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $payload;
    }

    public function sourceOptions(): array
    {
        return [
            'modrinth' => 'Modrinth',
            'curseforge' => 'CurseForge',
            'nexus' => 'Nexus Mods',
            'thunderstore' => 'Thunderstore',
            'steam-workshop' => 'Steam Workshop',
        ];
    }

    public function searchProviderOptions(): array
    {
        return [
            '' => 'No built-in search',
            'modrinth' => 'Modrinth',
            'nexus' => 'Nexus Mods',
            'thunderstore' => 'Thunderstore',
        ];
    }

    public function gameOptions(): array
    {
        return [
            '' => 'Auto detect',
            'minecraft' => 'Minecraft',
            'hytale' => 'Hytale',
            'windrose' => 'Windrose',
            'valheim' => 'Valheim',
            'v-rising' => 'V Rising',
            'ark-survival-evolved' => 'Ark: Survival Evolved',
            'ark-survival-ascended' => 'Ark: Survival Ascended',
            'arma-3' => 'Arma 3',
            'arma-reforger' => 'Arma Reforger',
            'garrys-mod' => 'Garrys Mod',
            'tmodloader' => 'tModLoader',
            'unturned' => 'Unturned',
            'generic' => 'Generic',
        ];
    }

    private function defaultOverride(): array
    {
        return [
            'enabled' => false,
            'game' => null,
            'catalog_search_provider' => null,
            'supported_kinds' => [],
            'sources' => [],
        ];
    }

    private function key(int $eggId): string
    {
        return self::KEY_PREFIX . $eggId;
    }

    private function normalizeStringArray(array|string|null $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_unique(array_filter(array_map(function ($item) {
            $item = trim((string) $item);

            return $item !== '' ? $item : null;
        }, $items))));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}

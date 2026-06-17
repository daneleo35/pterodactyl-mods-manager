import http from '@/api/http';

export interface ModContext {
    game: string;
    loader: string | null;
    game_version: string | null;
    server_name: string;
    egg_name: string;
    nest_name: string;
}

export interface ModDirectory {
    kind: 'mod' | 'plugin' | 'datapack';
    label: string;
    path: string;
    exists: boolean;
    entry_count: number;
}

export interface InstalledModItem {
    kind: 'mod' | 'plugin' | 'datapack';
    directory: string;
    path: string;
    filename: string;
    display_name: string;
    version: string | null;
    size: number;
    modified_at: string | null;
    is_file: boolean;
}

export interface ModProviderSource {
    key: string;
    name: string;
    supports_search: boolean;
    supports_install: boolean;
    external_url: string;
    notes: string;
}

export interface ModOverview {
    context: ModContext;
    directories: ModDirectory[];
    installed: InstalledModItem[];
    counts: {
        installed: number;
        mods: number;
        plugins: number;
        datapacks: number;
    };
    providers: {
        supported: boolean;
        direct_url: boolean;
        catalog_search_provider: string | null;
        supported_kinds: Array<'mod' | 'plugin' | 'datapack'>;
        sources: ModProviderSource[];
    };
}

export default async (uuid: string): Promise<ModOverview> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/mods`);

    return data;
};

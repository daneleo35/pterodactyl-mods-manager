import http from '@/api/http';

export interface ModCatalogVersion {
    id: string | null;
    name: string | null;
    version_number: string | null;
    version_type: string | null;
    loaders: string[];
    game_versions: string[];
    published: string | null;
    download_url: string | null;
    filename: string | null;
    size: number | null;
}

export interface ModCatalogResult {
    project_id: string | null;
    slug: string | null;
    external_url?: string | null;
    title: string;
    description: string;
    author: string;
    icon_url: string | null;
    downloads: number;
    categories: string[];
    display_categories: string[];
    versions: string[];
    latest_version: string | null;
    latest_compatible_version: ModCatalogVersion | null;
    installable: boolean;
}

export interface ModCatalogSearchResponse {
    provider: string;
    supported: boolean;
    search_url?: string | null;
    context: {
        game: string;
        loader: string | null;
        game_version: string | null;
        server_name: string;
    };
    filters: {
        query: string;
        content_type: string;
        loader: string;
        game_version: string;
    };
    results: ModCatalogResult[];
    message?: string;
}

export default async (
    uuid: string,
    params: {
        query: string;
        provider?: string;
        content_type: 'mod' | 'plugin' | 'datapack';
        loader?: string;
        game_version?: string;
        limit?: number;
    }
): Promise<ModCatalogSearchResponse> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/mods/search`, { params });

    return data;
};

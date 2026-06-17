import React, { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import { ServerContext } from '@/state/server';
import { httpErrorToHuman } from '@/api/http';
import getServerMods, { InstalledModItem, ModDirectory, ModOverview } from '@/api/server/mods/getServerMods';
import searchModCatalog, { ModCatalogResult, ModCatalogVersion } from '@/api/server/mods/searchModCatalog';
import installMod from '@/api/server/mods/installMod';
import getProjectVersions from '@/api/server/mods/getProjectVersions';
import Can from '@/components/elements/Can';
import deleteFiles from '@/api/server/files/deleteFiles';

type ContentKind = 'mod' | 'plugin' | 'datapack';
type InstalledItemMeta = {
    description?: string;
    icon_url?: string | null;
    latest_version?: string | null;
    update_available?: boolean;
};

const kindLabel = (kind: string) => (
    kind === 'plugin' ? 'Plugins' : kind === 'datapack' ? 'Datapacks' : 'Mods'
);

const kindSummary = (kind: ContentKind) => (
    kind === 'plugin' ? 'Server-side extensions and plugin packs.' : kind === 'datapack' ? 'Vanilla world content and gameplay packs.' : 'Mod files and gameplay overhauls for the server.'
);

const byteLabel = (size: number) => {
    if (size <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let value = size;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value >= 100 || unit === 0 ? 0 : 1)} ${units[unit]}`;
};

const providerVisual = (key: string) => {
    switch (key) {
        case 'modrinth':
            return { short: 'MR', tint: 'rgba(36, 208, 124, 0.18)', border: 'rgba(36, 208, 124, 0.45)' };
        case 'curseforge':
            return { short: 'CF', tint: 'rgba(245, 117, 41, 0.18)', border: 'rgba(245, 117, 41, 0.45)' };
        case 'nexus':
            return { short: 'NX', tint: 'rgba(255, 196, 77, 0.18)', border: 'rgba(255, 196, 77, 0.45)' };
        case 'thunderstore':
            return { short: 'TS', tint: 'rgba(96, 165, 250, 0.18)', border: 'rgba(96, 165, 250, 0.45)' };
        case 'steam-workshop':
            return { short: 'SW', tint: 'rgba(88, 101, 242, 0.18)', border: 'rgba(88, 101, 242, 0.45)' };
        default:
            return { short: 'DL', tint: 'rgba(148, 163, 184, 0.18)', border: 'rgba(148, 163, 184, 0.45)' };
    }
};

const kindVisual = (kind: string) => {
    switch (kind) {
        case 'plugin':
            return { short: 'PL', tint: 'rgba(59, 130, 246, 0.18)', border: 'rgba(59, 130, 246, 0.45)' };
        case 'datapack':
            return { short: 'DP', tint: 'rgba(234, 179, 8, 0.18)', border: 'rgba(234, 179, 8, 0.45)' };
        default:
            return { short: 'MD', tint: 'rgba(16, 185, 129, 0.18)', border: 'rgba(16, 185, 129, 0.45)' };
    }
};

const ModsContainer = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    const [overview, setOverview] = useState<ModOverview | null>(null);
    const [loadingOverview, setLoadingOverview] = useState(true);
    const [overviewError, setOverviewError] = useState('');

    const [query, setQuery] = useState('');
    const [contentType, setContentType] = useState<ContentKind>('mod');
    const [loader, setLoader] = useState('');
    const [gameVersion, setGameVersion] = useState('');
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState('');
    const [searchResults, setSearchResults] = useState<ModCatalogResult[]>([]);
    const [selectedKind, setSelectedKind] = useState<'all' | ContentKind>('all');

    const [installingKey, setInstallingKey] = useState<string | null>(null);
    const [installMessage, setInstallMessage] = useState('');
    const [installError, setInstallError] = useState('');

    const [directUrl, setDirectUrl] = useState('');
    const [directFilename, setDirectFilename] = useState('');
    const [directDirectory, setDirectDirectory] = useState('');

    const [expandedProject, setExpandedProject] = useState<string | null>(null);
    const [projectVersions, setProjectVersions] = useState<Record<string, ModCatalogVersion[]>>({});
    const [loadingVersions, setLoadingVersions] = useState<string | null>(null);
    const [itemActionKey, setItemActionKey] = useState<string | null>(null);
    const [activeProvider, setActiveProvider] = useState<string>('modrinth');
    const [installedMeta, setInstalledMeta] = useState<Record<string, InstalledItemMeta>>({});
    const [searchMeta, setSearchMeta] = useState<{ provider: string; supported: boolean; search_url?: string | null; message?: string } | null>(null);

    const canUseModrinth = overview?.providers.catalog_search_provider === 'modrinth';
    const canUseDirectUrl = overview?.providers.direct_url ?? false;
    const supportedKinds = overview?.providers.supported_kinds || [];
    const canAutoUpdate = canUseModrinth;
    const availableKinds = filterKinds(supportedKinds);

    const loadOverview = async () => {
        setLoadingOverview(true);
        setOverviewError('');

        try {
            const data = await getServerMods(uuid);
            setOverview(data);
            setLoader(data.context.loader || '');
            setGameVersion(data.context.game_version || '');
            setDirectDirectory(firstExistingDirectory(data.directories) || defaultDirectoryForKind(data.directories, data.providers.supported_kinds?.[0] || 'mod'));
            setActiveProvider(data.providers.catalog_search_provider || data.providers.sources?.[0]?.key || 'modrinth');
        } catch (error) {
            setOverviewError(httpErrorToHuman(error));
        } finally {
            setLoadingOverview(false);
        }
    };

    useEffect(() => {
        loadOverview();
    }, [uuid]);

    useEffect(() => {
        if (supportedKinds.length > 0 && !supportedKinds.includes(contentType)) {
            setContentType(supportedKinds[0]);
        }
    }, [contentType, supportedKinds]);

    useEffect(() => {
        if (!overview || !canUseModrinth) {
            return;
        }

        const pending = overview.installed.filter((item) => !installedMeta[item.path]);
        if (pending.length === 0) {
            return;
        }

        let cancelled = false;

        const run = async () => {
            const updates: Record<string, InstalledItemMeta> = {};

            for (const item of pending.slice(0, 24)) {
                try {
                    const response = await searchModCatalog(uuid, {
                        query: item.display_name,
                        provider: 'modrinth',
                        content_type: item.kind === 'plugin' ? 'plugin' : item.kind === 'datapack' ? 'datapack' : 'mod',
                        loader: loader.trim() || undefined,
                        game_version: gameVersion.trim() || undefined,
                        limit: 2,
                    });

                    const match = response.results[0];
                    if (!match) {
                        continue;
                    }

                    updates[item.path] = {
                        description: match.description || fallbackInstalledDescription(item),
                        icon_url: match.icon_url,
                        latest_version: match.latest_compatible_version?.version_number || match.latest_version,
                        update_available: hasVersionChanged(item.version, match.latest_compatible_version?.version_number || match.latest_version),
                    };
                } catch {
                    updates[item.path] = {
                        description: fallbackInstalledDescription(item),
                    };
                }
            }

            if (!cancelled && Object.keys(updates).length > 0) {
                setInstalledMeta((current) => ({ ...current, ...updates }));
            }
        };

        run();

        return () => {
            cancelled = true;
        };
    }, [overview, canUseModrinth, uuid, loader, gameVersion, installedMeta]);

    const groupedInstalled = useMemo(() => {
        const groups: Record<string, InstalledModItem[]> = {};
        (overview?.installed || []).forEach((item) => {
            if (selectedKind !== 'all' && item.kind !== selectedKind) {
                return;
            }

            const key = `${item.kind}:${item.directory}`;
            groups[key] = groups[key] || [];
            groups[key].push(item);
        });

        return groups;
    }, [overview, selectedKind]);

    const allInstalledItems = useMemo(
        () => Object.values(groupedInstalled).flat(),
        [groupedInstalled]
    );

    const runSearch = async () => {
        if (!query.trim()) return;

        setSearching(true);
        setSearchError('');
        setInstallMessage('');
        setSearchMeta(null);

        try {
            const response = await searchModCatalog(uuid, {
                query: query.trim(),
                provider: activeProvider,
                content_type: contentType,
                loader: loader.trim() || undefined,
                game_version: gameVersion.trim() || undefined,
            });

            setSearchResults(response.results || []);
            setSearchMeta({
                provider: response.provider,
                supported: response.supported,
                search_url: response.search_url,
                message: response.message,
            });
            if (response.message) {
                setSearchError(response.message);
            }
        } catch (error) {
            setSearchResults([]);
            setSearchError(httpErrorToHuman(error));
        } finally {
            setSearching(false);
        }
    };

    const installVersion = async (version: ModCatalogVersion, fallbackType: ContentKind) => {
        if (!version.download_url) return;

        const key = version.id || version.filename || version.download_url;
        setInstallingKey(key);
        setInstallError('');
        setInstallMessage('');

        try {
            const response = await installMod(uuid, {
                download_url: version.download_url,
                filename: version.filename || undefined,
                target_directory: targetDirectoryForKind(overview?.directories || [], fallbackType),
                content_type: fallbackType,
            });

            setInstallMessage(`Installed ${response.filename} to ${response.target_directory}.`);
            await loadOverview();
        } catch (error) {
            setInstallError(httpErrorToHuman(error));
        } finally {
            setInstallingKey(null);
        }
    };

    const loadVersions = async (projectId: string) => {
        if (projectVersions[projectId]) {
            setExpandedProject(expandedProject === projectId ? null : projectId);
            return;
        }

        setExpandedProject(projectId);
        setLoadingVersions(projectId);
        setInstallError('');

        try {
            const response = await getProjectVersions(uuid, projectId, {
                loader: loader.trim() || undefined,
                game_version: gameVersion.trim() || undefined,
            });
            setProjectVersions((current) => ({ ...current, [projectId]: response.versions || [] }));
        } catch (error) {
            setInstallError(httpErrorToHuman(error));
        } finally {
            setLoadingVersions(null);
        }
    };

    const installDirectUrl = async () => {
        if (!directUrl.trim()) return;

        setInstallingKey('direct-url');
        setInstallError('');
        setInstallMessage('');

        try {
            const response = await installMod(uuid, {
                download_url: directUrl.trim(),
                filename: directFilename.trim() || undefined,
                target_directory: directDirectory.trim() || undefined,
                content_type: contentType,
            });
            setInstallMessage(`Downloaded ${response.filename} to ${response.target_directory}.`);
            setDirectUrl('');
            setDirectFilename('');
            await loadOverview();
        } catch (error) {
            setInstallError(httpErrorToHuman(error));
        } finally {
            setInstallingKey(null);
        }
    };

    const removeInstalledItem = async (item: InstalledModItem) => {
        setItemActionKey(`remove:${item.path}`);
        setInstallError('');
        setInstallMessage('');

        try {
            await deleteFiles(uuid, item.directory, [item.filename]);
            setInstallMessage(`Removed ${item.filename} from ${item.directory}.`);
            await loadOverview();
        } catch (error) {
            setInstallError(httpErrorToHuman(error));
        } finally {
            setItemActionKey(null);
        }
    };

    const updateInstalledItem = async (item: InstalledModItem) => {
        if (!canAutoUpdate) {
            setInstallError('Automatic update lookup is currently supported for Minecraft content only.');
            return;
        }

        setItemActionKey(`update:${item.path}`);
        setInstallError('');
        setInstallMessage('');

        try {
            const response = await searchModCatalog(uuid, {
                query: item.display_name,
                content_type: item.kind === 'plugin' ? 'plugin' : item.kind === 'datapack' ? 'datapack' : 'mod',
                loader: loader.trim() || undefined,
                game_version: gameVersion.trim() || undefined,
                limit: 4,
            });

            const match = response.results.find((result) => result.latest_compatible_version?.download_url) || response.results[0];
            const version = match?.latest_compatible_version;
            if (!version?.download_url) {
                throw new Error(`No compatible Modrinth update was found for ${item.display_name}.`);
            }

            const installResponse = await installMod(uuid, {
                download_url: version.download_url,
                filename: version.filename || undefined,
                target_directory: item.directory,
                content_type: item.kind === 'plugin' ? 'plugin' : item.kind === 'datapack' ? 'datapack' : 'mod',
            });

            if (installResponse.filename !== item.filename) {
                await deleteFiles(uuid, item.directory, [item.filename]);
            }

            setInstallMessage(`Updated ${item.display_name} to ${installResponse.filename}.`);
            await loadOverview();
        } catch (error) {
            setInstallError(httpErrorToHuman(error));
        } finally {
            setItemActionKey(null);
        }
    };

    return (
        <ServerContentBlock title={'Mods'} fullWidth>
            {loadingOverview ? (
                <Spinner size={'large'} centered />
            ) : overviewError ? (
                <p css={tw`text-sm text-red-200`}>{overviewError}</p>
            ) : overview ? (
                <div css={tw`space-y-6`}>
                    <section css={tw`grid gap-4 xl:grid-cols-4`}>
                        {[
                            { label: 'Game', value: humanizeContextValue(overview.context.game || 'unknown'), detail: overview.context.nest_name || 'Detected from egg context' },
                            { label: 'Loader', value: humanizeContextValue(overview.context.loader || 'not detected'), detail: overview.context.egg_name || 'No loader metadata found yet' },
                            { label: 'Game Version', value: overview.context.game_version || 'not detected', detail: overview.context.game_version ? 'Pulled from startup variables or jar naming' : 'No version field was detected in startup yet' },
                            { label: 'Installed Items', value: String(overview.counts.installed), detail: `${overview.counts.mods} mods, ${overview.counts.plugins} plugins, ${overview.counts.datapacks} datapacks` },
                        ].map((stat) => (
                            <div key={stat.label} css={tw`rounded-2xl border border-neutral-600 bg-neutral-700/60 p-4 shadow-lg`}>
                                <p css={tw`text-xs uppercase tracking-wider text-neutral-400`}>{stat.label}</p>
                                <p css={tw`mt-3 text-2xl font-semibold text-neutral-100`}>{stat.value}</p>
                                <p css={tw`mt-2 text-xs text-neutral-400`}>{stat.detail}</p>
                            </div>
                        ))}
                    </section>

                    <section css={tw`grid gap-6 xl:grid-cols-12`}>
                        <aside css={tw`space-y-6 self-start xl:sticky xl:top-6 xl:col-span-4`}>
                            <section css={tw`rounded-3xl border border-neutral-600 bg-neutral-700/50 p-5 shadow-2xl`}>
                                <div>
                                    <p css={tw`text-xs uppercase tracking-wider text-cyan-300/80`}>Catalog</p>
                                    <h2 css={tw`mt-2 text-xl font-semibold text-neutral-100`}>Search Content</h2>
                                    <p css={tw`mt-2 text-sm leading-6 text-neutral-400`}>
                                        Pick a provider, search, and keep the results right here instead of further down the page.
                                    </p>
                                </div>

                                <div css={tw`mt-5 flex flex-wrap gap-2`}>
                                    {overview.providers.sources.length > 0 ? overview.providers.sources.map((source) => (
                                        <button
                                            key={source.key}
                                            type={'button'}
                                            css={tw`inline-flex items-center gap-2 rounded-full border px-3 py-2 text-sm text-neutral-100 transition hover:border-neutral-500 hover:bg-neutral-800/50`}
                                            style={{
                                                borderColor: activeProvider === source.key ? providerVisual(source.key).border : 'rgba(82, 82, 91, 0.9)',
                                                backgroundColor: activeProvider === source.key ? providerVisual(source.key).tint : 'rgba(23, 28, 39, 0.55)',
                                            }}
                                            onClick={() => {
                                                setActiveProvider(source.key);
                                                setSearchResults([]);
                                                setSearchError('');
                                                setSearchMeta(null);
                                            }}
                                        >
                                            <ProviderBadge providerKey={source.key} small />
                                            <span>{source.name}</span>
                                        </button>
                                    )) : (
                                        <div css={tw`rounded-2xl border border-yellow-700/50 bg-yellow-900/20 p-4 text-sm text-yellow-100`}>
                                            This egg does not look like a managed mod or plugin server, so the panel is hiding search tooling here.
                                        </div>
                                    )}
                                </div>

                                <div css={tw`mt-5 rounded-2xl border border-neutral-700 bg-neutral-900/40 p-4`}>
                                    <p css={tw`text-xs uppercase tracking-wider text-neutral-500`}>Supported content</p>
                                    <div css={tw`mt-3 flex flex-wrap gap-2`}>
                                        {supportedKinds.length > 0 ? supportedKinds.map((kind) => (
                                            <span key={kind} css={tw`rounded-full border border-neutral-600 bg-neutral-800/70 px-3 py-1 text-xs text-neutral-200`}>
                                                {kindLabel(kind)}
                                            </span>
                                        )) : (
                                            <span css={tw`text-sm text-neutral-400`}>None detected</span>
                                        )}
                                    </div>
                                </div>

                                {overview.providers.sources.length > 0 && (
                                    <div css={tw`mt-5 space-y-4`}>
                                        <div>
                                            <label css={tw`mb-2 block text-sm text-neutral-200`}>Search</label>
                                            <Input
                                                value={query}
                                                onChange={(e) => setQuery(e.currentTarget.value)}
                                                placeholder={'Search by name, feature, or author'}
                                                onKeyDown={(e) => e.key === 'Enter' && runSearch()}
                                            />
                                        </div>

                                        <div css={tw`grid gap-4 sm:grid-cols-2`}>
                                            <div>
                                                <label css={tw`mb-2 block text-sm text-neutral-200`}>Type</label>
                                                <select
                                                    css={tw`w-full rounded-2xl border-2 border-neutral-500 bg-neutral-600 px-4 py-3 text-sm text-neutral-100 shadow-inner`}
                                                    value={contentType}
                                                    onChange={(e) => setContentType(e.currentTarget.value as ContentKind)}
                                                >
                                                    {availableKinds.map((kind) => (
                                                        <option key={kind} value={kind}>{kindLabel(kind)}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label css={tw`mb-2 block text-sm text-neutral-200`}>Loader</label>
                                                <Input value={loader} onChange={(e) => setLoader(e.currentTarget.value)} placeholder={'fabric, forge, paper'} />
                                            </div>
                                            <div>
                                                <label css={tw`mb-2 block text-sm text-neutral-200`}>Game version</label>
                                                <Input value={gameVersion} onChange={(e) => setGameVersion(e.currentTarget.value)} placeholder={'Pulled from startup when available'} />
                                            </div>
                                            <div css={tw`flex items-end`}>
                                                <Button onClick={runSearch} isLoading={searching} css={tw`w-full rounded-2xl`}>
                                                    Search Catalog
                                                </Button>
                                            </div>
                                        </div>

                                        {searchError && <p css={tw`text-sm text-red-200`}>{searchError}</p>}
                                        <div css={tw`space-y-3`}>
                                            {searchMeta?.supported === false && searchMeta.search_url ? (
                                                <div css={tw`rounded-2xl border border-neutral-700 bg-neutral-900/60 p-4`}>
                                                    <p css={tw`text-sm text-neutral-200`}>{searchMeta.message || 'This provider opens in its own search page.'}</p>
                                                    <a href={searchMeta.search_url} target={'_blank'} rel={'noreferrer'} css={tw`mt-3 inline-flex text-sm font-medium text-cyan-300 hover:text-cyan-200`}>
                                                        Open provider search
                                                    </a>
                                                </div>
                                            ) : searchResults.length > 0 ? (
                                                <div css={tw`space-y-3`}>
                                                    {searchResults.map((result) => (
                                                        <SearchResultCard
                                                            key={result.project_id || result.slug || result.title}
                                                            result={result}
                                                            contentType={contentType}
                                                            expandedProject={expandedProject}
                                                            projectVersions={projectVersions}
                                                            loadingVersions={loadingVersions}
                                                            installingKey={installingKey}
                                                            onLoadVersions={loadVersions}
                                                            onInstallVersion={installVersion}
                                                        />
                                                    ))}
                                                </div>
                                            ) : (
                                                <div css={tw`rounded-2xl border border-neutral-700 bg-neutral-900/30 p-4 text-sm text-neutral-300`}>
                                                    Search results will appear directly under the search controls here.
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </section>

                            {!overview.providers.catalog_search_provider && canUseDirectUrl && (
                                <section css={tw`rounded-3xl border border-neutral-600 bg-neutral-700/40 p-5 shadow-xl`}>
                                    <h2 css={tw`text-lg font-semibold text-neutral-100`}>Direct Download</h2>
                                    <p css={tw`mt-1 text-sm leading-6 text-neutral-400`}>
                                        Useful for CurseForge, Nexus, Thunderstore, or any source that gives you a direct file URL.
                                    </p>

                                    <div css={tw`mt-5 space-y-4`}>
                                        <div>
                                            <label css={tw`mb-2 block text-sm text-neutral-200`}>Download URL</label>
                                            <Input value={directUrl} onChange={(e) => setDirectUrl(e.currentTarget.value)} placeholder={'https://example.com/mod.jar'} />
                                        </div>
                                        <div>
                                            <label css={tw`mb-2 block text-sm text-neutral-200`}>Filename override</label>
                                            <Input value={directFilename} onChange={(e) => setDirectFilename(e.currentTarget.value)} placeholder={'Optional custom filename'} />
                                        </div>
                                        <div>
                                            <label css={tw`mb-2 block text-sm text-neutral-200`}>Target directory</label>
                                            <Input
                                                value={directDirectory}
                                                onChange={(e) => setDirectDirectory(e.currentTarget.value)}
                                                placeholder={targetDirectoryForKind(overview.directories, contentType)}
                                            />
                                        </div>
                                        <Can action={'file.create'}>
                                            <Button
                                                isLoading={installingKey === 'direct-url'}
                                                onClick={installDirectUrl}
                                                css={tw`w-full rounded-2xl`}
                                            >
                                                Download to Server
                                            </Button>
                                        </Can>
                                    </div>
                                </section>
                            )}
                        </aside>

                        <div css={tw`space-y-6 xl:col-span-8`}>
                            <section css={tw`rounded-3xl border border-neutral-600 bg-neutral-700/40 p-5 shadow-2xl`}>
                                <div css={tw`flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between`}>
                                    <div>
                                        <h2 css={tw`text-2xl font-semibold text-neutral-100`}>Installed Content</h2>
                                        <p css={tw`mt-2 text-sm leading-6 text-neutral-400`}>
                                            Showing {allInstalledItems.length} detected items across the mod, plugin, and datapack folders that match this egg.
                                        </p>
                                    </div>

                                    <div css={tw`flex flex-wrap gap-2`}>
                                        {(['all', 'mod', 'plugin', 'datapack'] as const).map((kind) => (
                                            <Button
                                                key={kind}
                                                size={'xsmall'}
                                                isSecondary={selectedKind !== kind}
                                                onClick={() => setSelectedKind(kind)}
                                                css={tw`rounded-full`}
                                            >
                                                {kind === 'all' ? 'All content' : kindLabel(kind)}
                                            </Button>
                                        ))}
                                        <Button isSecondary size={'xsmall'} onClick={loadOverview} css={tw`rounded-full`}>
                                            Refresh
                                        </Button>
                                    </div>
                                </div>

                                {installMessage && <p css={tw`mt-4 text-sm text-green-200`}>{installMessage}</p>}
                                {installError && <p css={tw`mt-2 text-sm text-red-200`}>{installError}</p>}

                                <div css={tw`mt-5 space-y-6`}>
                                    {Object.keys(groupedInstalled).length === 0 ? (
                                        <div css={tw`rounded-2xl border border-neutral-700 bg-neutral-900/40 p-5 text-sm text-neutral-300`}>
                                            No supported mod content was found in the common directories yet.
                                        </div>
                                    ) : (
                                        Object.entries(groupedInstalled).map(([groupKey, items]) => (
                                            <div key={groupKey} css={tw`space-y-4`}>
                                                <div css={tw`flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between`}>
                                                    <div>
                                                        <p css={tw`text-base font-medium text-neutral-100`}>
                                                            {kindLabel(items[0].kind)} - {items[0].directory}
                                                        </p>
                                                        <p css={tw`text-sm text-neutral-400`}>
                                                            {items.length} item(s) in this folder.
                                                        </p>
                                                    </div>
                                                </div>

                                                <div css={tw`grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4`}>
                                                    {items.map((item) => (
                                                        <InstalledItemCard
                                                            key={item.path}
                                                            item={item}
                                                            meta={installedMeta[item.path]}
                                                            canAutoUpdate={canAutoUpdate}
                                                            itemActionKey={itemActionKey}
                                                            onUpdate={updateInstalledItem}
                                                            onRemove={removeInstalledItem}
                                                        />
                                                    ))}
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </section>

                            <section css={tw`rounded-3xl border border-neutral-600 bg-neutral-700/40 p-5 shadow-2xl`}>
                                <h2 css={tw`text-xl font-semibold text-neutral-100`}>Detected Directories</h2>
                                <p css={tw`mt-2 text-sm leading-6 text-neutral-400`}>
                                    These are the folders the panel checks for installed server content on this egg.
                                </p>
                                <div css={tw`mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3`}>
                                    {overview.directories.map((directory: ModDirectory) => (
                                        <div key={`${directory.kind}:${directory.path}`} css={tw`rounded-2xl border border-neutral-700 bg-neutral-900/50 p-4`}>
                                            <div css={tw`flex items-start gap-3`}>
                                                <KindBadge kind={directory.kind} />
                                                <div css={tw`min-w-0`}>
                                                    <p css={tw`text-sm font-medium text-neutral-100`}>{directory.label}</p>
                                                    <p css={tw`mt-1 text-xs text-neutral-400 break-all`}>{directory.path}</p>
                                                    <p css={tw`mt-3 text-xs`} style={{ color: directory.exists ? '#9ae6b4' : '#fbb6ce' }}>
                                                        {directory.exists ? `${directory.entry_count} item(s) found` : 'Directory not found'}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        </div>
                    </section>
                </div>
            ) : null}
        </ServerContentBlock>
    );
};

const ProviderBadge = ({ providerKey, small }: { providerKey: string; small?: boolean }) => {
    const visual = providerVisual(providerKey);

    return (
        <div
            css={small ? tw`flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full border text-[10px] font-semibold text-neutral-100` : tw`flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-2xl border text-sm font-semibold text-neutral-100`}
            style={{ backgroundColor: visual.tint, borderColor: visual.border }}
        >
            {visual.short}
        </div>
    );
};

const KindBadge = ({ kind }: { kind: string }) => {
    const visual = kindVisual(kind);

    return (
        <div
            css={tw`flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-2xl border text-xs font-semibold text-neutral-100`}
            style={{ backgroundColor: visual.tint, borderColor: visual.border }}
        >
            {visual.short}
        </div>
    );
};

const InstalledItemCard = ({
    item,
    meta,
    canAutoUpdate,
    itemActionKey,
    onUpdate,
    onRemove,
}: {
    item: InstalledModItem;
    meta?: InstalledItemMeta;
    canAutoUpdate: boolean;
    itemActionKey: string | null;
    onUpdate: (item: InstalledModItem) => void;
    onRemove: (item: InstalledModItem) => void;
}) => (
    <div css={tw`flex h-full flex-col rounded-3xl border border-neutral-700 bg-neutral-900/90 p-4 shadow-lg`}>
        <div css={tw`flex items-start gap-3`}>
            {meta?.icon_url ? (
                <img src={meta.icon_url} alt={item.display_name} css={tw`h-10 w-10 flex-shrink-0 rounded-2xl border border-neutral-700 object-cover`} />
            ) : (
                <KindBadge kind={item.kind} />
            )}
            <div css={tw`min-w-0`}>
                <p css={tw`text-sm font-semibold leading-6 text-neutral-100 line-clamp-2`}>{item.display_name}</p>
                <div css={tw`mt-1 flex items-center gap-2 text-xs text-neutral-400`}>
                    <span>{kindLabel(item.kind)} - {item.version || meta?.latest_version || 'Unknown version'}</span>
                    {meta?.update_available && <span title={'Update available'} css={tw`inline-flex h-2.5 w-2.5 rounded-full bg-yellow-400`} />}
                </div>
            </div>
        </div>

        <p css={tw`mt-4 text-sm leading-6 text-neutral-300`}>
            {meta?.description || fallbackInstalledDescription(item)}
        </p>

        <div css={tw`mt-4 space-y-1 text-xs text-neutral-500`}>
            <div>{byteLabel(item.size)}</div>
            <div css={tw`break-all`}>{item.filename}</div>
        </div>

        <div css={tw`mt-auto pt-4 flex flex-col gap-2`}>
            {canAutoUpdate && (
                <Can action={'file.create'}>
                    <Button
                        size={'xsmall'}
                        isSecondary
                        isLoading={itemActionKey === `update:${item.path}`}
                        onClick={() => onUpdate(item)}
                        css={tw`rounded-xl`}
                    >
                        Update
                    </Button>
                </Can>
            )}
            <Can action={'file.delete'}>
                <Button
                    size={'xsmall'}
                    color={'red'}
                    isSecondary
                    isLoading={itemActionKey === `remove:${item.path}`}
                    onClick={() => onRemove(item)}
                    css={tw`rounded-xl`}
                >
                    Remove
                </Button>
            </Can>
        </div>
    </div>
);

const SearchResultCard = ({
    result,
    contentType,
    expandedProject,
    projectVersions,
    loadingVersions,
    installingKey,
    onLoadVersions,
    onInstallVersion,
}: {
    result: ModCatalogResult;
    contentType: ContentKind;
    expandedProject: string | null;
    projectVersions: Record<string, ModCatalogVersion[]>;
    loadingVersions: string | null;
    installingKey: string | null;
    onLoadVersions: (projectId: string) => void;
    onInstallVersion: (version: ModCatalogVersion, fallbackType: ContentKind) => void;
}) => (
    <div css={tw`rounded-3xl border border-neutral-700 bg-neutral-900/90 p-4 shadow-lg`}>
        <div css={tw`flex items-start gap-3`}>
            {result.icon_url ? (
                <img src={result.icon_url} alt={result.title} css={tw`h-14 w-14 flex-shrink-0 rounded-2xl border border-neutral-700 object-cover`} />
            ) : (
                <KindBadge kind={contentType} />
            )}
            <div css={tw`min-w-0 flex-1`}>
                <p css={tw`text-base font-semibold text-neutral-100`}>{result.title}</p>
                <p css={tw`mt-1 text-xs text-neutral-400`}>
                    by {result.author || 'unknown'} - {result.downloads.toLocaleString()} downloads
                </p>
            </div>
        </div>

        <p css={tw`mt-4 text-sm leading-6 text-neutral-300`}>
            {result.description || fallbackSearchDescription(contentType, result.title)}
        </p>

        <div css={tw`mt-4 flex flex-wrap gap-2`}>
            {(result.display_categories || []).slice(0, 5).map((category) => (
                <span key={category} css={tw`rounded-full border border-neutral-700 bg-neutral-800/70 px-2.5 py-1 text-xs text-neutral-200`}>
                    {category}
                </span>
            ))}
        </div>

        {result.latest_compatible_version && (
            <p css={tw`mt-4 text-xs text-neutral-400`}>
                Latest compatible version: {result.latest_compatible_version.version_number || 'unknown'}
            </p>
        )}

        <div css={tw`mt-4 flex flex-col gap-2 sm:flex-row`}>
            <Can action={'file.create'}>
                <Button
                    disabled={!result.latest_compatible_version?.download_url || installingKey !== null}
                    isLoading={installingKey === (result.latest_compatible_version?.id || '')}
                    onClick={() => result.latest_compatible_version && onInstallVersion(result.latest_compatible_version, contentType)}
                    css={tw`rounded-xl`}
                >
                    Install Latest
                </Button>
            </Can>
            {result.external_url && (
                <a
                    href={result.external_url}
                    target={'_blank'}
                    rel={'noreferrer'}
                    css={tw`inline-flex items-center justify-center rounded-xl border border-neutral-600 bg-neutral-800 px-4 py-2 text-sm font-medium text-neutral-100 transition hover:border-cyan-400 hover:text-cyan-200`}
                >
                    Open Page
                </a>
            )}
            {result.project_id && (
                <Button
                    isSecondary
                    onClick={() => onLoadVersions(result.project_id!)}
                    isLoading={loadingVersions === result.project_id}
                    css={tw`rounded-xl`}
                >
                    {expandedProject === result.project_id ? 'Hide Versions' : 'Show Versions'}
                </Button>
            )}
        </div>

        {expandedProject === result.project_id && (
            <div css={tw`mt-4 space-y-2 border-t border-neutral-700 pt-4`}>
                {(projectVersions[result.project_id || ''] || []).slice(0, 8).map((version) => (
                    <div key={version.id || version.filename || version.download_url || `version-${result.project_id}`} css={tw`flex flex-col gap-3 rounded-2xl border border-neutral-700 bg-neutral-900/60 p-3`}>
                        <div>
                            <p css={tw`text-sm font-medium text-neutral-100`}>
                                {version.version_number || version.name || version.filename || 'Unnamed version'}
                            </p>
                            <p css={tw`mt-1 text-xs text-neutral-400`}>
                                {(version.loaders || []).join(', ') || 'No loader info'} - {(version.game_versions || []).slice(0, 3).join(', ') || 'No game version info'}
                            </p>
                        </div>
                        <Can action={'file.create'}>
                            <Button
                                size={'small'}
                                disabled={!version.download_url || installingKey !== null}
                                isLoading={installingKey === (version.id || version.filename || '')}
                                onClick={() => onInstallVersion(version, contentType)}
                                css={tw`self-start rounded-xl`}
                            >
                                Install
                            </Button>
                        </Can>
                    </div>
                ))}
            </div>
        )}
    </div>
);

const filterKinds = (kinds: Array<'mod' | 'plugin' | 'datapack'>) => {
    const filtered = kinds.filter((kind) => ['mod', 'plugin', 'datapack'].includes(kind));

    return filtered.length > 0 ? filtered : ['mod'];
};

const firstExistingDirectory = (directories: ModDirectory[]) => {
    const found = directories.find((directory) => directory.exists);

    return found?.path || '';
};

const targetDirectoryForKind = (directories: ModDirectory[], kind: ContentKind) => {
    const exact = directories.find((directory) => directory.kind === kind && directory.exists);
    if (exact) return exact.path;

    return defaultDirectoryForKind(directories, kind);
};

const defaultDirectoryForKind = (directories: ModDirectory[], kind: ContentKind) => {
    const exact = directories.find((directory) => directory.kind === kind);
    if (exact) return exact.path;

    return kind === 'plugin' ? '/plugins' : kind === 'datapack' ? '/world/datapacks' : '/mods';
};

const fallbackInstalledDescription = (item: InstalledModItem) => {
    const base = item.display_name.replace(/[_-]+/g, ' ').trim();

    return `${base} is installed on this server. ${kindSummary(item.kind)}`;
};

const fallbackSearchDescription = (kind: ContentKind, title: string) => (
    `${title} is listed as a ${kind === 'plugin' ? 'plugin' : kind === 'datapack' ? 'datapack' : 'mod'} project in the selected catalog.`
);

const humanizeContextValue = (value: string) => value.replace(/[-_]/g, ' ');

const hasVersionChanged = (current?: string | null, latest?: string | null) => {
    if (!current || !latest) {
        return false;
    }

    return normalizeVersion(current) !== normalizeVersion(latest);
};

const normalizeVersion = (value: string) => value.toLowerCase().replace(/[^a-z0-9.]+/g, '');

export default ModsContainer;

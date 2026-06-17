<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Server;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Services\ServerMods\ModManagerService;
use Pterodactyl\Http\Requests\Api\Client\Servers\Mods\GetModsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Mods\SearchModsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Mods\InstallModRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Mods\GetProjectVersionsRequest;

class ModController extends ClientApiController
{
    public function __construct(
        private ModManagerService $modManagerService,
    ) {
        parent::__construct();
    }

    public function index(GetModsRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse($this->modManagerService->overview($server));
    }

    public function search(SearchModsRequest $request, Server $server): JsonResponse
    {
        return new JsonResponse($this->modManagerService->search($server, $request->validated()));
    }

    public function versions(GetProjectVersionsRequest $request, Server $server, string $project): JsonResponse
    {
        return new JsonResponse($this->modManagerService->projectVersions($server, $project, $request->validated()));
    }

    public function install(InstallModRequest $request, Server $server): JsonResponse
    {
        $payload = $this->modManagerService->install($server, $request->validated());

        Activity::event('server:mods.install')
            ->property('directory', $payload['target_directory'])
            ->property('filename', $payload['filename'])
            ->property('url', $payload['download_url'])
            ->log();

        return new JsonResponse($payload);
    }
}

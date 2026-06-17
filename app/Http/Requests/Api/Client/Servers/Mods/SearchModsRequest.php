<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Mods;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SearchModsRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_FILE_READ;
    }

    public function rules(): array
    {
        return [
            'query' => 'required|string|max:120',
            'provider' => 'sometimes|nullable|string|max:50',
            'content_type' => 'sometimes|string|in:mod,plugin,datapack',
            'loader' => 'sometimes|nullable|string|max:50',
            'game_version' => 'sometimes|nullable|string|max:30',
            'limit' => 'sometimes|integer|min:1|max:12',
        ];
    }
}

# Pterodactyl Mods Manager

custom `Mods` tab built for a Pterodactyl panel fork.

This package contains the main panel files used to add:

- a server-level `Mods` tab
- egg-aware mod/provider detection
- admin egg overrides for mod support
- native `Modrinth` search
- native `Nexus Mods` search
- native `Thunderstore` search
- installed content discovery for common mod/plugin folders
- provider-aware install and update flows

## What This Repo Is

This is a feature extraction repo, not a full standalone Pterodactyl distribution.

It is intended to provide:

- a patchable file set that can be applied to an existing Pterodactyl panel fork

## Included Files

The repo mirrors the panel paths for the feature files:

- `app/Http/Controllers/Admin/Nests/EggController.php`
- `app/Http/Controllers/Api/Client/Servers/ModController.php`
- `app/Http/Requests/Admin/Egg/EggFormRequest.php`
- `app/Http/Requests/Api/Client/Servers/Mods/SearchModsRequest.php`
- `app/Services/ServerMods/EggModSupportService.php`
- `app/Services/ServerMods/ModManagerService.php`
- `config/services.php`
- `resources/scripts/api/server/mods/getServerMods.ts`
- `resources/scripts/api/server/mods/searchModCatalog.ts`
- `resources/scripts/components/server/mods/ModsContainer.tsx`
- `resources/views/admin/eggs/view.blade.php`

## Notes

- `Nexus Mods` search uses the official Nexus GraphQL API.
- `Thunderstore` search uses the Thunderstore community API.
- `CurseForge` 

## Environment

If you enable Nexus support, add this to your panel environment:

```env
NEXUS_API_KEY=your_nexus_application_key
```

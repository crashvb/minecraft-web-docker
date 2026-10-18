# minecraft-web-docker

[![version)](https://img.shields.io/docker/v/crashvb/minecraft-web/latest)](https://hub.docker.com/repository/docker/crashvb/minecraft-web)
[![image size](https://img.shields.io/docker/image-size/crashvb/minecraft-web/latest)](https://hub.docker.com/repository/docker/crashvb/minecraft-web)
[![linting](https://img.shields.io/badge/linting-hadolint-yellow)](https://github.com/hadolint/hadolint)
[![license](https://img.shields.io/github/license/crashvb/minecraft-web-docker.svg)](https://github.com/crashvb/minecraft-web-docker/blob/main/LICENSE.md)

## Overview

This docker image contains a web interface for managing Minecraft servers.

The interface displays the cached public IPv4 address and each server's deployed
version, server name, and level name; manages `allowlist.json` and
`permissions.json` using the gamer tag mappings in `xuid.yml`; and compares each
`server.properties` with the other servers and its distribution default. The
current allowlist and permissions files can be inspected as formatted JSON.
The public address is refreshed from the configured STUN server after one hour,
or immediately when requested from the interface. Player and property tables
can be filtered and sorted in the browser.

Authentication uses PHP sessions backed by the bcrypt credentials regenerated
from the secrets volume into `/etc/minecraft/.htpasswd` at container startup.

## Environment Variables

| Variable | Default | Description |
| -------- | ------- | ----------- |
| MINECRAFT\_BACKUP | /var/backup/minecraft | Writable root containing per-server JSON backups. |
| MINECRAFT\_CONFIG | /etc/minecraft | Web configuration containing `xuid.yml`. |
| MINECRAFT\_DATA | /var/lib/minecraft-data | Read-only server data containing `version` and `server.properties.dist`. |
| MINECRAFT\_LOCKS | /run/minecraft-web | Ephemeral update-lock directory. |
| MINECRAFT\_SHARE | /var/lib/minecraft | Writable server configuration root. |
| MINECRAFT\_STUN | stun.cloudflare.com:3478 | STUN server used to determine the public IPv4 address. |
| MINECRAFT\_USERS | admin | Comma-separated web authentication users. |

## Volumes

Server identifiers are discovered from the directories mounted directly under
`MINECRAFT_SHARE`. Each server requires a corresponding read-only data directory
under `MINECRAFT_DATA`. The application itself is baked into `/var/www`; do not
mount a volume over that path.

PHP-FPM runs as `minecraft` (`1000:1000`), matching the Minecraft server
containers. The web container does not change ownership or permissions on the
shared server config or data volumes. At startup, `xuid.yml` keeps its owner and
is set to group `minecraft` with mode `0660`. Each mounted backup-volume root
also keeps its owner and is set to group `minecraft` with mode `0770` so PHP can
create backups.

Changes to XUID mappings, allowlists, and permissions are persisted immediately.
A running Minecraft server still requires the corresponding console reload
command before it uses allowlist or permission changes. Before a changed JSON
file is replaced, its prior version is copied with metadata preserved to the
corresponding server backup volume as `filename.json.YYYY-MM-DD_HH:MM:SS`.
Before `xuid.yml` is changed, its prior version is copied to the web backup
volume as `xuid.yml.YYYY-MM-DD_HH:MM:SS`.

## Development

[Source Control](https://github.com/crashvb/minecraft-web-docker)

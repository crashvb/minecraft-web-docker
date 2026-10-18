FROM crashvb/nginx:202603100920@sha256:2372803f48fa5129dcb87c2f751c106bb3fc2db30b70e8b906cbe78f5b7d18f7
ARG org_opencontainers_image_created=undefined
ARG org_opencontainers_image_revision=undefined
LABEL \
	org.opencontainers.image.authors="Richard Davis <crashvb@gmail.com>" \
	org.opencontainers.image.base.digest="sha256:2372803f48fa5129dcb87c2f751c106bb3fc2db30b70e8b906cbe78f5b7d18f7" \
	org.opencontainers.image.base.name="crashvb/nginx:202603100920" \
	org.opencontainers.image.created="${org_opencontainers_image_created}" \
	org.opencontainers.image.description="Image containing minecraft-web." \
	org.opencontainers.image.licenses="Apache-2.0" \
	org.opencontainers.image.source="https://github.com/crashvb/minecraft-web-docker" \
	org.opencontainers.image.revision="${org_opencontainers_image_revision}" \
	org.opencontainers.image.title="crashvb/minecraft-web" \
	org.opencontainers.image.url="https://github.com/crashvb/minecraft-web-docker"

# Install packages, download files ...
RUN docker-apt coturn php-yaml

# Configure: minecraft
ENV \
	MINECRAFT_BACKUP=/var/backup/minecraft \
	MINECRAFT_CONFIG=/etc/minecraft \
	MINECRAFT_DATA=/var/lib/minecraft-data \
	MINECRAFT_HOME=/var/www \
	MINECRAFT_LOCKS=/run/minecraft-web \
	MINECRAFT_SHARE=/var/lib/minecraft \
	MINECRAFT_STUN=stun.cloudflare.com:3478 \
	MINECRAFT_USERS=admin \
	MINECRAFT_WEB_SHARE=/usr/local/share/minecraft-web
COPY public/ ${MINECRAFT_HOME}/public/
COPY src/ ${MINECRAFT_HOME}/src/
COPY minecraft.php-fpm.conf.template ${MINECRAFT_WEB_SHARE}/
RUN groupadd --gid=1000 minecraft && \
	useradd --gid=minecraft --no-create-home --shell=/usr/sbin/nologin --uid=1000 minecraft && \
	chown --recursive root:root "${MINECRAFT_HOME}"

# Configure: nginx
COPY default.nginx /etc/nginx/sites-available/default

# Configure: entrypoint
COPY entrypoint.minecraft /etc/entrypoint.d/10minecraft

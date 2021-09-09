#!/bin/bash
set -eou pipefail

[[ -z "$DB_HOST" ]] && echo 'Error: $DB_HOST required but missing.' && exit 1

DB_NAME="aurweb"
DB_USER="aur"
DB_PASS="aur"

# Setup a config for our mysql db.
cp -vf conf/config.dev conf/config
sed -i "s;YOUR_AUR_ROOT;$(pwd);g" conf/config
sed -ri "s/^(name) = .+/\1 = ${DB_NAME}/" conf/config
sed -ri "s/^(host) = .+/\1 = ${DB_HOST}/" conf/config
sed -ri "s/^(user) = .+/\1 = ${DB_USER}/" conf/config
sed -ri "s/^;?(password) = .+/\1 = ${DB_PASS}/" conf/config

sed -ri "s;^(aur_location) = .+;\1 = https://localhost:8444;" conf/config

# Setup Redis for FastAPI.
sed -ri 's/^(cache) = .+/\1 = redis/' conf/config
sed -ri 's|^(redis_address) = .+|\1 = redis://redis|' conf/config

sed -ri "s|^(git_clone_uri_anon) = .+|\1 = https://localhost:8444/%s.git|" conf/config.defaults
sed -ri "s|^(git_clone_uri_priv) = .+|\1 = ssh://aur@localhost:2222/%s.git|" conf/config.defaults

exec "$@"

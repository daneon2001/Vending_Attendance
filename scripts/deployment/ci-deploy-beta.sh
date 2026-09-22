#!/usr/bin/env bash
set -euo pipefail
umask 077
: "${BETA_SSH_HOST:?}" "${BETA_SSH_PORT:?}" "${BETA_SSH_USER:?}" "${BETA_SSH_PRIVATE_KEY:?}" "${BETA_SSH_KNOWN_HOSTS:?}" "${BETA_DEPLOY_PATH:?}" "${BETA_APP_URL:?}"
[[ "${CI_ENVIRONMENT_NAME:-}" == beta && "${CI_COMMIT_REF_PROTECTED:-}" == true ]] || exit 64
[[ "${CI_COMMIT_TAG:-}" =~ ^vending-beta-[0-9]+[.][0-9]+[.][0-9]+$ ]] || exit 64
[[ "$CI_COMMIT_SHA" =~ ^[a-f0-9]{40}$ ]] || exit 64
[[ "$BETA_SSH_HOST" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]*$ && "$BETA_SSH_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$BETA_SSH_PORT" =~ ^[0-9]{1,5}$ ]] || exit 64
[[ "$BETA_DEPLOY_PATH" =~ ^/srv/vending-beta(/[a-zA-Z0-9_-]+)?$ && "$BETA_APP_URL" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?$ ]] || exit 64
# Both are protected GitLab FILE variables. Pin host keys out of band.
[[ -f "$BETA_SSH_PRIVATE_KEY" && -f "$BETA_SSH_KNOWN_HOSTS" ]] || exit 64
chmod 600 "$BETA_SSH_PRIVATE_KEY"
ssh_args=(-p "$BETA_SSH_PORT" -i "$BETA_SSH_PRIVATE_KEY" -o BatchMode=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$BETA_SSH_KNOWN_HOSTS")
remote="$BETA_SSH_USER@$BETA_SSH_HOST"
release="$BETA_DEPLOY_PATH/releases/$CI_COMMIT_SHA"
sha256sum -c dist/vending-beta.tar.gz.sha256
ssh "${ssh_args[@]}" "$remote" "test -d '$BETA_DEPLOY_PATH/releases' && mkdir '$release'"
scp -P "$BETA_SSH_PORT" -i "$BETA_SSH_PRIVATE_KEY" -o BatchMode=yes -o StrictHostKeyChecking=yes -o "UserKnownHostsFile=$BETA_SSH_KNOWN_HOSTS" dist/vending-beta.tar.gz "$remote:$release/package.tar.gz"
ssh "${ssh_args[@]}" "$remote" "cd '$release' && tar --no-same-owner -xzf package.tar.gz && bash scripts/deployment/deploy-beta.sh '$BETA_DEPLOY_PATH' '$CI_COMMIT_SHA' '$BETA_APP_URL'"

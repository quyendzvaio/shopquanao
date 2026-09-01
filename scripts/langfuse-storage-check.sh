#!/usr/bin/env bash
set -euo pipefail

# Read only the one storage setting we need. Do not source .env: it contains
# application and provider secrets that must never be imported into diagnostics.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd "$script_dir/.." && pwd -P)"

die() {
  printf 'LANGFUSE_STORAGE_PREFLIGHT=FAIL\nERROR: %s\n' "$1" >&2
  exit 1
}

env_value() {
  local key="$1" file="$repo_root/.env" line value
  if [ "${!key+x}" = x ]; then
    printf '%s' "${!key}"
    return 0
  fi
  [ -f "$file" ] || return 0
  line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
  [ -n "$line" ] || return 0
  value="${line#*=}"
  value="${value%$'\r'}"
  if [[ "$value" == \"*\" && "$value" == *\" ]]; then
    value="${value:1:${#value}-2}"
  elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
    value="${value:1:${#value}-2}"
  fi
  printf '%s' "$value"
}

postgres_dir="$(env_value LANGFUSE_POSTGRES_DATA_DIR)"
postgres_dir="${postgres_dir:-./.docker-data/langfuse-postgres}"
if [[ "$postgres_dir" != /* ]]; then
  postgres_dir="$repo_root/${postgres_dir#./}"
fi
postgres_dir="$(realpath -m -- "$postgres_dir")"

printf 'Langfuse storage preflight\n'
printf 'repository=%s\n' "$repo_root"
printf 'postgres_bind_path=%s\n' "$postgres_dir"

[ "$postgres_dir" != "/" ] || die "Postgres path must not be filesystem root"
[ "$postgres_dir" != "$repo_root" ] || die "Postgres path must not be repository root"

path_for_fs="$postgres_dir"
if [ ! -e "$path_for_fs" ]; then
  path_for_fs="$(dirname -- "$path_for_fs")"
fi
[ -d "$path_for_fs" ] || die "filesystem probe path does not exist: $path_for_fs"

fs_type="$(df -T -P -- "$path_for_fs" | awk 'NR == 2 { print $2 }')"
stat_type="$(stat -f -c '%T' -- "$path_for_fs")"
printf 'postgres_filesystem=%s stat_type=%s\n' "$fs_type" "$stat_type"

case "$fs_type" in
  ext2|ext3|ext4|xfs|btrfs|zfs)
    ;;
  fuseblk|ntfs|exfat|vfat|cifs|nfs|overlay|*)
    die "unsupported filesystem '$fs_type'; use an ext4/xfs/btrfs path such as /var/lib/shopquanao/langfuse/postgres"
    ;;
esac

docker_root="$(timeout 15s docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
[ -n "$docker_root" ] || die "Docker daemon did not answer within 15 seconds"
docker_fs="$(df -T -P -- "$docker_root" | awk 'NR == 2 { print $2 }')"
printf 'docker_root=%s docker_root_filesystem=%s\n' "$docker_root" "$docker_fs"

if [ -e "$postgres_dir" ]; then
  owner="$(stat -c '%u:%g' -- "$postgres_dir")"
  mode="$(stat -c '%a' -- "$postgres_dir")"
  printf 'postgres_path_owner=%s mode=%s\n' "$owner" "$mode"
  if command -v docker >/dev/null 2>&1 && docker image inspect postgres:17-alpine >/dev/null 2>&1; then
    postgres_identity="$(timeout 20s docker run --rm --entrypoint id postgres:17-alpine postgres 2>/dev/null || true)"
    expected_owner="$(printf '%s' "$postgres_identity" | sed -n 's/^uid=\([0-9][0-9]*\).*gid=\([0-9][0-9]*\).*$/\1:\2/p')"
    if [ -n "$expected_owner" ]; then
      printf 'postgres_image_owner=%s\n' "$expected_owner"
      expected_uid="${expected_owner%%:*}"
      actual_uid="${owner%%:*}"
      [ "$actual_uid" = "$expected_uid" ] || die "Postgres bind path UID $actual_uid does not match image UID $expected_uid"
      if [ "$owner" != "$expected_owner" ]; then
        # PostgreSQL's init/permission check is keyed to the effective UID;
        # group permissions are disabled by mode 700. Keep this as an
        # explicit warning rather than forcing a metadata-only chgrp that may
        # require host root privileges on an otherwise healthy data directory.
        printf 'postgres_path_group_warning=host group differs from image group; UID and mode are safe\n'
      fi
      case "$mode" in
        700|750) ;;
        *) die "Postgres bind path mode $mode is not supported (expected 700 or provider-approved 750)" ;;
      esac
    else
      printf 'postgres_image_owner=UNKNOWN (image identity probe unavailable)\n'
    fi
  else
    printf 'postgres_image_owner=UNKNOWN (postgres:17-alpine image not present)\n'
  fi
else
  printf 'postgres_path_owner=NOT_CREATED (Postgres will initialize it)\n'
fi

printf 'LANGFUSE_STORAGE_PREFLIGHT=PASS\n'

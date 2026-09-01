#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd "$script_dir/.." && pwd -P)"
dry_run=false
confirm=false
explicit_all=false

usage() {
  cat <<'USAGE'
Usage: scripts/langfuse-reset.sh [--dry-run] [--confirm] [--all]

  --dry-run  Show the exact Langfuse bind path and named volumes; do not stop
             containers or delete anything.
  --confirm  Stop the observability services and delete only the validated
             Langfuse bind path and active Langfuse named volumes.
  --all      Explicitly request the whole observability data reset. This is
             accepted only together with --confirm.

With no flags the script is read-only and prints the planned targets. The
script never runs docker system prune and never deletes application/database
volumes outside the Langfuse allow-list.
USAGE
}

die() {
  printf 'LANGFUSE_RESET=FAIL\nERROR: %s\n' "$1" >&2
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

while [ "$#" -gt 0 ]; do
  case "$1" in
    --dry-run) dry_run=true ;;
    --confirm) confirm=true ;;
    --all) explicit_all=true ;;
    -h|--help) usage; exit 0 ;;
    *) usage >&2; die "unknown option: $1" ;;
  esac
  shift
done

[ "$explicit_all" = false ] || [ "$confirm" = true ] || die "--all requires --confirm"

postgres_dir="$(env_value LANGFUSE_POSTGRES_DATA_DIR)"
postgres_dir="${postgres_dir:-./.docker-data/langfuse-postgres}"
if [[ "$postgres_dir" != /* ]]; then
  postgres_dir="$repo_root/${postgres_dir#./}"
fi
postgres_dir="$(realpath -m -- "$postgres_dir")"
local_target="$repo_root/.docker-data/langfuse-postgres"
external_root="/var/lib/shopquanao/langfuse"

# Only these two layouts are supported. This prevents a typo or an injected
# environment value from turning a reset into a recursive delete elsewhere.
case "$postgres_dir" in
  "$local_target"|"$external_root/postgres") ;;
  *) die "refusing unexpected Postgres path '$postgres_dir'; allowed paths are '$local_target' or '$external_root/postgres'" ;;
esac
[ "$postgres_dir" != "/" ] || die "refusing filesystem root"

check_no_symlink_components() {
  local path="$1" current part
  current="/"
  IFS='/' read -r -a parts <<< "${path#/}"
  for part in "${parts[@]}"; do
    [ -n "$part" ] || continue
    current="$current$part"
    [ ! -L "$current" ] || die "refusing symlink component in '$path': $current"
    current="$current/"
  done
}
check_no_symlink_components "$postgres_dir"

docker_available=true
if ! timeout 15s docker info >/dev/null 2>&1; then
  docker_available=false
  [ "$confirm" = false ] || die "Docker daemon did not answer; refusing to delete while containers may still be running"
fi

active_langfuse_volumes=(
  shop_quan_ao_langfuse_clickhouse_data
  shop_quan_ao_langfuse_clickhouse_logs
  shop_quan_ao_langfuse_minio_data
)
legacy_langfuse_volumes=(
  shop_quan_ao_langfuse_postgres_data
)
present_volumes=()
present_legacy_volumes=()
if [ "$docker_available" = true ]; then
  for volume in "${active_langfuse_volumes[@]}"; do
    if docker volume inspect "$volume" >/dev/null 2>&1; then
      present_volumes+=("$volume")
    fi
  done
  for volume in "${legacy_langfuse_volumes[@]}"; do
    if docker volume inspect "$volume" >/dev/null 2>&1; then
      present_legacy_volumes+=("$volume")
    fi
  done
fi

if [ "$explicit_all" = true ]; then
  present_volumes+=("${present_legacy_volumes[@]}")
fi

printf 'Langfuse reset plan\n'
printf 'repository=%s\n' "$repo_root"
printf 'postgres_bind_path=%s\n' "$postgres_dir"
if [ "$dry_run" = true ] || [ "$confirm" = false ]; then
  printf 'mode=READ_ONLY\n'
else
  printf 'mode=CONFIRMED_FULL_OBSERVABILITY_RESET\n'
fi
if [ "${#present_volumes[@]}" -eq 0 ]; then
  printf 'named_volumes=NONE\n'
else
  printf 'named_volumes=%s\n' "${present_volumes[*]}"
fi
if [ "${#present_legacy_volumes[@]}" -gt 0 ] && [ "$explicit_all" = false ]; then
  printf 'legacy_named_volumes=NOT_TARGETED_WITHOUT_--all %s\n' "${present_legacy_volumes[*]}"
fi
if [ -e "$postgres_dir" ]; then
  printf 'bind_path_state=EXISTS\n'
else
  printf 'bind_path_state=ABSENT\n'
fi
printf 'redis_persistence=NONE (Compose declares no Redis volume)\n'

[ "$dry_run" = true ] || [ "$confirm" = true ] || {
  printf 'No deletion requested. Use --dry-run to audit or --confirm to execute the exact reset.\n'
  printf 'LANGFUSE_RESET=NOOP\n'
  exit 0
}

[ "$dry_run" = false ] || {
  printf 'Dry-run complete; no containers stopped and no data deleted.\nLANGFUSE_RESET=DRY_RUN\n'
  exit 0
}

[ "$docker_available" = true ] || die "Docker is required for a confirmed reset"

printf 'Stopping Langfuse observability services...\n'
docker compose --profile observability stop \
  langfuse-web langfuse-worker langfuse-postgres langfuse-clickhouse \
  langfuse-redis langfuse-minio >/dev/null

if [ -e "$postgres_dir" ]; then
  [ -d "$postgres_dir" ] || die "validated Postgres target is not a directory"
  # The path was allow-listed and every component was checked for symlinks.
  rm -rf -- "$postgres_dir"
  printf 'deleted_bind_path=%s\n' "$postgres_dir"
fi

for volume in "${present_volumes[@]}"; do
  docker volume rm "$volume" >/dev/null
  printf 'deleted_named_volume=%s\n' "$volume"
done

printf 'LANGFUSE_RESET=PASS\n'

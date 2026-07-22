#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd "${script_dir}/.." && pwd)"
version="$(sed -n "s/^define( 'MINDIO_MAGIC_MCP_VERSION', '\\([^']*\\)' );$/\\1/p" "${plugin_dir}/flatsome-mcp.php")"

if [[ -z "${version}" ]]; then
  echo "Could not determine MINDIO_MAGIC_MCP_VERSION." >&2
  exit 1
fi

release_dir="${plugin_dir}/dist"
archive="${release_dir}/mindio-magic-mcp-${version}.zip"
staging_root="$(mktemp -d)"
trap 'rm -rf "${staging_root}"' EXIT

mkdir -p "${release_dir}" "${staging_root}/mindio-magic-mcp"
rsync -a --delete --exclude-from="${plugin_dir}/.distignore" "${plugin_dir}/" "${staging_root}/mindio-magic-mcp/"
mv "${staging_root}/mindio-magic-mcp/flatsome-mcp.php" "${staging_root}/mindio-magic-mcp/mindio-magic-mcp.php"
rm -f "${archive}"
(
  cd "${staging_root}"
  zip -q -r "${archive}" mindio-magic-mcp
)

echo "Built ${archive}"

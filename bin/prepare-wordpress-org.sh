#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
plugin_dir="$(cd "${script_dir}/.." && pwd)"

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 /path/to/wordpress-org-svn-checkout" >&2
  exit 1
fi

svn_dir="$1"

if [[ ! -d "${svn_dir}/.svn" ]]; then
  echo "Not an SVN working copy: ${svn_dir}" >&2
  exit 1
fi

for required_dir in trunk tags assets; do
  if [[ ! -d "${svn_dir}/${required_dir}" ]]; then
    echo "Missing SVN directory: ${svn_dir}/${required_dir}" >&2
    exit 1
  fi
done

if [[ -n "$(svn status "${svn_dir}")" ]]; then
  echo "The SVN working copy has uncommitted changes; review or revert them first." >&2
  svn status "${svn_dir}" >&2
  exit 1
fi

version="$(sed -n "s/^define( 'MINDIO_MAGIC_MCP_VERSION', '\\([^']*\\)' );$/\\1/p" "${plugin_dir}/mindio-magic-mcp.php")"

if [[ -z "${version}" ]]; then
  echo "Could not determine MINDIO_MAGIC_MCP_VERSION." >&2
  exit 1
fi

if [[ -e "${svn_dir}/tags/${version}" ]]; then
  echo "SVN tag ${version} already exists." >&2
  exit 1
fi

"${plugin_dir}/bin/build-release.sh"

archive="${plugin_dir}/dist/mindio-magic-mcp-${version}.zip"
staging_root="$(mktemp -d)"
trap 'rm -rf "${staging_root}"' EXIT

unzip -q "${archive}" -d "${staging_root}"

release_source="${staging_root}/mindio-magic-mcp"

if [[ ! -f "${release_source}/mindio-magic-mcp.php" ]]; then
  echo "Release archive is missing mindio-magic-mcp.php." >&2
  exit 1
fi

rsync -a --delete --exclude='.svn' "${release_source}/" "${svn_dir}/trunk/"
rsync -a --delete --exclude='.svn' "${plugin_dir}/.wordpress-org/" "${svn_dir}/assets/"

svn add --force "${svn_dir}/trunk" "${svn_dir}/assets"

for image in "${svn_dir}"/assets/*.png; do
  [[ -e "${image}" ]] || continue
  svn propset svn:mime-type image/png "${image}" >/dev/null
done

while IFS= read -r missing_path; do
  svn rm --force "${missing_path}"
done < <(svn status "${svn_dir}/trunk" "${svn_dir}/assets" | sed -n 's/^!       //p')

svn copy "${svn_dir}/trunk" "${svn_dir}/tags/${version}"

echo
echo "Prepared WordPress.org release ${version}."
echo "Review the following changes before committing:"
svn status "${svn_dir}"

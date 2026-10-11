#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
  echo "Usage: $0 <40-character-commit-sha> <output-directory>" >&2
  exit 2
fi

commit="$1"
output_dir="$2"

if [[ ! "$commit" =~ ^[0-9a-f]{40}$ ]]; then
  echo "Commit must be a full lowercase 40-character SHA." >&2
  exit 2
fi

git cat-file -e "${commit}^{commit}"
mkdir -p "$output_dir"

zip_path="$output_dir/dsquared-hub-connector.zip"
checksum_path="$output_dir/dsquared-hub-connector.zip.sha256"
manifest_path="$output_dir/dsquared-hub-connector.manifest.txt"

for path in "$zip_path" "$checksum_path" "$manifest_path"; do
  if [[ -e "$path" ]]; then
    echo "Refusing to overwrite existing package file: $path" >&2
    exit 2
  fi
done

git archive --format=zip --prefix=dsquared-hub-connector/ --output="$zip_path" "$commit"

if command -v sha256sum >/dev/null 2>&1; then
  digest="$(sha256sum "$zip_path" | awk '{print $1}')"
else
  digest="$(shasum -a 256 "$zip_path" | awk '{print $1}')"
fi

printf '%s  %s\n' "$digest" "dsquared-hub-connector.zip" > "$checksum_path"
{
  printf 'Source-Commit: %s\n' "$commit"
  printf 'SHA-256: %s\n' "$digest"
  printf 'Archive: dsquared-hub-connector.zip\n'
  printf 'Members:\n'
  unzip -Z1 "$zip_path" | LC_ALL=C sort
} > "$manifest_path"

printf '%s\n' "$digest"

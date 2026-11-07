#!/bin/sh
# Builds dist/loft-studio-<version>.zip, ready for Plugins → Add New → Upload, from a release tag.
#   bin/build-zip.sh          the version in loft-studio.php (its tag must exist)
#   bin/build-zip.sh 0.1.1    a specific version
# The zip holds a loft-studio/ folder; files marked export-ignore in .gitattributes are left out.
set -eu
cd "$(dirname "$0")/.."

version="${1:-$(sed -n "s/^define( 'LOFT_VERSION', '\(.*\)' );\$/\1/p" loft-studio.php)}"
if [ -z "$version" ]; then
	echo "Couldn't read LOFT_VERSION from loft-studio.php; pass the version, e.g. bin/build-zip.sh 0.1.1" >&2
	exit 1
fi
tag="v$version"
if ! git rev-parse -q --verify "refs/tags/$tag" >/dev/null; then
	echo "No tag $tag yet. Tag the release first: git tag -a $tag -m \"Loft Studio $version\"" >&2
	exit 1
fi

mkdir -p dist
out="dist/loft-studio-$version.zip"
git archive --format=zip --prefix=loft-studio/ -o "$out" "$tag"
echo "$out"

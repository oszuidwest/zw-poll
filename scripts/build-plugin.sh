#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SLUG="zw-poll"
MAIN_FILE="${SLUG}.php"
DIST_DIR="${ROOT_DIR}/dist"
PACKAGE_ROOT="${DIST_DIR}/${SLUG}"
PRINT_VERSION=false

usage() {
	cat <<'EOF'
Usage: scripts/build-plugin.sh [--print-version]

Builds an uploadable WordPress plugin zip in dist/. The plugin ships its
frontend and admin assets unbundled, so no npm build step is involved.

Options:
  --print-version  Print the validated plugin version and exit without building.
  -h, --help       Show this help text.
EOF
}

for arg in "$@"; do
	case "$arg" in
		--print-version)
			PRINT_VERSION=true
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown option: $arg" >&2
			usage >&2
			exit 1
			;;
	esac
done

if [ ! -f "$MAIN_FILE" ]; then
	echo "Main plugin file missing: $MAIN_FILE" >&2
	exit 1
fi

VERSION="$(awk '
	/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*/ {
		sub(/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*/, "")
		sub(/[[:space:]]+$/, "")
		print
		exit
	}
' "$MAIN_FILE")"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?(-(alpha|beta|rc)\.[0-9]+)?$ ]]; then
	echo "Invalid Version header in $MAIN_FILE: '$VERSION'" >&2
	exit 1
fi

if [ "$PRINT_VERSION" = true ]; then
	echo "$VERSION"
	exit 0
fi

if compgen -G "languages/*.po" > /dev/null; then
	if ! command -v msgfmt > /dev/null; then
		echo "msgfmt not found; cannot compile languages/*.po files." >&2
		exit 1
	fi
	for po in languages/*.po; do
		msgfmt -o "${po%.po}.mo" "$po"
		echo "Compiled $po"
	done
fi

rm -rf "$PACKAGE_ROOT"
mkdir -p "$PACKAGE_ROOT"

rsync -a \
	"$MAIN_FILE" \
	uninstall.php \
	LICENSE \
	README.md \
	languages \
	src \
	"$PACKAGE_ROOT/"

find "$PACKAGE_ROOT" -name '.DS_Store' -delete

ZIP_NAME="${SLUG}-${VERSION}.zip"
ZIP_PATH="${DIST_DIR}/${ZIP_NAME}"
CHECKSUM_PATH="${ZIP_PATH}.sha256"
rm -f "$ZIP_PATH" "$CHECKSUM_PATH"

(cd "$DIST_DIR" && zip -qr "$ZIP_NAME" "$SLUG")
rm -rf "$PACKAGE_ROOT"

(cd "$DIST_DIR" && shasum -a 256 "$ZIP_NAME" > "${ZIP_NAME}.sha256")

echo "Built ${ZIP_PATH}"
echo "Checksum ${CHECKSUM_PATH}"

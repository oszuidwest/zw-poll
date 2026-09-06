#!/usr/bin/env bash
set -euo pipefail

# Single source of truth for the make-pot invocation used locally and in CI.
#
# Usage:
#   scripts/pot.sh          Regenerate languages/zw-poll.pot.
#   scripts/pot.sh --check  Verify the committed POT is up-to-date (ignoring
#                           the POT-Creation-Date and X-Generator headers).

cd "$(dirname "${BASH_SOURCE[0]}")/.."

POT="languages/zw-poll.pot"

make_pot() {
	local output="$1"
	shift
	wp i18n make-pot . "$output" \
		--slug=zw-poll \
		--domain=zw-poll \
		--include=src,zw-poll.php,uninstall.php \
		--exclude=dist,node_modules,vendor \
		"$@"
}

if [ "${1:-}" = "--check" ]; then
	tmp="$(mktemp -d)"
	trap 'rm -rf "$tmp"' EXIT
	make_pot "$tmp/generated.pot" --skip-audit
	sed -E '/POT-Creation-Date:|X-Generator:/d' "$POT" > "$tmp/committed.stripped"
	sed -E '/POT-Creation-Date:|X-Generator:/d' "$tmp/generated.pot" > "$tmp/generated.stripped"
	diff -u "$tmp/committed.stripped" "$tmp/generated.stripped"
else
	make_pot "$POT"
fi

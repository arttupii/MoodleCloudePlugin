#!/usr/bin/env bash
# Päivittää local_claudeapi-lisäosan Moodleen ja MCP-palvelimen Python-riippuvuudet tästä reposta.
#
# Käyttö (aja omana käyttäjänä, skripti kysyy sudo-salasanan tarvittaessa):
#   scripts/update-plugin.sh                 # git pull + lisäosa + upgrade + venv
#   scripts/update-plugin.sh --branch X      # vaihda haaraan X ennen päivitystä (esim. PR:n testaus)
#   scripts/update-plugin.sh --no-pull       # käytä nykyistä työkopiota sellaisenaan
#   MOODLE_DIR=/srv/moodle scripts/update-plugin.sh
#
# Lopuksi käynnistä Claude-sovellus uudelleen, jotta MCP-palvelin latautuu uudelleen.
set -euo pipefail

# Koko skripti luetaan muistiin ennen ajoa: git pull voi muuttaa tätä tiedostoa kesken ajon.
{

MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
WEB_USER="${WEB_USER:-www-data}"
PULL=1
BRANCH=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --no-pull) PULL=0; shift ;;
    --branch)  BRANCH="${2:?--branch tarvitsee haaran nimen}"; shift 2 ;;
    -h|--help) sed -n '2,11p' "$0"; exit 0 ;;
    *) echo "Tuntematon valitsin: $1"; exit 1 ;;
  esac
done

if [[ $EUID -eq 0 ]]; then
  echo "Aja omana käyttäjänäsi ilman sudoa (venv kuuluu sinulle). Skripti käyttää sudoa itse."
  exit 1
fi

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SRC="$REPO_DIR/moodle-plugin/claudeapi"
MCP_DIR="$REPO_DIR/mcp-server"
cd "$REPO_DIR"

version_of() { grep -oP "\\\$plugin->(version|release)\s*=\s*'?\K[^';]+" "$1" | paste -sd' ' -; }

echo "==> Repo: $REPO_DIR"
if [[ -n "$(git status --porcelain)" ]]; then
  echo "!! Työkopiossa on tallentamattomia muutoksia:"
  git status --short
  [[ $PULL -eq 1 || -n "$BRANCH" ]] && { echo "Tallenna tai peru ne, tai aja --no-pull."; exit 1; }
fi
if [[ -n "$BRANCH" ]]; then
  git fetch origin "$BRANCH"
  git checkout "$BRANCH" 2>/dev/null || git checkout -b "$BRANCH" "origin/$BRANCH"
fi
if [[ $PULL -eq 1 ]]; then
  git pull --ff-only
fi
echo "    $(git rev-parse --abbrev-ref HEAD) @ $(git log -1 --format='%h %s')"

# Moodle 5.1+: lisäosat public/-kansiossa
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Moodlea ei löydy: $MOODLE_DIR (aseta MOODLE_DIR)"; exit 1; }
if [[ -d "$MOODLE_DIR/public/local" ]]; then LOCAL_DIR="$MOODLE_DIR/public/local"; else LOCAL_DIR="$MOODLE_DIR/local"; fi
if [[ -f "$MOODLE_DIR/public/admin/cli/upgrade.php" ]]; then CLI="$MOODLE_DIR/public/admin/cli"; else CLI="$MOODLE_DIR/admin/cli"; fi
TARGET="$LOCAL_DIR/claudeapi"

OLD="(ei asennettu)"
[[ -f "$TARGET/version.php" ]] && OLD="$(version_of "$TARGET/version.php")"
NEW="$(version_of "$PLUGIN_SRC/version.php")"
echo "==> Lisäosa: $OLD  ->  $NEW"

echo "==> Kopioidaan lisäosa: $TARGET"
if command -v rsync >/dev/null; then
  sudo rsync -a --delete "$PLUGIN_SRC/" "$TARGET/"
else
  sudo rm -rf "$TARGET"
  sudo cp -r "$PLUGIN_SRC" "$TARGET"
fi
sudo chown -R "$WEB_USER:$WEB_USER" "$TARGET"

echo "==> Moodlen upgrade"
(cd /tmp && sudo -u "$WEB_USER" php "$CLI/upgrade.php" --non-interactive)
(cd /tmp && sudo -u "$WEB_USER" php "$CLI/purge_caches.php")
INSTALLED="$(cd /tmp && sudo -u "$WEB_USER" php "$CLI/cfg.php" --component=local_claudeapi --name=version || true)"
echo "    Moodlessa nyt: local_claudeapi $INSTALLED"

echo "==> MCP-palvelimen Python-ympäristö"
PY="$(command -v python3.12 || command -v python3.11 || command -v python3)"
if [[ ! -x "$MCP_DIR/.venv/bin/python" ]]; then
  "$PY" -m venv "$MCP_DIR/.venv" || { echo "venv puuttuu: sudo apt install python3-venv"; exit 1; }
fi
"$MCP_DIR/.venv/bin/pip" install -q --upgrade pip
"$MCP_DIR/.venv/bin/pip" install -q -r "$MCP_DIR/requirements.txt"
"$MCP_DIR/.venv/bin/python" -c "import ast,sys; ast.parse(open(sys.argv[1]).read())" "$MCP_DIR/moodle_mcp.py"
echo "    $("$MCP_DIR/.venv/bin/python" --version), riippuvuudet ajan tasalla"

cat <<EOF

=========================================
 Päivitys valmis: local_claudeapi $NEW
 Käynnistä Claude-sovellus uudelleen, jotta
 MCP-palvelimen uusi versio latautuu:
   pkill -f -i claude   (ja avaa sovellus)
=========================================
EOF
exit 0
}

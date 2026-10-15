#!/usr/bin/env bash
# Build a FOSSBilling adapter zip whose root is library/.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

SLUG="cryptonly-fossbilling"
VERSION="$(grep -E '"version"' package.json | head -1 | sed 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/')"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/stage"
ZIP_PATH="${DIST_DIR}/${SLUG}-${VERSION}.zip"
ADAPTER_SRC="${ROOT_DIR}/library/Payment/Adapter/Cryptonly"
ADAPTER_STAGE="${STAGE_DIR}/library/Payment/Adapter/Cryptonly"

rm -rf "${STAGE_DIR}"
mkdir -p "${ADAPTER_STAGE}"
cp -R "${ADAPTER_SRC}/." "${ADAPTER_STAGE}/"
find "${STAGE_DIR}" -name '.DS_Store' -delete

mkdir -p "${DIST_DIR}"
rm -f "${ZIP_PATH}"
(
  cd "${STAGE_DIR}"
  zip -r "${ZIP_PATH}" library -x "*.DS_Store"
)

test -f "${ADAPTER_STAGE}/Cryptonly.php"
test -f "${ADAPTER_STAGE}/lib/billing/bootstrap.php"
php -l "${ADAPTER_STAGE}/Cryptonly.php" >/dev/null
echo "Built ${ZIP_PATH}"

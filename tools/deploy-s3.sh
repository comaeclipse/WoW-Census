#!/usr/bin/env bash
# Deploy static/ to the wowcensus.io S3 website bucket (us-east-2).
#   bash tools/deploy-s3.sh            # upload + prune removed files
# Requires: aws CLI v2 signed in under the "wowcensus" profile (aws login --profile wowcensus).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/static"
BUCKET="${BUCKET:-wowcensus.io}"
AWS="${AWS:-aws}"
command -v "$AWS" >/dev/null || AWS="$LOCALAPPDATA/Programs/Amazon/AWSCLIV2/aws.exe"
P=(--profile "${AWS_PROFILE_NAME:-wowcensus}" --region us-east-2)

SHORT="public, max-age=300"
LONG="public, max-age=31536000, immutable"

# Hashed CSS, fonts and filter data never change under the same name.
"$AWS" s3 sync "$SRC" "s3://$BUCKET" "${P[@]}" --exclude "*" --include "*.css" --include "fonts/*" \
  --include "data/*" --include "*/data/*" --cache-control "$LONG"
# Markdown twins need an explicit type.
"$AWS" s3 sync "$SRC" "s3://$BUCKET" "${P[@]}" --exclude "*" --include "*.md" \
  --content-type "text/markdown; charset=utf-8" --cache-control "$SHORT"
# Everything else (html, json, images, js, txt).
"$AWS" s3 sync "$SRC" "s3://$BUCKET" "${P[@]}" --exclude "*.css" --exclude "fonts/*" --exclude "*.md" \
  --exclude "data/*" --exclude "*/data/*" --exclude "_headers" --cache-control "$SHORT"

# S3 website hosting has no pretty URLs: /gear must exist as a key. Mirror every
# non-index page (foo.html -> foo) as text/html.
cd "$SRC"
find . -name "*.html" ! -name "index.html" ! -name "404.html" | sed 's|^\./||' | while read -r f; do
  "$AWS" s3 cp "$f" "s3://$BUCKET/${f%.html}" "${P[@]}" --only-show-errors \
    --content-type "text/html; charset=utf-8" --cache-control "$SHORT"
done

# Prune objects that no longer exist locally (keeps the extensionless mirrors).
"$AWS" s3 sync "$SRC" "s3://$BUCKET" "${P[@]}" --delete --exclude "_headers" --size-only --dryrun \
  | sed -n 's|^(dryrun) delete: s3://[^/]*/||p' \
  | while read -r key; do
      [ -f "$SRC/$key.html" ] && continue
      "$AWS" s3 rm "s3://$BUCKET/$key" "${P[@]}" --only-show-errors
    done

echo "Deployed to http://$BUCKET.s3-website.us-east-2.amazonaws.com/"

#!/bin/bash
# ---------------------------------------------------------------
#  Woodland Cottages — put this folder on GitHub
#
#  Double-click this file in Finder, or run it in Terminal.
#  It creates the repository and pushes everything.
#
#  You need the GitHub CLI once:   brew install gh && gh auth login
#  (If you would rather not install it, see README-DEPLOY.md.)
# ---------------------------------------------------------------
set -e
cd "$(dirname "$0")"

REPO="woodland-cottages"

command -v gh >/dev/null || { echo "GitHub CLI not found. Run:  brew install gh && gh auth login"; exit 1; }

[ -d .git ] || git init -b main
git add -A
git commit -m "Woodland Cottages — static site, booking + availability functions" || true

if gh repo view "$REPO" >/dev/null 2>&1; then
  echo "Repository already exists — pushing to it."
  git remote remove origin 2>/dev/null || true
  git remote add origin "https://github.com/$(gh api user --jq .login)/$REPO.git"
  git push -u origin main
else
  gh repo create "$REPO" --private --source=. --remote=origin --push
fi

echo
echo "Done. The repository is at:"
gh repo view --json url --jq .url

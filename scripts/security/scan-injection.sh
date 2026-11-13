#!/usr/bin/env bash
# Supply-chain injection scanner.
#
# Detects the build-time malware pattern that was force-pushed to Vittas repos
# on 2026-11-05 (and similar variants):
#   - a root `init` / `config.bat` dropper wired into npm scripts (`node init && ...`)
#   - obfuscated code hidden far to the right of a line, after long runs of whitespace
#   - remote-code loaders (fetch a URL from an env var, then eval it)
#   - committed .env files / .env removed from .gitignore
#   - committed Terraform plan/state files (they carry plaintext secrets)
#
# Usage:  bash scripts/security/scan-injection.sh          (scans tracked files)
# Exit 0 = clean, 1 = suspicious content found.
#
# Runs in CI before dependencies install or secrets load — keep it dependency-free.
set -u

cd "$(git rev-parse --show-toplevel)" || exit 2
SELF="scripts/security/scan-injection.sh"
fail=0
report() { echo "::error title=Injection scan::$1"; fail=1; }

# Tracked files, excluding this scanner and vendored/minified code.
files() { git ls-files -- "$@" ':!:node_modules/**' ':!:**/*.min.js' ":!:$SELF"; }
# NUL-delimited variant for xargs, so odd filenames (newlines, a leading '-')
# can't split or be parsed as options. Every grep below also passes `--`.
files0() { git ls-files -z -- "$@" ':!:node_modules/**' ':!:**/*.min.js' ":!:$SELF"; }
# Tool errors (bad options, unreadable files) must fail the scan, never read as clean.
errlog=$(mktemp); trap 'rm -f "$errlog"' EXIT

# 1. Dropper files at repo root.
for f in init init.js config.bat; do
  git ls-files --error-unmatch "$f" >/dev/null 2>&1 && report "unexpected root file '$f' (known dropper name)"
done

# 2. package.json scripts that execute a bare root file before the real command.
while IFS= read -r p; do
  if grep -nE '"[a-z:-]+"[[:space:]]*:[[:space:]]*"[^"]*node[[:space:]]+(\./)?(init|config|setup)(\.c?js)?[[:space:]]*(&&|;|")' -- "$p" 2>>"$errlog"; then
    report "$p: npm script runs a root bootstrap file (node init && ...)"
  fi
done < <(files '*package.json')

# 3. Code hidden after long whitespace runs (pushes payload off-screen in editors/diffs).
hits=$(files0 '*.js' '*.cjs' '*.mjs' '*.ts' '*.tsx' '*.jsx' '*.json' |
  xargs -0 -r grep -lE -e '[[:space:]]{150,}[^[:space:]]' -- 2>>"$errlog")
[ -n "$hits" ] && report "code hidden after long whitespace in: $(echo $hits)"

# 4. Abnormally long single lines in build/tooling config files (obfuscated blobs).
while IFS= read -r f; do
  len=$(awk '{ if (length > m) m = length } END { print m + 0 }' "./$f" 2>>"$errlog")
  [ "${len:-0}" -gt 2000 ] && report "$f has a ${len}-char line (obfuscated config?)"
done < <(files '*.config.js' '*.config.cjs' '*.config.mjs' '*.config.ts' 'init*' 'api.js')

# 5. Remote-code loaders / known IOCs.
pat='anondrop\.net|eval\(proxyInfo|atob\(process\.env|eval\(await|new Function\(await|global\[.\\u00'
hits=$(files0 '*.js' '*.cjs' '*.mjs' '*.ts' '*.tsx' '*.jsx' | xargs -0 -r grep -lE -e "$pat" -- 2>>"$errlog")
[ -n "$hits" ] && report "remote-code-loader / known IOC pattern in: $(echo $hits)"

# 6. Secrets hygiene: no tracked .env, and .gitignore must keep ignoring it.
envs=$(git ls-files | grep -E '(^|/)\.env(\.[^/]*)?$' | grep -vE '\.(example|sample|template)$')
[ -n "$envs" ] && report "tracked env file(s): $(echo $envs)"
grep -qE '^\.env([[:space:]]|$)' .gitignore 2>/dev/null || report ".gitignore no longer ignores .env"

[ -s "$errlog" ] && report "scanner tool error(s), scan incomplete: $(head -c 500 "$errlog" | tr '\n' ' ')"

# 7. Terraform plan/state files embed full state snapshots, secrets included.
tf=$(git ls-files | grep -E '(^|/)(tfplan|[^/]*\.tfplan|[^/]*\.tfstate(\.[^/]*)?)$')
[ -n "$tf" ] && report "tracked Terraform plan/state file(s) (contain secrets): $(echo $tf)"

if [ "$fail" -ne 0 ]; then
  echo "Injection scan FAILED. Do not install or build this revision; alert the team." >&2
  exit 1
fi
echo "Injection scan: clean"

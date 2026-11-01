#!/usr/bin/env bash
# Layer-1 JezPress code review through the Codex CLI (OpenAI).
#
# Builds the prompt from .claude/codex-review-prompt.md plus a per-review brief,
# runs Codex in a read-only sandbox, writes the report to --out and prints it.
# The final reviewer (the jezpress-code-reviewer agent) then reviews the same
# diff blind and rules on these findings. See .claude/audit-guide.md.
#
# Usage (from the repo root):
#   .claude/scripts/codex_review.sh --range v1.2.0..HEAD [--uncommitted] \
#       [--brief <file>] [--out <file>] [--model <model>] [--effort <level>]
#
# Exit codes: 0 report written (read its VERDICT line), 2 usage error,
#             3 codex not installed or not logged in, 4 codex run failed.

set -euo pipefail

MODEL="${CODEX_REVIEW_MODEL:-gpt-6.1-sol}"
EFFORT="${CODEX_REVIEW_EFFORT:-high}"
RANGE=""
UNCOMMITTED=0
BRIEF=""
OUT=""

# A value-taking option needs a non-empty value that is not another option.
need_value() {
	case "${2:-}" in
		''|--*) echo "codex_review: $1 needs a value" >&2; exit 2 ;;
	esac
}

while [ $# -gt 0 ]; do
	case "$1" in
		--range) need_value "$@"; RANGE="$2"; shift 2 ;;
		--uncommitted) UNCOMMITTED=1; shift ;;
		--brief) need_value "$@"; BRIEF="$2"; shift 2 ;;
		--out) need_value "$@"; OUT="$2"; shift 2 ;;
		--model) need_value "$@"; MODEL="$2"; shift 2 ;;
		--effort) need_value "$@"; EFFORT="$2"; shift 2 ;;
		-h|--help) sed -n '2,14p' "$0"; exit 0 ;;
		*) echo "codex_review: unknown argument: $1" >&2; exit 2 ;;
	esac
done

if [ -z "$RANGE" ] && [ "$UNCOMMITTED" -eq 0 ]; then
	echo "codex_review: pass --range <git-range> and/or --uncommitted" >&2
	exit 2
fi

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || { echo "codex_review: not inside a git repo" >&2; exit 2; }
PROMPT_FILE="$ROOT/.claude/codex-review-prompt.md"
[ -f "$PROMPT_FILE" ] || { echo "codex_review: missing $PROMPT_FILE" >&2; exit 2; }
case "$EFFORT" in
	minimal|low|medium|high|xhigh) ;;
	*) echo "codex_review: --effort must be one of minimal|low|medium|high|xhigh" >&2; exit 2 ;;
esac

# Fail fast on a bad range: otherwise Codex gets a diff that errors inside its
# sandbox and may report PASS on nothing. (First release has no tag - diff from
# git's empty tree so the root commit is included:
# --range "$(git hash-object -t tree /dev/null)..HEAD".)
if [ -n "$RANGE" ]; then
	rc=0
	git -C "$ROOT" diff --quiet "$RANGE" -- >/dev/null 2>&1 || rc=$?
	if [ "$rc" -gt 1 ]; then
		echo "codex_review: bad --range (git diff failed): $RANGE" >&2
		exit 2
	fi
	if [ "$rc" -eq 0 ] && { [ "$UNCOMMITTED" -eq 0 ] || [ -z "$(git -C "$ROOT" status --porcelain)" ]; }; then
		echo "codex_review: nothing to review - the range and the uncommitted set are both empty" >&2
		exit 2
	fi
fi

if [ -n "$BRIEF" ] && [ ! -f "$BRIEF" ]; then
	echo "codex_review: brief file not found: $BRIEF" >&2
	exit 2
fi

# The review is read-only, so the report must never land inside the repo.
if [ -n "$OUT" ]; then
	if [ -d "$OUT" ] || [ -L "$OUT" ]; then
		echo "codex_review: --out must be a regular file path, not a directory or symlink: $OUT" >&2
		exit 2
	fi
	OUT_DIR="$(cd "$(dirname "$OUT")" 2>/dev/null && pwd -P)" || { echo "codex_review: --out directory does not exist: $(dirname "$OUT")" >&2; exit 2; }
	[ -w "$OUT_DIR" ] || { echo "codex_review: --out directory is not writable: $OUT_DIR" >&2; exit 2; }
	ROOT_REAL="$(cd "$ROOT" && pwd -P)"
	case "$OUT_DIR/" in
		"$ROOT_REAL/"*) echo "codex_review: --out must be outside the repo (use the session scratchpad or \$TMPDIR)" >&2; exit 2 ;;
	esac
	OUT="$OUT_DIR/$(basename "$OUT")"
fi

for d in "$HOME/.local/bin" /opt/homebrew/bin /usr/local/bin; do
	[ -d "$d" ] && PATH="$d:$PATH"
done
export PATH

if ! command -v codex >/dev/null 2>&1; then
	echo "CODEX UNAVAILABLE: codex CLI not installed (npm install -g @openai/codex)" >&2
	exit 3
fi
if ! codex login status >/dev/null 2>&1; then
	echo "CODEX UNAVAILABLE: codex is not logged in (run: codex login)" >&2
	exit 3
fi

WORK="$(mktemp -d "${TMPDIR:-/tmp}/codex-review.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
REPORT="$WORK/report.md"

{
	cat "$PROMPT_FILE"
	echo
	echo "---"
	echo
	echo "# This review"
	echo
	echo "Repo root: \`$ROOT\` (plugin slug: \`$(basename "$ROOT")\`)."
	echo
	echo "Get the diff with:"
	echo
	[ -n "$RANGE" ] && echo "- \`git diff $RANGE\` (committed changes in scope)"
	[ "$UNCOMMITTED" -eq 1 ] && echo "- \`git diff HEAD\` and \`git status --porcelain\` (uncommitted and untracked work, also in scope)"
	echo
	if [ -n "$BRIEF" ]; then
		echo "## Brief from the main session"
		echo
		cat "$BRIEF"
	else
		echo "No extra brief was given. Work out what changed from the diff and the commit messages."
	fi
} > "$WORK/prompt.md"

if ! codex exec \
	--model "$MODEL" \
	-c "model_reasoning_effort=\"$EFFORT\"" \
	--sandbox read-only \
	--cd "$ROOT" \
	--ephemeral \
	--color never \
	--output-last-message "$REPORT" \
	- < "$WORK/prompt.md" > "$WORK/codex.log" 2>&1; then
	echo "codex_review: codex exec failed. Last lines of its log:" >&2
	tail -20 "$WORK/codex.log" >&2
	exit 4
fi

if [ ! -s "$REPORT" ]; then
	echo "codex_review: codex returned an empty report. Last lines of its log:" >&2
	tail -20 "$WORK/codex.log" >&2
	exit 4
fi

grep -q '^VERDICT: ' "$REPORT" || echo "codex_review: WARNING - report has no VERDICT line (truncated?); treat as not passed" >&2

echo "# Layer-1 review: Codex $MODEL (effort $EFFORT)"
echo
cat "$REPORT"

[ -z "$OUT" ] || cp "$REPORT" "$OUT"

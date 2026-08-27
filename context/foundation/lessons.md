# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## Terminal communication in Polish, commits in English

- **Context**: Any interaction with the AI agent in this repository — chat/terminal responses vs. git commit messages.
- **Problem**: Without this rule, the agent may default to English in terminal replies (mismatching the user's working language) or write commit messages in Polish (breaking the English-only convention expected for git history).
- **Rule**: All terminal/chat communication with the user must be written in Polish. Git commit messages must always be written in English, regardless of the conversation language.
- **Applies to**: all

## CSV export of free-text fields needs formula-injection escaping

- **Context**: Any CSV export service that writes free-text into cells (e.g. `src/Service/Export/DiaryExportService.php` and any future export service).
- **Problem**: Today `DiaryExportService` only exports dates, range-constrained numerics, and a fixed enum — none can start with `=`, `+`, `-`, or `@`, so there's no CSV/formula-injection risk yet. If a free-text field (e.g. a future "notes" column) is ever added, a value like `=cmd|'/c calc'!A1` could execute as a formula when opened in Excel/Sheets.
- **Rule**: Before adding a free-text field to any CSV export, escape leading `=`, `+`, `-`, `@` (prefix with a single quote or strip the leading character).
- **Applies to**: CSV/spreadsheet export services

# Git workflow

## Branches
- `main`: stable, demo-ready. Only merged from `develop` at the end of a phase.
- `develop`: integration branch. All feature branches merge here.
- `feature/<short-name>`: one branch per task, e.g. `feature/dept-routing`.
- `fix/<short-name>`: bug fixes.

## Commit messages
`type: short summary` (imperative, max ~70 chars). Types:

| Type | Use for |
|---|---|
| `feat` | a new feature or behaviour |
| `fix` | a bug fix |
| `test` | adding or fixing tests |
| `docs` | documentation only |
| `refactor` | code change with no behaviour change |
| `chore` | tooling, config, dependencies |

Examples: `feat: add assigned_department_id to tickets`, `test: department seeder`, `docs: phase 1 notes`.

## Code review checklist (before merging a feature branch)
1. `php artisan test` passes.
2. Migrations run on a fresh database (`migrate:fresh --seed`) and roll back (`migrate:rollback`).
3. Access control: a user from another department cannot see or change the record (hidden = 404, no permission = 403).
4. Input is validated; no raw SQL with user input; no secrets in the code.
5. Naming and style follow the surrounding code; no leftover debug code.
6. Docs updated (`docs/OPEN_QUESTIONS.md` for any decision that needs the school's confirmation).

## Per phase
One commit (or a few small ones) per phase of `PHASED_PROMPTS.md`, then merge to `develop`.

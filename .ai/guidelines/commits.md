# Commit & Pull Request Guidelines

- Short imperative commit messages in English (e.g., `Add character store action`). Group related changes; avoid formatting-only noise in feature commits.
- Branch names: `fix/{slug}`, `feature/{slug}`, `refactoring/{slug}`, or `docs/{slug}`. Default integration branch: `develop` (create when the GitHub remote exists). Do not push directly to `develop` or `master`/`main`. PRs on GitHub Actions.
- Before opening a PR: sync with the target branch, run `composer check-parallel`, fix failures.
- PR description in Russian when stakeholders need it: Зачем / Что сделано / Проверка (+ Риски when relevant).
- Do not commit `.env`, `database/database.sqlite` with player data, or secrets.

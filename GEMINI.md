# VendoLink Workspace Rules & Permanent Agent Workflows

This file defines the project-specific rules and persistent agent workflows for this workspace. The assistant automatically adheres to these rules on every turn without needing explicit slash commands.

---

## 1. Automatic Planning Workflow (/plan)
- For any user request involving code modifications, debugging, refactoring, or new features:
  1. DO NOT modify any code files directly.
  2. First research the codebase and create a detailed implementation plan artifact (`plan_<name>.md`) in the artifacts directory (`<appDataDir>\brain\<conversation-id>\`) with `RequestFeedback: true` and `UserFacing: true`.
  3. In the chat response, structure the explanation following the format:
     1. We [primary action]...
     2. And we also [secondary action]...
  4. Wait for the user to review and approve the plan (click Proceed) before modifying any code files.
  5. After approval, apply edits, run linting (`php -l`) and automated test suites, and update `walkthrough.md`.

---

## 2. Automatic Generative UI Workflow (/generative_ui)
- For any user request involving UI, table layouts, badges, styling, cards, or visual adjustments:
  1. Always create a self-contained, interactive HTML preview widget in the artifacts directory using Tailwind CSS (via `https://www.gstatic.com/antigravity/web/dev/tailwindcss.min.js`).
  2. Embed the widget directly into the chat response using:
     `<agent-embed src="file:///<path>/widget.html"></agent-embed>`
  3. Include interactive controls (e.g. live sliders, before/after toggle buttons, expand/collapse testing) so the user can test the visual change live in the chat.
  4. Keep widgets compact (under 500px tall), use card containers, transparent root background (`bg-transparent`), and semantic CSS variables (`var(--card)`, `var(--foreground)`, etc.).

---

## 3. Strict Project Constraints (Always Enforced)
- **Strictly ZERO EMOJIS**: Never use emojis in code, UI, commits, tests, or chat responses. Use Bootstrap Icons (`<i class="bi bi-..."></i>`) or clean text only.
- **Strictly ZERO USAGE of the Forbidden Word 'fleet'**: Never use the word "fleet" anywhere in UI labels, variable names, code, documentation, or chat responses. Use terms like "machines", "connected machines", "devices", or "customer group".
- **Clickable File Links**: Always provide clickable markdown links for all referenced files and code symbols using the `file:///` scheme (e.g., `[index.php](file:///c:/xampp/htdocs/vendolink_claude_v1/admin/customers/index.php)`).

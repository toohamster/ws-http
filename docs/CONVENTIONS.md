# Documentation Conventions (docs/ 双语文档规范)

> **Scope**: this document governs everything under `docs/reference/` (bilingual user manual) and the two root READMEs.
> **Out of scope**: `design/` (developer-facing decision archive — Chinese by design, never translated) and root-level operational files (`CHANGELOG.md`, `CLAUDE.md`).
> Language switch line format, file pairing, section templates, and the terminology table below are **binding** for every document generated after 2026-10-28.

---

## 1. Audience map (who reads what)

| Reader | Reads | Language |
| --- | --- | --- |
| Tool user (GitHub / Packagist visitor) | `README.md` + `docs/reference/*` | English default, Chinese switchable |
| Tool user investigating mechanics | `docs/*` guides (wiki) | Chinese (growing to bilingual later, not required) |
| Project developer (design traceability) | `design/*` + `design/PROGRESS.md` | Chinese (kept as-is, never translated) |

**Division of labor (one line each):**

- `README` — what the project is, install, first lines of code, navigation;
- `docs/reference/` — the manual: module-by-module API and usage (bilingual);
- `docs/` root guides — how the system works internally (narrative wiki);
- `design/` — why it is designed this way, contracts (decision archive).

## 2. File pairing (bilingual mechanism)

1. **Naming**: `<module>.md` (English, the default) + `<module>.zh-CN.md` (Chinese mirror). Same basename, one-to-one. A missing pair member is a broken index entry — treat it as a build error.
2. **Switch line**: the **first line** of every paired document (both languages) is exactly:

   ```markdown
   [English](./<module>.md) | [中文版本](./<module>.zh-CN.md)
   ```

   For the two root READMEs: `[English](README.md) | [中文版本](README.zh-CN.md)` (each file links to its counterpart).
3. **Paragraph alignment**: the Chinese mirror follows the English document section-for-section, same order, same headings count. A section added to one language must be added to the other in the same change (one commit per content unit — no language drift).
4. **What is NOT translated**:
   - Code blocks (identical in both languages; only comments inside code may be translated);
   - Class / method / namespace names, JSON keys, file names, error codes, CLI flags;
   - Links' target paths (both versions link to the same real targets).
5. **Which language wins on conflict**: English is authoritative; the Chinese mirror is updated to match. Fix the English first, then the mirror.

## 3. Section template (every reference page, both languages)

Each module page contains exactly these five top-level sections, in this order:

```markdown
## Overview                 — what the module is for, its design intent (2–5 sentences)
## Key Components           — classes/interfaces with real signatures (PHP 7.4 types)
## Usage Examples           — ≥ 2 runnable snippets from real APIs (never invented ones)
## Configuration            — config keys/defaults, or "None" with one line explaining why
## Dependencies             — depends-on / depended-on-by, links to related pages & design docs
```

Additional rules:

- Signatures are copied from `src/` (grep-verified), formatted as ```php blocks with doc-comment summaries;
- Every example must be reproducible from the shown signature — no `new HttpClient()`-style hallucinations (the real entry is `new Ws\Http\Request()`);
- JSON examples use `jsonc` fencing when they carry comments;
- Cross-page links use **relative paths**; design-doc links (filenames contain spaces) must URL-encode spaces as `%20`;
- Each page ends with a "Design details" line linking the authoritative `design/` document.

## 4. Terminology table (binding for the Chinese mirrors)

| English | 中文 (fixed) | Note |
| --- | --- | --- |
| Scenario | 场景 | never 剧本/脚本流程 |
| Assertion | 断言 | |
| Extraction / extractor | 提取 / 提取器 | |
| Variable scope | 变量作用域 | |
| Transport | 传输(厂商接入层) | NanoGpt context |
| Protocol family | 协议族 | |
| Vendor class | 厂商类 | |
| Sandbox | 沙箱 | |
| Snapshot / restore | 快照 / 恢复 | |
| Compaction / compactor | 压缩 / 压缩器 | context compaction context |
| Usage (tokens) | 用量 | cost accounting |
| Context window | 上下文窗口 | |
| Budget | 预算 | |
| Memory (long-term) | 长期记忆 | |
| Skill | 技能(能力包) | first mention may carry "能力包" |
| Descriptor file | 描述文件 | `.tool.json` / `.skill.json` |
| Model catalog | 模型目录 | |
| Capability profile | 能力档案 | |
| Cookie jar | Cookie 罐 | or keep "jar" — 首现标注 |
| Webhook / Bearer / JSONPath / Netscape | 不译 | |
| Runner / Watcher / Registry / Agent | 不译(类名) | |
| Dataset | 数据集 | |
| Batch | 批量 | |
| Redaction / redactor | 脱敏 | |
| Fail-fast | 不译(惯用) | |
| Wither (style) | wither 风格(不可变派生) | 首现解释一次 |
| Escape hatch | 逃生门 | GenericOpenAiTransport context |

**Never translate**: identifiers (`Ws\Http\*`), HTTP method names, HTTP status semantics, MIME types, curl constants, PSR terms.

## 5. Index & navigation contract

- `docs/README.md` is the **single index**: sections `Reference (manual)` and `Guides (mechanics wiki)` + a pointer to `design/` for contributors;
- Every index entry lists **both** language links side by side: `[module](reference/<module>.md) · [中文](reference/<module>.zh-CN.md)`;
- The root `README.md` (English) links to `docs/README.md` and to each reference page grouped by layer; `README.zh-CN.md` mirrors it;
- `docs/` guides link to their related reference pages and vice versa (Related: …).

## 6. Change protocol

1. **One content unit = one commit** (both languages + index updates together) — no language-split commits;
2. All relative links validated (target exists) before commit;
3. New modules get a new pair + index line in the same commit;
4. Deprecating a page: remove both files + both index entries in one commit; never leave a dangling pair.

---

*Established 2026-10-28. Binding for all docs after this date. Changes to this file itself require project-owner confirmation.*

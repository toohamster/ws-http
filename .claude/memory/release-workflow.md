# 发布工作流(2026-09-24 用户提供,2026-10-09 修订分支映射)

> 触发指令:"发布到 main" / "发布新版本" → 执行完整发布流程。
> 分支映射:**本地 `master` = 开发分支;远端 `main` = 主分支**——
> `git push origin master:main` + `git push origin --tags`。

## 工具与凭据

- **发起 PR 和打 Tag 使用 GitHub API(curl),不使用 `gh` CLI**;
- Token 从 git 配置提取:
  ```bash
  git config --get remote.origin.url
  # 输出:https://username:token@github.com/owner/repo.git
  # 提取 token 后:curl -H "Authorization: Bearer $TOKEN" ...
  ```

## 发布流程

### A. 有功能分支时
1. 检查未提交修改,如有先提交(走正常确认制);
2. 推送功能分支到远程;
3. 从 git config 提取 GitHub Token;
4. 对比远程 main 确认未合并提交:`git fetch origin && git log origin/main..HEAD`;
5. curl 创建 Pull Request 到 main;
6. 自动合并 PR;
7. 自动生成版本号并打标签;
8. curl 创建 GitHub Release(发布说明取 CHANGELOG 对应条目)。

### B. 已在 master(当前常态)
1. 检查未提交修改,如有先提交(走正常确认制);
2. `git push origin master:main` + `git push origin --tags`;
3. 自动生成版本号并打标签(若本次变更尚未打);
4. curl 创建 GitHub Release,summary 生成三步:
   a. **主体 = CHANGELOG 对应版本条目**(经过 review,优先于 commit 反推);
   b. `git log <lastTag>..HEAD --oneline` **核对条目完整性**(发现遗漏 → 先报告用户补记,不擅自扩);
   c. 首屏一句话摘要(按 commit 类型归并)+ 条目正文 → curl 创建;
   - 若发布时 CHANGELOG 无对应条目 → **先补条目给用户 review,再创建 Release**(不拿 commit log 凑合)。

## 版本号规则(与 MEMORY.md semver 六条同构,勿两套标准)

基于最新标签自动递增:
- 小修复:v2.0.2 → v2.0.3(PATCH)
- 新功能:v2.0.2 → v2.1.0(MINOR)
- 重大变更:v2.0.2 → v3.0.0(MAJOR)
- 纯 semver,描述后缀禁用;预发布用标准词(-beta.1/-rc.1)

## 已发布标签不可移动(铁律,2026-10-21 Packagist 告警教训)

**已 push 的 tag = 对外承诺,永远不移动**(不 force push tag、不删重打):
- Packagist 对 stable 版本**不可变**:tag 指向的 commit 一旦被抓取即固化;移动已发布 tag → Packagist 阻断更新并告警("source/dist reference changed"),下游用户对同一版本号拿到不同内容 = 破坏 semver 根本承诺;
- **tag 后发现的任何遗漏(CHANGELOG 补段/docs 修正/代码修复),一律进位新版本**(纯 docs 补段 → PATCH 进位),新 tag + push,旧 tag 永不回头;
- 打 tag 前的最后自检:确认 CHANGELOG/README/docs 全部就位**之后**才打 tag——tag 是流程的最后一步,不是中间步骤;
- 若已发生移动:恢复 tag 到原 commit(`git tag -f <tag> <原commit>` + force push tag)+ 变更内容进位新版本重新发布。

## 默认行为

- 默认**保留远程功能分支**,除非用户明确说"删除远程分支";
- 完成后**留在当前功能分支**,除非用户明确说"切换到 main";
- push 恒由用户指令触发("发布到 main"/"发布新版本"/明确说 push),不自动推。

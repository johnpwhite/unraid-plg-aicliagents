# Source-host and live-agent notification survey

## Purpose and method

This survey supports the pluggable live-session notification design in
`docs/specs/SOURCE_HOST_LIVE_AGENT_NOTIFICATIONS.md`.

“Top 10” here means the ten platform families that give AICliAgents the most
useful practical coverage across major SaaS products, enterprise installations,
and common self-hosted Git software. It is a product-priority list, not a claim
of exact market-share order; no authoritative dataset compares SaaS and
self-hosted installations on one consistent measure.

The agent inventory comes from the plugin's live `AgentRegistry` source on
2026-07-14. It includes all 12 built-in agents plus the custom-agent manifest
path. Current official documentation, installed help where available, and the
project's previously verified Config Hub surface audit were used to distinguish
MCP tool access from true external event delivery.

## Ten priority Git and work-tracking platform families

| Priority | Platform family | Deployment | Work-item model | Event input | Architectural consequence |
|---:|---|---|---|---|---|
| 1 | GitHub | GitHub.com and GitHub Enterprise Server | Issues, pull requests, discussions | Repository/organization/GitHub App webhooks; REST/GraphQL reconciliation | One adapter family can share canonical models, but each configured cloud or enterprise host is a separate instance with its own app/token and hostname matcher. |
| 2 | GitLab | GitLab.com, Self-Managed, Dedicated | Issues, work items, epics, merge requests, notes | Project/group/system webhooks; REST/GraphQL reconciliation | Nested namespace paths and group-scoped work items mean repository identity cannot be reduced to the last two URL segments. |
| 3 | Bitbucket | Bitbucket Cloud and Bitbucket Data Center | Cloud has an optional repository issue tracker and pull requests; Data Center centres repositories/pull requests and commonly links Jira | Cloud webhooks/API; Data Center webhooks/API | Cloud and Data Center require separate provider implementations behind one family label. Jira linkage must be an explicit work-source mapping, not guessed from the Git remote. |
| 4 | Azure DevOps | Azure DevOps Services and Azure DevOps Server | Project-scoped work items plus Git pull requests | Service Hooks and REST APIs | Organisation, collection, project, and repository identities are distinct. A project work item may affect several repositories, so explicit mapping is first-class. |
| 5 | Forgejo | Self-hosted; Codeberg is a prominent hosted deployment | Issues, pull requests, comments, labels, milestones | Repository/organisation/system webhooks and REST API | First implementation target. Share only tested primitives with Gitea-family adapters; keep Forgejo signature headers, payload versions, and API variance inside its adapter. |
| 6 | Gitea | Self-hosted and hosted installations | Issues, pull requests, comments, labels, milestones | Repository/organisation/user/system webhooks and REST API | Similar ancestry to Forgejo is an implementation reuse opportunity, not permission to treat their payloads and versions as identical. |
| 7 | Gerrit Code Review | Self-hosted | Review changes, patch sets, comments, reviewers, votes | Privileged SSH `stream-events`; optional webhooks plugin; REST API | Its canonical item is a `change`, not an issue. It needs a streaming event source and a review-change normaliser rather than an issue adapter. |
| 8 | SourceHut | Hosted service suite; components can be self-hosted | `todo.sr.ht` trackers/tickets are separate from `git.sr.ht` repositories | GraphQL APIs and tracker webhook subscriptions | Repository and tracker are different services. Require an explicit repository-to-tracker mapping and preserve SourceHut's cursor/UUID identities. |
| 9 | Gogs | Self-hosted | Issues and pull requests | Repository webhooks and REST API | GitHub-like/Gitea-family concepts allow shared fixtures and normalisation helpers, but the adapter owns its narrower event and authentication surface. |
| 10 | GitBucket | Self-hosted JVM application | Issues and pull requests | GitHub-compatible-style API and webhooks | Treat compatibility as versioned capability detection. Never route it through the GitHub adapter merely because many shapes look familiar. |

### Coverage tiers

- **Tier 1 implementation:** Forgejo, GitHub, GitLab, Gitea. These cover the
  current installation plus the largest public/SaaS ecosystems and the dominant
  lightweight self-host family.
- **Tier 2 implementation:** Bitbucket Cloud, Bitbucket Data Center, Azure
  DevOps, Gerrit. These add enterprise and review-centric models that validate
  the abstraction.
- **Tier 3 implementation:** SourceHut, Gogs, GitBucket. These validate split
  tracker/repository mapping and long-tail self-host compatibility.

Forgejo remains the first concrete adapter and test fixture. The core must not
contain Forgejo identifiers, headers, URL rules, or issue JSON fields.

## Platform capability differences the interface must preserve

| Capability | Examples | Design rule |
|---|---|---|
| Repository and tracker are the same resource tree | GitHub, GitLab, Forgejo, Gitea | Adapter may derive the default work-source mapping from the repository identity. |
| Tracker is project-scoped rather than repository-scoped | Azure DevOps | Mapping can be one project/work source to many repositories. |
| Tracker is a separate service | SourceHut; Bitbucket Data Center with Jira | A separate configured work-source connector and explicit mapping are mandatory. |
| Primary work item is a code-review change | Gerrit | Canonical model must include `review_change`; do not force it into issue semantics. |
| Webhook supported | Most reviewed platforms | Webhook is a low-latency hint and is verified by the provider adapter. |
| Durable poll/reconciliation API | GitHub, GitLab, Azure DevOps, Forgejo/Gitea families, SourceHut | Adapter owns cursors, pagination, rate limits, ETags, and look-back rules. |
| Long-lived event stream | Gerrit | Event source lifecycle supports streams in addition to polls and webhooks. |
| Organisation/system-wide hook scope | GitHub, GitLab, Forgejo, Gitea | Connector advertises supported scopes; the core does not assume one hook per repository. |

## AICliAgents built-in agent inventory

| AgentRegistry id | Agent | MCP tool surface | Verified native push into the existing interactive session | Recommended first delivery profile |
|---|---|---:|---:|---|
| `claude-code` | Claude Code | Yes | **Yes:** Claude Channels, research preview | Native Channel when loaded; safe tmux/inbox fallback otherwise. |
| `gemini-cli` | Gemini CLI | Yes | Not verified | MCP read/ack tools plus version-tested idle-gated tmux wake. |
| `qwen-code` | Qwen Code | Yes | Not verified for an existing TUI; SDK/ACP automation surfaces exist | MCP read/ack tools plus idle-gated tmux wake; investigate native SDK/ACP attachment separately. |
| `opencode` | OpenCode | Yes | Not verified | MCP read/ack tools plus idle-gated tmux wake. |
| `kilocode` | Kilo Code | Yes | Not verified for the existing TUI; server, attach, ACP, remote, and daemon surfaces exist | MCP read/ack tools plus idle-gated tmux wake; a future native adapter may use the supported server/attach protocol. |
| `codex-cli` | Codex CLI | Yes | Not exposed by the existing TUI; app-server is a separate integration surface | MCP read/ack tools plus idle-gated tmux wake. Do not start a replacement app-server thread. |
| `factory-cli` | Factory Droid CLI | Yes | Not verified | MCP read/ack tools plus idle-gated tmux wake. |
| `gh-copilot` | GitHub Copilot CLI | Yes | Not verified | MCP read/ack tools plus idle-gated tmux wake. |
| `goose` | Goose | Yes | Not verified for the existing TUI; API and ACP surfaces exist | MCP read/ack tools plus idle-gated tmux wake; investigate an attachable native API adapter separately. |
| `antigravity-cli` | Antigravity CLI (`agy`) | Yes | Not verified | MCP read/ack tools plus idle-gated tmux wake. |
| `nanocoder` | NanoCoder | Yes | Not verified | MCP read/ack tools plus idle-gated tmux wake. |
| `pi-coder` | Pi Coder | **No:** upstream deliberately omits MCP | Not verified | Scoped local notification CLI/file reader plus idle-gated tmux wake. |

### Custom agents

`AgentRegistry::getRegistry()` merges user-defined agents from
`/boot/config/plugins/unraid-aicliagents/agents.json`. Therefore a design that
switches only over the 12 built-in ids is incomplete.

Custom-agent rules:

- The safe default is `inbox-only`; an unknown TUI must never inherit a generic
  Enter-key sequence or idle detector.
- A custom manifest may select a trusted, installed delivery profile and declare
  compatible version constraints.
- Declarative manifests may configure capabilities and launch integration, but
  may not name arbitrary PHP classes or executable adapter code.
- Third-party delivery adapters require the plugin's normal signed/package trust
  path before they can run code.
- A newly added built-in agent must make an explicit notification-delivery
  decision, enforced by a regression guard in the same style as Config Hub's MCP
  decision guard.

## What MCP does and does not solve

MCP is the preferred common read/ack tool surface for 11 built-ins. It lets an
agent retrieve a bounded canonical event after it has been notified. Standard
MCP tool availability does not itself wake an interactive TUI. Claude Channels
are a vendor extension that does provide push delivery; no equivalent was
verified for the other existing interactive sessions.

The durable inbox and visual badge are therefore universal. Native push and
tmux wake are replaceable delivery strategies layered above that inbox.

## Sources

### Git/work platforms

- GitHub webhook types and GitHub App delivery:
  <https://docs.github.com/en/webhooks/types-of-webhooks>
- GitLab webhook events:
  <https://docs.gitlab.com/user/project/integrations/webhook_events/>
- Bitbucket Cloud webhooks and issue events:
  <https://support.atlassian.com/bitbucket-cloud/docs/manage-webhooks/>
- Azure DevOps Service Hook events:
  <https://learn.microsoft.com/en-us/azure/devops/service-hooks/events?view=azure-devops>
- Forgejo webhooks:
  <https://forgejo.org/docs/latest/user/webhooks/>
- Gitea webhooks:
  <https://docs.gitea.com/usage/repository/webhooks>
- Gerrit `stream-events`:
  <https://gerrit-review.googlesource.com/Documentation/cmd-stream-events.html>
- SourceHut tracker GraphQL/webhook API:
  <https://docs.sourcehut.org/todo.sr.ht/>
- Gogs webhook/API documentation:
  <https://gogs.io/docs/features/webhook>
- GitBucket project documentation:
  <https://gitbucket.github.io/>

### Agents

- Claude Code Channels:
  <https://code.claude.com/docs/en/channels-reference>
- OpenAI Codex manual:
  <https://developers.openai.com/codex/codex-manual.md>
- Gemini CLI MCP management:
  <https://github.com/google-gemini/gemini-cli/blob/main/docs/cli/cli-reference.md>
- OpenCode MCP servers:
  <https://opencode.ai/docs/mcp-servers>
- Kilo Code CLI and MCP:
  <https://kilo.ai/docs/code-with-ai/platforms/cli>
- GitHub Copilot CLI MCP:
  <https://docs.github.com/en/copilot/how-tos/copilot-cli/customize-copilot/add-mcp-servers>
- Goose agent and API/ACP overview:
  <https://block.github.io/goose/>
- Qwen Code MCP:
  <https://qwenlm.github.io/qwen-code-docs/en/users/features/mcp/>

Factory, Antigravity, NanoCoder, and Pi surfaces also use the repository's
official-doc-verified Config Hub audit at
`docs/CONFIG_HUB_AGENT_SURFACE_AUDIT_2026-06.md`, plus the current registry and
installed CLI help where available.

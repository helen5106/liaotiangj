# AI Engine — Strategy & WordPress 7.0 Roadmap

> **Audience:** humans and AI agents working on AI Engine Pro.
> **Purpose:** anchor every decision to (1) how AI Engine is *actually* used and (2) the WordPress 7.0 reality landing in mid-late May 2026.
> **Visual brief:** `/Users/meow/Desktop/ai-engine-wp7-brief/index.html` (three-page editorial brief with full source citations and code samples).
> **Last updated:** 2026-04-20

---

## Part 1 — How AI Engine is *actually* used

Three independent deep-research passes (Gemini, ChatGPT, Claude) landed on the same priority ordering. Use this whenever you're tempted to optimise something niche before something core.

### The consensus, in priority order

| Rank | Feature | Estimated share of real usage | Why it matters |
|---|---|---|---|
| **1** | **Chatbots** (front-end assistants) | **~35–50%** | The single biggest reason people install AI Engine. Non-technical site owners drop a shortcode, set a prompt, done. Dominates reviews, tutorials, support traffic. **Entry point.** |
| **2** | **Content generation** (Magic Wand, Editor Assistant, Playground) | **~18–20%** | The "reason I bought Pro" feature for many. Bulk writing, translation, SEO, WooCommerce product descriptions. Especially big in Spanish/Portuguese markets. **Retention driver.** |
| **3** | **Knowledge base / RAG / Embeddings** | **~12–15%** | Top Pro conversion driver. Users love how easy it is to RAG their own content. **Common misunderstanding:** users expect embeddings to *override* the model's base knowledge and get confused when they don't. Worth surfacing this in UX. |
| Cross-cutting | **Multi-provider / BYOK flexibility** | (not named, but the *choice* driver) | Why people pick AI Engine over GetGenie, Bertha, Jetpack AI. OpenAI, Claude, Gemini, Mistral, OpenRouter — wins roundup slots. |
| Cross-cutting | **Cost control & usage analytics** | Universal | "Will I get a surprise bill?" is the #1 fear that keeps people *out* of AI on WordPress. AI Engine answering this credibly is the most concrete sellable feature. |
| **4** | **MCP server** | **~12–15% of recent support, <5% of installs** | The strategic bet. Fastest-growing surface. Real early traction with Claude Code, ChatGPT, multi-site agents. Almost all MCP content comes from us; the biggest external validation came from security press covering CVEs. |
| **5** | **Function calling** | **~5%** | Niche but growing, tied to MCP and WooCommerce automation. Booking bots, inventory lookups. Zero third-party tutorials. |
| **6** | **AI Forms** | **~3–5%** | Prominent in marketing, nearly invisible in the wild. Pro-gated, no third-party tutorials. Users who need forms already have Fluent/WPForms/Gravity. |
| **7** | **Image generation** | **~5–7%** | A feature, not a reason. Usually a 5-minute segment in broader tutorials. |
| **8** | **WooCommerce integration** | C+, climbing | Bulk product descriptions established. The new 25-tool WooCommerce MCP module has potential but minimal external coverage yet. |
| **9** | **Realtime audio / voice** | D | First-party marketing, zero community uptake so far. |
| **10** | **OpenAI Assistants** | D | Essentially no organic discussion. Users wanting agent flows are going straight to MCP. May be a feature whose moment passed. |

### Strategic implications of the usage data

- **Chatbots get the headline UX love.** Any UI/UX investment without a chatbot-experience improvement is poorly prioritized.
- **Content generation needs polish, not new features.** It already wins; keep it winning.
- **Embeddings/RAG needs a UX clarification pass.** Users misunderstand it; this is fixable with better in-UI copy and examples.
- **Multi-provider story should be loud.** It's the differentiator on roundup posts — make sure it's visible everywhere.
- **Cost control is the marketing headline.** "You won't get a surprise bill" is the most credible thing we can say to a hesitant user.
- **MCP is the long bet.** Keep investing; it's the moat that'll matter most in 2027–28.
- **Don't over-invest** in Realtime, Assistants, or Forms unless something specific changes — they consume engineering attention without commensurate adoption.

---

## Part 2 — WordPress 7.0 reality

WP 7.0 is in RC 2 (April 2026), expected release mid-late May 2026. Three new APIs land in `wp-includes/`:

### What ships

| Piece | What it does | File |
|---|---|---|
| **Connectors API** | Generic credential + UI registry. Generic across types (`ai_provider`, `spam_filtering`, etc.). Auth methods: `'api_key'` or `'none'` only. Per-connector option storage. Built-in stubs for Anthropic / Google / OpenAI with `plugin.file` pointers to single-provider plugins. | `wp-includes/connectors.php` |
| **AI Client** | Thin builder over the bundled `php-ai-client` PSR SDK. Provider-agnostic. Supports text, image, multimodal in, structured JSON. **Function calling exists via Abilities** (`->using_abilities()` + `WP_AI_Client_Ability_Function_Resolver`). | `wp-includes/ai-client.php` |
| **Abilities API** | Tool registration with JSON schema + permission + execute callbacks. **Single registration becomes:** AI function call (via AI Client) + MCP tool (via the separate MCP Adapter plugin) + REST endpoint (with `show_in_rest`). | `wp-includes/abilities-api.php` |

### Hooks on the prompt path (the entire injection surface)

- `wp_supports_ai` (filter) — master switch
- `wp_ai_client_prevent_prompt` (filter) — block / re-route a prompt; **our cost-cap hook**
- `wp_ai_client_default_request_timeout` (filter)
- `wp_ai_client_{event_name}` (dynamic action) — every PSR-14 SDK event fires a WP action; **our unified-stats hook**
- `wp_connectors_init` (action) — register Connectors here

### Key constraints found in source

- **Connectors auth is single-field.** Azure (endpoint + region + deployments), Bedrock, Vertex, Ollama, custom endpoints **don't fit core's UI.** Permanent moat for AI Engine.
- **No "default provider per task" anywhere.** AI Engine has it (`ai_default_env`, `ai_images_default_env`, etc.); the framework doesn't.
- **API keys stored unencrypted** in per-connector options.
- **No streaming, no embeddings** in the WP wrapper (they exist in the SDK but aren't exposed).
- **No cost tracking, no caps, no charts** — at all.

### The honest read on the ecosystem

| Observation | Reality |
|---|---|
| Plugins consuming `wp_ai_client_prompt()` | **One** — the WP AI Team's `ai/` plugin (v0.7.0) |
| Provider registration plugins | Three pure 50-line shims (`ai-provider-for-anthropic` / `-google` / `-openai`) |
| Third-party plugins on WordPress.org adopting the framework | **Zero** (as of RC 2) |
| Will SEO/page-builder/major plugins switch? | Most won't soon. Realistic adoption window: 12–24 months. They have working integrations and the framework's API is too limited. |
| Status of "the AI plugin"'s features | Every feature is in an `Experiments/` directory. Every model preference and temperature is hard-coded. No per-feature controls. |

**Strategic implication:** the framework is beautifully designed but its current ecosystem is mostly theatre. The **Connectors page is valuable real estate** (users land there during AI setup) regardless of how many plugins adopt the underlying API.

---

## Part 3 — AI Engine's positioning

**The one-line position:** *AI Engine ships stable connectors for an experimental framework.*

This works because it's **literally true**. The WP AI Team's plugins are explicitly experimental (their own labeling). AI Engine has been production-grade for years with mature implementations of every provider on core's list.

### The four supporting framings

1. **One install, every connector.** AI Engine covers Anthropic, Google, OpenAI, Perplexity, OpenRouter, Replicate, Mistral — and Azure (which core's UI structurally cannot host).
2. **Multiple Environments per provider.** Core's Connectors API is one row per provider id. AI Engine's Environments are unlimited (e.g. "OpenAI Personal" + "OpenAI Work" + "OpenAI Client A"). Permanent structural moat.
3. **Per-task default routing.** Chat, Image, Audio, Embeddings — each gets its own default Environment. Core has nothing like this.
4. **Cost dashboard + spend caps.** Live charts, running totals, hard limits, alerts. Core has zero of this; the WP AI plugin doesn't even attempt it. **The headline marketing answer to "will I get a surprise bill?"**

### The audience messages (use these on launch)

**End users:**
> *"WordPress 7 introduces basic AI features. AI Engine adds what's missing: a chatbot for your visitors, a Magic Wand in the editor you can actually configure, AI forms, voice agents, semantic search — and the thing nobody else gives you, **a real cost dashboard with spend caps so you don't get a surprise bill.** Same API key. Real product. No surprises."*

**Plugin developers:**
> *"Build with `wp_ai_client_prompt()` for the simple things, build with AI Engine for everything else. We're the application layer above the platform — function calling that works cross-provider, embeddings, streaming, agent orchestration."*

**Agencies:**
> *"AI Engine is the responsible-adult layer for WordPress AI. Cost caps, statistics, audit trail, GDPR tooling, Azure (which core's UI can't host), multiple accounts per provider, per-feature default routing, smart fallbacks. Install once, ship AI projects with confidence."*

---

## Part 4 — The work plan

### Architectural principle

**All WP 7 integration code lives in `/labs/wp7-integration/`.** AI Engine's core stays untouched. WP 7 is RC; the API will shift. Keeping the integration in `/labs` means we evolve it at WP's pace, A/B test it, or roll it back without disturbing anything stable. A 3-line conditional loader in AI Engine fires the bootstrap when `wp_ai_client_prompt()` exists. That's the only AI Engine core change required.

```
labs/
└── wp7-integration/
    ├── bootstrap.php           ← gated loader
    ├── providers/              ← Provider classes (one per engine type)
    ├── connectors/             ← Connector registration + override of core stubs
    ├── abilities/              ← Ability registration (mapped from MCP tools)
    ├── connectors-page-takeover/  ← Move 5 UI
    └── README.md               ← clear "experimental" labeling
```

### The six moves (priority order)

| # | Move | Effort | Strategic weight |
|---|---|---|---|
| **1** | **Register AI Engine as Connectors** for OpenAI, Anthropic, Google, Perplexity, OpenRouter, Replicate, Mistral. Override core's stub Connectors so they point at AI Engine instead of the WP AI Team's three shims. Toggle: *"Use AI Engine as the WordPress Connectors page"* (default ON). | ~2 days | High |
| **2** | **Expose AI Engine's tools as Abilities.** Map our existing MCP tool definitions to `wp_register_ability()`. Each one becomes simultaneously an AI function call, an MCP tool, and a REST endpoint. | 2–3 days | High |
| **3** | **Subscribe to `wp_ai_client_*` events** for unified statistics. AI Engine becomes the canonical observability layer for any AI usage on the site, including from other plugins. | 1 day | Narrative |
| **4** | **Ship cost guardrails through `wp_ai_client_prevent_prompt`.** The headline differentiator. *"AI Engine adds the brake pedal to WordPress AI."* | 1–2 days | **Headline** |
| **5** | **Connectors page takeover** (the headliner). Render AI Engine's richer view in place of core's three cards: Environments + per-task defaults + cost chart + spend cap status. Opt-in toggle, easily reversed. | 3–4 days | **Generational** |
| **6** | **Rename Dashboard "Usage" → "Connectors"** and render the same `<ConnectorsPanel>` component on both AI Engine's Dashboard and the WP 7 takeover. One component, two surfaces. | 1–2 days | Cohesion |

### What the takeover view shows (Move 5 + Move 6 share this)

A `<ConnectorsPanel>` React component, rendered both on `options-connectors.php` and on AI Engine's Dashboard:

- **Provider icon** (Anthropic, OpenAI, Google, Azure logos — same icons WP uses)
- **User's chosen Environment name** (e.g. *"OpenAI Personal"*, *"Anthropic Work"*, *"Azure Production"*)
- **Status indicator** (green/amber/grey)
- **Cost & request chart** — sparkline + 30-day total + request count. **The panel users will check most often.**
- **Spend cap status** — *"$8.40 / $20 monthly cap"* with one-click "set a cap" link
- **Default-for badges** — quick read of "where am I routing what" (Default: Chat / Default: Images / etc.)
- **Manage link** — opens AI Engine's full Environment editor

### Critical UX guardrails for Move 5 (keep this respectful)

- The toggle is always one click away (top-right *"← Switch to default WordPress Connectors view"*)
- If the WP AI Team's plugins are installed, acknowledge them; offer a switch back per-provider
- Switch state is per-site (not per-user)
- First-run notice is friendly, not aggressive; dismissible
- Standard four rules for any banner: dismissible, contextual, honest, not blocking

### The "no AI Engine but Meow Common installed" case

Many sites already run Meow Common via Code Engine, Photo Engine, Media File Renamer. On those sites, Meow Common can render a respectful promo banner on the Connectors page offering AI Engine. Same opt-in principles.

---

## Part 5 — Beyond WP 7: roadmap themes

The deep usage research informs not just WP 7 work but everything else we build in 2026.

### Neko UI

- **Support beautifully** in AI Engine — every screen should feel like first-party Neko UI.
- **Improve Neko UI itself** in parallel. The library and the plugin co-evolve.
- The new `<ConnectorsPanel>` (Move 6) is a perfect first showcase.

### Chatbot UX (priority #1 by usage)

- Cleaner shortcode setup flow; guided first-bot experience
- Streaming, markdown rendering, mobile polish
- The chatbot is *why* most users install — every release should have at least one chatbot improvement

### Content generation polish (priority #2)

- Magic Wand and Editor Assistant tightening — keep these elegant
- Translation flows for Spanish / Portuguese markets (heavy real-world usage)
- WooCommerce product description pipelines

### Embeddings UX clarification (priority #3)

- Surface the "embeddings ≠ override of model knowledge" misconception in UI copy
- Better PDF chunking visibility
- Cleaner "what is in this knowledge base?" view

### Cost control (universal need)

- The chart, the cap, the alert — these should be loud in the UI
- Spend cap setup should be in the onboarding flow
- *"You won't get a surprise bill"* — bake this into onboarding, marketing, the dashboard

### MCP (the strategic bet)

- Keep investing — fastest-growing surface
- Better docs (since most MCP content comes from us, not the community)
- Standardize tool authoring patterns for third-party plugins to extend AI Engine's MCP server

### What to deprioritize

- Realtime audio / voice (no community uptake so far)
- OpenAI Assistants (essentially superseded by MCP for users)
- AI Forms (prominent in marketing, invisible in the wild — don't expand unless something changes)

### Bug fixes / polish themes

- General UI/UX consistency pass across screens
- Mobile chatbot experience (recent fixes are a start; keep going)
- Performance: bundle sizes, query speed
- Documentation gaps (especially MCP)

---

## Part 6 — The mantra & things to remember

### The mantra (use this voice consistently)

> **We believe in the future of AI in WordPress.** AI Engine isn't a workaround for an underwhelming framework — it's an active participant in WordPress becoming an AI platform.
>
> **AI Engine is the safe, optimized place that gathers everything.** Instead of installing separate plugins for each provider, AI Engine handles all of them through a single, mature, production-grade implementation. One install, every provider, no fragmentation.
>
> **We streamline, optimize, and provide cost control.** AI Engine takes care of the messy parts the new framework leaves bare: per-task default routing, multi-Environment support, cost dashboards, spend caps, smart fallbacks, observability.
>
> **Features should feel perfect.** Not just functional — polished. Chatbots, content generation tools, embeddings, MCP — every flagship feature is carefully designed and constantly refined.
>
> **We respect the AI framework in WordPress and adapt to it.** AI Engine plugs into Connectors, Abilities, and the AI Client cleanly. We extend and enhance, never fight or fork. If WP core users prefer the WordPress AI Team's plugins, that path is always one click away.

This is the voice the brief, this strategy doc, marketing, in-product copy, and commit messages should reflect. When in doubt, lead with what AI Engine *enables*, not what it *replaces*.

### Operating principles

- **Don't bash the WP AI Team or Automattic.** The framework is well-designed even if its current ecosystem is theatre. Our credibility comes from honest comparison, not competitive sniping.
- **Don't predict the framework's future.** The strategy must work whether the framework grows or stays mostly theatre. AI Engine continues being the real production-grade AI implementation either way.
- **Cost control is the marketing headline.** "Will I get a surprise bill?" is the #1 user fear. Lead with the answer.
- **Chatbots are the entry point.** Every release should have a chatbot improvement.
- **Multi-provider is the differentiator on roundups.** Keep it loud.
- **MCP is the long bet.** Invest steadily.
- **Move slowly and reversibly on WP 7 integration.** It lives in `/labs`. If WP 7.1 changes the API, only `/labs` needs updating.
- **The Environment concept is structurally richer than core's Connector concept.** Lean into this — multiple accounts per provider, per-task defaults, usage tracking — these are differentiators core can't replicate without rewriting their data model.
- **Abilities are the universal tool surface of WordPress.** Anything we register flows to AI function calls, MCP, and REST automatically. Every MCP tool worth shipping should also be an Ability.

---

## References

- **Visual brief (3-page editorial doc):** `/Users/meow/Desktop/ai-engine-wp7-brief/index.html`
  - Part 01 — What WP 7.0 ships (with verbatim source code from `WordPress/wordpress · 7.0-branch`)
  - Part 02 — What it means for AI Engine
  - Part 03 — What we should do (six moves + positioning matrix + checklist)
- **Primary source for WP 7 claims:** `https://github.com/WordPress/wordpress/tree/7.0-branch/wp-includes`
  - `connectors.php`
  - `ai-client.php`
  - `abilities-api.php`
  - `ai-client/class-wp-ai-client-ability-function-resolver.php`
- **WP AI Team plugin (the only real consumer of the framework today):** `https://github.com/WordPress/ai`
- **Make/AI team blog:** `https://make.wordpress.org/ai/`
- **Slack #core-ai:** Wednesdays 17:00 UTC

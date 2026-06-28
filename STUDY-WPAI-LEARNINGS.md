# Open architecture study: lessons from WP AI Client

**Status:** thinking, not implementing.
**Owner:** Jordy.
**Started:** 2026-04-22.
**Trigger:** building the WP AI Client gateway in `/labs/` (commits
`4e5f9398`, `e14ee516`) exposed real shape mismatches between AI Engine's
internal data model and a modern, typed AI SDK.

This document is a working proposal, not a roadmap. It captures what we
learned from building the gateway, sketches a typed message/result layer
that AI Engine could grow into, and lists the smaller patterns worth
borrowing. Read before any large refactor of the query / reply layer.

---

## 1. What the gateway exposed

When mapping AiClient → AI Engine, three frictions kept showing up:

### a. Lossy message shape

AI Engine messages are flat:

```php
$query->messages = [
  [ 'role' => 'user', 'content' => 'Hi.' ],
  [ 'role' => 'assistant', 'content' => 'Hi back.' ],
];
$query->add_file( DroppedFile::from_url( $url, 'analysis' ) );
```

Files attach to the **whole query**, not to specific turns. This means a
multi-turn conversation that interleaves images ("here's image A, your
thoughts? — okay, here's image B, compare them") loses position. Engines
have to guess where the file belongs. The gateway worked around this
with a `[attachment]` placeholder string, which is the kind of hack you
write because the data model can't express the truth.

WP AI Client's `Message`/`MessagePart` solves this naturally:

```php
new UserMessage([
  new MessagePart( 'Compare these:' ),
  new MessagePart( $imageA ),
  new MessagePart( $imageB ),
]);
```

### b. Reply shape is a property bag

`Meow_MWAI_Reply` exposes:

- `$reply->result` (string, but might be markdown image-tag soup for image queries)
- `$reply->results` (array — embeddings? URLs? text alternatives?)
- `$reply->usage` (associative array)
- `$reply->needFeedbacks`, `$reply->needClientActions` (queues)

It works, but every consumer reaches into different fields depending on
the query type. The gateway has to normalise this twice:

- text → take `$reply->result`, wrap in a `MessagePart` text
- image → take `$reply->results`, wrap each as a `MessagePart` file

A typed `Meow_MWAI_Data_Reply` with explicit `candidates`, `usage`, and
`finish_reason` would let consumers (gateway, REST endpoints, Insights)
read the same fields regardless of operation.

### c. Capability is implicit

A model's tags array (`['core', 'chat', 'vision', 'functions', 'json']`)
declares broad capability, but engines do per-tag checks at dispatch
time. The gateway directory recently added the same tag-based
capability/option mapping the engines do — duplicated logic.

If the model object itself carried typed capability metadata, both the
engine and the gateway could read it from one place.

---

## 2. The typed data layer (the main proposal)

A new namespace under `/classes/data/`. Additive — nothing existing
breaks. Existing arrays continue to work; new code can opt in to the
typed surface.

### 2.1 Message DTOs

```php
// classes/data/message.php
class Meow_MWAI_Data_Message {
  public string $role;          // 'user' | 'assistant' | 'system'
  public array $parts = [];     // list<Meow_MWAI_Data_MessagePart>

  public static function user( array $parts ): self { ... }
  public static function assistant( array $parts ): self { ... }
  public static function system( string $text ): self { ... }
  public function add_part( Meow_MWAI_Data_MessagePart $p ): self { ... }
  public function to_array(): array { /* legacy [role, content] form */ }
}

// classes/data/message-part.php
class Meow_MWAI_Data_MessagePart {
  public string $type;          // 'text' | 'file' | 'function_call' | 'function_result'
  public ?string $text = null;
  public ?Meow_MWAI_Query_DroppedFile $file = null;
  public ?Meow_MWAI_Data_FunctionCall $function_call = null;
  public ?Meow_MWAI_Data_FunctionResult $function_result = null;

  public static function text( string $t ): self { ... }
  public static function file( Meow_MWAI_Query_DroppedFile $f ): self { ... }
  public static function function_call( Meow_MWAI_Data_FunctionCall $c ): self { ... }
  public static function function_result( Meow_MWAI_Data_FunctionResult $r ): self { ... }
}
```

`Meow_MWAI_Data_FunctionCall` and `Meow_MWAI_Data_FunctionResult` already
exist (`classes/data/function-call.php`, `classes/data/function-result.php`).
They slot in cleanly.

### 2.2 Query side

`Meow_MWAI_Query_Text` gains an additive setter:

```php
public function set_typed_messages( array $messages ): void {
  // accepts list<Meow_MWAI_Data_Message>
  // internally, also writes the legacy ->messages array via to_array() so
  // every existing engine keeps working unchanged
  $this->typed_messages = $messages;
  $this->messages = array_map( fn($m) => $m->to_array(), $messages );
}
```

Engines that want richer data (per-message file attachments, etc.) read
`$query->typed_messages` when present. Engines that don't read `$query->messages`
exactly as today.

### 2.3 Reply side

```php
// classes/data/reply-candidate.php
class Meow_MWAI_Data_ReplyCandidate {
  public Meow_MWAI_Data_Message $message;
  public string $finish_reason;  // 'stop' | 'length' | 'content_filter' | 'tool_calls' | 'error'
}

// classes/data/token-usage.php
class Meow_MWAI_Data_TokenUsage {
  public int $prompt_tokens;
  public int $completion_tokens;
  public int $total_tokens;
  public ?int $thought_tokens;   // for reasoning models
}
```

`Meow_MWAI_Reply` gains optional typed accessors:

```php
public function get_candidates(): array { /* list<ReplyCandidate> */ }
public function get_token_usage(): Meow_MWAI_Data_TokenUsage { ... }
public function get_finish_reason(): string { ... }
```

These derive from existing fields when set the old way. Engines can
populate them directly when constructing a fresh Reply.

### 2.4 What this buys us

- **Gateway thinness.** `wpai-gateway-model.php::extract_parts` and
  `build_result` collapse to one-liners.
- **Vision/audio/document support** without per-engine guesswork.
- **Function calling** flows through typed parts instead of side-channel
  arrays (`needFeedbacks`).
- **Streaming events** can carry `MessagePart` deltas instead of raw
  strings. Less ambiguity about what part of the response just changed.
- **Insights** can record per-modality stats cleanly.

### 2.5 Migration strategy

This is the important part. AI Engine has thousands of installs.
Breaking the API is not on the table.

1. **Phase A — parallel layer.** Add the DTOs. No engine reads them yet.
   Add accessors on Reply that derive from existing fields. Tests + docs.
2. **Phase B — gateway adopts.** Refactor `wpai-gateway-model.php` to
   build typed messages and read typed candidates. This is a low-risk
   pilot since the gateway is in `/labs/` and opt-in.
3. **Phase C — one engine adopts.** Pick the smallest engine
   (`anthropic.php` is well-bounded) and have it consume typed messages
   when present. Keep the legacy code path. Compare behavior.
4. **Phase D — REST endpoints adopt.** New endpoints (or new params on
   existing ones) accept typed payloads. Old payloads keep working.
5. **Phase E — engines opt in one by one** as time allows. Years, not
   weeks. The legacy path stays compatible forever.

No deprecations until phase E completes everywhere.

---

## 3. Other patterns worth borrowing (smaller)

These are independent and can be picked up à la carte.

### a. Capability matching before dispatch

WP AI Client builds `ModelRequirements::fromPromptData(...)` and matches
it against `ModelMetadata::getSupportedCapabilities()` *before* sending
anything. We do this implicitly via tag checks scattered across engines.

A central `Meow_MWAI_Services_CapabilityMatcher` could:

- Take a query.
- Find what features it needs (vision input, JSON output, function tools).
- Cross-check against the resolved model's tag set.
- Throw a clean "model X doesn't support Y" exception up front.

Saves users from cryptic provider errors.

### b. Fluent prompt builder (additive)

A new `Meow_MWAI_Prompt::create('My text')->with_system('...')->with_max_tokens(500)->run()`
surface, layered on top of `simpleTextQuery`. Old API stays. New API
reads better.

### c. Enums for finish reasons, modalities, options

Replace string literals like `'stop'` / `'length'` / `'tool_calls'` with
class constants. Helps autocomplete in IDEs and prevents typos. Low
priority.

### d. Provider availability separated from configuration

WP AI Client's `ProviderAvailabilityInterface::isConfigured()` is a
runtime check, not a static one. AI Engine could expose
`Meow_MWAI_Services_EnvAvailability::is_ready( $env_id )` that checks
key + reachability, useful for fallback chains.

---

## 4. Things NOT to copy

These are areas where AI Engine is already ahead — don't regress.

- **Streaming.** WP AI Client has no streaming hook. AI Engine's
  `$core->run_query( $q, $cb )` is years more mature.
- **Provider plugin per provider.** WP requires a separate plugin per
  provider. AI Engine bundles them. Splitting would create more support
  burden, not less.
- **Single key per provider.** WP allows one config per provider; AI
  Engine has multi-environment, which real teams need (staging vs prod,
  client A vs client B).
- **Tool calling via separate side channel.** WP AI Client treats tools
  as a special option. AI Engine's `mwai_functions_list` /
  `mwai_ai_feedback` filter pair is more WordPress-native and lets
  plugins extend without subclassing.
- **No Insights, no rate limiting, no MCP.** These are AI Engine's
  reason to exist. Don't dilute.

---

## 5. Reading list before any work starts

Before opening this back up, re-read:

- `/labs/wpai-gateway-model.php` — see `extract_parts()` for the kind of
  conversion the typed layer would eliminate.
- `/labs/wpai-gateway-directory.php` — `make_metadata()` mirrors what
  WP AI Client expects.
- `/Users/meow/sites/seven/app/public/wp-includes/php-ai-client/src/Messages/DTO/Message.php`
  — reference shape for message DTOs.
- `/Users/meow/sites/seven/app/public/wp-includes/php-ai-client/src/Results/DTO/GenerativeAiResult.php`
  — reference shape for typed results.
- `/Users/meow/sites/seven/app/public/wp-content/plugins/ai-provider-for-anthropic/src/Models/AnthropicTextGenerationModel.php`
  — full reference of how a typed model is built end-to-end.
- AI Engine: `classes/data/function-call.php` and
  `classes/data/function-result.php` — existing examples of the DTO
  pattern AI Engine already started.

---

## 6. Open questions

- Does `Meow_MWAI_Data_Message::to_array()` cleanly degrade when a turn
  has only a file part? The gateway uses an `[attachment]` placeholder
  today; the typed layer should be honest about what's there.
- How do we signal *which* candidate a tool call came from when an engine
  returns multiple candidates with different tool calls? (Anthropic does;
  Google often does for parallel calls.)
- Do we want to expose typed messages in the public PHP API
  (`Meow_MWAI_API`) immediately, or keep it internal first?
- Is `finish_reason: 'tool_calls'` enough, or do we want richer state
  for partially-completed tool loops?

These don't block the proposal; they're worth thinking about before
phase D.

---

## 7. When to revisit

Look at this doc when:

- Touching `classes/query/text.php`, `classes/reply.php`, or any engine.
- Adding a new modality (vision, audio, video, document) at the API layer.
- Building a new gateway/integration similar to WP AI Client.
- Designing a new query type.
- Considering a major refactor of the function-calling pipeline.

Otherwise, leave it alone. The current data model works for the cases it
already serves.

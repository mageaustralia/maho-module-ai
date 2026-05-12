---
title: Maho AI User Guide
---

# Maho AI User Guide

---

## 1. Introduction

**Maho AI** is the core AI platform for Maho. It provides a unified `Mage::helper('ai')->invoke()` entry point that routes calls through [Symfony AI Platform](https://symfony.com/doc/current/ai/components/platform.html) bridges to OpenAI, Anthropic, Google, Mistral, OpenRouter, Ollama, or any OpenAI-compatible endpoint. Consumer modules use the same helper to add features like AI-generated product descriptions, natural-language reporting, or image generation without owning the per-provider plumbing.

### Built-in Providers

| Platform | Bridge | Capabilities |
|---|---|---|
| **OpenAI** | `Symfony\AI\Platform\Bridge\OpenAi` | Chat, Embeddings, Image |
| **Anthropic** | `Symfony\AI\Platform\Bridge\Anthropic` | Chat |
| **Google** | `Symfony\AI\Platform\Bridge\Gemini` | Chat, Embeddings |
| **Mistral** | `Symfony\AI\Platform\Bridge\Mistral` | Chat, Embeddings |
| **OpenRouter** | `Symfony\AI\Platform\Bridge\OpenRouter` | Chat, Image (meta-provider for GPT/Claude/Gemini/Llama via single key) |
| **Ollama** | `Symfony\AI\Platform\Bridge\Ollama` | Chat, Embeddings (local / self-hosted) |
| **Generic** | `Symfony\AI\Platform\Bridge\OpenAi` (custom base URL) | Chat, Embeddings, Image (LiteLLM, vLLM, any OpenAI-compatible endpoint) |

### Key Features

- **Single helper API** - `Mage::helper('ai')->invoke()` returns a string; same call shape across every provider
- **Sync or async** - call inline for interactive flows, queue for batch / long-running work
- **Async task queue** - cron sweep processes pending tasks with retry, timeout recovery, and callback dispatch
- **Safety guardrails** - prompt-injection patterns, configurable regex blocklist, output sanitisation, PII detection
- **Token + cost telemetry** - per-task usage, aggregated daily, with a configurable monthly cost cap
- **Vector storage** - `maho_ai_vector` table for embedding-backed lookup / RAG
- **Admin UI** - Tasks grid, Usage grid, Reindex page, System Configuration with model-fetch button
- **Extensible** - community providers (e.g. NanoGPT) plug in via the config XML registry without touching core
- **Store-scoped configuration** - different stores can use different platforms / models / credentials

---

## 2. Getting Started

### Module Configuration

Navigate to **System > Configuration > Maho AI** to configure the module. You'll see five sections:

| Section | Purpose |
|---|---|
| **General** | Master enable, default platform / model, request logging, cost cap |
| **Image** | Default platform / model for image generation |
| **Embed** | Default platform / model for embeddings |
| **Video** | Default platform / model for video generation (via community providers) |
| **Safety** | Input validator + output sanitiser toggles, blocked-pattern regex list |
| **Queue** | Cron frequency, batch size, retry limits, cleanup retention |

Each platform has its own sub-section under General / Image / Embed for the API key (encrypted via `adminhtml/system_config_backend_encrypted`) and per-platform default model. Click **Update Models** next to any model dropdown to fetch the provider's live model list.

### Picking a Default Platform

The simplest setup: pick one platform, paste an API key, and set a default model.

1. **System > Configuration > Maho AI > General**
2. Set **Default Platform** (e.g. `OpenAI`)
3. In the **OpenAI** sub-section, paste your API key into **API Key**
4. Set the **Default Model** (e.g. `gpt-4o-mini` for chat, or click **Update Models** to refresh the list)
5. Save

Test it from any controller or CLI:

```php
$response = Mage::helper('ai')->invoke(
    userMessage: 'Say hello.',
);
```

---

## 3. The `invoke()` API

The single entry point every consumer module uses.

### Signature

```php
public function invoke(
    string $userMessage,
    ?string $systemPrompt = null,
    ?string $platform = null,
    ?string $model = null,
    array $options = [],
    ?int $storeId = null,
): string;
```

### Examples

**Simple completion** - uses configured defaults:

```php
$response = Mage::helper('ai')->invoke(
    userMessage: 'Summarise this product description in 30 words: ' . $product->getDescription(),
);
```

**With system prompt** - sets the model's role / tone:

```php
$response = Mage::helper('ai')->invoke(
    userMessage: 'Write a product description for: ' . $product->getName(),
    systemPrompt: 'You are an e-commerce copywriter. Be concise and persuasive.',
);
```

**Override platform and model** - bypass defaults for this call:

```php
$response = Mage::helper('ai')->invoke(
    userMessage: $prompt,
    platform: Maho_Ai_Model_Platform::ANTHROPIC,
    model: 'claude-sonnet-4-6',
);
```

**With options** - temperature, max-tokens, etc:

```php
$response = Mage::helper('ai')->invoke(
    userMessage: $prompt,
    options: [
        'temperature' => 0.7,
        'max_tokens' => 500,
        'is_html' => true,  // run output through OutputSanitizer for safe HTML
    ],
);
```

### Model Resolution Order

When `$model` isn't passed explicitly:

1. If `$model` parameter is set → use it
2. Else if `maho_ai/general/default_model` is configured → use it as global override
3. Else use `maho_ai/{platform}/model` for the selected platform

This lets you pin a single model globally (e.g. `gpt-4o-mini`) without touching each platform's config, while still allowing per-call overrides.

---

## 4. Async Task Queue

For long-running calls, catalog-wide batch jobs, or anything you don't want to block a controller request - submit to the queue and let cron handle it.

### Submitting a Task

```php
$taskId = Mage::helper('ai')->submitTask([
    'consumer' => 'catalog_product',
    'action' => 'generate_description',
    'messages' => [
        ['role' => 'user', 'content' => 'Write a description for: ' . $product->getName()],
    ],
    'platform' => Maho_Ai_Model_Platform::ANTHROPIC,
    'model' => 'claude-sonnet-4-6',
    'callback_class' => 'My_Module_Model_Callback',
    'callback_method' => 'onComplete',
    'max_retries' => 3,
    'priority' => Maho_Ai_Model_Task::PRIORITY_BACKGROUND,
]);
```

### Task Lifecycle

`pending` → `processing` → `complete` / `failed` / `cancelled`

| State | Meaning |
|---|---|
| `pending` | Queued, awaiting cron pickup |
| `processing` | Cron has picked it up and is calling the provider |
| `complete` | Provider returned successfully; callback fired |
| `failed` | Provider returned an error after `max_retries` attempts; callback fires with error context |
| `cancelled` | Cancelled by admin or by exceeding the monthly cost cap |

### Cron Behaviour

- Sweep runs **every 2 minutes** (configurable via `maho_ai/queue/cron_frequency`)
- Processes up to **10 tasks per run** (configurable via `maho_ai/queue/batch_size`)
- **Stuck-task recovery**: anything in `processing` for >120s gets re-queued (provider timeout, killed worker, etc.)
- **Weekly cleanup**: `complete` and `failed` rows older than 90 days are dropped (configurable via `maho_ai/queue/cleanup_age_days`)

### Callbacks

When a task completes (or fails), the configured callback class is instantiated and the method is called with two arguments:

```php
class My_Module_Model_Callback
{
    public function onComplete(Maho_Ai_Model_Task $task, string $response): void
    {
        if ($task->getStatus() === Maho_Ai_Model_Task::STATUS_FAILED) {
            // $response holds the error message
            return;
        }
        $product = Mage::getModel('catalog/product')->load(/* ... */);
        $product->setData('description', $response)->save();
    }
}
```

---

## 5. Safety

Both guardrails are **on by default**.

### InputValidator

Runs before each `invoke()` / `submitTask()`. Checks the user message and (where present) the system prompt against:

- **15 known prompt-injection patterns** (e.g. "ignore previous instructions", "DAN mode", role-rewrite attempts)
- **Configurable regex blocklist** - admin-set patterns in `maho_ai/safety/blocked_patterns` (one per line)
- **Base64-payload heuristic** - flags long base64-like strings that may hide encoded instructions

When a request matches a pattern, the validator throws `Mage_Core_Exception` with a generic "Input contains disallowed content" message (specific reason logged to `var/log/ai.log`). Catch this in your consumer to show a friendly error.

To bypass for trusted internal flows, pass `options: ['skip_input_validation' => true]`.

### OutputSanitizer

Runs against model responses when `options['is_html'] === true`:

- Strips dangerous tags (`<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`)
- Strips `on*=` event-handler attributes and `javascript:` / `data:` URLs from `href` / `src`
- **PII detection** - emails, credit-card-like numbers, AU phone numbers are flagged in `var/log/ai-pii.log` but not blocked (model may have generated them legitimately)

---

## 6. Embeddings + Vector Storage

For RAG, semantic search, similarity lookup, etc.

### Generating Embeddings

```php
$vectors = Mage::helper('ai')->embed(
    input: ['First text to embed', 'Second text to embed'],
    platform: Maho_Ai_Model_Platform::OPENAI,
    model: 'text-embedding-3-small',
);
// $vectors is float[][] - one inner array per input string
```

### Storing in `maho_ai_vector`

```php
Mage::getModel('ai/vector')
    ->setEntityType('catalog_product')
    ->setEntityId($product->getId())
    ->setEmbedding($vectors[0])  // float[] - serialised as compact JSON
    ->setSourceModel('text-embedding-3-small')
    ->save();
```

### Querying

The Vector model + collection support cosine-similarity ranking against a query vector. See the AI Reports module for a production example.

---

## 7. Image Generation

```php
$imageUrl = Mage::helper('ai')->generateImage(
    prompt: 'A minimalist product photo of a black coffee mug on a white background',
    platform: Maho_Ai_Model_Platform::OPENAI,
    model: 'dall-e-3',
    options: ['size' => '1024x1024'],
);
```

Returns a data URI by default (works for every provider regardless of upload behaviour). Pass `options: ['return_url' => true]` to get a hosted URL from providers that support it.

---

## 8. Usage Telemetry + Cost Cap

### Per-Call Tracking

Every `invoke()` / `embed()` / `generateImage()` call records token usage to `maho_ai_usage_event`:

| Field | Description |
|---|---|
| `platform` | Provider code (openai, anthropic, ...) |
| `model` | Resolved model string |
| `capability` | chat / embed / image |
| `input_tokens` / `output_tokens` | Reported by the provider |
| `estimated_cost_usd` | Computed from per-model rates in `maho_ai/general/cost_table` |
| `consumer` | Module name passed in `submitTask` (or `'sync'` for inline calls) |
| `created_at` | Timestamp |

A nightly cron aggregates these into `maho_ai_usage` (daily granularity, per provider/model/capability) - keeps the per-event table compact.

### Monthly Cost Cap

Set `maho_ai/general/monthly_cost_cap_usd` to enforce a soft budget. Once exceeded:

- `invoke()` / `embed()` / `generateImage()` throw `Mage_Core_Exception` with the configured cap-exceeded message
- New `submitTask()` calls accept the row but immediately mark it `cancelled`
- Admin sees a flash message + email notification (configurable in `maho_ai/notifications`)

Reset on the 1st of each month.

---

## 9. Admin UI

Four admin pages under **System > Maho AI**:

### Tasks

Full async queue grid - filter by status / consumer / platform / model, sort by creation time / cost / tokens. Click any row to view full prompt, response, and stack trace (for failures).

### Usage

Daily aggregated grid - token + cost totals per platform / model / capability. Filter by date range, consumer, or platform to attribute cost.

### Reindex

Tools for vector store maintenance:

- **Rebuild embeddings** - queue an embedding task for every entity in a chosen entity type
- **Clear vectors** - drop all `maho_ai_vector` rows (with confirm)
- **Recompute usage aggregates** - re-run the daily aggregation if you've edited cost rates

### System Configuration > Maho AI

Per-provider API keys (encrypted), default models, rate limits, safety toggles, cost cap, queue settings. Each provider's model dropdown has a **Update Models** button that queries the provider's `/models` endpoint live (so dropdowns stay current as providers ship new models).

---

## 10. Dev Guide: Extending With Community Providers

The whole point of the module is to make adding a new provider easy. The recommended pattern: **extend `Maho_Ai_Model_Platform_Symfony`**. You inherit everything Maho-side (encrypted config wiring, model resolution, token-usage capture in `{input, output}` shape, custom `ModelCatalog` plumbing, exception types) and only override what's actually different about your provider.

Real-world example: [`MageAustralia/AiContent`](https://github.com/mageaustralia/maho-module-ai-content) adds NanoGPT — chat (OpenAI-compatible at a custom host), image (OpenAI-shaped with NanoGPT-specific extras), and video (custom async API). The full implementation is ~200 lines.

### Why extend `Maho_Ai_Model_Platform_Symfony` (and not implement interfaces directly)?

Symfony AI Platform's `Bridge\OpenAi\PlatformFactory::create()` already accepts a custom `host:` parameter, so any OpenAI-compatible endpoint (NanoGPT, LiteLLM, vLLM, Azure OpenAI, etc.) can be wired without writing new HTTP code. The Maho shim sits on top of that and adds:

- Encrypted API key retrieval from store config
- Model resolution order (`maho_ai/{platform}/{capability}_model` → defaults)
- Token-usage normalisation to Maho's `{input, output}` shape
- Custom `ModelCatalog` so admin-set model IDs not in Symfony's built-in catalog still resolve
- Maho's `Mage_Core_Exception` from provider errors

Extending the shim gets you all of that for free. The shim is non-final and the following members are `protected` (intentional extension points):

| Member | Purpose |
|---|---|
| `protected readonly PlatformInterface $platform` | The Symfony bridge instance you built in the subclass constructor |
| `protected readonly string $platformCode` | Your provider code (`'nanogpt'`, `'azureopenai'`, etc.) |
| `protected readonly string $defaultChatModel` / `$defaultEmbedModel` / `$defaultImageModel` | Defaults passed through the constructor |
| `protected string $lastModel` / `$lastEmbedModel` / `$lastImageModel` | Update from your overrides so getLastModel*() reports correctly |
| `protected array $lastTokenUsage` / `$lastEmbedTokenUsage` | Update from your overrides so getLastTokenUsage*() reports correctly |
| `protected function buildMessageBag(array $messages): MessageBag` | Maho `[{role, content}]` → Symfony `MessageBag` |
| `protected function mapChatOptions(array $options): array` | Maho options → Symfony invoke options |
| `protected function mapEmbedOptions(array $options): array` | (same, for embeddings) |
| `protected function mapImageOptions(array $options): array` | (same, for image gen) |
| `protected function captureChatMetadata(DeferredResult $deferred, string $model): void` | Pulls token usage + model from the response and stores in `$lastTokenUsage` / `$lastModel` |
| `protected function extractTokenUsage(DeferredResult $deferred): ?TokenUsage` | Just the token-usage extraction (useful in custom flows that don't use full captureChatMetadata) |

### Step 1: Provider Class

For an OpenAI-compatible chat endpoint at a custom host (like NanoGPT):

```php
// app/code/community/My/Module/Model/Platform/Foo.php
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory as OpenAiPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

class My_Module_Model_Platform_Foo extends Maho_Ai_Model_Platform_Symfony
{
    public function __construct(string $apiKey, string $defaultChatModel)
    {
        // Build a Symfony OpenAI bridge against your provider's OpenAI-compatible host.
        // Custom ModelCatalog entries register admin-set models so they resolve.
        $catalog = new \Symfony\AI\Platform\Bridge\OpenAi\ModelCatalog([
            $defaultChatModel => [
                'class' => \Symfony\AI\Platform\Bridge\OpenAi\Gpt::class,
                'capabilities' => [
                    \Symfony\AI\Platform\Capability::INPUT_MESSAGES,
                    \Symfony\AI\Platform\Capability::OUTPUT_TEXT,
                ],
            ],
        ]);

        parent::__construct(
            platform: OpenAiPlatformFactory::create($apiKey, host: 'api.foo.example/v1', modelCatalog: $catalog),
            platformCode: 'foo',
            defaultChatModel: $defaultChatModel,
        );
    }
}
```

That's it for the common case — chat works out of the box, inherited from the parent. To add a custom-shaped image endpoint (where the provider accepts extra fields the OpenAI bridge doesn't expose), override `generateImage()`:

```php
    #[\Override]
    public function generateImage(string $prompt, array $options = []): string
    {
        $model = (string) ($options['model'] ?? $this->defaultImageModel);
        $this->lastImageModel = $model;

        $response = HttpClient::create()->request('POST', 'https://api.foo.example/v1/images', [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
            'json'    => ['model' => $model, 'prompt' => $prompt, /* ... */],
        ]);
        $data = $response->toArray(false);
        return $data['data'][0]['url'] ?? '';
    }
```

For a fully custom non-OpenAI-shaped endpoint (e.g. video, where Symfony has no bridge), implement the relevant interface and add direct HTTP logic — see NanoGpt's `generateVideo()` / `getVideoStatus()` for a real implementation.

### Step 2: Factory Class

Builds the provider from store config and returns the instance to `Maho_Ai_Model_Platform_Factory`:

```php
// app/code/community/My/Module/Model/Platform/Foo/Factory.php
class My_Module_Model_Platform_Foo_Factory
    implements Maho_Ai_Model_Platform_ProviderFactoryInterface
{
    #[\Override]
    public function create(?int $storeId = null): Maho_Ai_Model_Platform_ProviderInterface
    {
        $apiKey = (string) Mage::helper('core')->decrypt(
            (string) Mage::getStoreConfig('maho_ai/general/foo_api_key', $storeId),
        );
        if ($apiKey === '') {
            throw new Mage_Core_Exception('Foo API key is not configured.');
        }

        return new My_Module_Model_Platform_Foo(
            apiKey: $apiKey,
            defaultChatModel: (string) Mage::getStoreConfig('maho_ai/general/foo_model', $storeId) ?: 'foo-default',
        );
    }
}
```

### Step 3: Register in `config.xml`

```xml
<config>
    <global>
        <ai>
            <providers>
                <foo>
                    <label>Foo AI</label>
                    <factory_class>My_Module_Model_Platform_Foo_Factory</factory_class>
                    <capabilities>chat</capabilities>
                    <sort_order>100</sort_order>
                </foo>
            </providers>
        </ai>
    </global>
</config>
```

Done. `Mage::helper('ai')->invoke(platform: 'foo')` now works, and `foo` appears in the System Configuration default-platform dropdown.

**Real example:** see `MageAustralia_AiContent` for [NanoGPT](https://github.com/mageaustralia/maho-module-ai-content) - a community provider supporting chat + image + video via a single API key.

---

## 11. CLI Commands

```bash
# Process the queue manually (useful during dev or when cron is paused)
./maho ai:queue:process

# Force-process N pending tasks regardless of priority
./maho ai:queue:process --limit=50

# Reset stuck tasks (anything in 'processing' > timeout seconds)
./maho ai:queue:recover

# Show usage summary for the current month
./maho ai:usage:summary

# Show usage summary for a date range
./maho ai:usage:summary --from=2026-01-01 --to=2026-01-31
```

---

## 12. Troubleshooting

### "Input contains disallowed content"

The InputValidator matched a known injection pattern or admin-configured blocklist regex. Specific reason is in `var/log/ai.log`. For trusted internal flows, pass `options: ['skip_input_validation' => true]`.

### Tasks stuck in `processing`

The stuck-task recovery cron resets anything older than `maho_ai/queue/task_timeout_seconds` (default 120). To force-recover immediately: `./maho ai:queue:recover`.

### "Monthly cost cap exceeded"

Check **System > Maho AI > Usage** to see what consumed the budget. Either raise the cap (`maho_ai/general/monthly_cost_cap_usd`) or wait for the 1st of next month to reset. Per-consumer attribution is recorded so you can find the heavy spender.

### Model dropdown is empty / outdated

Click **Update Models** next to the dropdown. Each provider has a `model_fetcher_method` (or `model_fetcher_class` for community providers) registered in config XML that queries the provider's `/models` endpoint. If the button is missing, the provider hasn't declared a fetcher - the field falls back to a free-text input.

### "Provider 'X' does not support {capability}"

The provider's `<capabilities>` in `config.xml` doesn't include the requested capability. Either pick a different provider for that capability (e.g. embeddings on OpenAI but chat on Anthropic) or check whether the community provider should extend its declared capabilities.

### Checking Logs

| File | What's in it |
|---|---|
| `var/log/ai.log` | Request logging (enable in System Config), validator rejections, provider errors |
| `var/log/ai-pii.log` | PII detection warnings from OutputSanitizer |
| Tasks grid | Full prompt + response + stack trace for every async task |

### Getting Help

- Issue tracker: https://github.com/MahoCommerce/maho/issues
- Reference issue: [#468 Feature: Base AI Module](https://github.com/MahoCommerce/maho/issues/468)
- Example consumer: [mageaustralia/maho-module-ai-reports](https://github.com/mageaustralia/maho-module-ai-reports)

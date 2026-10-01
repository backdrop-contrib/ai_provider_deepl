# AI Provider DeepL

DeepL translation provider for the Backdrop CMS AI module.

Adds DeepL (https://www.deepl.com/) machine translation to the providers the
`ai` module can route to, so modules such as AI Translate can use it in place of
a general-purpose language model. DeepL is a translation engine, not a chat
model: every request is translated, never answered.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Translation | Yes | HTML tags are preserved (`tag_handling: html`). |
| Chat | Translation only | The last user message is translated; see below. |
| Completions | Translation only | The prompt is translated. |
| Tool calling | No | Requests are translated and no tool calls are returned. |
| Vision | No | |
| Embeddings | No | |
| Image generation | No | |
| Moderation | No | |
| Speech-to-text | No | |

## How chat requests are translated

The target language is taken from `context_extra['target_langcode']` (or
`target_lang`). Without it, the provider looks for "into/to {language}" in the
system prompt and matches it against the site's languages; if a system prompt is
present but no language can be resolved from it, the request fails with an
exception. With neither a target nor a system prompt, the target is English.
A source language can be passed as `source_langcode`.

Three models are offered: `deepl-default`, `deepl-formal` and
`deepl-informal`. The formal and informal models request
`prefer_more` / `prefer_less` formality.

Callers that want DeepL directly can use the adapter's `translate()` method,
which accepts a string or an array of strings plus `formality`,
`tag_handling` and `preserve_formatting` options.

## Settings

The provider settings at `admin/config/ai/settings` add:

- **API Account Tier** — auto-detect (keys ending in `:fx` use
  `api-free.deepl.com`, others `api.deepl.com`), or force Free or Pro.
- **Default Formality** — used by `deepl-default` and by `translate()` when no
  formality is passed.

A `custom_endpoint` value in `ai_provider_deepl.settings` (for example a
proxy) overrides the tier choice; it is set through configuration management
rather than the form.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding your DeepL API key
  (https://www.deepl.com/your-account/keys).
- Enable and configure the provider at `admin/config/ai/settings`.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_deepl/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.

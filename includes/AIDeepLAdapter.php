<?php

/**
 * @file
 * DeepL translation adapter for AI core.
 */

class AIDeepLAdapter extends AIAdapterBase {

  use AICompatibleTrait;

  /** @var string */
  protected $endpointTier = 'auto';

  /** @var string */
  protected $customEndpoint = '';

  /** @var string */
  protected $defaultFormality = 'default';

  /** @var array|null */
  protected $models = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct($api_key, ?AIApi $api = NULL) {
    parent::__construct($api_key, $api);

    $config = config('ai_provider_deepl.settings');
    $this->endpointTier = $config->get('endpoint_tier') ?: 'auto';
    $this->customEndpoint = rtrim(trim((string) $config->get('custom_endpoint')), '/');
    $this->defaultFormality = $config->get('formality') ?: 'default';
  }

  /**
   * Resolve the base API endpoint URL based on key and configured tier.
   */
  public function getBaseUrl(): string {
    // A configured custom endpoint (proxy or gateway) overrides tier detection.
    if ($this->customEndpoint !== '') {
      return $this->customEndpoint;
    }
    if ($this->endpointTier === 'free') {
      return 'https://api-free.deepl.com/v2';
    }
    if ($this->endpointTier === 'pro') {
      return 'https://api.deepl.com/v2';
    }
    // Auto-detect tier based on DeepL key convention:
    if (str_ends_with(trim($this->apiKey), ':fx')) {
      return 'https://api-free.deepl.com/v2';
    }
    return 'https://api.deepl.com/v2';
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultHeaders(): array {
    return [
      'Authorization' => 'DeepL-Auth-Key ' . $this->apiKey,
      'Content-Type' => 'application/json',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getModels(): array {
    if ($this->models !== NULL) {
      return $this->models;
    }

    $models = [
      'deepl-default' => 'DeepL Translation Engine (General)',
      'deepl-formal' => 'DeepL Translation (Formal Tone)',
      'deepl-informal' => 'DeepL Translation (Informal Tone)',
    ];

    return $this->models = $models;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelsByCapability($capability): array {
    $models = $this->getModels();
    $capability = ai_normalize_capability_name($capability);
    $filtered = [];

    foreach ($models as $id => $label) {
      $ok = FALSE;
      switch ($capability) {
        case 'text':
        case 'chat':
        case 'translation':
          $ok = TRUE;
          break;

        case 'thinking':
        case 'tool_calling':
        case 'vision':
        case 'embeddings':
        case 'embedding':
        case 'image':
        case 'moderation':
        case 'stt':
          $ok = FALSE;
          break;
      }

      if ($ok) {
        $filtered[$id] = $label;
      }
    }

    backdrop_alter('ai_model_capabilities', $filtered, $capability, $this);
    return $filtered;
  }

  /**
   * Normalize ISO language code for DeepL requirements.
   */
  public function normalizeLanguageCode(string $langcode, bool $is_target = TRUE): string {
    $lang = strtoupper(trim($langcode));

    // Handle common regional dialects.
    if ($is_target) {
      if ($lang === 'EN') {
        return 'EN-US';
      }
      if ($lang === 'PT') {
        return 'PT-PT';
      }
    }
    else {
      // Source language does not accept regional variants in DeepL.
      if (strpos($lang, '-') !== FALSE) {
        [$base] = explode('-', $lang, 2);
        return $base;
      }
    }

    return $lang;
  }

  /**
   * Native translation call against DeepL /v2/translate endpoint.
   *
   * @param string|array $text
   *   Single string or array of strings to translate.
   * @param string $target_lang
   *   Target language code.
   * @param string|null $source_lang
   *   Source language code, or NULL for automatic detection.
   * @param array $options
   *   Additional options (formality, tag_handling, preserve_formatting).
   *
   * @return string|array
   *   Translated string or array of translated strings.
   *
   * @throws \Exception
   */
  public function translate($text, string $target_lang, ?string $source_lang = NULL, array $options = []) {
    $url = $this->getBaseUrl() . '/translate';
    $is_array = is_array($text);
    $text_list = $is_array ? array_values($text) : [$text];

    $payload = [
      'text' => $text_list,
      'target_lang' => $this->normalizeLanguageCode($target_lang, TRUE),
      'tag_handling' => $options['tag_handling'] ?? 'html',
    ];

    if (!empty($source_lang) && $source_lang !== 'und') {
      $payload['source_lang'] = $this->normalizeLanguageCode($source_lang, FALSE);
    }

    $formality = $options['formality'] ?? $this->defaultFormality;
    if ($formality !== 'default' && $formality !== '') {
      $payload['formality'] = $formality;
    }

    if (isset($options['preserve_formatting'])) {
      $payload['preserve_formatting'] = (bool) $options['preserve_formatting'];
    }

    try {
      $result = $this->makeRequest($url, $payload, [], 'POST', 60);
      $translated_items = [];
      if (!empty($result['translations']) && is_array($result['translations'])) {
        foreach ($result['translations'] as $t) {
          $translated_items[] = $t['text'] ?? '';
        }
      }

      if (!$is_array) {
        return $translated_items[0] ?? '';
      }
      return $translated_items;
    }
    catch (\Exception $e) {
      watchdog('ai_provider_deepl', 'DeepL translation error: @message', ['@message' => $e->getMessage()], WATCHDOG_ERROR);
      throw $e;
    }
  }

  /**
   * Resolve a language name or code to a language code.
   *
   * Checks the site's languages first, then Backdrop's standard language list,
   * so "French" resolves even when French is not enabled on the site.
   *
   * @return string
   *   The language code, or an empty string when nothing matches.
   */
  protected function resolveLanguage(string $candidate): string {
    foreach (language_list() as $code => $language) {
      if (strcasecmp($language->name, $candidate) === 0 || strcasecmp($code, $candidate) === 0) {
        return $code;
      }
    }

    require_once BACKDROP_ROOT . '/core/includes/standard.inc';
    foreach (standard_language_list() as $code => $names) {
      if (strcasecmp($code, $candidate) === 0 || strcasecmp($names[0], $candidate) === 0 || (isset($names[1]) && strcasecmp($names[1], $candidate) === 0)) {
        return $code;
      }
    }

    return '';
  }

  /**
   * {@inheritdoc}
   */
  public function completions(string $model, string $prompt, $temperature, $max_tokens = 512, bool $stream_response = FALSE) {
    $messages = [
      ['role' => 'user', 'content' => $prompt],
    ];
    return $this->chat($model, $messages, $temperature, $max_tokens, $stream_response);
  }

  /**
   * {@inheritdoc}
   */
  public function chat(string $model, array $messages, $temperature, $max_tokens = 1024, bool $stream_response = FALSE, array $context_extra = []) {
    // Extract input text from the last user message.
    $input_text = '';
    $system_prompt = '';

    foreach ($messages as $msg) {
      if (($msg['role'] ?? '') === 'user') {
        $input_text = is_array($msg['content']) ? ($msg['content']['text'] ?? '') : (string) ($msg['content'] ?? '');
      }
      elseif (($msg['role'] ?? '') === 'system') {
        $system_prompt = (string) ($msg['content'] ?? '');
      }
    }

    // Determine target and source languages from context_extra or system prompt.
    $target_lang = $context_extra['target_langcode'] ?? ($context_extra['target_lang'] ?? '');
    $source_lang = $context_extra['source_langcode'] ?? ($context_extra['source_lang'] ?? NULL);

    if (empty($target_lang) && !empty($system_prompt)) {
      // Look for "into/to {language}" in the system prompt. Every match is
      // tried, since "to" also appears in phrases such as "to translate".
      if (preg_match_all('/\b(?:into|to)\s+([a-zA-Z\-]+)/i', $system_prompt, $matches)) {
        foreach ($matches[1] as $candidate) {
          $target_lang = $this->resolveLanguage($candidate);
          if ($target_lang !== '') {
            break;
          }
        }
      }
      if ($target_lang === '') {
        throw new \InvalidArgumentException('DeepL could not determine the target language; pass target_langcode in the request context.');
      }
    }

    if (empty($target_lang)) {
      $target_lang = 'en';
    }

    $options = [
      'tag_handling' => 'html',
    ];

    if ($model === 'deepl-formal') {
      $options['formality'] = 'prefer_more';
    }
    elseif ($model === 'deepl-informal') {
      $options['formality'] = 'prefer_less';
    }

    return (string) $this->translate($input_text, $target_lang, $source_lang, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function chatWithTools(string $model, array $messages, array $tools, $temperature, $max_tokens = 1024, string $tool_choice = 'auto', array $context_extra = []): array {
    return [
      'finish_reason' => 'stop',
      'content' => $this->chat($model, $messages, $temperature, $max_tokens, FALSE, $context_extra),
      'tool_calls' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function embedding(string $input, string $model, bool $log = TRUE): array {
    watchdog('ai_provider_deepl', 'Embeddings are not supported by DeepL.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Embeddings are not supported by DeepL.');
  }

  /**
   * {@inheritdoc}
   */
  public function images(string $model, string $prompt, string $size, string $response_format, string $quality = 'standard', string $style = 'natural', ?string $output_format = NULL) {
    watchdog('ai_provider_deepl', 'Image generation is not supported by DeepL.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Image generation is not supported by DeepL.');
  }

  /**
   * {@inheritdoc}
   */
  public function textToSpeech(string $model, string $input, string $voice, string $response_format) {
    watchdog('ai_provider_deepl', 'Text-to-speech is not supported by DeepL.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Text-to-speech is not supported by DeepL.');
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string $model, string $file, string $task = 'transcribe', $temperature = 0.4, string $response_format = 'verbose_json') {
    watchdog('ai_provider_deepl', 'Speech-to-text is not supported by DeepL.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Speech-to-text is not supported by DeepL.');
  }

  /**
   * {@inheritdoc}
   */
  public function moderation(string $input, string $model = 'omni-moderation-latest'): array {
    watchdog('ai_provider_deepl', 'Moderation is not supported by DeepL.', [], WATCHDOG_WARNING);
    throw new \RuntimeException('Moderation is not supported by DeepL.');
  }

}

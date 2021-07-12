# Apple News Auto Retry
Sets up and manages automatic push retries for Apple News articles that were generated using the [`Publish to Apple News`](https://en-gb.wordpress.org/plugins/publish-to-apple-news/) by Alley Interactive

# Installation
Download the [latest release](https://github.com/MailOnline/mdt-apple-news-auto-retry/releases/latest) and include it within your plugins folder and enable as normal. This plugin has a dependency on the [`Publish to Apple News`](https://en-gb.wordpress.org/plugins/) plugin and will do nothing if it isn't also enabled on the same WordPress install.

This plugin has no settings within the Admin.

# Hooks

## Actions

### `mdt_an_auto_retry_push_success`

**`do_action('mdt_an_auto_retry_push_success', int $post_id, string $share_url, int $attempt);`**

Hook in after a successful push retry to run custom logic

#### Parameters

**`$post_id`**
(int) Post ID

**`$share_url`**
(string) Apple News share url for $post_id

**`$attempt`**
(int) Number of attempts made

### `mdt_an_auto_retry_push_failure`

Hook in after a successful push retry to run custom logic

**`do_action('mdt_an_auto_retry_push_failure', int $post_id, string $error_message, int $attempt);`**

#### Parameters

**`$post_id`**
(int) Post ID

**`$error_message`**
(string) Error message

**`$attempt`**
(int) Number of attempts made

## Filters

### `mdt_an_auto_retry_should_schedule`

Allows for preventing auto retry scheduling

**`apply_filters('mdt_an_auto_retry_should_schedule', bool $should, int $post_id);`**

#### Parameters

**`$should`**
(bool) Whether to schedule or not (default: true)

**`$post_id`**
(int) Post ID

### `mdt_an_auto_retry_schedule_delay`

**`apply_filters('mdt_an_auto_retry_schedule_delay', int $delay;`**

#### Parameters

**`$delay`**
(int) Time in seconds to schedule the future event for (default: 120)
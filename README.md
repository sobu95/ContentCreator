# Content Application

This project requires a few sensitive values that should not be committed to version control.  
Provide them either as environment variables or by creating a `config.local.php` file in the project root which defines the constants used in `config.php`.

## Required variables

- `DB_HOST` – database hostname
- `DB_NAME` – database name
- `DB_USER` – database user
- `DB_PASS` – database password
- `GEMINI_API_KEY` – API key used for requests to the Google Gemini API
- `ANTHROPIC_API_KEY` – API key used for Anthropic Claude
- `CURL_VERIFY_SSL` – optional flag to disable SSL certificate verification when set to `false` (defaults to `true`)

Example `config.local.php`:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'my_db');
define('DB_USER', 'my_user');
define('DB_PASS', 'secret');
define('GEMINI_API_KEY', 'your-api-key');
define('ANTHROPIC_API_KEY', 'your-anthropic-key');
// Optional: disable SSL certificate verification for debugging
define('CURL_VERIFY_SSL', false);
```

This file should **not** be committed to version control.

## PHP extensions

The application requires several standard PHP extensions. The admin settings
page checks for the following at runtime:

- `pdo`
- `curl`
- `zip` *(required for the DOCX export feature)*
- `json`

Additionally the code relies on these common extensions:

- `dom`
- `mbstring`
- `openssl`

## Database character set

New installations use the `utf8mb4` encoding for all tables. If you are
upgrading from an earlier release, convert your database and tables with:

```sql
ALTER DATABASE <db_name> CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
ALTER TABLE <table_name> CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## Node dependencies and linting

Install the Node packages defined in `package.json` before running the lint
script. The repository includes a `package-lock.json` so you can use `npm ci`
for reproducible installs:

```bash
npm install # or `npm ci`
npm run lint
```

## Managing language models

Administrators can define multiple API endpoints for text generation. Use **Ustawienia** in the admin panel to add, edit or delete models in the *Modele językowe* section. Each model defines the endpoint URL and generation configuration parameters (temperature, topK, topP, max tokens).

When creating a task, choose one of the configured models from the model selector. The queue processor will call the selected endpoint with its configuration.

To quickly verify your setup, open `test_api.php` in the admin area and pick a model to send a sample request.

## Cron automation

You can automate queue processing and page content fetching by adding the CLI
scripts to your system cron table. Replace `/path/to` with the path to this
project and adjust the `php` binary if necessary:

```cron
* * * * * /usr/bin/php /path/to/process_queue_cli.php >> /path/to/logs/queue_cron.log 2>&1
*/5 * * * * /usr/bin/php /path/to/process_page_content.php >> /path/to/logs/page_content_cron.log 2>&1
```

`process_queue_cli.php` checks whether there are any tasks waiting and exits
immediately when the queue is empty to minimize server load.

The page content processor will also mark a task item as failed when no text can
be extracted from the provided URL.

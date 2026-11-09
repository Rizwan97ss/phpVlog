# Vlog

A fast, secure log viewer for Laravel. Browse, filter and search your application's log files in the browser, including files that are gigabytes in size.

![Vlog showing a Laravel log with level filters and a list of entries](art/screenshot-light.png)

## Features

- **Handles large files.** Vlog indexes each log file once, then opens any page instantly. Only new lines are read as the file grows, and memory use stays low regardless of file size.
- **Understands your logs.** Vlog reads Laravel (Monolog), Monolog JSON, and CodeIgniter 3 and 4 log formats, and detects the format automatically. Multi-line entries stay together, and stack traces are split into frames.
- **Filters and search.** Vlog filters by level and date range, and searches messages, context and stack traces as plain text or as a regular expression. It can also search every log file at once.
- **Follows new entries.** Turn on **Follow** to see new entries as they're written, highlighted as they arrive.
- **Captures browser errors.** JavaScript errors, unhandled promise rejections and files that fail to load in your users' browsers are logged too, with their stack traces.
- **Shows the code.** See the lines of code around each stack frame and where an exception was thrown, and open any of them in your editor.
- **Hides secrets.** Passwords, tokens, API keys and other secrets are replaced with `[redacted]` before they reach the browser.
- **Groups similar errors.** Repeated entries, such as `Order 1042 failed` and `Order 1043 failed`, are grouped together with a count, when they were first and last seen, and a 14-day trend, so you can see which problems matter most.
- **Saves entries.** Bookmark any entry with a note. Saved entries stay available after the log file is rotated or deleted.
- **Exports and manages logs.** Export filtered results as JSON, CSV or plain text, download the original file, write your own entries, and clear or delete files.
- **Doesn't depend on your frontend.** The interface is built in, so it doesn't use your application's Vite, React, Vue or Tailwind setup.
- **Light and dark themes**, and URLs you can share that keep the selected file and filters.

## Requirements

| | Version |
|---|---|
| PHP | 8.2 or later, 64-bit |
| Laravel | 12 or 13 |

## Installation

Install the package with Composer:

```bash
composer require rss/vlog
```

Then visit `/vlog` in your application. Laravel registers Vlog automatically.

## Access in production

By default, Vlog is only available when your application's environment is `local`. To allow access in other environments, define a `viewVlog` gate in a service provider, such as `App\Providers\AppServiceProvider`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewVlog', function (User $user) {
        return in_array($user->email, [
            'admin@example.com',
        ]);
    });
}
```

When the gate's closure requires a user, as in this example, guests are always denied.

### Managing logs

A second gate, `manageVlog`, controls the actions that change log files or show them without hiding secrets:

- Downloading the original file
- Clearing or deleting a file
- Writing an entry to your application's log
- Previewing the source code that stack traces point to

Like `viewVlog`, it only allows access in the `local` environment until you define it. To let only some of your Vlog users manage logs, define both gates:

```php
Gate::define('manageVlog', function (User $user) {
    return $user->email === 'admin@example.com';
});
```

Everyone who can view logs can save entries and export results. Exports use the same secret hiding as the viewer.

> [!WARNING]
> Log files often contain personal data and secrets. Always define a `viewVlog` gate before deploying Vlog. Without one, Vlog only checks that the environment is `local`, so a server that is mistakenly set to `local` would let anyone view your logs.

## Configuration

To change Vlog's settings, publish its configuration file:

```bash
php artisan vendor:publish --tag=vlog-config
```

This creates `config/vlog.php` in your application, with these options:

| Option | Default | Description |
|---|---|---|
| `enabled` | `true` | Set to `false` to remove every Vlog route. Also set by `VLOG_ENABLED`. |
| `path` | `vlog` | The URL path Vlog is served at. Also set by `VLOG_PATH`. |
| `domain` | `null` | An optional domain to serve Vlog from. Also set by `VLOG_DOMAIN`. |
| `middleware` | `['web']` | Middleware for Vlog's routes. The `viewVlog` gate is always checked after these. |
| `paths` | `[storage_path('logs')]` | The folders Vlog can read logs from. |
| `patterns` | `['*.log']` | The file name patterns to include. |
| `max_depth` | `3` | How many levels of subfolders to search. |
| `redact` | See [Hiding secrets](#hiding-secrets) | Settings for hiding secrets. |
| `index_path` | `storage_path('framework/vlog')` | Where indexes are stored. |
| `time_limit` | `2.0` | The most seconds one request spends indexing or searching. |
| `max_entry_bytes` | `1048576` | Entries larger than this are shortened when displayed. |
| `browser_errors.enabled` | `null` | Whether to capture browser errors. `null` captures them only in the `local` environment. Also set by `VLOG_BROWSER_ERRORS`. |
| `browser_errors.path` | `storage_path('logs/browser-errors.log')` | The file browser errors are written to. |
| `source.enabled` | `true` | Whether stack frames can be previewed. Also set by `VLOG_SOURCE_PREVIEW`. |
| `source.paths` | `[base_path()]` | The folders whose PHP files can be previewed. |
| `editor` | `null` | The editor file links open in, such as `vscode` or `phpstorm`. Uses `app.editor` when it's not set. Also set by `VLOG_EDITOR`. |

> [!IMPORTANT]
> Vlog can show any file in the `paths` folders that matches `patterns`. Only list folders that contain logs, never broad folders such as your application's root folder.

To read CodeIgniter 3 logs, which use a `.php` extension, add their pattern:

```php
'patterns' => ['*.log', 'log-*.php'],
```

## Hiding secrets

Before an entry is sent to the browser, Vlog hides:

- **Context values** under keys that look secret, such as `password`, `token`, `api_key`, `secret`, `authorization`, `cookie` and `credit_card`, at any depth.
- **Secrets written in text**, such as `password=...`, `"api_key": "..."`, bearer and basic tokens, JSON Web Tokens, passwords in URLs, private keys, and AWS, GitHub, Stripe and Slack keys.

To hide more, add context keys or regular expressions with delimiters:

```php
'redact' => [
    'enabled' => true,
    'keys' => ['iban', 'national_id'],
    'patterns' => ['/ACME-\d{4}-\d{4}/'],
],
```

Hiding protects secrets from being shown on screen, such as during screen sharing. It has two limits:

- It recognises common formats only, so it can miss secrets. Logs still shouldn't contain them.
- Search runs on the original text, so anyone who can access Vlog can still confirm whether a log contains a specific value.

To turn hiding off, set `VLOG_REDACT=false`.

## Large files and indexes

The first time a log file is opened, Vlog builds an index of where each entry starts. The index is stored in `index_path` and is about 16 bytes per entry. Each later request only reads the lines added since the last one.

For very large files, indexing and searching are limited by `time_limit` so a request never runs for too long. Vlog shows its indexing progress and continues on the next request, and a search that reaches the limit offers to continue.

Indexes are rebuilt automatically when a log file is rotated or replaced, and indexes of deleted log files are removed automatically. You can delete index files at any time, because they're rebuilt when needed.

Saved entries are also stored in `index_path`, in `bookmarks.json`. Keep this file if you want to keep your saved entries.

## Grouping

The **Groups** view combines entries that only differ in the parts that change between occurrences: numbers, IDs, UUIDs, email addresses and IP addresses. For example, `Order 1042 failed for amy@example.com` and `Order 1043 failed for bo@example.com` both belong to the group `Order {n} failed for {email}`. Entries at different levels are never grouped together.

Groups are built while indexing, so they cost no extra reading. Each file can have up to 5,000 groups. Any further new kinds of entries are still indexed and searchable, but not grouped. Select a group to see its entries.

## Following and searching every file

Select **Follow** to check for new entries every two seconds. New entries are highlighted as they arrive. Following pauses while the browser tab is hidden, or when you go to an older page.

To search every log file at once, use **Search all files** above the file list. Vlog shows the newest matches in each file, and **See all matches in this file** opens the file with the same search.

## Browser errors

In the `local` environment, Vlog adds a small script to your application's HTML pages. The script reports uncaught JavaScript errors, unhandled promise rejections and files that fail to load, and Vlog writes them to `storage/logs/browser-errors.log`, which appears in the file list like any other log. Each entry includes the page, the browser and the stack trace.

To capture browser errors in other environments, set `VLOG_BROWSER_ERRORS=true`. To turn capturing off, set it to `false`.

Browser errors are protected against abuse:

- Reports are only accepted from your application's own pages, and each visitor can send up to 30 reports a minute.
- Each page reports at most 20 different errors.
- Messages, URLs and stack traces are shortened, and line breaks are removed so a report can't add fake entries.
- Query strings are removed from URLs, because they can contain tokens.

The script is only added to full HTML pages, never to JSON responses, downloads, Inertia visits or Vlog itself. If your application uses `Vite::useCspNonce()`, the script uses the same nonce.

## Source code and editor links

Each stack frame, and the place where a logged exception was thrown, has an **Open** link that opens the file at that line in your editor. Select the gear icon to choose your editor. If your application runs in Docker or on another machine, enter the path on the server and the matching path on your computer, such as `/var/www/html` and `/Users/you/Code/app`. These settings are saved in your browser.

Frames from `vendor` and `node_modules` are hidden until you select **Show all frames**, so you can see your own code first.

Users the `manageVlog` gate allows can also select **Code** to see the lines around a frame. Only PHP files inside the `source.paths` folders are shown, never hidden files such as `.env`, or files in hidden folders such as `.git`. Secrets in the code are hidden in the same way as in log entries.

## Content Security Policy

The interface is a single page with an inline script and inline styles. If your application uses a strict Content Security Policy with Laravel's `Vite::useCspNonce()`, Vlog uses the same nonce.

## Security

Viewing, searching and exporting never change your log files. Only users the `manageVlog` gate allows can clear or delete files, write entries or preview source code. Every request that changes something must include Laravel's CSRF token and a header that other sites can't send. Files are requested by an ID, not by a path, so only files that Vlog itself finds in your configured folders can be read or changed. Exported CSV files are protected against spreadsheet formula injection. Server paths aren't sent to the browser. Responses are never cached, and the page can't be embedded in other sites.

To report a security vulnerability, please contact the maintainer privately instead of opening a public issue.

## Contributing

The PHP code is in `src`, and the interface source is in `resources/ui`. The built interface is committed to `dist`, so installing Vlog doesn't need Node.

```bash
composer install
composer test        # PHP tests
composer analyse     # PHPStan

npm install
npm test             # Interface tests
npm run typecheck
npm run build        # Rebuild dist after changing resources/ui
```

## License

Vlog is open-source software licensed under the [MIT license](LICENSE).

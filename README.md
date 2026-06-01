# Afterburner Installer

Create new Laravel projects with Afterburner using a simple command.

## Installation

```bash
composer global require laravel-afterburner/installer
```

Ensure Composer's global bin directory is in your PATH:

- **macOS**: `~/.composer/vendor/bin` or `~/.config/composer/vendor/bin`
- **Linux**: `~/.config/composer/vendor/bin` or `~/.composer/vendor/bin`
- **Windows**: `%USERPROFILE%\AppData\Roaming\Composer\vendor\bin`

## Usage

Create a new Afterburner application:

```bash
afterburner new my-app
```

This will:

1. Create a new Laravel project using the Afterburner template
2. Install all core dependencies
3. Set up the project structure
4. Optionally install add-on packages (Documents, Communications, Voting, Meetings, Playbook, Subscriptions) via interactive prompts after migrations

## Add-on packages

During `afterburner new`, you can select:

- **Documents** — `laravel-afterburner/documents` ^1.0
- **Communications** — `laravel-afterburner/communications` ^1.0
- **Voting** — `laravel-afterburner/voting` ^1.0
- **Meetings** — `laravel-afterburner/meetings` ^1.0
- **Playbook** — `laravel-afterburner/playbook` ^1.0
- **Subscriptions** — `laravel-afterburner/subscriptions` ^1.0

Each selection runs `composer require` and the package install Artisan command (`afterburner:voting:install`, etc.).

To add packages to an existing app:

```bash
composer require laravel-afterburner/voting:^1.0
php artisan afterburner:voting:install
```

## Next Steps

After creating your application:

```bash
cd my-app
php artisan migrate
php artisan afterburner:seed-install
```

During `afterburner new`, optional prompts set the entity type in `config/afterburner.php` and pass admin details to `afterburner:seed-install` — not to `.env`.

## Requirements

- PHP ^8.2
- Composer
- Laravel template repository accessible via Composer

## Development

To work on the installer locally:

```bash
cd afterburner-installer
composer install
php bin/afterburner new test-app
```

## License

MIT License - see LICENSE file for details.


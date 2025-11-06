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

## Next Steps

After creating your application:

```bash
cd my-app
php artisan migrate
php artisan db:seed --class=RolesSeeder  # Optional
```

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


<?php

namespace Afterburner\Installer\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Afterburner\Installer\Installers\CoreInstaller;
use function Laravel\Prompts\text;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class NewCommand extends Command
{
    /**
     * Configure the command.
     */
    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Afterburner application')
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the application')
            ->setHelp('This command creates a new Afterburner application in the specified directory.');
    }

    /**
     * Execute the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Display ASCII art banner
        $this->displayBanner($output);

        // Get project name - prompt if not provided
        $name = $input->getArgument('name');
        if (!$name) {
            $name = text('What is the name of your project?', required: true);
        }

        $directory = getcwd() . '/' . $name;

        // Check if directory already exists
        if (is_dir($directory)) {
            $output->writeln('<error>Directory already exists!</error>');
            return Command::FAILURE;
        }

        $installer = new CoreInstaller($output);
        
        try {
            // Install the project
            $installer->install($name, $directory);
            
            // Run post-installation steps and get installation details
            $installationDetails = $this->postInstall($name, $directory, $output);
            
            // Change to project directory as final step
            chdir($directory);
            
            // Display installation summary
            $this->displayInstallationSummary($directory, $output, $installationDetails);
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Installation failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Display the ASCII art banner for Afterburner in red.
     */
    protected function displayBanner(OutputInterface $output): void
    {
        $banner = <<<'ASCII'
        _    _____ _____ _____ ____  ____  _   _ ____  _   _ _____ ____  
       / \  |  ___|_   _| ____|  _ \| __ )| | | |  _ \| \ | | ____|  _ \ 
      / _ \ | |_    | | |  _| | |_) |  _ \| | | | |_) |  \| |  _| | |_) |
     / ___ \|  _|   | | | |___|  _ <| |_) | |_| |  _ <| |\  | |___|  _ < 
    /_/   \_\_|     |_| |_____|_| \_\____/ \___/|_| \_\_| \_|_____|_| \_\
    
    ASCII;

        $output->writeln('<fg=red>' . $banner . '</>');
        $output->writeln('');
    }

    /**
     * Handle post-installation prompts for database and migrations.
     */
    protected function postInstall(string $name, string $directory, OutputInterface $output): array
    {
        $envPath = $directory . '/.env';
        $envExamplePath = $directory . '/.env.example';

        // Track installation details
        $details = [
            'database' => null,
            'databaseName' => null,
            'databaseCreated' => false,
            'migrationsRun' => false,
            'seedsRun' => false,
            'entityType' => null,
            'systemAdminConfigured' => false,
            'domainConfigured' => false,
            'npmInstalled' => false,
            'assetsCompiled' => false,
            'featuresSelected' => [],
        ];

        // Ensure .env file exists
        if (!file_exists($envPath) && file_exists($envExamplePath)) {
            copy($envExamplePath, $envPath);
        }

        // Generate application key if needed
        $this->generateApplicationKey($directory, $output);

        // Prompt for domain configuration (optional)
        $domainConfigured = $this->promptDomain($name, $envPath, $envExamplePath, $output);
        $details['domainConfigured'] = $domainConfigured;

        // Prompt for database selection
        $database = select(
            label: 'Which database will your application use?',
            options: [
                'mysql' => 'MySQL',
                'pgsql' => 'PostgreSQL',
                'sqlite' => 'SQLite',
                'sqlsrv' => 'SQL Server',
            ],
            default: 'mysql'
        );

        $details['database'] = $database;

        // Update .env with selected database and get the database name
        $databaseName = $this->updateDatabaseConfig($envPath, $database, $name, $directory);
        $details['databaseName'] = $databaseName;

        $output->writeln('<info>Default database updated.</info>');

        // Clear config cache immediately after updating .env to ensure Laravel uses new database name
        $this->clearConfigCache($directory, $output);

        // Check and create database first (for non-SQLite databases)
        if ($database !== 'sqlite') {
            $databaseCreated = $this->checkAndCreateDatabase($directory, $database, $databaseName, $output);
            $details['databaseCreated'] = $databaseCreated;
        }

        // Prompt for system admin configuration (optional)
        $systemAdminConfigured = $this->promptSystemAdmin($envPath, $envExamplePath, $output);
        $details['systemAdminConfigured'] = $systemAdminConfigured;

        // Prompt for migrations after database is ready
        $runMigrations = confirm(
            label: 'Would you like to run the default database migrations?',
            default: true
        );

        if ($runMigrations) {
            $migrationSuccess = $this->runMigrations($directory, $output);
            $details['migrationsRun'] = $migrationSuccess;
            
            // If migrations were successful, prompt for feature selection
            if ($migrationSuccess) {
                $featuresSelected = $this->promptFeatures($directory, $output);
                $details['featuresSelected'] = $featuresSelected;
                
                // Prompt for entity type (optional) - needed for seeding
                $entityType = $this->promptEntityType($envPath, $envExamplePath, $output);
                $details['entityType'] = $entityType;

                $runSeeds = confirm(
                    label: 'Would you like to seed the database?',
                    default: false
                );

                if ($runSeeds) {
                    $seedsSuccess = $this->runSeeds($directory, $output);
                    $details['seedsRun'] = $seedsSuccess;
                }
            }
        }

        // Prompt for npm installation and asset compilation
        $installAssets = confirm(
            label: 'Would you like to install npm dependencies and compile assets?',
            default: true
        );

        if ($installAssets) {
            $npmSuccess = $this->installNpmAssets($directory, $output);
            $details['npmInstalled'] = $npmSuccess;
            $details['assetsCompiled'] = $npmSuccess;
        }

        return $details;
    }

    /**
     * Generate application key if needed.
     */
    protected function generateApplicationKey(string $directory, OutputInterface $output): void
    {
        $envPath = $directory . '/.env';
        
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);
        
        // Check if APP_KEY is empty or not set
        if (preg_match('/^APP_KEY=\s*$/m', $envContent) || !preg_match('/^APP_KEY=base64:.+$/m', $envContent)) {
            $process = new Process(['php', 'artisan', 'key:generate', '--force'], $directory);
            $process->setTimeout(60);
            $process->run(function ($type, $line) use ($output) {
                $output->write($line);
            });

            if ($process->isSuccessful()) {
                $output->writeln('<info>Application key set successfully.</info>');
            }
        }
    }

    /**
     * Update database configuration in .env file.
     */
    protected function updateDatabaseConfig(string $envPath, string $database, string $projectName, string $directory): string
    {
        if (!file_exists($envPath)) {
            return '';
        }

        $envContent = file_get_contents($envPath);

        // Convert project name to database name (lowercase with underscores)
        $databaseName = strtolower(str_replace(['-', ' '], '_', $projectName));

        // Update DB_CONNECTION
        $envContent = preg_replace('/^DB_CONNECTION=.*$/m', "DB_CONNECTION={$database}", $envContent);

        // Update only the database name, leave other settings as defaults from .env.example
        $expectedDbValue = '';
        switch ($database) {
            case 'sqlite':
                // Quote SQLite path to handle spaces and special characters
                $sqlitePath = $directory . '/database/database.sqlite';
                $expectedDbValue = $this->escapeEnvValue($sqlitePath);
                $envContent = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=' . $expectedDbValue, $envContent);
                break;
            case 'pgsql':
            case 'sqlsrv':
            case 'mysql':
            default:
                // Quote database name to handle special characters
                $expectedDbValue = $this->escapeEnvValue($databaseName);
                // Only update the database name, leave host, port, username, password as defaults
                // Handle both commented and uncommented lines
                $envContent = preg_replace('/^DB_DATABASE=.*$/m', "DB_DATABASE={$expectedDbValue}", $envContent);
                // If DB_DATABASE doesn't exist at all, add it
                if (!preg_match('/^DB_DATABASE=/m', $envContent)) {
                    // Find where other DB_ settings are and add it there
                    if (preg_match('/^DB_CONNECTION=/m', $envContent)) {
                        $envContent = preg_replace('/^(DB_CONNECTION=.*)$/m', "$1\nDB_DATABASE={$expectedDbValue}", $envContent);
                    } else {
                        $envContent .= "\nDB_DATABASE={$expectedDbValue}\n";
                    }
                }
                break;
        }

        file_put_contents($envPath, $envContent);
        
        // Verify the database name was set correctly (account for quoted values)
        $verifyContent = file_get_contents($envPath);
        $expectedDbValuePattern = preg_quote($expectedDbValue, '/');
        if (!preg_match('/^DB_DATABASE=' . $expectedDbValuePattern . '(\s|$)/m', $verifyContent)) {
            throw new \RuntimeException("Failed to update database name in .env file. Expected: {$databaseName}");
        }
        
        return $databaseName;
    }

    /**
     * Check if database exists and prompt to create it.
     */
    protected function checkAndCreateDatabase(string $directory, string $database, string $databaseName, OutputInterface $output): bool
    {
        if ($database === 'sqlite') {
            return false; // SQLite doesn't need database creation
        }

        if (empty($databaseName)) {
            return false;
        }

        $envPath = $directory . '/.env';
        if (!file_exists($envPath)) {
            return false;
        }

        $envContent = file_get_contents($envPath);

        // Try to connect and check if database exists
        preg_match('/^DB_HOST=(.*)$/m', $envContent, $hostMatches);
        preg_match('/^DB_PORT=(.*)$/m', $envContent, $portMatches);
        preg_match('/^DB_USERNAME=(.*)$/m', $envContent, $userMatches);
        preg_match('/^DB_PASSWORD=(.*)$/m', $envContent, $passwordMatches);

        // Extract values, handling empty strings from .env
        $host = isset($hostMatches[1]) ? trim($hostMatches[1]) : '127.0.0.1';
        $port = isset($portMatches[1]) ? trim($portMatches[1]) : ($database === 'pgsql' ? '5432' : '3306');
        $username = isset($userMatches[1]) ? trim($userMatches[1]) : 'root';
        $password = isset($passwordMatches[1]) ? trim($passwordMatches[1]) : '';

        // Use defaults if values are empty
        $host = empty($host) ? '127.0.0.1' : $host;
        $port = empty($port) ? ($database === 'pgsql' ? '5432' : '3306') : $port;
        $username = empty($username) ? 'root' : $username;
        // Password can be empty, that's fine

        // Try to check if database exists - if connection fails, skip creation
        $databaseExists = false;
        try {
            $databaseExists = $this->checkDatabaseExists($database, $host, $port, $username, $password, $databaseName);
        } catch (\Exception $e) {
            $output->writeln('<comment>Could not connect to database. Please check your database credentials in .env file.</comment>');
            $output->writeln('<comment>Database creation skipped: ' . $e->getMessage() . '</comment>');
            return false;
        }

        if (!$databaseExists) {
            $output->writeln("<comment>The database '{$databaseName}' does not exist on the '{$database}' connection.</comment>");
            
            $createDatabase = confirm(
                label: 'Would you like to create it?',
                default: false
            );

            if ($createDatabase) {
                try {
                    $this->createDatabase($database, $host, $port, $username, $password, $databaseName, $output);
                    return true;
                } catch (\Exception $e) {
                    $output->writeln("<error>Failed to create database: " . $e->getMessage() . "</error>");
                    $output->writeln("<info>You can create the database manually and run migrations later.</info>");
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Check if database exists.
     */
    protected function checkDatabaseExists(string $driver, string $host, string $port, string $username, string $password, string $databaseName): bool
    {
        try {
            switch ($driver) {
                case 'mysql':
                    $dsn = "mysql:host={$host};port={$port}";
                    $pdo = new \PDO($dsn, $username, $password);
                    $stmt = $pdo->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = " . $pdo->quote($databaseName));
                    return $stmt->rowCount() > 0;
                
                case 'pgsql':
                    $dsn = "pgsql:host={$host};port={$port}";
                    $pdo = new \PDO($dsn, $username, $password);
                    $stmt = $pdo->query("SELECT 1 FROM pg_database WHERE datname = " . $pdo->quote($databaseName));
                    return $stmt->rowCount() > 0;
                
                default:
                    return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create the database.
     */
    protected function createDatabase(string $driver, string $host, string $port, string $username, string $password, string $databaseName, OutputInterface $output): void
    {
        try {
            switch ($driver) {
                case 'mysql':
                    $dsn = "mysql:host={$host};port={$port}";
                    $pdo = new \PDO($dsn, $username, $password);
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$databaseName}`");
                    $output->writeln("<info>Database '{$databaseName}' created successfully.</info>");
                    break;
                
                case 'pgsql':
                    $dsn = "pgsql:host={$host};port={$port}";
                    $pdo = new \PDO($dsn, $username, $password);
                    $pdo->exec("CREATE DATABASE " . $pdo->quote(str_replace('"', '""', $databaseName)));
                    $output->writeln("<info>Database '{$databaseName}' created successfully.</info>");
                    break;
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to create database: " . $e->getMessage() . "</error>");
        }
    }

    /**
     * Clear configuration cache.
     */
    protected function clearConfigCache(string $directory, OutputInterface $output): void
    {
        $clearCacheProcess = new Process(['php', 'artisan', 'config:clear'], $directory);
        $clearCacheProcess->setTimeout(60);
        $clearCacheProcess->run();
        
        // Also clear cache if it exists
        $cacheClearProcess = new Process(['php', 'artisan', 'cache:clear'], $directory);
        $cacheClearProcess->setTimeout(60);
        $cacheClearProcess->run();
    }

    /**
     * Run database migrations.
     */
    protected function runMigrations(string $directory, OutputInterface $output): bool
    {
        // Clear config cache to ensure fresh database connection
        $this->clearConfigCache($directory, $output);

        // Check if migration files exist
        $migrationPath = $directory . '/database/migrations';
        if (!is_dir($migrationPath)) {
            $output->writeln('<comment>Migration directory not found. Skipping migrations.</comment>');
            return false;
        }

        $migrationFiles = glob($migrationPath . '/*.php');
        if (empty($migrationFiles)) {
            $output->writeln('<comment>No migration files found. Skipping migrations.</comment>');
            return false;
        }

        // Run migrations
        $process = new Process(['php', 'artisan', 'migrate', '--force'], $directory);
        $process->setTimeout(300);
        $migrationOutput = '';
        $process->run(function ($type, $line) use ($output, &$migrationOutput) {
            $output->write($line);
            $migrationOutput .= $line;
        });

        // Check if "Nothing to migrate" was the output
        if (strpos($migrationOutput, 'Nothing to migrate') !== false) {
            $output->writeln('');
            $output->writeln('<comment>Laravel reported "Nothing to migrate". This might mean:</comment>');
            $output->writeln('<comment>  - Migrations table exists but Laravel thinks migrations already ran</comment>');
            $output->writeln('<comment>  - Database connection issue preventing migration detection</comment>');
            $output->writeln('');
            $output->writeln('<info>To fix this, you can try:</info>');
            $output->writeln('  <info>cd ' . basename($directory) . '</info>');
            $output->writeln('  <info>php artisan migrate:status</info>');
            $output->writeln('  <info>php artisan migrate</info>');
            $output->writeln('');
            return false;
        }

        if (!$process->isSuccessful()) {
            $output->writeln('<comment>Migrations may have failed. Please check the output above.</comment>');
            return false;
        }

        return true;
    }

    /**
     * Prompt for domain configuration (optional).
     */
    protected function promptDomain(string $projectName, string $envPath, string $envExamplePath, OutputInterface $output): bool
    {
        $setDomain = confirm(
            label: 'Would you like to set the APP_URL domain?',
            default: false
        );

        if ($setDomain) {
            // Convert project name to URL-friendly format
            $domainName = strtolower(str_replace([' ', '_'], '-', $projectName));
            $appUrl = "http://{$domainName}.test";

            // Update .env if it exists, otherwise update .env.example
            $targetFile = file_exists($envPath) ? $envPath : $envExamplePath;
            // Quote URL to handle special characters
            $this->updateEnvFile($targetFile, 'APP_URL', $this->escapeEnvValue($appUrl));
            $output->writeln("<info>APP_URL set to {$appUrl}</info>");
            return true;
        }

        return false;
    }

    /**
     * Prompt for system admin configuration (optional).
     */
    protected function promptSystemAdmin(string $envPath, string $envExamplePath, OutputInterface $output): bool
    {
        $setSystemAdmin = confirm(
            label: 'Would you like to configure the system admin?',
            default: false
        );

        if ($setSystemAdmin) {
            $username = text(
                label: 'System admin username:',
                required: true
            );

            $email = text(
                label: 'System admin email:',
                required: true,
                validate: function ($value) {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        return 'Please enter a valid email address.';
                    }
                    return null;
                }
            );

            // Update .env if it exists, otherwise update .env.example
            $targetFile = file_exists($envPath) ? $envPath : $envExamplePath;
            // Quote username and email to handle special characters
            $this->updateEnvFile($targetFile, 'AFTERBURNER_USERNAME', $this->escapeEnvValue($username));
            $this->updateEnvFile($targetFile, 'AFTERBURNER_EMAIL', $this->escapeEnvValue($email));
            $output->writeln('<info>System admin configuration saved.</info>');
            return true;
        }

        return false;
    }

    /**
     * Prompt for entity type selection (optional).
     */
    protected function promptEntityType(string $envPath, string $envExamplePath, OutputInterface $output): ?string
    {
        $setEntityType = confirm(
            label: 'Would you like to set the entity type?',
            default: false
        );

        if ($setEntityType) {
            $entityType = select(
                label: 'Select entity type:',
                options: [
                    'team' => 'Team',
                    'strata' => 'Strata',
                    'company' => 'Company',
                    'organization' => 'Organization',
                ],
                default: 'team'
            );

            // Update .env if it exists, otherwise update .env.example
            $targetFile = file_exists($envPath) ? $envPath : $envExamplePath;
            $this->updateEnvFile($targetFile, 'AFTERBURNER_ENTITY_LABEL', $entityType);
            $output->writeln("<info>AFTERBURNER_ENTITY_LABEL set to {$entityType}</info>");
            
            return $entityType;
        }

        return null;
    }

    /**
     * Prompt for feature selection from afterburner config.
     */
    protected function promptFeatures(string $directory, OutputInterface $output): array
    {
        // Get features from config file
        $allFeatures = $this->getFeaturesFromConfig($directory);
        
        if (empty($allFeatures)) {
            $configPath = $directory . '/config/afterburner.php';
            if (!file_exists($configPath)) {
                $output->writeln('<comment>Config file not found at: ' . $configPath . '</comment>');
            } else {
                $output->writeln('<comment>No features found in afterburner config. Skipping feature selection.</comment>');
            }
            return [];
        }

        // Separate team-based features from other features
        $teamFeatures = [];
        $nonTeamFeatures = [];
        
        foreach ($allFeatures as $key => $label) {
            // Check if feature is team-related (contains 'team' in the key)
            if (stripos($key, 'team') !== false) {
                $teamFeatures[$key] = $label;
            } else {
                $nonTeamFeatures[$key] = $label;
            }
        }

        $selectedFeatures = [];
        $teamsEnabled = false;

        // First, ask if user wants teams
        if (!empty($teamFeatures)) {
            $teamsEnabled = confirm(
                label: 'Would you like to enable teams?',
                default: false
            );

            if ($teamsEnabled) {
                // Set default to include 'teams' if it exists
                $defaultTeamFeatures = [];
                if (isset($teamFeatures['teams'])) {
                    $defaultTeamFeatures[] = 'teams';
                }
                
                // Prompt for team-based features
                $selectedTeamFeatures = multiselect(
                    label: 'Which team features would you like to enable?',
                    options: $teamFeatures,
                    default: $defaultTeamFeatures,
                    hint: 'Use the space bar to select or deselect features. Press Enter to confirm.'
                );
                
                $selectedFeatures = array_merge($selectedFeatures, $selectedTeamFeatures);
            }
            // If teams are disabled, team features are not shown and not added to selectedFeatures
        }

        // Prompt for non-team features
        if (!empty($nonTeamFeatures)) {
            $selectedNonTeamFeatures = multiselect(
                label: 'Which other features would you like to enable?',
                options: $nonTeamFeatures,
                default: [],
                hint: 'Use the space bar to select or deselect features. Press Enter to confirm.'
            );
            
            $selectedFeatures = array_merge($selectedFeatures, $selectedNonTeamFeatures);
        }

        // Store all features in database (selected as enabled, unselected as disabled)
        // Ensure all feature keys are in snake_case
        $allFeatureKeys = array_map(function($key) {
            return $this->toSnakeCase($key);
        }, array_keys($allFeatures));
        
        $selectedFeaturesSnakeCase = array_map(function($key) {
            return $this->toSnakeCase($key);
        }, $selectedFeatures);
        
        $this->storeFeaturesInDatabase($directory, $allFeatureKeys, $selectedFeaturesSnakeCase, $output);
        
        if (!empty($selectedFeaturesSnakeCase)) {
            $output->writeln('<info>Features configured successfully.</info>');
        } else {
            $output->writeln('<comment>No features selected. All features will be disabled.</comment>');
        }

        return $selectedFeaturesSnakeCase;
    }

    /**
     * Convert a string to snake_case format.
     */
    protected function toSnakeCase(string $string): string
    {
        // If already in snake_case, return as-is
        if (preg_match('/^[a-z]+(_[a-z]+)*$/', $string)) {
            return $string;
        }
        
        // Convert camelCase or PascalCase to snake_case
        $snakeCase = strtolower(preg_replace('/([A-Z])/', '_$1', $string));
        $snakeCase = ltrim($snakeCase, '_');
        
        // Replace multiple underscores with single underscore
        $snakeCase = preg_replace('/_+/', '_', $snakeCase);
        
        return $snakeCase;
    }

    /**
     * Get features from afterburner config file by parsing it instead of requiring it.
     */
    protected function getFeaturesFromConfig(string $directory): array
    {
        $configPath = $directory . '/config/afterburner.php';
        
        if (!file_exists($configPath)) {
            return [];
        }

        // Read the config file as text
        $configContent = file_get_contents($configPath);
        
        // Parse the file to extract Features:: calls from the features array
        $features = $this->extractFeaturesFromConfig($configContent);
        
        // Debug: Check if we found any features
        if (empty($features) && strpos($configContent, "'features'") !== false) {
            // Config file exists and has features key, but parser found nothing
            // This helps diagnose parser issues
        }
        
        return $features;
    }

    /**
     * Extract Features::methodName() calls from config file content.
     */
    protected function extractFeaturesFromConfig(string $content): array
    {
        $features = [];
        
        // Tokenize the PHP code
        $tokens = token_get_all($content);
        $tokenCount = count($tokens);
        
        $inFeaturesArray = false;
        $arrayDepth = 0;
        $skipUntilNewline = false;
        $foundFeaturesKey = false;
        
        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];
            
            // Track if we're in a comment
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Skip until newline for single-line comments
                    if (strpos($token[1], '//') === 0) {
                        $skipUntilNewline = true;
                        // Check if comment contains newline
                        if (strpos($token[1], "\n") !== false) {
                            $skipUntilNewline = false;
                        }
                    }
                    continue;
                }
                
                // Reset skip flag on newline in whitespace
                if ($token[0] === T_WHITESPACE && strpos($token[1], "\n") !== false) {
                    $skipUntilNewline = false;
                }
            } else {
                // Reset skip flag on newline in string token
                if ($token === "\n") {
                    $skipUntilNewline = false;
                }
            }
            
            // Skip tokens if we're in a comment
            if ($skipUntilNewline) {
                continue;
            }
            
            // Look for 'features' key
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $tokenValue = trim($token[1], '\'"');
                if ($tokenValue === 'features') {
                    $foundFeaturesKey = true;
                    // Check if next non-whitespace token is =>
                    $j = $i + 1;
                    while ($j < $tokenCount) {
                        if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                            $j++;
                            continue;
                        }
                        if (is_string($tokens[$j]) && $tokens[$j] === '=>') {
                            // Check if next is array opening
                            $j++;
                            while ($j < $tokenCount) {
                                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                                    $j++;
                                    continue;
                                }
                                if (is_string($tokens[$j]) && $tokens[$j] === '[') {
                                    // Mark that we found the features array opening
                                    // We'll set the flag when we process this [ token below
                                    $foundFeaturesKey = true;
                                    break 2;
                                }
                                break;
                            }
                        }
                        break;
                    }
                }
            }
            
            // Track array depth - also check if this is the features array opening
            if (is_string($token)) {
                if ($token === '[') {
                    // If we just found the features key and this is the opening bracket, enter the array
                    if ($foundFeaturesKey && !$inFeaturesArray) {
                        $inFeaturesArray = true;
                        $arrayDepth = 1;
                        $foundFeaturesKey = false; // Reset flag
                    } elseif ($inFeaturesArray) {
                        $arrayDepth++;
                    }
                } elseif ($token === ']' && $inFeaturesArray) {
                    $arrayDepth--;
                    if ($arrayDepth === 0) {
                        // We've exited the features array
                        break;
                    }
                }
            }
            
            // Extract Features::methodName() calls when inside features array
            if ($inFeaturesArray && $arrayDepth === 1 && !$skipUntilNewline) {
                if (is_array($token) && $token[0] === T_STRING && $token[1] === 'Features') {
                    // Find the next non-whitespace token (should be ::)
                    $j = $i + 1;
                    while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                    
                    if ($j < $tokenCount) {
                        $nextToken = $tokens[$j];
                        $isDoubleColon = false;
                        
                        if (is_string($nextToken) && $nextToken === '::') {
                            $isDoubleColon = true;
                        } elseif (is_array($nextToken) && ($nextToken[0] === T_DOUBLE_COLON || $nextToken[0] === T_PAAMAYIM_NEKUDOTAYIM)) {
                            $isDoubleColon = true;
                        }
                        
                        if ($isDoubleColon) {
                            // Find the next non-whitespace token (should be method name)
                            $j++;
                            while ($j < $tokenCount && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                                $j++;
                            }
                            
                            if ($j < $tokenCount) {
                                $methodToken = $tokens[$j];
                                if (is_array($methodToken) && $methodToken[0] === T_STRING) {
                                    $methodName = $methodToken[1];
                                    
                                    // Convert method name to snake_case feature key
                                    $featureKey = $this->toSnakeCase($methodName);
                                    
                                    // Convert to label (e.g., "terms_and_privacy_policy" -> "Terms And Privacy Policy")
                                    $label = ucwords(str_replace('_', ' ', $featureKey));
                                    
                                    $features[$featureKey] = $label;
                                    
                                    // Skip ahead past Features::methodName (and any whitespace)
                                    $i = $j;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $features;
    }

    /**
     * Store features in the database features table.
     * All features from config are stored, with selected ones enabled and unselected ones disabled.
     */
    protected function storeFeaturesInDatabase(string $directory, array $allFeatures, array $selectedFeatures, OutputInterface $output): void
    {
        // Use artisan tinker or direct database access via artisan command
        // We'll use a custom artisan command or direct PDO connection
        
        // First, try to get database connection info from .env
        $envPath = $directory . '/.env';
        if (!file_exists($envPath)) {
            $output->writeln('<comment>Cannot store features: .env file not found.</comment>');
            return;
        }

        $envContent = file_get_contents($envPath);
        
        // Extract database connection details
        preg_match('/^DB_CONNECTION=(.*)$/m', $envContent, $connectionMatches);
        preg_match('/^DB_HOST=(.*)$/m', $envContent, $hostMatches);
        preg_match('/^DB_PORT=(.*)$/m', $envContent, $portMatches);
        preg_match('/^DB_DATABASE=(.*)$/m', $envContent, $databaseMatches);
        preg_match('/^DB_USERNAME=(.*)$/m', $envContent, $usernameMatches);
        preg_match('/^DB_PASSWORD=(.*)$/m', $envContent, $passwordMatches);

        $connection = isset($connectionMatches[1]) ? trim($connectionMatches[1]) : 'mysql';
        $host = isset($hostMatches[1]) ? trim($hostMatches[1]) : '127.0.0.1';
        $port = isset($portMatches[1]) ? trim($portMatches[1]) : ($connection === 'pgsql' ? '5432' : '3306');
        $database = isset($databaseMatches[1]) ? trim($databaseMatches[1]) : '';
        $username = isset($usernameMatches[1]) ? trim($usernameMatches[1]) : 'root';
        $password = isset($passwordMatches[1]) ? trim($passwordMatches[1]) : '';

        // Remove quotes from database name if present
        $database = trim($database, '"\'');
        
        // For SQLite, use the full path
        if ($connection === 'sqlite') {
            // If database path is relative, make it absolute relative to the project directory
            if (!empty($database) && !str_starts_with($database, '/')) {
                $database = $directory . '/' . $database;
            }
        }
        
        if (empty($database)) {
            $output->writeln('<comment>Cannot store features: database name not found in .env.</comment>');
            return;
        }

        try {
            // Connect to database
            $dsn = $this->buildDsn($connection, $host, $port, $database);
            $pdo = new \PDO($dsn, $username, $password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // Check if feature_flags table exists
            $tableName = 'feature_flags';
            $tableExists = $this->checkTableExists($pdo, $connection, $database, $tableName);
            
            if (!$tableExists) {
                $output->writeln('<comment>Feature flags table does not exist. Skipping feature storage.</comment>');
                return;
            }

            // Insert or update all features
            // Selected features are enabled (1), unselected features are disabled (0)
            $selectedFeaturesSet = array_flip($selectedFeatures); // Convert to hash set for faster lookup
            
            foreach ($allFeatures as $feature) {
                $isEnabled = isset($selectedFeaturesSet[$feature]) ? 1 : 0;
                
                // Check if feature already exists by key
                $stmt = $pdo->prepare("SELECT id FROM {$tableName} WHERE `key` = ?");
                $stmt->execute([$feature]);
                
                if ($stmt->rowCount() === 0) {
                    // Insert new feature flag
                    $insertStmt = $pdo->prepare("INSERT INTO {$tableName} (`key`, enabled, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
                    $insertStmt->execute([$feature, $isEnabled]);
                } else {
                    // Update existing feature flag
                    $updateStmt = $pdo->prepare("UPDATE {$tableName} SET enabled = ?, updated_at = NOW() WHERE `key` = ?");
                    $updateStmt->execute([$isEnabled, $feature]);
                }
            }

        } catch (\Exception $e) {
            $output->writeln('<comment>Could not store features in database: ' . $e->getMessage() . '</comment>');
            $output->writeln('<comment>You can enable features manually later.</comment>');
        }
    }

    /**
     * Build DSN string for database connection.
     */
    protected function buildDsn(string $connection, string $host, string $port, string $database): string
    {
        return match ($connection) {
            'mysql' => "mysql:host={$host};port={$port};dbname={$database}",
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
            'sqlite' => "sqlite:{$database}",
            'sqlsrv' => "sqlsrv:Server={$host},{$port};Database={$database}",
            default => "mysql:host={$host};port={$port};dbname={$database}",
        };
    }

    /**
     * Check if a table exists in the database.
     */
    protected function checkTableExists(\PDO $pdo, string $connection, string $database, string $tableName): bool
    {
        try {
            // Escape table name to prevent SQL injection
            $escapedTableName = str_replace(['`', "'", '"'], '', $tableName);
            
            return match ($connection) {
                'mysql' => $pdo->query("SHOW TABLES LIKE " . $pdo->quote($escapedTableName))->rowCount() > 0,
                'pgsql' => $pdo->query("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = " . $pdo->quote($escapedTableName) . ")")->fetchColumn(),
                'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($escapedTableName))->rowCount() > 0,
                'sqlsrv' => $pdo->query("SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = " . $pdo->quote($escapedTableName))->rowCount() > 0,
                default => false,
            };
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Escape a value for use in .env file by wrapping in quotes and escaping internal quotes.
     */
    protected function escapeEnvValue(string $value): string
    {
        // If value is already quoted, return as-is (assumes it's properly formatted)
        if (preg_match('/^".*"$/', $value)) {
            return $value;
        }
        
        // Escape double quotes and backslashes, then wrap in quotes
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    /**
     * Update a value in an .env file (.env or .env.example).
     */
    protected function updateEnvFile(string $envFilePath, string $key, string $value): void
    {
        if (!file_exists($envFilePath)) {
            return;
        }

        $envContent = file_get_contents($envFilePath);

        // Update existing key or add new one
        if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $envContent)) {
            $envContent = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', "{$key}={$value}", $envContent);
        } else {
            // Add at the end of the file
            $envContent .= "\n{$key}={$value}\n";
        }

        file_put_contents($envFilePath, $envContent);
    }

    /**
     * Run database seeding.
     */
    protected function runSeeds(string $directory, OutputInterface $output): bool
    {
        // Entity type is already set in .env file, so RolesSeeder can read it from there
        $process = new Process(['php', 'artisan', 'db:seed', '--force'], $directory);
        $process->setTimeout(300);
        $process->run(function ($type, $line) use ($output) {
            $output->write($line);
        });

        if (!$process->isSuccessful()) {
            $output->writeln('<comment>Seeding may have failed. Please check the output above.</comment>');
            return false;
        } else {
            $output->writeln('<info>Database seeded successfully.</info>');
            return true;
        }
    }

    /**
     * Install npm dependencies and compile assets.
     */
    protected function installNpmAssets(string $directory, OutputInterface $output): bool
    {
        // Check if npm is available
        $npmCheck = new Process(['npm', '--version'], $directory);
        $npmCheck->run();
        
        if (!$npmCheck->isSuccessful()) {
            $output->writeln('<comment>npm is not installed. Skipping npm installation.</comment>');
            $output->writeln('<comment>You can run "npm install" and "npm run build" manually later.</comment>');
            return false;
        }
        
        // Check if package.json exists
        $packageJsonPath = $directory . '/package.json';
        if (!file_exists($packageJsonPath)) {
            $output->writeln('<comment>package.json not found. Skipping npm installation.</comment>');
            return false;
        }
        
        // Run npm install
        $output->writeln('<comment>Installing npm dependencies...</comment>');
        $installProcess = new Process(['npm', 'install'], $directory);
        $installProcess->setTimeout(600); // 10 minutes
        $installProcess->run(function ($type, $line) use ($output) {
            $output->write($line);
        });
        
        if (!$installProcess->isSuccessful()) {
            $output->writeln('<comment>npm install may have failed. Please check the output above.</comment>');
            $output->writeln('<comment>You can run "npm install" manually later.</comment>');
            return false;
        }
        
        $output->writeln('<info>npm dependencies installed successfully.</info>');
        
        // Run npm run build
        $output->writeln('<comment>Compiling assets...</comment>');
        $buildProcess = new Process(['npm', 'run', 'build'], $directory);
        $buildProcess->setTimeout(600); // 10 minutes
        $buildProcess->run(function ($type, $line) use ($output) {
            $output->write($line);
        });
        
        if (!$buildProcess->isSuccessful()) {
            $output->writeln('<comment>npm run build may have failed. Please check the output above.</comment>');
            $output->writeln('<comment>You can run "npm run build" manually later.</comment>');
            return false;
        }
        
        $output->writeln('<info>Assets compiled successfully.</info>');
        return true;
    }

    /**
     * Read a value from .env file.
     */
    protected function readEnvValue(string $envPath, string $key, ?string $default = null): ?string
    {
        if (!file_exists($envPath)) {
            return $default;
        }

        $envContent = file_get_contents($envPath);
        
        // Match the key=value pattern, handling quoted values
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $envContent, $matches)) {
            $value = trim($matches[1]);
            
            // Remove quotes if present
            if (preg_match('/^"(.*)"$/', $value, $quoteMatches)) {
                $value = $quoteMatches[1];
                // Unescape quotes and backslashes
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
            }
            
            return $value ?: $default;
        }
        
        return $default;
    }

    /**
     * Display installation summary with admin login information.
     */
    protected function displayInstallationSummary(string $directory, OutputInterface $output, array $details): void
    {
        $envPath = $directory . '/.env';
        
        // Get admin email (either from AFTERBURNER_EMAIL or default)
        $adminEmail = $this->readEnvValue($envPath, 'AFTERBURNER_EMAIL', 'admin@laravel-afterburner.com');
        
        // Get APP_URL (default to http://localhost if not set)
        $appUrl = $this->readEnvValue($envPath, 'APP_URL', 'http://localhost');
        // Remove quotes if present
        $appUrl = trim($appUrl, '"');
        
        // Database type labels
        $databaseLabels = [
            'mysql' => 'MySQL',
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            'sqlsrv' => 'SQL Server',
        ];
        
        $output->writeln('');
        $output->writeln('<info>Application created successfully!</info>');
        $output->writeln('');
        $output->writeln('<comment>Installation Summary:</comment>');
        $output->writeln('');
        
        // Database configuration
        if ($details['database']) {
            $dbLabel = $databaseLabels[$details['database']] ?? ucfirst($details['database']);
            $output->writeln('<comment>Database:</comment>');
            $output->writeln("  <info>Type:</info> {$dbLabel}");
            if ($details['databaseName']) {
                $output->writeln("  <info>Name:</info> {$details['databaseName']}");
            }
            if ($details['databaseCreated']) {
                $output->writeln('  <info>Status:</info> Created');
            } elseif ($details['database'] !== 'sqlite') {
                $output->writeln('  <info>Status:</info> Using existing database');
            }
            $output->writeln('');
        }
        
        // Migrations
        if ($details['migrationsRun']) {
            $output->writeln('<comment>Database Migrations:</comment>');
            $output->writeln('  <info>Status:</info> Completed');
            $output->writeln('');
        }
        
        // Features
        $allFeatures = $this->getFeaturesFromConfig($directory);
        if (!empty($allFeatures)) {
            $selectedFeatures = $details['featuresSelected'] ?? [];
            $selectedFeaturesSet = array_flip($selectedFeatures);
            
            $enabledFeatures = [];
            $disabledFeatures = [];
            
            foreach ($allFeatures as $featureKey => $featureLabel) {
                if (isset($selectedFeaturesSet[$featureKey])) {
                    $enabledFeatures[] = $featureLabel;
                } else {
                    $disabledFeatures[] = $featureLabel;
                }
            }
            
            $output->writeln('<comment>Features:</comment>');
            
            if (!empty($enabledFeatures)) {
                $output->writeln('  <info>Enabled:</info>');
                foreach ($enabledFeatures as $feature) {
                    $output->writeln("    <info>•</info> {$feature}");
                }
            }
            
            if (!empty($disabledFeatures)) {
                $output->writeln('  <comment>Disabled:</comment>');
                foreach ($disabledFeatures as $feature) {
                    $output->writeln("    <comment>•</comment> {$feature}");
                }
            }
            
            $output->writeln('');
        }
        
        // Seeds
        if ($details['seedsRun']) {
            $output->writeln('<comment>Database Seeding:</comment>');
            $output->writeln('  <info>Status:</info> Completed');
            if ($details['entityType']) {
                $output->writeln("  <info>Entity Type:</info> " . ucfirst($details['entityType']));
            }
            $output->writeln('');
        }
        
        // NPM and assets
        if ($details['npmInstalled']) {
            $output->writeln('<comment>NPM Dependencies:</comment>');
            $output->writeln('  <info>Status:</info> Installed');
            $output->writeln('');
        }
        
        if ($details['assetsCompiled']) {
            $output->writeln('<comment>Assets:</comment>');
            $output->writeln('  <info>Status:</info> Compiled');
            $output->writeln('');
        }
        
        // System Admin Login
        $output->writeln('<comment>System Admin Login:</comment>');
        $output->writeln("  <info>Email:</info> {$adminEmail}");
        $output->writeln('  <info>Password:</info> Afterburner');
        $output->writeln('');
        
        // Application URL
        $output->writeln('<comment>Application URL:</comment>');
        $output->writeln("  <info>{$appUrl}</info>");
        $output->writeln('');
    }
}


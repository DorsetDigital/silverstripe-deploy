<?php
declare(strict_types=1);
namespace DorsetDigital\SSDeploy;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Application
{
    private array $temporary = [];
    public function __construct(private readonly string $packageRoot) {}

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = $this->options(array_slice($argv, 2));
        $projectRoot = getcwd();
        $configFile = $projectRoot . '/deploy.yml';
        try {
            if (in_array($command, ['help', '--help', '-h'], true)) {
                $this->help(); return 0;
            }
            if ($command === 'init') {
                if ($options['_'] !== [] || count($options) !== 1) throw new \RuntimeException('init does not accept arguments or options.');
                return $this->init($projectRoot);
            }
            if (!is_file($configFile)) throw new \RuntimeException('Missing deploy.yml in current directory.');
            $config = Yaml::parseFile($configFile);
            if (!is_array($config)) throw new \RuntimeException('deploy.yml must contain a mapping.');
            $stage = $options['stage'] ?? 'staging';
            if (!is_string($stage) || !in_array($stage, ['staging', 'prod'], true)) {
                throw new \RuntimeException('Invalid stage. Use staging or prod.');
            }
            $targets = $this->targets($config, $stage);
            if ($command === 'sites') {
                foreach ($targets as $alias => $target) echo "$alias\t{$target['deploy_path']}\n";
                return 0;
            }
            if ($command === 'check') {
                $this->validate($config, $targets);
                echo 'Configuration OK; ' . count($targets) . " site(s).\n";
                $this->checkTools($config);
                return 0;
            }
            if ($command === 'test-connection') {
                $this->validate($config, $targets);
                $this->checkTools($config);
                $transport = new Transport($projectRoot);
                try {
                    $connection = $transport->prepare($config);
                    return $this->testConnection($config, $connection, $projectRoot);
                } finally {
                    $transport->cleanup();
                }
            }
            if (!in_array($command, ['deploy', 'rollback', 'unlock'], true)) throw new \RuntimeException("Unknown command: $command");
            $this->validate($config, $targets);
            $sites = $options['_'];
            $all = isset($options['all']);
            if ($all && $sites !== []) throw new \RuntimeException('Specify site aliases OR --all, not both.');
            if ($command === 'unlock' && $all) throw new \RuntimeException('Unlock requires explicit site aliases; --all is not supported.');
            if ($command === 'rollback' && ($all || count($sites) > 1)) {
                throw new \RuntimeException('Batch rollback is deliberately unsupported.');
            }
            if ($sites !== []) {
                $selected = [];
                foreach ($sites as $site) {
                    if (!isset($targets[$site])) throw new \RuntimeException("Unknown site: $site");
                    if (isset($selected[$site])) throw new \RuntimeException("Duplicate site: $site");
                    $selected[$site] = $targets[$site];
                }
                $targets = $selected;
            } elseif (in_array($command, ['rollback', 'unlock'], true) && count($targets) !== 1) {
                throw new \RuntimeException("Specify a site alias for $command.");
            }
            // With deploy, no site aliases means all configured targets.
            $workers = (int) ($options['workers'] ?? $config['deployment']['workers'] ?? 2);
            if ($workers < 1 || $workers > 10) throw new \RuntimeException('Worker count must be 1–10.');
            $this->showDeploymentSummary($command, $config, $targets, $stage, $workers, $projectRoot);
            if (isset($options['dry-run'])) {
                echo "Dry run: no commands executed.\n";
                return 0;
            }
            if (!isset($options['yes'])) {
                $question = new ConfirmationQuestion('Proceed? [y/N] ', false);
                if (!(new QuestionHelper())->ask(new ArrayInput([]), new ConsoleOutput(), $question)) return 1;
            }
            $this->checkTools($config);
            if ($command === 'deploy') $this->prepareLocalBuild($config, $projectRoot);
            $transport = new Transport($projectRoot);
            $transportConfig = $transport->prepare($config);
            try {
                return $this->queue($command, $targets, $workers, $configFile, $transportConfig, $projectRoot, $stage, isset($options['verbose']), $config);
            } finally {
                $transport->cleanup();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'ssdeploy: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function init(string $projectRoot): int
    {
        $files = [
            'deploy' => <<<'SH'
#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"
exec ./vendor/bin/ssdeploy deploy "$@"
SH,
            'deploy.yml' => <<<'YAML'
# Configure this file before deploying. See the package README for all options.
project:
  name: Example Project

server:
  connection: ssh
  host: deploy
  user: ubuntu
  port: 22

hosts:
  website:
    staging_deploy_path: /var/www/sites/example-staging
    prod_deploy_path: /var/www/sites/example-production

aws:
  aws_account_id: 'YOUR_AWS_ACCOUNT_ID'
  aws_region: eu-west-2

php:
  version: '8.3'

composer:
  install: true
  install_dev: false

silverstripe:
  dev_build: true

shared_dirs:
  - public/assets
shared_files:
  - .env
YAML,
            '.deployignore' => <<<'IGNORE'
# Project-local files excluded from rsync uploads.
# Paths beginning with / are relative to the project root.

# Version control and editor configuration
/.git/
/.github/
/.gitlab/
/.idea/
/.vscode/
/.fleet/
/.history/
/.settings/
/.metadata/
/nbproject/
/.project
/.classpath
/.buildpath
/*.iml
/.ddev/

# Local dependencies and deployment tooling
/vendor/
/node_modules/
/bin/
/.deploy-logs/
/deploy
/deploy.php
/deploy.yml
/.deployignore

# Local Docker development environment
/docker/
/docker-compose.yml
/docker-compose.yaml
/docker-compose.*.yml
/docker-compose.*.yaml
/compose.yml
/compose.yaml
/compose.*.yml
/compose.*.yaml

# Local backups, diagnostic logs and OS noise
/dump.sql
/dump.sql.gz
/*.sql.gz
/*.sql.zip
/yarn-error.log
/npm-debug.log*
/.DS_Store
/Thumbs.db
/*.swp
/*.swo
/*~

# Keep composer.json, composer.lock, package.json, yarn.lock,
# webpack.config.js and generated public/build assets where required.
# Shared paths such as public/assets and .env are excluded automatically.
IGNORE,
        ];
        foreach ($files as $name => $contents) {
            $path = $projectRoot . '/' . $name;
            if (file_exists($path) || is_link($path)) {
                echo "Exists (unchanged): $name\n";
                continue;
            }
            // Exclusive creation avoids overwriting a file created by another process.
            $handle = @fopen($path, 'x');
            if ($handle === false) throw new \RuntimeException("Cannot create $path");
            try {
                if (fwrite($handle, $contents . "\n") !== strlen($contents) + 1) {
                    throw new \RuntimeException("Unable to write $path");
                }
            } finally {
                fclose($handle);
            }
            if ($name === 'deploy' && !chmod($path, 0755)) {
                throw new \RuntimeException("Unable to make $path executable");
            }
            echo "Created: $name\n";
        }
        echo "Review deploy.yml before running a deployment.\n";
        return 0;
    }

    private function testConnection(array $config, array $connection, string $projectRoot): int
    {
        $server = $config['server'];
        $mode = $server['connection'] ?? 'ssh';
        $host = $connection['hostname'];
        $port = (string) ($server['port'] ?? 22);
        $remote = $server['user'] . '@' . $host;

        $args = ['ssh'];
        if (!empty($connection['ssh_config'])) {
            array_push($args, '-F', $connection['ssh_config']);
        }
        array_push($args,
            '-o', 'BatchMode=yes',
            '-o', 'ConnectTimeout=15',
            '-o', 'ConnectionAttempts=1',
            '-p', $port,
            $remote,
            'printf "Connected as: "; id -un; printf "Hostname: "; hostname'
        );

        echo "Testing " . strtoupper($mode) . " connection to $remote (port $port)...\n";
        $process = new Process($args, $projectRoot);
        $process->setTimeout(60);
        $process->run();
        if (!$process->isSuccessful()) {
            fwrite(STDERR, "Connection failed:\n" . $process->getErrorOutput() . $process->getOutput());
            return 1;
        }
        echo $process->getOutput();
        echo "Connection successful. No deployment files were changed.\n";
        return 0;
    }

    private function showDeploymentSummary(string $command, array $config, array $targets, string $stage, int $workers, string $projectRoot): void
    {
        $branch = 'Not version controlled';
        if ($this->isGitRepository($projectRoot)) {
            $git = new Process(['git', 'branch', '--show-current'], $projectRoot);
            $git->mustRun();
            $branch = trim($git->getOutput());
            if ($branch === '') {
                $git = new Process(['git', 'rev-parse', '--short', 'HEAD'], $projectRoot);
                $git->mustRun();
                $branch = trim($git->getOutput()) . ' (detached HEAD)';
            }
        }
        $server = $config['server'];
        $transport = $server['connection'] ?? 'ssh';
        $destination = $transport === 'ssm'
            ? $server['instance_id']
            : $server['host'];

        $output = new ConsoleOutput();
        $esc = static fn (string $value): string => OutputFormatter::escape($value);
        $output->writeln('');
        $output->writeln('<info>You are about to ' . $esc($command) . ':</info>');
        $output->writeln('<comment>Project:</comment> ' . $esc((string) $config['project']['name']));
        $output->writeln('<comment>Git branch:</comment> ' . $esc($branch));
        $stageRelevant = false;
        foreach (array_keys($targets) as $alias) {
            $host = $config['hosts'][$alias] ?? [];
            if (!isset($host['deploy_path'])) {
                $stageRelevant = true;
                break;
            }
        }
        if ($stageRelevant) {
            $output->writeln('<comment>Stage:</comment> <info>' . $esc($stage) . '</info>');
        }
        $output->writeln('<comment>Connection:</comment> ' . $esc($transport));
        $output->writeln('<comment>Server:</comment> ' . $esc((string) $destination));
        $output->writeln('<comment>User:</comment> ' . $esc((string) $server['user']));
        $output->writeln('<comment>Targets:</comment>');
        foreach ($targets as $alias => $target) {
            $output->writeln('  <info>' . $esc((string) $alias) . '</info> -> ' . $esc((string) $target['deploy_path']));
        }
        if (count($targets) > 1) $output->writeln('<comment>Concurrent workers:</comment> ' . $workers);
        $output->writeln('');
    }

    private function options(array $args): array
    {
        $result = ['_' => []];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                if (!in_array($key, ['all', 'yes', 'dry-run', 'stage', 'workers', 'verbose'], true)) throw new \RuntimeException("Unknown option --$key");
                $result[$key] = $value;
            } else $result['_'][] = $arg;
        }
        return $result;
    }

    private function targets(array $config, string $stage): array
    {
        $hosts = $config['hosts'] ?? null;
        if (!is_array($hosts) || $hosts === []) {
            throw new \RuntimeException('hosts must contain at least one site; define deployment paths under hosts.<alias>.');
        }
        $targets = [];
        foreach ($hosts as $alias => $host) {
            if (!is_array($host)) throw new \RuntimeException("Invalid host configuration for $alias");
            $path = $host['deploy_path'] ?? $host[$stage . '_deploy_path'] ?? null;
            if (!$path) throw new \RuntimeException("Missing deploy_path or {$stage}_deploy_path for $alias");
            $targets[$alias] = ['deploy_path' => $path];
        }
        return $targets;
    }

    private function validate(array $config, array $targets): void
    {
        if (empty($config['project']['name'])) throw new \RuntimeException('project.name required');
        foreach (['deploy_path', 'staging_deploy_path', 'prod_deploy_path'] as $key) {
            if (array_key_exists($key, $config['server'] ?? [])) {
                throw new \RuntimeException("server.$key is no longer supported; move deployment paths under hosts.<alias>.");
            }
        }
        if (empty($config['server']['user'])) throw new \RuntimeException('server.user required');
        $type = $config['server']['connection'] ?? 'ssh';
        if (!in_array($type, ['ssh', 'ssm'], true)) throw new \RuntimeException('connection must be ssh or ssm');
        if ($type === 'ssh' && empty($config['server']['host'])) throw new \RuntimeException('server.host required for SSH');
        if ($type === 'ssm' && (empty($config['server']['instance_id']) || empty($config['server']['region']))) throw new \RuntimeException('SSM needs instance_id and region');
        foreach ($targets as $alias => $target) {
            if (!preg_match('~^/[a-zA-Z0-9_./-]+$~', $target['deploy_path']) || str_contains($target['deploy_path'], '..')) throw new \RuntimeException("Invalid absolute deployment path for $alias");
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', (string) $alias)) throw new \RuntimeException("Invalid site alias $alias");
        }
    }

    private function checkTools(array $config): void
    {
        foreach (['ssh', 'rsync'] as $binary) {
            $p = new Process(['which', $binary]); $p->run();
            if (!$p->isSuccessful()) throw new \RuntimeException("Missing local executable $binary");
        }
        if (($config['server']['connection'] ?? 'ssh') === 'ssm') {
            foreach (['aws', 'ssh-keygen', 'session-manager-plugin'] as $binary) {
                $p = new Process(['which', $binary]); $p->run();
                if (!$p->isSuccessful()) throw new \RuntimeException("Missing local executable $binary");
            }
        }
        echo "Local transport prerequisites available.\n";
    }

    private function isGitRepository(string $projectRoot): bool
    {
        $git = new Process(['git', '-C', $projectRoot, 'rev-parse', '--show-toplevel']);
        $git->run();
        return $git->isSuccessful()
            && realpath(trim($git->getOutput())) === realpath($projectRoot);
    }

    private function prepareLocalBuild(array $config, string $projectRoot): void
    {
        if ($this->isGitRepository($projectRoot)) {
            $git = new Process(['git', 'status', '--porcelain'], $projectRoot);
            $git->mustRun();
            if (trim($git->getOutput()) !== '') {
                throw new \RuntimeException('Working tree is not clean. Commit or stash changes before deploying.');
            }
        }
        foreach ($config['build']['local'] ?? [] as $command) {
            if (!is_string($command) || trim($command) === '') {
                throw new \RuntimeException('build.local commands must be non-empty strings');
            }
            echo "Running locally: $command\n";
            $process = Process::fromShellCommandline($command, $projectRoot);
            $process->setTimeout(null);
            $process->mustRun(function ($type, $output): void { echo $output; });
        }
    }

    private function queue(string $action, array $targets, int $workers, string $configFile, array $transport, string $projectRoot, string $stage, bool $verbose, array $config): int
    {
        $active = []; $pending = array_keys($targets); $failed = []; $done = [];
        $deployerBin = $projectRoot . '/vendor/bin/dep';
        if (!is_file($deployerBin)) throw new \RuntimeException('Deployer binary missing: install package dependencies with Composer.');

        $palette = [36, 35, 33, 34, 32, 96, 95, 93, 94, 92];
        $colours = [];
        foreach (array_keys($targets) as $index => $site) {
            $colours[$site] = $palette[$index % count($palette)];
        }
        $colourEnabled = function_exists('stream_isatty') && stream_isatty(STDOUT) && getenv('NO_COLOR') === false;
        $prefix = static function (string $site) use ($colours, $colourEnabled): string {
            $label = "[$site]";
            return $colourEnabled ? "\033[" . $colours[$site] . "m" . $label . "\033[0m" : $label;
        };

        $buffers = []; $currentTasks = []; $logs = []; $logPaths = []; $failureTasks = [];
        $runId = date('Y-m-d_His') . '_' . bin2hex(random_bytes(3));
        $logDir = $projectRoot . '/.deploy-logs/' . $runId;
        $displayLine = static function (string $site, string $line) use (&$currentTasks, $prefix, $verbose, $config): void {
            $plain = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $line);
            $isTask = preg_match('/(?:^|\s)task\s+([a-zA-Z][a-zA-Z0-9:_-]*)/', $plain, $match);
            if ($isTask) $currentTasks[$site] = $match[1];
            $task = $currentTasks[$site] ?? '';
            $showConfigured = ($task === 'deploy:composer' && ($config['composer']['show_output'] ?? false))
                || ($task === 'deploy:assets' && ($config['assets']['show_output'] ?? false))
                || ($task === 'deploy:silverstripe_build' && ($config['silverstripe']['show_output'] ?? false));
            if ($verbose || $isTask || $showConfigured) echo $prefix($site) . ' ' . $line . "\n";
        };
        $writeOutput = static function (string $site, string $chunk) use (&$buffers, &$logs, $displayLine): void {
            // Log raw output before filtering; successful logs are deleted.
            fwrite($logs[$site]['handle'], $chunk);
            $buffers[$site] = ($buffers[$site] ?? '') . $chunk;
            while (($newline = strpos($buffers[$site], "\n")) !== false) {
                $line = rtrim(substr($buffers[$site], 0, $newline), "\r");
                $buffers[$site] = substr($buffers[$site], $newline + 1);
                if ($line !== '') $displayLine($site, $line);
            }
        };

        try {
            while ($pending || $active) {
                while ($pending && count($active) < $workers) {
                    $site = array_shift($pending);
                    $path = tempnam(sys_get_temp_dir(), 'ssdeploy-');
                    if ($path === false) throw new \RuntimeException('Unable to create temporary deployment log.');
                    $handle = fopen($path, 'wb');
                    if ($handle === false) {
                        unlink($path);
                        throw new \RuntimeException('Unable to open temporary deployment log.');
                    }
                    $logs[$site] = ['path' => $path, 'handle' => $handle];
                    $process = new Process([PHP_BINARY, $deployerBin, $action === 'unlock' ? 'deploy:unlock' : $action, $site, '-f', $this->packageRoot . '/recipe/deploy.php'], $projectRoot, [
                        'SSDEPLOY_CONFIG' => $configFile,
                        'SSDEPLOY_TARGET' => $site,
                        'SSDEPLOY_STAGE' => $stage,
                        'SSDEPLOY_TRANSPORT_HOST' => $transport['hostname'],
                        'SSDEPLOY_SSH_CONFIG' => $transport['ssh_config'] ?? '',
                        'SSDEPLOY_SSH_IDENTITY' => $transport['identity'] ?? '',
                    ]);
                    $process->setTimeout(null);
                    $process->start(static function ($type, $chunk) use ($site, $writeOutput): void {
                        $writeOutput($site, $chunk);
                    });
                    $active[$site] = $process;
                    echo $prefix($site) . " START\n";
                }
                foreach ($active as $site => $process) {
                    if ($process->isRunning()) continue;
                    if (($buffers[$site] ?? '') !== '') {
                        $displayLine($site, $buffers[$site]);
                    }
                    unset($buffers[$site]);
                    $status = $process->getExitCode();
                    fclose($logs[$site]['handle']);
                    $logs[$site]['handle'] = null;
                    if ($status === 0) {
                        $done[] = $site;
                        unlink($logs[$site]['path']);
                        echo $prefix($site) . " DONE\n";
                    } else {
                        $failed[] = $site;
                        $failureTasks[$site] = $currentTasks[$site] ?? 'unknown task';
                        if (!is_dir($logDir) && !mkdir($logDir, 0700, true) && !is_dir($logDir)) {
                            throw new \RuntimeException("Unable to create deployment log directory: $logDir");
                        }
                        $ignoreFile = $projectRoot . '/.deploy-logs/.gitignore';
                        if (!is_file($ignoreFile)) file_put_contents($ignoreFile, "*\n!.gitignore\n");
                        $destination = $logDir . '/' . $site . '.log';
                        if (!rename($logs[$site]['path'], $destination)) {
                            throw new \RuntimeException("Unable to save deployment log: $destination");
                        }
                        $logPaths[$site] = '.deploy-logs/' . $runId . '/' . $site . '.log';
                        echo $prefix($site) . " FAIL (exit $status; {$failureTasks[$site]})\n";
                    }
                    unset($logs[$site], $currentTasks[$site], $active[$site]);
                }
                if ($active) usleep(200000);
            }
        } finally {
            foreach ($logs as $log) {
                if (is_resource($log['handle'])) fclose($log['handle']);
                if (is_file($log['path'])) unlink($log['path']);
            }
        }

        echo "\nDeployment summary:\n";
        foreach (array_keys($targets) as $site) {
            if (isset($logPaths[$site])) {
                echo $prefix($site) . " FAILED ({$failureTasks[$site]})\n";
                echo '  Log: ' . $logPaths[$site] . "\n";
            } else {
                echo $prefix($site) . " OK\n";
            }
        }
        echo 'Finished: ' . count($done) . ' succeeded, ' . count($failed) . " failed.\n";
        return $failed ? 1 : 0;
    }

    private function help(): void
    {
        echo <<<'TEXT'
ssdeploy — Silverstripe deployment CLI

  ssdeploy init
  ssdeploy sites
  ssdeploy check
  ssdeploy test-connection [--stage=staging]
  ssdeploy deploy [site ...] [--all] [--stage=staging] [--workers=2] [--dry-run] [--yes] [--verbose]
  ssdeploy rollback [site] [--stage=prod] [--yes]
  ssdeploy unlock [site ...] [--stage=staging] [--yes]

Run from the project directory containing deploy.yml.
TEXT;
        echo "\n";
    }
}

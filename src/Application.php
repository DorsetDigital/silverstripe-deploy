<?php
declare(strict_types=1);
namespace DorsetDigital\SSDeploy;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Process\Process;

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
            if (!is_file($configFile)) throw new \RuntimeException('Missing deploy.yml in current directory.');
            $config = Yaml::parseFile($configFile);
            if (!is_array($config)) throw new \RuntimeException('deploy.yml must contain a mapping.');
            $targets = $this->targets($config, $options['stage'] ?? 'prod');
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
            if (!in_array($command, ['deploy', 'rollback'], true)) throw new \RuntimeException("Unknown command: $command");
            $this->validate($config, $targets);
            $all = isset($options['all']);
            $site = $options['_'][0] ?? null;
            if ($all && $site !== null) throw new \RuntimeException('Specify a site OR --all, not both.');
            if (!$all) {
                if ($site === null && count($targets) === 1) $site = array_key_first($targets);
                if ($site === null) throw new \RuntimeException('Specify a site alias or --all.');
                if (!isset($targets[$site])) throw new \RuntimeException("Unknown site: $site");
                $targets = [$site => $targets[$site]];
            }
            $workers = (int) ($options['workers'] ?? $config['deployment']['workers'] ?? 3);
            if ($workers < 1 || $workers > 10) throw new \RuntimeException('Worker count must be 1–10.');
            if ($command === 'rollback' && $all) throw new \RuntimeException('Batch rollback is deliberately unsupported.');
            echo strtoupper($command) . ': ' . implode(', ', array_keys($targets)) . " (workers: $workers)\n";
            if (isset($options['dry-run'])) {
                echo "Dry run: no commands executed.\n";
                return 0;
            }
            if (!isset($options['yes'])) {
                fwrite(STDOUT, 'Proceed? [y/N] ');
                if (!in_array(strtolower(trim((string) fgets(STDIN))), ['y', 'yes'], true)) return 1;
            }
            $this->checkTools($config);
            if ($command === 'deploy') $this->prepareLocalBuild($config, $projectRoot);
            $transport = new Transport($projectRoot);
            $transportConfig = $transport->prepare($config);
            try {
                return $this->queue($command, $targets, $workers, $configFile, $transportConfig, $projectRoot, $options['stage'] ?? 'prod');
            } finally {
                $transport->cleanup();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'ssdeploy: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function options(array $args): array
    {
        $result = ['_' => []];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
                if (!in_array($key, ['all', 'yes', 'dry-run', 'stage', 'workers'], true)) throw new \RuntimeException("Unknown option --$key");
                $result[$key] = $value;
            } else $result['_'][] = $arg;
        }
        return $result;
    }

    private function targets(array $config, string $stage): array
    {
        $server = $config['server'] ?? [];
        if (!empty($config['hosts'])) {
            $targets = [];
            foreach ($config['hosts'] as $alias => $host) {
                $path = $host['deploy_path'] ?? $host[$stage . '_deploy_path'] ?? null;
                if ($path === null) throw new \RuntimeException("Missing deploy_path for $alias");
                $targets[$alias] = ['deploy_path' => $path];
            }
            return $targets;
        }
        $path = $server[$stage . '_deploy_path'] ?? $server['deploy_path'] ?? null;
        if (!$path) throw new \RuntimeException("No deployment path for stage $stage");
        return ['default' => ['deploy_path' => $path]];
    }

    private function validate(array $config, array $targets): void
    {
        if (empty($config['project']['name'])) throw new \RuntimeException('project.name required');
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

    private function prepareLocalBuild(array $config, string $projectRoot): void
    {
        $git = new Process(['git', 'status', '--porcelain'], $projectRoot);
        $git->mustRun();
        if (trim($git->getOutput()) !== '') {
            throw new \RuntimeException('Working tree is not clean. Commit or stash changes before deploying.');
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

    private function queue(string $action, array $targets, int $workers, string $configFile, array $transport, string $projectRoot, string $stage): int
    {
        $active = []; $pending = array_keys($targets); $failed = []; $done = [];
        $deployerBin = $projectRoot . '/vendor/bin/dep';
        if (!is_file($deployerBin)) throw new \RuntimeException('Deployer binary missing: install package dependencies with Composer.');
        while ($pending || $active) {
            while ($pending && count($active) < $workers) {
                $site = array_shift($pending);
                $process = new Process([PHP_BINARY, $deployerBin, 'deploy' === $action ? 'deploy' : 'rollback', $site, '-f', $this->packageRoot . '/recipe/deploy.php'], $projectRoot, [
                    'SSDEPLOY_CONFIG' => $configFile,
                    'SSDEPLOY_TARGET' => $site,
                    'SSDEPLOY_STAGE' => $stage,
                    'SSDEPLOY_TRANSPORT_HOST' => $transport['hostname'],
                    'SSDEPLOY_SSH_CONFIG' => $transport['ssh_config'] ?? '',
                    'SSDEPLOY_SSH_IDENTITY' => $transport['identity'] ?? '',
                ]);
                $process->setTimeout(null);
                $process->start(function ($type, $buffer) use ($site) { foreach (explode("\n", rtrim($buffer, "\n")) as $line) if ($line !== '') echo "[$site] $line\n"; });
                $active[$site] = $process;
                echo "START $site\n";
            }
            foreach ($active as $site => $process) {
                if ($process->isRunning()) continue;
                $status = $process->getExitCode();
                if ($status === 0) { $done[] = $site; echo "DONE $site\n"; }
                else { $failed[] = $site; echo "FAIL $site (exit $status)\n"; }
                unset($active[$site]);
            }
            if ($active) usleep(200000);
        }
        echo 'Finished: ' . count($done) . ' succeeded, ' . count($failed) . " failed.\n";
        return $failed ? 1 : 0;
    }

    private function help(): void
    {
        echo <<<'TEXT'
ssdeploy — Silverstripe deployment CLI

  ssdeploy sites
  ssdeploy check
  ssdeploy deploy [site] [--all] [--stage=prod] [--workers=3] [--dry-run] [--yes]
  ssdeploy rollback [site] [--stage=prod] [--yes]

Run from the project directory containing deploy.yml.
TEXT;
        echo "\n";
    }
}

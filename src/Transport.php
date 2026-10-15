<?php
declare(strict_types=1);
namespace DorsetDigital\SSDeploy;

use Symfony\Component\Process\Process;

final class Transport
{
    private ?string $directory = null;
    public function __construct(private readonly string $projectRoot) {}
    public function prepare(array $config): array
    {
        $server = $config['server'];
        if (($server['connection'] ?? 'ssh') !== 'ssm') return ['hostname' => $server['host']];
        $instance = $server['instance_id'];
        $region = $server['region'];
        $user = $server['user'];
        $hostKeyChecking = $server['host_key_checking'] ?? 'accept-new';
        if (!in_array($hostKeyChecking, ['yes', 'accept-new'], true)) {
            throw new \RuntimeException('server.host_key_checking must be yes or accept-new');
        }
        $availabilityZone = $server['availability_zone'] ?? null;
        foreach ([$instance, $region, $user] as $arg) {
            if (!preg_match('/^[a-zA-Z0-9_.-]+$/', (string)$arg)) throw new \RuntimeException('Invalid SSM connection parameter');
        }
        $this->directory = sys_get_temp_dir() . '/ssdeploy-' . bin2hex(random_bytes(12));
        if (!mkdir($this->directory, 0700)) throw new \RuntimeException('Unable to create temporary transport directory');
        $key = $this->directory . '/key';
        $this->run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $key]);
        chmod($key, 0600);
        // Push a fresh short-lived public key each time a new SSH connection is opened.
        // SSH and rsync use the same ProxyCommand and do not depend on a 60s batch window.
        $proxy = $this->directory . '/proxy.sh';
        $script = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
instance="$1"; region="$2"; user="$3"; public_key="$4"; port="$5"; zone="$6"
zone_args=()
if [ -n "$zone" ]; then zone_args=(--availability-zone "$zone"); fi
aws ec2-instance-connect send-ssh-public-key \
  --instance-id "$instance" --instance-os-user "$user" \
  --ssh-public-key "file://$public_key" --region "$region" \
  "${zone_args[@]}" --output json >/dev/null
exec aws ssm start-session --target "$instance" --region "$region" \
  --document-name AWS-StartSSHSession --parameters "portNumber=$port"
BASH;
        file_put_contents($proxy, $script . "\n");
        chmod($proxy, 0700);
        $sshConfig = $this->directory . '/ssh_config';
        $quote = static fn(string $s): string => escapeshellarg($s);
        // Match the previous working SSM script by accepting an unknown host key
        // for this invocation only. Opt into persistent verification with
        // server.host_key_checking: yes and a prepopulated known_hosts_file.
        $proxyCommand = implode(' ', array_map($quote, [$proxy, $instance, $region, $user, $key . '.pub', (string)($server['port'] ?? 22), (string)($availabilityZone ?? '')]));
        $knownHostsFile = $hostKeyChecking === 'accept-new'
            ? $this->directory . '/known_hosts'
            : ($server['known_hosts_file'] ?? '~/.ssh/known_hosts');
        $cfg = "Host ssdeploy-target\n" .
            "  HostName $instance\n" .
            "  User $user\n" .
            "  Port " . ($server['port'] ?? 22) . "\n" .
            "  IdentityFile $key\n" .
            "  IdentitiesOnly yes\n" .
            "  StrictHostKeyChecking $hostKeyChecking\n" .
            "  UserKnownHostsFile " . $knownHostsFile . "\n" .
            "  ProxyCommand $proxyCommand\n";
        file_put_contents($sshConfig, $cfg);
        chmod($sshConfig, 0600);
        return ['hostname' => 'ssdeploy-target', 'ssh_config' => $sshConfig, 'identity' => $key];
    }

    private function run(array $command): void
    {
        $p = new Process($command, $this->projectRoot); $p->mustRun();
    }
    public function cleanup(): void
    {
        if ($this->directory === null) return;
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }
}

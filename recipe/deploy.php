<?php
declare(strict_types=1);
namespace Deployer;

require 'recipe/common.php';

use Symfony\Component\Yaml\Yaml;

$config = Yaml::parseFile(getenv('SSDEPLOY_CONFIG') ?: getcwd() . '/deploy.yml');
$alias = getenv('SSDEPLOY_TARGET');
if (!$alias) throw new \RuntimeException('SSDEPLOY_TARGET missing');
$server = $config['server'];
$hostConfig = $config['hosts'][$alias] ?? null;
if (!is_array($hostConfig)) throw new \RuntimeException("Unknown or invalid site alias: $alias");
$stage = getenv('SSDEPLOY_STAGE') ?: 'prod';
$deployPath = $hostConfig['deploy_path'] ?? $hostConfig[$stage . '_deploy_path'] ?? null;
if (!$deployPath) throw new \RuntimeException('Missing deploy path');
$hostname = getenv('SSDEPLOY_TRANSPORT_HOST') ?: ($server['host'] ?? null);
if (!$hostname) throw new \RuntimeException('Missing deployment host');
$host = host($alias)->setHostname($hostname)->setRemoteUser($server['user'])
    ->setPort((int)($server['port'] ?? 22))->setDeployPath($deployPath);
if (getenv('SSDEPLOY_SSH_CONFIG')) $host->set('config_file', getenv('SSDEPLOY_SSH_CONFIG'));
if (getenv('SSDEPLOY_SSH_IDENTITY')) $host->set('identity_file', getenv('SSDEPLOY_SSH_IDENTITY'));
set('default_selector', $alias);
set('ssh_multiplexing', true);
set('repository', '');
set('application', $config['project']['name']);
set('shared_dirs', $config['shared_dirs'] ?? []);
set('shared_files', $config['shared_files'] ?? []);
set('writable_dirs', $config['writable'] ?? []);
set('keep_releases', $config['deployment']['keep_releases'] ?? 2);
set('git_tty', false);

$excludes = $config['exclude'] ?? [];
$ignoreFile = getcwd() . '/.deployignore';
if (is_file($ignoreFile)) foreach (file($ignoreFile, FILE_IGNORE_NEW_LINES) as $line) {
    $line = trim($line);
    if ($line !== '' && !str_starts_with($line, '#')) $excludes[] = $line;
}
$excludes = array_unique(array_merge($excludes, ['.git', 'vendor', 'node_modules', '.deploy-logs']));

// Never create a new site root on a typo.
task('deploy:check_target', function () {
    if (!test('[ -d {{deploy_path}} ]') || !test('[ -w {{deploy_path}} ]')) {
        throw new \RuntimeException('Deploy path must already exist and be writable: ' . get('deploy_path'));
    }
});
task('deploy:setup', function () {
    invoke('deploy:check_target');
    run('cd {{deploy_path}} && mkdir -p .dep releases shared');
    if (test('[ ! -L {{current_path}} ] && [ -d {{current_path}} ]')) {
        throw new \RuntimeException('current must be a symlink or absent');
    }
});
task('deploy:update_code', function () { writeln('Uploading local code with rsync'); });
task('git:check_clean', function () {
    if (trim(runLocally('git status --porcelain')) !== '') {
        throw new \RuntimeException('Working tree is not clean');
    }
});
task('deploy:rsync', function () use ($excludes, $server) {
    $source = rtrim(getcwd(), '/') . '/';
    $destination = get('release_path');
    $ssh = ['ssh'];
    if ($configFile = getenv('SSDEPLOY_SSH_CONFIG')) array_push($ssh, '-F', $configFile);
    array_push($ssh, '-p', (string)($server['port'] ?? 22));
    $sshCmd = implode(' ', array_map('escapeshellarg', $ssh));
    $remote = get('remote_user') . '@' . get('hostname') . ':' . $destination;
    $args = implode(' ', array_map(fn($e) => '--exclude=' . escapeshellarg($e), $excludes));
    runLocally('rsync -az --delete ' . $args . ' -e ' . escapeshellarg($sshCmd) . ' ' . escapeshellarg($source) . ' ' . escapeshellarg($remote));
});

$phpImage = sprintf('%s.dkr.ecr.%s.amazonaws.com/php-fpm-%s', $config['aws']['aws_account_id'] ?? '', $config['aws']['aws_region'] ?? '', $config['php']['version'] ?? '8.3');
$quotedImage = escapeshellarg($phpImage);

task('deploy:composer', function () use ($config, $quotedImage) {
    if (!(bool)($config['composer']['install'] ?? true)) return;
    $release = escapeshellarg(get('release_path'));
    $cache = '/home/ubuntu/.composer';
    $devFlag = !($config['composer']['install_dev'] ?? false) ? '--no-dev ' : '';
    run('mkdir -p ' . escapeshellarg($cache . '/cache'));
    run("docker pull $quotedImage");
    $cmd = "docker run --rm --user 1000:1000 -v {$release}:/app -v {$cache}:/tmp/composer -e COMPOSER_HOME=/tmp/composer -e COMPOSER_CACHE_DIR=/tmp/composer/cache -w /app $quotedImage composer install {$devFlag}--prefer-dist --no-interaction --no-progress --optimize-autoloader --classmap-authoritative";
    $output = run($cmd); if ($output !== '') writeln($output);
});

task('deploy:assets', function () use ($config) {
    if (!(bool)($config['assets']['enabled'] ?? false)) return;
    $release = get('release_path'); $root = get('deploy_path');
    $cache = '/home/ubuntu/.build-cache/' . preg_replace('/[^a-zA-Z0-9_.-]/', '-', get('application')) . '/node';
    $nodeImage = $config['assets']['image'] ?? 'node:14';
    $buildCommand = $config['assets']['command'] ?? 'yarn webpack --config webpack.config.js';
    $outputDir = $config['assets']['output'] ?? 'public/build';
    if (!preg_match('~^[a-zA-Z0-9_./-]+$~', $outputDir) || str_contains($outputDir, '..')) throw new \RuntimeException('Invalid output path');
    $script = <<<'BASH'
set -euo pipefail
release=__RELEASE__
site_env=__ENV__
cache_root=__CACHE__
image=__IMAGE__
build_cmd=__COMMAND__
output_dir=__OUTPUT__
[ -f "$release/package.json" ] && [ -f "$release/yarn.lock" ] || { echo 'Missing package.json/yarn.lock' >&2; exit 1; }
[ -f "$site_env" ] || { echo 'Missing site .env' >&2; exit 1; }
mkdir -p "$cache_root/dependencies" "$cache_root/workspaces" "$cache_root/yarn-cache"
key=$(cd "$release" && sha256sum package.json yarn.lock | sha256sum | cut -d' ' -f1)
deps="$cache_root/dependencies/$key"
exec 9>"$cache_root/.install.lock"
if ! flock -n 9; then
  echo 'Node dependencies: waiting for installation lock'
  flock -x 9
fi
if [ -f "$deps/.ready" ]; then
  echo 'Node dependencies: using cached installation'
else
  echo 'Node dependencies: installing (cache miss)'
  rm -rf "$deps"; mkdir -p "$deps"
  cp "$release/package.json" "$release/yarn.lock" "$deps/"
  docker run --rm --user 1000:1000 -v "$deps:/usr/src/app" -v "$cache_root/yarn-cache:/tmp/yarn-cache" -e YARN_CACHE_FOLDER=/tmp/yarn-cache -w /usr/src/app "$image" sh -c 'yarn install --frozen-lockfile --non-interactive'
  touch "$deps/.ready"
  echo 'Node dependencies: installation cached'
fi
flock -u 9
workspace=$(mktemp -d "$cache_root/workspaces/build.XXXXXXXX")
trap 'rm -rf "$workspace"' EXIT
mkdir -p "$workspace/site"
rsync -a --exclude='/vendor/' --exclude='/node_modules/' --exclude='/public/assets/' --exclude='/public/build/' --exclude='/.env' --exclude='/.git/' "$release/" "$workspace/site/"
cp -L "$site_env" "$workspace/site/.env"
chmod 600 "$workspace/site/.env"
docker run --rm --user 1000:1000 -v "$workspace/site:/usr/src/app" -v "$deps/node_modules:/opt/node_modules:ro" -w /usr/src/app "$image" sh -c 'ln -s /opt/node_modules node_modules && exec sh -c "$1"' sh "$build_cmd"
[ -d "$workspace/site/$output_dir" ] || { echo 'Build output missing' >&2; exit 1; }
mkdir -p "$release/$output_dir"
rsync -a --delete "$workspace/site/$output_dir/" "$release/$output_dir/"
BASH;
    $script = strtr($script, [
        '__RELEASE__' => escapeshellarg($release), '__ENV__' => escapeshellarg("$root/shared/.env"),
        '__CACHE__' => escapeshellarg($cache), '__IMAGE__' => escapeshellarg($nodeImage),
        '__COMMAND__' => escapeshellarg($buildCommand), '__OUTPUT__' => escapeshellarg($outputDir),
    ]);
    $output = run("printf '%s' '" . base64_encode($script) . "' | base64 -d | bash -se");
    if ($output !== '') writeln($output);
});

task('deploy:silverstripe_build', function () use ($config, $quotedImage) {
    if (!(bool)($config['silverstripe']['dev_build'] ?? false)) return;
    $release = escapeshellarg(get('release_path'));
    $shared = escapeshellarg(get('deploy_path') . '/shared');
    $output = run("docker run --rm --user 1000:1000 -v {$release}:/app -v {$shared}:/shared -w /app $quotedImage vendor/bin/sake dev/build 'flush=1'");
    if ($output !== '') writeln($output);
});

task('deploy:before_symlink', function () use ($config) {
    foreach ($config['deploy']['before_symlink'] ?? [] as $cmd) run('cd {{release_path}} && ' . $cmd);
});
task('deploy:after_symlink', function () use ($config) {
    foreach ($config['deploy']['after_symlink'] ?? [] as $cmd) run('cd {{release_path}} && ' . $cmd);
});

before('deploy:shared', 'deploy:rsync');
after('deploy:shared', 'deploy:composer');
after('deploy:composer', 'deploy:assets');
after('deploy:assets', 'deploy:silverstripe_build');
before('deploy:symlink', 'deploy:before_symlink');
after('deploy:symlink', 'deploy:after_symlink');
after('deploy:failed', 'deploy:unlock');

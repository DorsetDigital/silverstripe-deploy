<?php
declare(strict_types=1);

// Run with: php tests/configuration.php
require dirname(__DIR__) . '/src/Application.php';

$application = new DorsetDigital\SSDeploy\Application(dirname(__DIR__));
$method = new ReflectionMethod($application, 'targets');

$single = $method->invoke($application, [
    'project' => ['name' => 'Deployment Test'],
    'hosts' => ['website' => ['prod_deploy_path' => '/mnt/nfs/sites/deployment-testing']],
], 'prod');
assert(array_keys($single) === ['website'], 'A display name containing spaces must not become a host alias');
assert($single['website']['deploy_path'] === '/mnt/nfs/sites/deployment-testing');

$multi = $method->invoke($application, [
    'project' => ['name' => 'Reflex Group'],
    'hosts' => ['logixs' => ['deploy_path' => '/mnt/nfs/sites/logixs']],
], 'prod');
assert(array_keys($multi) === ['logixs'], 'Explicit host aliases must remain unchanged');

echo "Configuration smoke tests passed.\n";

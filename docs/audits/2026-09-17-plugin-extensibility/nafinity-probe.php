<?php
declare(strict_types=1);

// Read the real Nafinity autoloader; all hosts/files/SQL used below are disposable.
$project = dirname(__DIR__, 3);
$fixture = sys_get_temp_dir() . '/nafinity-extension-review-' . bin2hex(random_bytes(6));
foreach (['host/app', 'addon/src'] as $directory) {
    mkdir($fixture . '/' . $directory, 0700, true);
}
define('BASE_PATH', $fixture . '/host');
require $project . '/app/vendor/autoload.php';
$result = ['project' => $project, 'probe_scope' => 'isolated framework host with real Nafinity route file; not a complete application HTTP test'];
$result['installed_sources'] = [];
foreach (['naf/framework', 'naf/view', 'naf/form', 'naf/auth'] as $name) {
    $result['installed_sources'][$name] = [
        'version' => Composer\InstalledVersions::getPrettyVersion($name),
        'path' => realpath(Composer\InstalledVersions::getInstallPath($name)),
    ];
}
$result['cms_installed'] = Composer\InstalledVersions::isInstalled('naf/cms');

// Limit this fixture to one fake extension. Do not boot database/queue/LDAP services.
$data = Composer\InstalledVersions::getRawData();
foreach ($data['versions'] as &$package) {
    if (($package['type'] ?? '') === 'naf-plugin') $package['type'] = 'library';
}
unset($package);
$data['versions']['audit/nafinity-extension'] = [
    'pretty_version' => 'dev-main', 'version' => 'dev-main', 'type' => 'naf-plugin',
    'install_path' => $fixture . '/addon', 'aliases' => [], 'dev_requirement' => false,
];
Composer\InstalledVersions::reload($data);
file_put_contents(BASE_PATH . '/app/routes.php', '<?php require ' . var_export($project . '/app/app/routes.php', true) . ';');
file_put_contents($fixture . '/addon/src/routes.php', <<<'CODE'
<?php
Naf\route()->add('GET', '/', static fn() => Naf\json(['extension' => true]), 'home');
Naf\route()->add('GET', '/extension-probe/{name}', [ProbeController::class, 'show'], 'audit.own');
CODE);
class ProbeDependency { public string $label = 'injected'; }
class ProbeController {
    public function __construct(private ProbeDependency $dependency) {}
    public function show(string $name): Psr\Http\Message\ResponseInterface {
        return Naf\json(['name' => $name, 'dependency' => $this->dependency->label, 'handler' => 'original']);
    }
}
$app = Naf\app();
$app->container()->set(ProbeDependency::class, new ProbeDependency());
$app->container()->set(ProbeController::class, new class {
    public function show(string $name): Psr\Http\Message\ResponseInterface { return Naf\json(['handler' => 'bound replacement']); }
});
$result['plugin_booted'] = $app->getPlugin('audit/nafinity-extension')->isBooted();
$result['home_after_application_routes'] = Naf\route()->all()['home']['action'];
$result['controller_binding_get'] = json_decode((string) $app->container()->get(ProbeController::class)->show('test')->getBody(), true);
$response = $app->container()->get(Naf\Core\Dispatcher::class)->forward(new Nyholm\Psr7\ServerRequest('GET', '/extension-probe/alice'));
$result['controller_dispatch'] = ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getBody(), true)];

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE project_role_permissions (project_id INTEGER, role_id INTEGER, permission TEXT)');
$insert = $pdo->prepare('INSERT INTO project_role_permissions VALUES (?,?,?)');
$insert->execute([1, 7, 'comment']);
$insert->execute([1, 7, 'example.reports.view']);
$access = (new ReflectionClass(App\Services\Access::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($access, 'pdo'))->setValue($access, $pdo);
$result['custom_permissions_raw'] = $pdo->query('SELECT permission FROM project_role_permissions')->fetchAll(PDO::FETCH_COLUMN);
$result['custom_permissions_read'] = $access->permissions(1, 'viewer', 7);
$result['services_are_final'] = [];
foreach ([App\Services\TicketService::class, App\Services\BoardQuery::class, App\Services\PreferenceService::class, App\Services\AttachmentService::class] as $class) {
    $result['services_are_final'][$class] = (new ReflectionClass($class))->isFinal();
}
$result['settings_helper_exists'] = function_exists('Nafinity\\settings') || function_exists('App\\settings');
$result['collection'] = (new Naf\Support\Collection(['plugin.key' => 'value']))->all();
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

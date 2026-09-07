<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Boost\Contracts\SupportsGuidelines;
use Laravel\Boost\Contracts\SupportsMcp;
use Laravel\Boost\Contracts\SupportsSkills;
use Laravel\Boost\Install\Agents\Agent;
use Laravel\Boost\Install\AgentsDetector;
use Laravel\Boost\Install\Cloud;
use Laravel\Boost\Install\Enums\McpInstallationStrategy;
use Laravel\Boost\Install\Nightwatch;
use Laravel\Boost\Install\Sail;
use Laravel\Boost\Install\ThirdPartyPackage;
use Laravel\Boost\Support\Config;
use Laravel\Boost\Support\RenderFailures;
use Symfony\Component\Console\Output\BufferedOutput;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$version = InstalledVersions::getPrettyVersion('laravel/boost');
if (ltrim($version ?? '', 'v') !== '2.7.1') {
    throw new RuntimeException('Review this installer against the new Boost source before changing its version gate.');
}

$settings = app(Config::class);
if (is_file(base_path('boost.json')) && ! $settings->isValid()) {
    throw new RuntimeException('Existing boost.json is invalid; refusing to overwrite it.');
}

$agents = app(AgentsDetector::class)->getAgents()->filter(
    fn (Agent $agent): bool => $agent instanceof SupportsGuidelines
        || $agent instanceof SupportsSkills || $agent instanceof SupportsMcp,
)->values();

$resolvePath = static function (string $path): string {
    if (preg_match('/^[a-z]:[\\\\\/]/i', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
        return $path;
    }

    return base_path($path);
};

// Refuse global paths or shell installers before allowing the upstream writers to run.
foreach ($agents as $agent) {
    $paths = [];
    if ($agent instanceof SupportsGuidelines) {
        $paths[] = $agent->guidelinesPath();
    }
    if ($agent instanceof SupportsSkills) {
        $paths[] = $agent->skillsPath();
    }
    if ($agent instanceof SupportsMcp) {
        if ($agent->mcpInstallationStrategy() !== McpInstallationStrategy::FILE) {
            throw new RuntimeException('Non-project MCP installation requires a reviewed adapter: '.$agent->name());
        }
        $paths[] = $agent->mcpConfigPath();
    }
    foreach ($paths as $path) {
        $normalized = is_string($path) ? str_replace('\\', '/', $path) : '';
        $resolved = is_string($path) ? str_replace('\\', '/', $resolvePath($path)) : '';
        $projectRoot = str_replace('\\', '/', base_path());
        $isInsideProject = str_starts_with(strtolower($resolved), strtolower($projectRoot.'/'));
        if ($normalized === '' || in_array('..', explode('/', $normalized), true)
            || (($normalized[0] ?? '') === '/' && ! $isInsideProject)
            || str_starts_with($normalized, '~')
            || (preg_match('/^[a-z]:/i', $normalized) && ! $isInsideProject)) {
            throw new RuntimeException('Unsafe Boost output path for '.$agent->name());
        }
    }
}

$packages = ThirdPartyPackage::discover()->keys()->values()->all();
$sail = app(Sail::class)->isInstalled();
$nightwatch = app(Nightwatch::class)->isInstalled();
$settings->setAgents($agents->map(fn (Agent $agent): string => $agent->name())->all());
$settings->setPackages($packages);
$settings->setGuidelines(true);
$settings->setMcp(true);
$settings->setCloud(true);
$settings->setSail($sail);
$settings->setNightwatch($nightwatch);
config(['boost.enforce_tests' => true]);

$output = new BufferedOutput;
$exitCode = $kernel->call('boost:install', [
    '--guidelines' => true,
    '--skills' => true,
    '--mcp' => true,
    '--no-interaction' => true,
], $output);
$log = $output->fetch();
echo $log;

$failures = [];
if ($exitCode !== 0 || preg_match('/Failed to|could not be rendered|No agents are selected/i', $log)) {
    $failures[] = 'Installer reported an error; inspect the installation log.';
}
if (! app(RenderFailures::class)->isEmpty()) {
    $failures[] = 'Some guidelines or skills could not be rendered.';
}
if (! is_file(base_path('.ai/skills/'.app(Cloud::class)->skillName().'/SKILL.md'))) {
    $failures[] = 'Laravel Cloud skill was selected but its file is missing.';
}

$matrix = [];
foreach ($agents as $agent) {
    $row = ['agent' => $agent->name(), 'guidelines' => null, 'skills' => null, 'mcp' => null];
    if ($agent instanceof SupportsGuidelines) {
        $row['guidelines'] = $agent->guidelinesPath();
        $guidelinesPath = $resolvePath($row['guidelines']);
        if (! is_file($guidelinesPath) || filesize($guidelinesPath) === 0) {
            $failures[] = 'Missing guidelines: '.$agent->name();
        }
    }
    if ($agent instanceof SupportsSkills) {
        $row['skills'] = $agent->skillsPath();
        foreach ($settings->getSkills() as $skill) {
            if (! is_file($resolvePath($row['skills'].'/'.$skill.'/SKILL.md'))) {
                $failures[] = 'Missing skill '.$skill.': '.$agent->name();
            }
        }
    }
    if ($agent instanceof SupportsMcp) {
        $row['mcp'] = $agent->mcpConfigPath();
        $mcpPath = $resolvePath($row['mcp']);
        if (! is_file($mcpPath) || ! str_contains(file_get_contents($mcpPath), 'laravel-boost')) {
            $failures[] = 'Missing MCP server: '.$agent->name();
        }
    }
    $matrix[] = $row;
}

$report = [
    'boost_version' => $version,
    'features' => ['guidelines', 'skills', 'mcp'],
    'packages' => $packages,
    'integrations' => ['cloud' => true, 'nightwatch' => $nightwatch, 'sail' => $sail],
    'skills' => $settings->getSkills(),
    'agents' => $matrix,
    'failures' => $failures,
];
if (! is_dir(base_path('output'))) {
    mkdir(base_path('output'), 0755, true);
}
file_put_contents(base_path('output/boost-install.log'), $log);
file_put_contents(base_path('output/boost-install.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit($failures === [] ? 0 : 1);

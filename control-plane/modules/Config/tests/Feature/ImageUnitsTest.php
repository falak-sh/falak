<?php

use Falak\Config\Application\Exporter;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

/*
| `image` without `build` on a docker unit: the image is deployed and nothing is built.
*/

const CONFIG_IMAGE = 'ghcr.io/acme/api:1';
const CONFIG_IMAGE_NEXT = 'ghcr.io/acme/api:2';
const CONFIG_BUILT_IMAGE = 'registry.falak.local/falak/app@sha256:';

function config_image_file(string $fields = ', image: "'.CONFIG_IMAGE.'"', string $key = 'api'): string
{
    return "version: 1\nunits:\n  {$key}: { type: app, runtime: docker, port: 8080{$fields} }\nenvironments:\n  production: { branch: main, servers: [web-1] }\n";
}

/** A world whose `web` site builds its image from acme/shop (the dashboard made it, or an older Falak from the file). */
function config_built_world(array $site = []): ConfigWorld
{
    return config_world(['runtime' => 'docker', 'framework' => 'docker', 'build_mode' => 'docker', 'php_version' => null, 'laravel' => [],
        'app_port' => 3100, 'container_port' => 8080, 'dockerfile' => 'Dockerfile', ...$site]);
}

function config_builds(ConfigWorld $world, Site $site): int
{
    return count(array_filter($world->deploy->builds->builds, fn (array $build) => $build['request']->siteId === $site->id));
}

/** @return list<string> the images the site's containers were swapped to, in order */
function config_swapped(ConfigWorld $world, Site $site): array
{
    $swaps = array_filter($world->deploy->agents->dispatched('deploy.container.swap'), fn (array $command) => $command['payload']['site'] === $site->slug);

    return array_values(array_map(fn (array $command) => $command['payload']['image'], $swaps));
}

it('creates a docker unit with an image and no build as an image site, and deploys it without a build', function () {
    $world = config_world();
    $world->file(config_image_file());

    $world->push(config_sha('b'));
    $world->settle();
    $api = Site::query()->where('name', 'api')->sole();
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Applied, (string) $run->error)
        ->and($api->only(['source_connection_id', 'repository', 'branch', 'deploy_key_id', 'dockerfile']))
        ->toBe(['source_connection_id' => null, 'repository' => null, 'branch' => null, 'deploy_key_id' => null, 'dockerfile' => null])
        ->and($api->docker_image)->toBe(CONFIG_IMAGE)
        ->and(config_builds($world, $api))->toBe(0)
        ->and(config_swapped($world, $api))->toBe([CONFIG_IMAGE])
        ->and(Release::current($api->id)?->only(['image', 'commit']))->toBe(['image' => CONFIG_IMAGE, 'commit' => config_sha('b')]);
});

it('takes the image from the environment\'s override, which can also drop the build', function () {
    $world = config_world();
    $world->file("version: 1\nunits:\n  api: { type: app, runtime: docker, port: 8080, build: { mode: docker } }\n"
        ."environments:\n  production:\n    branch: main\n    servers: [web-1]\n    units:\n      api: { image: \"".CONFIG_IMAGE."\", build: null }\n");

    $world->push(config_sha('b'));
    $world->settle();
    $api = Site::query()->where('name', 'api')->sole();

    expect($api->repository)->toBeNull()
        ->and(config_swapped($world, $api))->toBe([CONFIG_IMAGE]);
});

it('deploys an image unit once on each push to the config repository, as for any unit', function () {
    $world = config_world();
    $world->file(config_image_file());
    $world->push(config_sha('b'));
    $world->settle();
    $api = Site::query()->where('name', 'api')->sole();

    $world->push(config_sha('c'), paths: ['README.md']);
    $world->settle();

    expect(Deployment::query()->where('site_id', $api->id)->where('commit', config_sha('c'))->sole()->status->value)->toBe('succeeded')
        ->and(config_builds($world, $api))->toBe(0)
        ->and(config_swapped($world, $api))->toBe([CONFIG_IMAGE, CONFIG_IMAGE]);

    // Push to deploy off still means a push doesn't deploy it.
    $api->refresh()->forceFill(['push_to_deploy' => false])->save();
    $world->push(config_sha('d'), paths: ['README.md']);

    expect(Deployment::query()->where('site_id', $api->id)->where('commit', config_sha('d'))->exists())->toBeFalse();
});

it('deploys the new image without a build when a push changes it', function () {
    $world = config_world();
    $world->file(config_image_file());
    $world->push(config_sha('b'));
    $world->settle();
    $api = Site::query()->where('name', 'api')->sole();

    $world->file(config_image_file(', image: "'.CONFIG_IMAGE_NEXT.'"'));
    $world->push(config_sha('c'), paths: ['falak.yaml']);
    $world->settle();

    expect(ConfigRun::query()->where('commit', config_sha('c'))->sole()->status)->toBe(RunStatus::Applied)
        ->and(config_builds($world, $api))->toBe(0)
        ->and(config_swapped($world, $api))->toBe([CONFIG_IMAGE, CONFIG_IMAGE_NEXT])
        ->and(Release::current($api->id)?->image)->toBe(CONFIG_IMAGE_NEXT);
});

it('stops building an existing site when a push sets or changes its image without a build', function (?string $stored) {
    $world = config_built_world(['docker_image' => $stored, 'deploy_key_id' => '01hzykey000000000000000001']);
    $site = $world->deploy->site;
    $world->file(config_image_file(', image: "'.CONFIG_IMAGE_NEXT.'"', 'web'));

    // Push to deploy may get this first push before Config does: its build is cancelled, the image is what goes live.
    $world->push(config_sha('b'));
    $world->settle();

    expect(ConfigRun::query()->sole()->status)->toBe(RunStatus::Applied)
        ->and($site->refresh()->only(['source_connection_id', 'repository', 'branch', 'deploy_key_id']))
        ->toBe(['source_connection_id' => null, 'repository' => null, 'branch' => null, 'deploy_key_id' => null])
        ->and($world->deploy->sourceControl->removedKeys)->toBe(['01hzykey000000000000000001'])
        ->and(config_swapped($world, $site))->toBe([CONFIG_IMAGE_NEXT]);

    // From then on it is an image unit like one the file created.
    $builds = config_builds($world, $site);
    $world->push(config_sha('c'), paths: ['README.md']);
    $world->settle();

    expect(config_builds($world, $site))->toBe($builds)
        ->and(config_swapped($world, $site))->toBe([CONFIG_IMAGE_NEXT, CONFIG_IMAGE_NEXT]);
})->with(['it had no image' => [null], 'it had another image' => [CONFIG_IMAGE]]);

it('stops building a unit the file created once the file gives it an image', function () {
    $world = config_world();
    $world->file(config_image_file(''));
    $world->push(config_sha('b'));
    $world->settle();
    $api = Site::query()->where('name', 'api')->sole();
    expect(config_builds($world, $api))->toBe(1);

    $world->file(config_image_file());
    $world->push(config_sha('c'), paths: ['falak.yaml']);
    $world->settle();

    expect($api->refresh()->repository)->toBeNull()
        ->and(config_builds($world, $api))->toBe(1)
        ->and(config_swapped($world, $api))->toBe([CONFIG_BUILT_IMAGE.str_repeat('a', 64), CONFIG_IMAGE]);

    // Rolling back still returns to the image that was built.
    $built = Release::query()->where('site_id', $api->id)->where('commit', config_sha('b'))->sole();
    app(TriggerDeployment::class)($api->toData(), Trigger::Rollback, releaseId: $built->id);
    $world->settle();

    expect(Release::current($api->id)?->id)->toBe($built->id)
        ->and(config_builds($world, $api))->toBe(1);
});

it('leaves an existing site building while its image stays the same', function (string $fields) {
    // What Falak made of `image` without `build` before: an image, and the repository it builds from.
    $world = config_built_world(['docker_image' => CONFIG_IMAGE, 'dockerfile' => null]);
    $site = $world->deploy->site;
    $world->file(config_image_file(', image: "'.CONFIG_IMAGE.'"'.$fields, 'web'));

    $world->push(config_sha('b'));
    $world->settle();

    expect($site->refresh()->repository)->toBe('acme/shop')
        ->and(config_swapped($world, $site))->toBe([CONFIG_BUILT_IMAGE.str_repeat('a', 64)]);
})->with(['nothing changes' => [''], 'something else changes' => [', health_path: /up']]);

it('keeps building a new docker unit without an image, or with a build', function (string $fields) {
    $world = config_world();
    $world->file(config_image_file($fields));

    $world->push(config_sha('b'));
    $world->settle();
    $api = Site::query()->where('name', 'api')->sole();

    expect($api->only(['repository', 'branch']))->toBe(['repository' => 'acme/shop', 'branch' => 'main'])
        ->and(config_builds($world, $api))->toBe(1)
        ->and(config_swapped($world, $api))->toBe([CONFIG_BUILT_IMAGE.str_repeat('a', 64)]);
})->with([
    'neither' => [''],
    'a build' => [', build: { mode: docker }'],
    'an image and a build mode' => [', image: "'.CONFIG_IMAGE.'", build: { mode: docker }'],
    'an image and a Dockerfile' => [', image: "'.CONFIG_IMAGE.'", build: { dockerfile: Dockerfile }'],
]);

it('keeps building an existing site whose image changes next to a build', function () {
    $world = config_built_world(['docker_image' => CONFIG_IMAGE]);
    $site = $world->deploy->site;
    $world->file(config_image_file(', image: "'.CONFIG_IMAGE_NEXT.'", build: { mode: docker }', 'web'));

    $world->push(config_sha('b'));
    $world->settle();

    expect($site->refresh()->repository)->toBe('acme/shop')
        ->and($site->docker_image)->toBe(CONFIG_IMAGE_NEXT)
        ->and(config_swapped($world, $site))->toBe([CONFIG_BUILT_IMAGE.str_repeat('a', 64)]);
});

it('warns that a build added to an image unit builds nothing', function () {
    $world = config_world();
    $world->file(config_image_file());
    $world->push(config_sha('b'));
    $world->settle();
    $api = Site::query()->where('name', 'api')->sole();

    $world->file(config_image_file(', build: { mode: docker }'));
    $world->push(config_sha('c'), paths: ['falak.yaml']);
    $world->settle();
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect($run->status)->toBe(RunStatus::Applied)
        ->and($run->errors)->toHaveCount(1)
        ->and($run->errors[0])->toMatchArray(['code' => 'value.unsupported', 'severity' => 'warning', 'pointer' => '/units/api/build'])
        ->and(config_builds($world, $api))->toBe(0);
});

it('exports an image unit without a build, so a file made from it creates an image unit again', function () {
    $world = config_world();
    $world->file(config_image_file());
    $world->push(config_sha('b'));
    $world->settle();

    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $document = app(Exporter::class)->document($environment, $world->source);
    $yaml = app(Exporter::class)->render($document, 'yaml');
    [$validation, $plan] = app(PlanFile::class)($world->source, $environment, $yaml, 'yaml');

    expect($document['units']['api'])->not->toHaveKey('build')
        ->and($document['units']['api']['image'])->toBe(CONFIG_IMAGE)
        ->and($document['units']['web'])->toHaveKey('build') // it builds from the repository
        ->and($validation->errors)->toBe([])
        ->and($plan?->changes->actionable())->toBe([]);
});

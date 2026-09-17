<?php

declare(strict_types=1);

use App\Domain\Estimation;

$auth->setIdentity($users['alice']);
$estimationProject = $projects->create([
    'name'             => 'Estimation',
    'description'      => 'Scale regression',
    'estimation_scale' => 'points',
]);
$estimationBoard = $query->board($estimationProject);
$estimationAdd   = fn(?int $points) => $tickets->create($estimationProject, [
    'title'           => 'Estimated',
    'description'     => 'Carries a number',
    'priority'        => 'normal',
    'column_id'       => $estimationBoard['columns'][0]['id'],
    'swimlane_id'     => $estimationBoard['swimlanes'][0]['id'],
    'estimate_points' => $points,
    'board_revision'  => $tickets->board($estimationProject)['revision'],
]);
$estimationSettings = fn(string $scale, bool $remap = false) => $projects->update($estimationProject, [
    'name'             => 'Estimation',
    'description'      => 'Scale regression',
    'ticket_key'       => 'EST',
    'estimation_scale' => $scale,
    'color'            => '#6366f1',
    'icon'             => 'E',
    'remap_estimates'  => $remap ? '1' : '',
]);
$estimationPoints  = fn(int $id) => $tickets->ticket($estimationProject, $id)['estimate_points'];
$estimationVersion = fn(int $id) => [
    'version'        => $tickets->ticket($estimationProject, $id)['version'],
    'board_revision' => $tickets->board($estimationProject)['revision'],
];

test('a project carries one estimation scale and rejects anything else', function () use (
    $projects,
    $query,
    $estimationProject,
    $estimationSettings,
) {
    check($query->board($estimationProject)['project']['estimation_scale'] === 'points', 'scale not stored');
    $estimationSettings('complexity');
    check($query->board($estimationProject)['project']['estimation_scale'] === 'complexity', 'scale not changed');
    // An unknown scale is not an error the user can trigger; it falls back to no estimation.
    $estimationSettings('velocity');
    check($query->board($estimationProject)['project']['estimation_scale'] === 'none', 'unknown scale accepted');
    $estimationSettings('points');
});

test('estimates persist through partial writes and refuse impossible numbers', function () use (
    $tickets,
    $estimationProject,
    $estimationAdd,
    $estimationPoints,
    $estimationVersion,
) {
    $id = $estimationAdd(8);
    check((int) $estimationPoints($id) === 8, 'estimate not stored');
    // The inline field saves on its own, so every other write has to leave it alone.
    $tickets->update($estimationProject, $id, ['title' => 'Renamed'] + $estimationVersion($id));
    check((int) $estimationPoints($id) === 8, 'estimate lost by an unrelated write');
    $tickets->update($estimationProject, $id, ['estimate_points' => ''] + $estimationVersion($id));
    check($estimationPoints($id) === null, 'estimate not clearable');
    denied(422, fn() => $estimationAdd(Estimation::MAX + 1));
    denied(422, fn() => $estimationAdd(-1));
});

test('a scale change keeps every estimate and reports the ones it cannot offer', function () use (
    $projects,
    $estimationProject,
    $estimationAdd,
    $estimationSettings,
    $estimationPoints,
) {
    $fits = $estimationAdd(3);
    $over = $estimationAdd(13);
    $estimationSettings('complexity');
    check((int) $estimationPoints($over) === 13, 'estimate dropped by the scale change');
    check(Estimation::offScale('complexity', 13), '13 counted as part of 1-5');
    check(!Estimation::offScale('complexity', 3), '3 counted as outside 1-5');
    check($projects->offScaleEstimates($estimationProject, 'complexity') === 1, 'off-scale count wrong');
    check($projects->offScaleEstimates($estimationProject, 'none') === 0, 'counted without a scale');
    // Only the explicit remap moves a number, and only the ones that no longer fit.
    $estimationSettings('complexity', true);
    check((int) $estimationPoints($over) === 5, 'off-scale estimate not remapped');
    check((int) $estimationPoints($fits) === 3, 'fitting estimate changed by the remap');
    check($projects->offScaleEstimates($estimationProject, 'complexity') === 0, 'remap left values behind');
});

test('the nearest value of a scale is unambiguous', function () {
    check(Estimation::nearest('points', 4) === 3, 'tie did not fall to the lower value');
    check(Estimation::nearest('points', 100) === 21, 'large value not capped to the scale');
    check(Estimation::nearest('complexity', 13) === 5, 'complexity remap wrong');
    check(Estimation::nearest('none', 13) === 13, 'value changed without a scale');
    check(Estimation::values('points') === [1, 2, 3, 5, 8, 13, 21], 'point scale changed');
    check(Estimation::scale(null) === 'none' && !Estimation::active('none'), 'empty scale not inactive');
});

<?php

use Licorice19\Backup\Services\BackupService;

beforeEach(function () {
    // Setup before each test
});

it('can instantiate backup service', function () {
    $service = app(BackupService::class);
    expect($service)->toBeInstanceOf(BackupService::class);
});
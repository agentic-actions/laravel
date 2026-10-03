<?php

use Tests\TestCase;
use Tests\Workbench\WorkbenchTestCase;

pest()->extend(TestCase::class)->in('Unit', 'Feature');
pest()->extend(WorkbenchTestCase::class)->in('Workbench');

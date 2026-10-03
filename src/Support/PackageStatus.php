<?php

namespace AgenticActions\Support;

/**
 * How a Composer package is installed in the app.
 *
 * @internal
 */
enum PackageStatus: string
{
    case Missing = 'missing';
    case DevOnly = 'dev-only';
    case Installed = 'installed';
}

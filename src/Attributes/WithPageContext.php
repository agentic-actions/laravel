<?php

namespace AgenticActions\Attributes;

use Attribute;

/**
 * On an agent that uses InteractsWithActions and implements HasMiddleware: each step's last user message carries the
 * page the person has open (its route name and page component), for Inertia apps.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class WithPageContext {}

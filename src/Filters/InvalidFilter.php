<?php

namespace MrPunyapal\TelescopeInspect\Filters;

use InvalidArgumentException;

/**
 * Thrown when command-line filters cannot be understood.
 *
 * The command layer turns this into a friendly error message with exit code 2,
 * signalling invalid usage to scripts and CI systems.
 */
final class InvalidFilter extends InvalidArgumentException {}

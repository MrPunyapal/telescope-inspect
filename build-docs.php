<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Docsmith\Docsmith;

$builder = Docsmith::make()
    ->source(__DIR__.'/md')
    ->output(__DIR__.'/docs')
    ->title('Telescope Inspect')
    ->description('Laravel Telescope inspection from the command line — human-friendly output with first-class JSON for scripts, CI, AI agents, and tooling.')
    ->repositoryUrl('https://github.com/MrPunyapal/telescope-inspect')
    ->siteUrl(getenv('DOCS_SITE_URL') ?: 'https://mrpunyapal.github.io/telescope-inspect')
    ->editBranch(getenv('DOCS_EDIT_BRANCH') ?: 'main')
    ->editPrefix('md')
    ->baseUrl(getenv('DOCS_BASE_URL') ?: '/telescope-inspect/')
    ->accentColor('#ff2d20');

$builder->build();

echo "[Docsmith] Documentation built to docs/\n";

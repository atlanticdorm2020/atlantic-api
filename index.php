<?php

/**
 * Hostinger document root is this directory (public_html), not /public.
 * Route every request through Laravel's real front controller so OPTIONS
 * preflight to /api/* reaches HandleCors instead of a Hostinger 404 page.
 */
require __DIR__.'/public/index.php';

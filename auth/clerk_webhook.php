<?php
/**
 * Clerk webhook support has been removed.
 *
 * This deployment uses Clerk only for client-side session sign-in. The server
 * no longer exposes a webhook endpoint, and the webhook secret is not needed.
 */

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
echo "Clerk webhook support has been disabled for this deployment.";
exit;

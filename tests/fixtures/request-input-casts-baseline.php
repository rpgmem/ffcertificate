<?php
/**
 * Request-input cast baseline (#1087 passo 2) — generated.
 * Regenerate: FFC_UPDATE_REQUEST_CAST_BASELINE=1 vendor/bin/phpunit --filter RequestInputCast
 *
 * Each entry is a direct integer cast over a superglobal that exists today
 * and is therefore tolerated. The guard fails on any cast NOT listed here
 * (a new one — route it through RequestInput::get_post_int()/get_get_int())
 * and on any listed cast that no longer exists (one was fixed — lock it in).
 *
 * This is a debt register, not a target to grow.
 */

return array(
);

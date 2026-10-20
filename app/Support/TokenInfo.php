<?php

namespace App\Support;

/**
 * What kind of token a saved value is, without ever revealing it.
 *
 * Shows only GitHub's own public prefix and the length, which is enough to
 * catch the commonest mistake: pasting the wrong thing (a webhook secret, an
 * expired token, a token from another account).
 */
final class TokenInfo
{
    /** @return string|null e.g. "fine-grained token (github_pat_…, 93 characters)" */
    public static function describe(?string $token): ?string
    {
        if (blank($token)) {
            return null;
        }

        $length = strlen($token);

        $kind = match (true) {
            str_starts_with($token, 'github_pat_') => ['fine-grained personal token', 'github_pat_'],
            str_starts_with($token, 'ghp_') => ['classic personal token', 'ghp_'],
            str_starts_with($token, 'gho_') => ['OAuth token', 'gho_'],
            str_starts_with($token, 'ghs_') => ['app installation token', 'ghs_'],
            str_starts_with($token, 'ghu_') => ['app user token', 'ghu_'],
            default => null,
        };

        return $kind === null
            ? "not a GitHub token format ({$length} characters): GitHub tokens start with github_pat_ or ghp_"
            : "{$kind[0]} ({$kind[1]}…, {$length} characters)";
    }
}

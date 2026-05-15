<?php

namespace App\Services;

class GitHubSignatureService
{
    public function verify(string $secret, string $rawBody, ?string $signatureHeader): bool
    {
        if (! $signatureHeader || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}

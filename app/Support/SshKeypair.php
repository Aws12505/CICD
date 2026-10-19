<?php

namespace App\Support;

use phpseclib3\Crypt\EC;

/**
 * A fresh ed25519 keypair in OpenSSH format (decision 17).
 *
 * The private half is created here and goes straight into an encrypted
 * column; nobody copies it, emails it or keeps it on a laptop. Only the public
 * half is ever shown, as the line someone pastes into the VPS console.
 */
final class SshKeypair
{
    private function __construct(
        public readonly string $privateKey,
        public readonly string $publicKey,
    ) {}

    public static function generate(string $comment): self
    {
        $key = EC::createKey('Ed25519');

        return new self(
            privateKey: $key->toString('OpenSSH', ['comment' => $comment]),
            publicKey: trim($key->getPublicKey()->toString('OpenSSH', ['comment' => $comment])),
        );
    }
}

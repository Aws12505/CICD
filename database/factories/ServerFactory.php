<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
{
    protected $model = Server::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'hostname' => $this->faker->domainName(),
            'ip_address' => $this->faker->ipv4(),
            'ssh_user' => 'deploy',
            'ssh_port' => 22,
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nnot-a-real-key\n-----END OPENSSH PRIVATE KEY-----",
            'host_key' => null,
            'status' => 'pending',
        ];
    }

    /** A server whose host key has been captured and which answered. */
    public function verified(): static
    {
        return $this->state(fn () => [
            'host_key' => 'example.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleKeyDataHere00000000000000',
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }
}

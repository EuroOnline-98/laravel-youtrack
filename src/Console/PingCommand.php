<?php

declare(strict_types=1);

namespace E98\YouTrack\Console;

use E98\YouTrack\Exceptions\YouTrackException;
use E98\YouTrack\YouTrackClient;
use Illuminate\Console\Command;

class PingCommand extends Command
{
    protected $signature = 'youtrack:ping';

    protected $description = 'Check the YouTrack URL and token by asking who the token belongs to';

    public function handle(YouTrackClient $client): int
    {
        try {
            $url = $client->baseUrl();
            $me = $client->ping();
        } catch (YouTrackException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Connected to %s as %s (%s).',
            $url,
            $me['fullName'] ?? '?',
            $me['login'] ?? '?',
        ));

        return self::SUCCESS;
    }
}

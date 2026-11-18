<?php

declare(strict_types=1);

namespace E98\YouTrack\Facades;

use E98\YouTrack\Resources\Issues;
use E98\YouTrack\Resources\Projects;
use E98\YouTrack\YouTrackClient;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Issues issues()
 * @method static Projects projects()
 * @method static array ping()
 * @method static string baseUrl()
 *
 * @see YouTrackClient
 */
class YouTrack extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return YouTrackClient::class;
    }
}

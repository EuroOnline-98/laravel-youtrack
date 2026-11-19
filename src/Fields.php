<?php

declare(strict_types=1);

namespace E98\YouTrack;

/**
 * YouTrack returns only the attributes named in the `fields` query parameter,
 * so every call states exactly what the DTOs need.
 */
final class Fields
{
    public const USER = 'id,login,fullName';

    public const PROJECT = 'id,shortName,name,archived';

    public const ISSUE = 'id,idReadable,summary,description,created,updated,resolved,'
        . 'project(id,shortName,name),'
        . 'reporter(id,login,fullName),'
        . 'customFields($type,id,name,value($type,id,name,login,fullName,text,presentation,minutes))';

    public const COMMENT = 'id,text,created,updated,author(id,login,fullName)';

    public const TAG = 'id,name,untagOnResolve,owner(id,login,fullName)';

    public const WATCHERS = 'watchers(hasStar,issueWatchers(id,isStarred,user(id,login,fullName)))';

    public const ATTACHMENT = 'id,name,size,mimeType,url,created,author(id,login,fullName)';
}

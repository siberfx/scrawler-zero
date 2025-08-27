<?php

namespace App\Enums;

enum AnalyticsStatType: string
{
    case Browser = 'browser';
    case Campaign = 'campaign';

    case Duration = 'duration';
    case City = 'city';
    case Continent = 'continent';
    case Country = 'country';
    case Device = 'device';
    case Event = 'event';
    case Language = 'language';
    case OperatingSystem = 'operating_system';
    case Page = 'page';
    case Pageview = 'pageview';
    case Referrer = 'referrer';
    case ScreenResolution = 'screen_resolution';
    case Visitor = 'visitor';
    case UserAgent = 'user_agent';
}

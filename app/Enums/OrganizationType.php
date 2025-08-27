<?php

namespace App\Enums;

enum OrganizationType: string
{
    case MUNICIPALITY = 'gemeenten';
    case PROVINCE = 'provincies';
    case WATER_BOARD = 'waterschappen';
    case REGIONAL_ASSOCIATION = 'regionale_samenwerkingsorganen';
    case CROSS_BORDER_REGIONAL_ASSOCIATION = 'grensoverschrijdende_regionale_samenwerkingsorganen';
    case PUBLIC_BODY = 'openbare_lichamen_voor_beroep_en_bedrijf';
    case HIGH_COUNCIL_OF_STATE = 'hoge_colleges_van_stat';
    case MINISTRY = 'ministeries';
    case AGENCY = 'agentschappen';
    case INSPECTION = 'inspecties';
    case ADVISORY_BOARD = 'adviescolleges';
    case INDEPENDENT_ADMINISTRATIVE_BODY = 'zelfstandige_bestuursorganen';
    case POLICE = 'politie';
    case JUDICIAL = 'rechtspraak';

    /**
     * Get the display name for the organization type
     */
    public function label(): string
    {

        return match ($this) {
            self::MUNICIPALITY => 'Gemeenten',
            self::PROVINCE => 'Provincies',
            self::WATER_BOARD => 'Waterschappen',
            self::REGIONAL_ASSOCIATION => 'Regionale samenwerkingsorganen',
            self::CROSS_BORDER_REGIONAL_ASSOCIATION => 'Grensoverschrijdende regionale samenwerkingsorganen',
            self::PUBLIC_BODY => 'Openbare lichamen voor beroep en bedrijf',
            self::HIGH_COUNCIL_OF_STATE => 'Hoge Colleges van Staat',
            self::MINISTRY => 'Ministeries',
            self::AGENCY => 'Agentschappen',
            self::INSPECTION => 'Inspecties',
            self::ADVISORY_BOARD => 'Adviescolleges',
            self::INDEPENDENT_ADMINISTRATIVE_BODY => 'Zelfstandige bestuursorganen',
            self::POLICE => 'Politie',
            self::JUDICIAL => 'Rechtspraak',
        };
    }

    /**
     * Get all organization types as an array for select inputs
     */
    public static function toArray(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn ($case) => $case->label(), self::cases())
        );
    }

    /**
     * Get the URL segment for the organization type on organisaties.overheid.nl
     */
    public function urlSegment(): string
    {
        return match ($this) {
            self::MUNICIPALITY => 'Gemeenten',
            self::PROVINCE => 'Provincies',
            self::WATER_BOARD => 'Waterschappen',
            self::REGIONAL_ASSOCIATION => 'Regionale_samenwerkingsorganen',
            self::CROSS_BORDER_REGIONAL_ASSOCIATION => 'Grensoverschrijdende_regionale_samenwerkingsorganen',
            self::PUBLIC_BODY => 'Openbare_lichamen_voor_beroep_en_bedrijf',
            self::HIGH_COUNCIL_OF_STATE => 'Hoge_Colleges_van_Staat',
            self::MINISTRY => 'Ministeries',
            self::AGENCY => 'Agentschappen',
            self::INSPECTION => 'Inspecties',
            self::ADVISORY_BOARD => 'Adviescolleges',
            self::INDEPENDENT_ADMINISTRATIVE_BODY => 'Zelfstandige_bestuursorganen',
            self::POLICE => 'Politie',
            self::JUDICIAL => 'Rechtspraak',
        };
    }

    /**
     * Get the full URL for the organization type on organisaties.overheid.nl
     */
    public function fullUrl(): string
    {
        $baseUrl = 'https://organisaties.overheid.nl/woo/';

        return $baseUrl.$this->urlSegment().'/';
    }

    /**
     * Get all organization types with their full URLs as an array
     */
    public static function getUrlsArray(): array
    {
        $urls = array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn ($case) => $case->fullUrl(), self::cases())
        );

        return $urls;
    }
}

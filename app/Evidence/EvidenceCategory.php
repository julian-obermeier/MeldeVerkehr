<?php

declare(strict_types=1);

namespace MeldeVerkehr\Evidence;

final class EvidenceCategory
{
    public const OVERVIEW = 'OVERVIEW';
    public const PLATE = 'PLATE';
    public const VEHICLE = 'VEHICLE';
    public const VEHICLE_POSITION = 'VEHICLE_POSITION';
    public const TRAFFIC_SIGN = 'TRAFFIC_SIGN';
    public const ADDITIONAL_SIGN = 'ADDITIONAL_SIGN';
    public const OBSTRUCTION = 'OBSTRUCTION';
    public const DANGER = 'DANGER';
    public const PROPERTY_DAMAGE = 'PROPERTY_DAMAGE';
    public const CONTEXT = 'CONTEXT';
    public const PERMIT = 'PERMIT';
    public const TEMPORARY_SIGN = 'TEMPORARY_SIGN';
    public const OTHER = 'OTHER';

    public static function all(): array
    {
        return [
            self::OVERVIEW,
            self::PLATE,
            self::VEHICLE,
            self::VEHICLE_POSITION,
            self::TRAFFIC_SIGN,
            self::ADDITIONAL_SIGN,
            self::OBSTRUCTION,
            self::DANGER,
            self::PROPERTY_DAMAGE,
            self::CONTEXT,
            self::PERMIT,
            self::TEMPORARY_SIGN,
            self::OTHER,
        ];
    }
}

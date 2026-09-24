<?php

declare(strict_types=1);

namespace MeldeVerkehr\Witness;

final class NeutralNarrativeBuilder
{
    public const VERSION = 'deterministic-de-v1';

    public function __construct(private readonly string $timezone = 'Europe/Berlin')
    {
    }

    public function build(array $caseData, array $observation): string
    {
        $case = $caseData['case'] ?? [];
        $vehicle = $caseData['vehicle'] ?? [];
        $location = $caseData['location'] ?? [];
        $offense = $caseData['offenses'][0] ?? null;

        $parts = [];

        $time = $this->timeDescription(
            $case['observed_from'] ?? null,
            $case['observed_until'] ?? null
        );
        $place = $this->locationDescription($location);

        $vehicleDescription = trim(implode(' ', array_filter([
            (string) ($vehicle['vehicle_type'] ?? ''),
            (string) ($vehicle['make'] ?? ''),
            (string) ($vehicle['model'] ?? ''),
        ])));
        if ($vehicleDescription === '') {
            $vehicleDescription = 'Fahrzeug';
        }

        $plate = trim((string) ($vehicle['license_plate'] ?? ''));
        $vehicleText = $plate !== ''
            ? sprintf('%s mit dem Kennzeichen %s', $vehicleDescription, $plate)
            : $vehicleDescription;

        $parts[] = trim(sprintf(
            '%s beobachtete ich %s%s.',
            $time,
            $vehicleText,
            $place !== '' ? ' ' . $place : ''
        ));

        $trafficSpace = trim((string) ($location['traffic_space_type'] ?? ''));
        if ($trafficSpace !== '' && $trafficSpace !== 'UNKNOWN') {
            $parts[] = 'Der von mir bestätigte Verkehrsraum ist als ' . $this->trafficSpaceLabel($trafficSpace) . ' erfasst.';
        }

        $facts = [];
        if ((int) ($case['obstruction'] ?? 0) === 1) {
            $facts[] = 'eine Behinderung';
        }
        if ((int) ($case['endangerment'] ?? 0) === 1) {
            $facts[] = 'eine Gefährdung';
        }
        if ((int) ($case['damage'] ?? 0) === 1) {
            $facts[] = 'ein Sachschaden';
        }

        if ($facts !== []) {
            $parts[] = 'Als eigene Beobachtung wurde zusätzlich ' . $this->joinGerman($facts) . ' angegeben.';
        }

        if (is_array($offense) && trim((string) ($offense['title'] ?? '')) !== '') {
            $parts[] = sprintf(
                'Im Vorgang habe ich den Tatbestand „%s“ ausgewählt; die abschließende Würdigung erfolgt durch die zuständige Stelle.',
                trim((string) $offense['title'])
            );
        }

        $ownObservation = trim((string) ($observation['observation_text'] ?? ''));
        if ($ownObservation !== '') {
            $parts[] = 'Meine ergänzende eigene Beobachtung: ' . $ownObservation;
        }

        $impact = trim((string) ($observation['impact_text'] ?? ''));
        if ($impact !== '') {
            $parts[] = 'Von mir beobachtete Auswirkung: ' . $impact;
        }

        $context = trim((string) ($observation['context_text'] ?? ''));
        if ($context !== '') {
            $parts[] = 'Ergänzender Kontext: ' . $context;
        }

        return implode("\n\n", $parts);
    }

    private function timeDescription(mixed $from, mixed $until): string
    {
        if (!is_string($from) || $from === '') {
            return 'Zum dokumentierten Zeitpunkt';
        }

        $timezone = new \DateTimeZone($this->timezone);
        $start = (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))->setTimezone($timezone);
        $text = 'Am ' . $start->format('d.m.Y') . ' um ' . $start->format('H:i') . ' Uhr';

        if (is_string($until) && $until !== '') {
            $end = (new \DateTimeImmutable($until, new \DateTimeZone('UTC')))->setTimezone($timezone);
            $text .= ' bis ' . $end->format('H:i') . ' Uhr';
        }

        return $text;
    }

    private function locationDescription(array $location): string
    {
        $street = trim((string) ($location['street'] ?? ''));
        $house = trim((string) ($location['house_number'] ?? ''));
        $postal = trim((string) ($location['postal_code'] ?? ''));
        $city = trim((string) ($location['city'] ?? ''));

        $address = trim($street . ($house !== '' ? ' ' . $house : ''));
        $cityLine = trim($postal . ($city !== '' ? ' ' . $city : ''));

        if ($address !== '' && $cityLine !== '') {
            return 'an der Örtlichkeit ' . $address . ', ' . $cityLine;
        }

        if ($address !== '') {
            return 'an der Örtlichkeit ' . $address;
        }

        if ($cityLine !== '') {
            return 'in ' . $cityLine;
        }

        return '';
    }

    private function trafficSpaceLabel(string $value): string
    {
        return match ($value) {
            'ROADWAY' => 'Fahrbahn',
            'SIDEWALK' => 'Gehweg',
            'BIKE_LANE' => 'Radfahrstreifen',
            'BIKE_PATH' => 'Radweg',
            'SHOULDER' => 'Seitenstreifen',
            'PARKING_AREA' => 'Parkfläche',
            'PEDESTRIAN_ZONE' => 'Fußgängerzone',
            'PRIVATE_PROPERTY' => 'Privatfläche',
            default => $value,
        };
    }

    private function joinGerman(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' und ' . $last;
    }
}

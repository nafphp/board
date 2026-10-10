<?php

declare(strict_types=1);

namespace Naf\Board\Export;

/** Aggregate integer minutes before conversion; keep changed title snapshots separate. */
final class InvoiceItems
{
    public const string SOURCE = 'invoice-items';

    /** @param iterable<array{record: array, data: array}> $bookings */
    public function rows(iterable $bookings, InvoiceExportOptions $options): iterable
    {
        $items = [];
        foreach ($bookings as $booking) {
            $record = $booking['record'];
            $data   = $booking['data'];
            $key    = json_encode([(int) $record['ticket_id'], $record['user_id'] ?? null, $data['key'], $data['title']], JSON_THROW_ON_ERROR);
            if (!isset($items[$key])) {
                $items[$key] = ['record' => ['ticket_id' => (int) $record['ticket_id'], 'booking_ids' => []], 'data' => [
                    'project'     => $data['project'], 'ticket' => $data['key'],
                    'name'        => $data['key'] . ' ' . $data['title'], 'person' => $data['user'],
                    'booked_from' => $data['recorded_at'], 'booked_until' => $data['recorded_at'], 'minutes' => 0,
                ]];
            }
            $item                            = &$items[$key];
            $item['record']['booking_ids'][] = (int) $record['id'];
            $item['data']['minutes'] += (int) $data['minutes'];
            $item['data']['booked_from']  = min($item['data']['booked_from'], $data['recorded_at']);
            $item['data']['booked_until'] = max($item['data']['booked_until'], $data['recorded_at']);
            unset($item);
        }
        foreach ($items as $item) {
            $data                  = &$item['data'];
            $data['quantity']      = round($data['minutes'] / 60, 6);
            $data['unit']          = 'HUR';
            $data['net_rate']      = $options->rateCents / 100;
            $data['rate_cents']    = $options->rateCents;
            $data['tax_rate']      = $options->taxBasisPoints / 100;
            $data['currency']      = 'EUR';
            $data['sevdesk_unity'] = $options->sevdeskUnity;
            $data['description']   = $data['project'] . ' / ' . $data['person'] . ' / '
                . $data['minutes'] . ' min (' . $data['quantity'] . ' h) / UTC: ' . $data['booked_from'] . ' – ' . $data['booked_until'];
            unset($data);
            yield $item;
        }
    }
}

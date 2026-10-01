<?php
require_once __DIR__ . '/app.php';

/**
 * ============================================================================
 * Advanced schedule prevention (central validator)
 * ============================================================================
 * Every event create / reschedule / availability check funnels through
 * sportsync_validate_schedule() so the same business rules apply everywhere.
 *
 * Tunable via .env (all optional, defaults shown):
 *   SCHEDULE_BUFFER_MINUTES=30     dead time required between bookings at a venue
 *   SCHEDULE_OPEN_HOUR=6           earliest local hour a booking may start
 *   SCHEDULE_CLOSE_HOUR=22         latest local hour a booking may end
 *   SCHEDULE_MIN_MINUTES=30        shortest allowed booking
 *   SCHEDULE_MAX_HOURS=12          longest allowed booking
 *   SCHEDULE_ADVANCE_DAYS=1        how far in the future bookings must be made
 *   SCHEDULE_MAX_ADVANCE_DAYS=180  how far ahead bookings may be made
 *   SCHEDULE_MAX_PER_DAY=1         events one user may create/reserve per day
 */

function sportsync_schedule_rules(): array {
    return [
        'buffer_minutes'     => max(0, (int)sportsync_env('SCHEDULE_BUFFER_MINUTES', '30')),
        'open_hour'          => max(0, min(23, (int)sportsync_env('SCHEDULE_OPEN_HOUR', '6'))),
        'close_hour'         => max(1, min(24, (int)sportsync_env('SCHEDULE_CLOSE_HOUR', '22'))),
        'min_minutes'        => max(5, (int)sportsync_env('SCHEDULE_MIN_MINUTES', '30')),
        'max_hours'          => max(1, (int)sportsync_env('SCHEDULE_MAX_HOURS', '12')),
        'advance_days'       => max(0, (int)sportsync_env('SCHEDULE_ADVANCE_DAYS', '1')),
        'max_advance_days'   => max(1, (int)sportsync_env('SCHEDULE_MAX_ADVANCE_DAYS', '180')),
        'max_per_day'        => max(1, (int)sportsync_env('SCHEDULE_MAX_PER_DAY', '1')),
    ];
}

/**
 * Validate a proposed schedule and, when $pdo is given, run the conflict
 * queries inside the caller's transaction.
 *
 * $args = [
 *   'start'        => 'Y-m-d H:i:s' (local, required),
 *   'end'          => 'Y-m-d H:i:s' (local, required),
 *   'venue_id'     => int (required),
 *   'people'       => int expected participants (required),
 *   'ignore_event' => int event id to exclude (reschedule),
 *   'organizer_id' => int user id for organizer double-booking check,
 *   'check_day_cap'=> bool apply per-user per-day creation cap,
 * ]
 * Returns ['ok'=>true] or ['ok'=>false,'error'=>string,'detail'=>?array].
 */
function sportsync_validate_schedule(PDO $pdo = null, array $args): array {
    $rules = sportsync_schedule_rules();
    $startTs = strtotime((string)($args['start'] ?? ''));
    $endTs = strtotime((string)($args['end'] ?? ''));
    $venueId = (int)($args['venue_id'] ?? 0);
    $people = max(1, (int)($args['people'] ?? 1));
    $ignore = (int)($args['ignore_event'] ?? 0);

    // -- 1. Basic integrity ----------------------------------------------------
    if ($startTs === false || $endTs === false) {
        return ['ok' => false, 'error' => 'Choose a valid start and end time.'];
    }
    if ($endTs <= $startTs) {
        return ['ok' => false, 'error' => 'The end time must be after the start time.'];
    }
    $durationMin = (int)(($endTs - $startTs) / 60);
    if ($durationMin < $rules['min_minutes']) {
        return ['ok' => false, 'error' => 'Bookings must run at least ' . $rules['min_minutes'] . ' minutes. Your slot is only ' . $durationMin . ' minute(s).'];
    }
    if ($durationMin > $rules['max_hours'] * 60) {
        return ['ok' => false, 'error' => 'Bookings are limited to ' . $rules['max_hours'] . ' hours per day. Your slot is ' . floor($durationMin / 60) . ' hours ' . ($durationMin % 60) . ' min.'];
    }

    // -- 2. Opening hours (local time) ------------------------------------------
    $startHour = (int)date('G', $startTs);
    $endHour = (int)date('G', $endTs);
    $endsMidnight = ((int)date('Hi', $endTs) === 0 || (int)date('Hi', $endTs) < (int)date('Hi', $startTs));
    $effectiveEndHour = $endsMidnight ? 24 : $endHour;
    if ($startHour < $rules['open_hour'] || ($endTs !== $startTs && $effectiveEndHour > $rules['close_hour'] && !$endsMidnight) || ($endsMidnight && $rules['close_hour'] < 24)) {
        return ['ok' => false, 'error' => 'Venue operating hours are ' . sprintf('%02d:00', $rules['open_hour']) . '–' . sprintf('%02d:00', $rules['close_hour']) . '. Your slot (' . date('g:i A', $startTs) . ' – ' . date('g:i A', $endTs) . ') falls outside them.'];
    }

    // -- 3. Advance booking window ----------------------------------------------
    $earliest = time() + $rules['advance_days'] * 86400;
    $latest = time() + $rules['max_advance_days'] * 86400;
    if ($startTs < $earliest) {
        return ['ok' => false, 'error' => 'Bookings must be made at least ' . $rules['advance_days'] . ' day(s) in advance. Earliest available start: ' . date('M d, Y g:i A', $earliest) . '.'];
    }
    if ($startTs > $latest) {
        return ['ok' => false, 'error' => 'Bookings can only be made up to ' . $rules['max_advance_days'] . ' days ahead.'];
    }

    if (!$pdo || !$venueId) {
        // Rules-only mode (availability API): everything above applies.
        if ($startTs < time() - 300) return ['ok' => false, 'error' => 'Event start time cannot be in the past.'];
        return ['ok' => true];
    }

    // -- 4. Venue existence, status, capacity ------------------------------------
    $q = $pdo->prepare('SELECT id,name,status,capacity FROM venues WHERE id=? FOR UPDATE');
    $q->execute([$venueId]);
    $venue = $q->fetch(PDO::FETCH_ASSOC);
    if (!$venue) return ['ok' => false, 'error' => 'Selected venue does not exist.'];
    if ($venue['status'] === 'maintenance') {
        return ['ok' => false, 'error' => $venue['name'] . ' is closed for maintenance and cannot be booked.'];
    }
    if ($venue['status'] !== 'available') {
        return ['ok' => false, 'error' => $venue['name'] . ' is not available for booking.'];
    }
    if ((int)$venue['capacity'] > 0 && $people > (int)$venue['capacity']) {
        return ['ok' => false, 'error' => $venue['name'] . ' holds ' . (int)$venue['capacity'] . ' people; you expect ' . $people . '. Choose a bigger venue or reduce participants.'];
    }

    // -- 5. Venue overlap including buffer ----------------------------------------
    $buf = $rules['buffer_minutes'] * 60;
    $q = $pdo->prepare('SELECT id,title,start_at,end_at FROM events WHERE venue_id=? AND status IN("scheduled","ongoing") AND id<>? AND start_at < ? AND end_at > ?');
    $q->execute([$venueId, $ignore, date('Y-m-d H:i:s', $endTs + $buf), date('Y-m-d H:i:s', $startTs - $buf)]);
    $clash = $q->fetch(PDO::FETCH_ASSOC);
    if ($clash) {
        return ['ok' => false,
            'error' => $venue['name'] . ' is already booked for "' . $clash['title'] . '" (' . date('M d, g:i A', strtotime($clash['start_at'])) . ' – ' . date('M d, g:i A', strtotime($clash['end_at'])) . ')' . ($buf ? '. A ' . $rules['buffer_minutes'] . '-minute turnaround is required between bookings.' : '.'),
            'detail' => ['type' => 'venue_conflict', 'event_id' => (int)$clash['id']],
        ];
    }

    // -- 6. Organizer double-booking ----------------------------------------------
    $organizerId = (int)($args['organizer_id'] ?? 0);
    if ($organizerId > 0) {
        $q = $pdo->prepare('SELECT id,title,start_at,end_at FROM events WHERE organizer_id=? AND status IN("scheduled","ongoing") AND id<>? AND start_at < ? AND end_at > ?');
        $q->execute([$organizerId, $ignore, date('Y-m-d H:i:s', $endTs), date('Y-m-d H:i:s', $startTs)]);
        $own = $q->fetch(PDO::FETCH_ASSOC);
        if ($own) {
            return ['ok' => false,
                'error' => 'You already have "' . $own['title'] . '" scheduled at that time (' . date('M d, g:i A', strtotime($own['start_at'])) . ' – ' . date('M d, g:i A', strtotime($own['end_at'])) . '). You cannot run two events at once.',
                'detail' => ['type' => 'organizer_conflict', 'event_id' => (int)$own['id']],
            ];
        }
        // Per-user daily creation cap.
        if (!empty($args['check_day_cap'])) {
            $dayStart = date('Y-m-d 00:00:00', $startTs);
            $dayEnd = date('Y-m-d 23:59:59', $startTs);
            $q = $pdo->prepare('SELECT COUNT(*) FROM events WHERE organizer_id=? AND status IN("scheduled","ongoing") AND id<>? AND start_at BETWEEN ? AND ?');
            $q->execute([$organizerId, $ignore, $dayStart, $dayEnd]);
            if ((int)$q->fetchColumn() >= $rules['max_per_day']) {
                return ['ok' => false, 'error' => 'You already have ' . $rules['max_per_day'] . ' event(s) on ' . date('M d, Y', $startTs) . '. Distribute events across days.'];
            }
        }
    }

    return ['ok' => true];
}

/**
 * ============================================================================
 * Automatic venue matching
 * ============================================================================
 * Picks the best venue for a proposed schedule so users do not have to guess.
 * Every candidate must pass the FULL sportsync_validate_schedule() rule set
 * (availability status, capacity, opening hours, turnaround buffer and the
 * venue-overlap conflict query), so an auto-matched venue can never collide
 * with an existing booking.
 *
 * Selection strategy: venues are examined smallest-capacity-first and the
 * first conflict-free venue that fits the group wins — this keeps large
 * venues free for genuinely large events. Capacity 0 is treated as unlimited.
 *
 * The organizer double-booking check is intentionally NOT part of matching:
 * it is venue-independent and the caller's final validate_schedule() pass
 * reports it with a precise message.
 *
 * $args = ['start','end','people'] plus optional 'ignore_event' (reschedule).
 * Returns ['ok'=>true,'venue'=>row,'alternatives'=>rows] or ['ok'=>false,'error'=>string].
 */
function sportsync_match_venue(?PDO $pdo, array $args): array {
    $start = (string)($args['start'] ?? '');
    $end = (string)($args['end'] ?? '');
    $people = max(1, (int)($args['people'] ?? 1));

    // Fail fast on schedule-integrity rules (duration, hours, advance window)
    // before scanning venues, so the user gets the precise rule error.
    $pre = sportsync_validate_schedule(null, ['start' => $start, 'end' => $end]);
    if (!$pre['ok']) return $pre;
    if (!$pdo) return ['ok' => false, 'error' => 'Database unavailable for venue matching.'];

    $q = $pdo->query('SELECT id,name,address,latitude,longitude,capacity,facilities,status FROM venues WHERE status="available" ORDER BY CASE WHEN capacity=0 THEN 999999 ELSE capacity END ASC, id ASC');
    $best = null;
    $alternatives = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $venue) {
        if ((int)$venue['capacity'] > 0 && $people > (int)$venue['capacity']) continue;
        $check = sportsync_validate_schedule($pdo, [
            'start' => $start,
            'end' => $end,
            'venue_id' => (int)$venue['id'],
            'people' => $people,
            'ignore_event' => (int)($args['ignore_event'] ?? 0),
        ]);
        if (!$check['ok']) continue; // conflict (incl. buffer) or capacity — skip
        $alternatives[] = $venue;
        if ($best === null) $best = $venue; // smallest fitting, conflict-free
    }

    if (!$best) {
        return ['ok' => false, 'error' => 'No venue is free for that schedule and group size. Adjust the date, time, or participant count and try again.'];
    }
    return ['ok' => true, 'venue' => $best, 'alternatives' => $alternatives];
}

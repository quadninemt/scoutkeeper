<?php

declare(strict_types=1);

namespace Tests\Modules\Events;

use App\Core\Database;
use App\Modules\Events\Services\ICalService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ICalService.
 *
 * Covers token generation, validation, and regeneration, plus iCal feed
 * generation: VCALENDAR structure, VEVENT per event, DTSTART/DTEND
 * formatting, all-day events (DATE format), timed events (UTC DATE-TIME),
 * multi-day events, optional fields, RFC 5545 line folding, text escaping,
 * and edge cases (empty feed, no end date, no description/location).
 */
class ICalServiceTest extends TestCase
{
    private Database $db;
    private ICalService $svc;

    /** Stable member ID used across token tests */
    private int $memberId = 42;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `event_ical_tokens`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("
            CREATE TABLE `event_ical_tokens` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `member_id`  INT UNSIGNED NOT NULL,
                `token`      VARCHAR(128) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_member` (`member_id`),
                UNIQUE KEY `uq_token` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->svc = new ICalService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
            $this->db->query("DROP TABLE IF EXISTS `event_ical_tokens`");
            $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Extract all VEVENT blocks from an iCal string.
     * Returns an array of raw block strings.
     */
    private function extractVEvents(string $ical): array
    {
        preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $ical, $matches);
        return $matches[0];
    }

    /**
     * Extract the value of a property from a VEVENT block.
     * Handles folded lines (CRLF + SPACE continuation).
     */
    private function getProperty(string $block, string $propName): ?string
    {
        // Unfold: replace CRLF+SPACE with nothing
        $unfolded = preg_replace("/\r\n[ \t]/", '', $block);

        $pattern = '/^' . preg_quote($propName, '/') . '[;:](.+)$/m';
        if (preg_match($pattern, $unfolded, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Build a minimal timed-event array as EventService would return.
     */
    private function timedEvent(
        int $id,
        string $title,
        string $start,
        ?string $end = null,
        ?string $description = null,
        ?string $location = null
    ): array {
        return [
            'id'          => $id,
            'title'       => $title,
            'start_date'  => $start,
            'end_date'    => $end,
            'all_day'     => false,
            'description' => $description,
            'location'    => $location,
            'updated_at'  => null,
        ];
    }

    /**
     * Build a minimal all-day event array.
     */
    private function allDayEvent(
        int $id,
        string $title,
        string $startDate,
        ?string $endDate = null
    ): array {
        return [
            'id'          => $id,
            'title'       => $title,
            'start_date'  => $startDate,
            'end_date'    => $endDate,
            'all_day'     => true,
            'description' => null,
            'location'    => null,
            'updated_at'  => null,
        ];
    }

    // ── Token: generateToken ──────────────────────────────────────────────

    public function testGenerateTokenReturns64CharHexString(): void
    {
        $token = $this->svc->generateToken($this->memberId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGenerateTokenPersistsToDatabase(): void
    {
        $token = $this->svc->generateToken($this->memberId);

        $record = $this->svc->getTokenForMember($this->memberId);
        $this->assertNotNull($record);
        $this->assertSame($token, $record['token']);
    }

    public function testGenerateTokenStoresCorrectMemberId(): void
    {
        $this->svc->generateToken($this->memberId);

        $record = $this->svc->getTokenForMember($this->memberId);
        $this->assertSame($this->memberId, $record['member_id']);
    }

    public function testGenerateTokenProducesUniqueTokensForSameMember(): void
    {
        $t1 = $this->svc->generateToken($this->memberId);
        $t2 = $this->svc->generateToken($this->memberId);
        $this->assertNotSame($t1, $t2);
    }

    // ── Token: getTokenForMember ──────────────────────────────────────────

    public function testGetTokenForMemberReturnsNullWhenNoToken(): void
    {
        $this->assertNull($this->svc->getTokenForMember(999));
    }

    public function testGetTokenForMemberReturnsMostRecent(): void
    {
        // Two tokens; getTokenForMember should return the latest
        $this->svc->generateToken($this->memberId);
        $second = $this->svc->generateToken($this->memberId);

        $record = $this->svc->getTokenForMember($this->memberId);
        $this->assertSame($second, $record['token']);
    }

    public function testGetTokenForMemberCastsMemberIdToInt(): void
    {
        $this->svc->generateToken($this->memberId);
        $record = $this->svc->getTokenForMember($this->memberId);
        $this->assertIsInt($record['member_id']);
    }

    // ── Token: validateToken ──────────────────────────────────────────────

    public function testValidateTokenReturnsRecordForValidToken(): void
    {
        $token = $this->svc->generateToken($this->memberId);
        $record = $this->svc->validateToken($token);

        $this->assertNotNull($record);
        $this->assertSame($token, $record['token']);
        $this->assertSame($this->memberId, $record['member_id']);
    }

    public function testValidateTokenReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->svc->validateToken(str_repeat('a', 64)));
    }

    public function testValidateTokenReturnsNullForEmptyString(): void
    {
        $this->assertNull($this->svc->validateToken(''));
    }

    // ── Token: regenerateToken ────────────────────────────────────────────

    public function testRegenerateTokenReturnsNewToken(): void
    {
        $old = $this->svc->generateToken($this->memberId);
        $new = $this->svc->regenerateToken($this->memberId);
        $this->assertNotSame($old, $new);
    }

    public function testRegenerateTokenInvalidatesOldToken(): void
    {
        $old = $this->svc->generateToken($this->memberId);
        $this->svc->regenerateToken($this->memberId);
        $this->assertNull($this->svc->validateToken($old));
    }

    public function testRegenerateTokenNewTokenIsValid(): void
    {
        $new = $this->svc->regenerateToken($this->memberId);
        $this->assertNotNull($this->svc->validateToken($new));
    }

    public function testRegenerateTokenWhenNoExistingTokenWorks(): void
    {
        // No prior token; should not throw
        $token = $this->svc->regenerateToken(888);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    // ── Feed: VCALENDAR structure ─────────────────────────────────────────

    public function testGenerateFeedContainsVCalendarWrapper(): void
    {
        $ical = $this->svc->generateFeed([]);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ical);
        $this->assertStringContainsString('END:VCALENDAR', $ical);
    }

    public function testGenerateFeedContainsRequiredHeaders(): void
    {
        $ical = $this->svc->generateFeed([]);
        $this->assertStringContainsString('VERSION:2.0', $ical);
        $this->assertStringContainsString('PRODID:', $ical);
        $this->assertStringContainsString('CALSCALE:GREGORIAN', $ical);
        $this->assertStringContainsString('METHOD:PUBLISH', $ical);
    }

    public function testGenerateFeedUsesCustomCalendarName(): void
    {
        $ical = $this->svc->generateFeed([], '1st Valletta Scout Troop');
        $this->assertStringContainsString('X-WR-CALNAME:1st Valletta Scout Troop', $ical);
    }

    public function testGenerateFeedDefaultCalendarName(): void
    {
        $ical = $this->svc->generateFeed([]);
        $this->assertStringContainsString('X-WR-CALNAME:ScoutKeeper Events', $ical);
    }

    public function testGenerateFeedEndsWithCrLf(): void
    {
        $ical = $this->svc->generateFeed([]);
        $this->assertStringEndsWith("\r\n", $ical);
    }

    public function testGenerateFeedLinesSeparatedByCrLf(): void
    {
        $ical = $this->svc->generateFeed([]);
        // Every line ending should be CRLF, not bare LF
        $this->assertStringNotContainsString("\n\n", str_replace("\r\n", '', $ical));
        $this->assertStringContainsString("\r\n", $ical);
    }

    // ── Feed: empty events ────────────────────────────────────────────────

    public function testGenerateFeedWithNoEventsContainsNoVEvent(): void
    {
        $ical = $this->svc->generateFeed([]);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ical);
    }

    // ── Feed: one VEVENT per event ────────────────────────────────────────

    public function testGenerateFeedOneVEventPerEvent(): void
    {
        $events = [
            $this->timedEvent(1, 'Alpha', '2099-06-01 10:00:00'),
            $this->timedEvent(2, 'Beta',  '2099-06-02 10:00:00'),
            $this->timedEvent(3, 'Gamma', '2099-06-03 10:00:00'),
        ];

        $vevents = $this->extractVEvents($this->svc->generateFeed($events));
        $this->assertCount(3, $vevents);
    }

    // ── Feed: UID ─────────────────────────────────────────────────────────

    public function testGenerateFeedVEventContainsUid(): void
    {
        $ical = $this->svc->generateFeed([$this->timedEvent(7, 'Test', '2099-01-01 09:00:00')]);
        $vevents = $this->extractVEvents($ical);
        $this->assertStringContainsString('UID:event-7@scoutkeeper', $vevents[0]);
    }

    // ── Feed: timed events ────────────────────────────────────────────────

    public function testTimedEventDtStartIsUtcFormat(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Timed', '2099-06-15 14:30:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $dtstart = $this->getProperty($vevents[0], 'DTSTART');
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $dtstart);
        $this->assertSame('20990615T143000Z', $dtstart);
    }

    public function testTimedEventDtEndIsUtcFormat(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'With End', '2099-06-15 09:00:00', '2099-06-15 17:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $dtend = $this->getProperty($vevents[0], 'DTEND');
        $this->assertSame('20990615T170000Z', $dtend);
    }

    public function testTimedEventWithNoEndDateHasNoDtEnd(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'No End', '2099-06-15 09:00:00', null),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringNotContainsString('DTEND', $vevents[0]);
    }

    // ── Feed: all-day events ──────────────────────────────────────────────

    public function testAllDayEventDtStartHasValueDateParameter(): void
    {
        $ical = $this->svc->generateFeed([
            $this->allDayEvent(1, 'Scout Day', '2099-08-01'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:', $vevents[0]);
    }

    public function testAllDayEventDtStartIsDateOnlyFormat(): void
    {
        $ical = $this->svc->generateFeed([
            $this->allDayEvent(1, 'Badge Day', '2099-09-10'),
        ]);

        $vevents = $this->extractVEvents($ical);
        // Extract value after VALUE=DATE:
        preg_match('/DTSTART;VALUE=DATE:(\d+)/', $vevents[0], $m);
        $this->assertSame('20990910', $m[1] ?? '');
    }

    public function testAllDayEventHasNoTimeComponent(): void
    {
        $ical = $this->svc->generateFeed([
            $this->allDayEvent(1, 'Camp', '2099-07-04'),
        ]);

        // Should not contain a T (time separator) on the DTSTART line
        $vevents = $this->extractVEvents($ical);
        preg_match('/DTSTART[^:\r\n]*:(\S+)/', $vevents[0], $m);
        $this->assertStringNotContainsString('T', $m[1] ?? '');
    }

    public function testAllDayEventDtEndIsExclusiveNextDay(): void
    {
        // iCal DTEND for all-day is exclusive: end date + 1 day
        $ical = $this->svc->generateFeed([
            $this->allDayEvent(1, 'One Day', '2099-07-10', '2099-07-10'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20990711', $vevents[0]);
    }

    public function testAllDayEventWithNoEndDateHasNoDtEnd(): void
    {
        $ical = $this->svc->generateFeed([
            $this->allDayEvent(1, 'Open End', '2099-08-01', null),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringNotContainsString('DTEND', $vevents[0]);
    }

    // ── Feed: multi-day event ─────────────────────────────────────────────

    public function testMultiDayTimedEventHasCorrectStartAndEnd(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Camp Week', '2099-08-01 08:00:00', '2099-08-07 18:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $dtstart = $this->getProperty($vevents[0], 'DTSTART');
        $dtend   = $this->getProperty($vevents[0], 'DTEND');

        $this->assertSame('20990801T080000Z', $dtstart);
        $this->assertSame('20990807T180000Z', $dtend);
    }

    public function testMultiDayAllDayEventEndIsExclusivePlusOne(): void
    {
        $ical = $this->svc->generateFeed([
            $this->allDayEvent(1, 'Long Camp', '2099-08-01', '2099-08-05'),
        ]);

        $vevents = $this->extractVEvents($ical);
        // End date 2099-08-05 → exclusive DTEND = 2099-08-06
        $this->assertStringContainsString('DTEND;VALUE=DATE:20990806', $vevents[0]);
    }

    // ── Feed: SUMMARY ─────────────────────────────────────────────────────

    public function testSummaryMatchesEventTitle(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'District Parade', '2099-03-17 11:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $summary = $this->getProperty($vevents[0], 'SUMMARY');
        $this->assertSame('District Parade', $summary);
    }

    public function testSummaryIsIncludedEvenWhenEmpty(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, '', '2099-01-01 10:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringContainsString('SUMMARY:', $vevents[0]);
    }

    // ── Feed: DESCRIPTION ────────────────────────────────────────────────

    public function testDescriptionIsIncludedWhenPresent(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Event', '2099-01-01 10:00:00', null, 'Bring a packed lunch'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringContainsString('DESCRIPTION:', $vevents[0]);
        $desc = $this->getProperty($vevents[0], 'DESCRIPTION');
        $this->assertSame('Bring a packed lunch', $desc);
    }

    public function testDescriptionIsOmittedWhenNull(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'No Desc', '2099-01-01 10:00:00', null, null),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringNotContainsString('DESCRIPTION', $vevents[0]);
    }

    // ── Feed: LOCATION ────────────────────────────────────────────────────

    public function testLocationIsIncludedWhenPresent(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Event', '2099-01-01 10:00:00', null, null, 'Scout Hall, Mosta'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $loc = $this->getProperty($vevents[0], 'LOCATION');
        $this->assertSame('Scout Hall\\, Mosta', $loc); // comma is escaped
    }

    public function testLocationIsOmittedWhenNull(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'No Loc', '2099-01-01 10:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringNotContainsString('LOCATION', $vevents[0]);
    }

    // ── Feed: text escaping ───────────────────────────────────────────────

    public function testEscapesBackslashInTitle(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'C:\\Path\\Event', '2099-01-01 10:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $summary = $this->getProperty($vevents[0], 'SUMMARY');
        $this->assertStringContainsString('\\\\', $summary);
    }

    public function testEscapesSemicolonInTitle(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Event; Extra', '2099-01-01 10:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $summary = $this->getProperty($vevents[0], 'SUMMARY');
        $this->assertSame('Event\\; Extra', $summary);
    }

    public function testEscapesCommaInTitle(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Hike, Walk', '2099-01-01 10:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $summary = $this->getProperty($vevents[0], 'SUMMARY');
        $this->assertSame('Hike\\, Walk', $summary);
    }

    public function testEscapesNewlineInDescription(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Event', '2099-01-01 10:00:00', null, "Line1\nLine2"),
        ]);

        $vevents = $this->extractVEvents($ical);
        $desc = $this->getProperty($vevents[0], 'DESCRIPTION');
        $this->assertSame('Line1\\nLine2', $desc);
    }

    // ── Feed: RFC 5545 line folding ───────────────────────────────────────

    public function testLongSummaryIsFolded(): void
    {
        // A title long enough (>75 chars) to trigger folding
        $longTitle = str_repeat('ScoutKeeper ', 10); // 120 chars

        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, $longTitle, '2099-01-01 10:00:00'),
        ]);

        // Folded continuation lines start with CRLF + SPACE
        $this->assertStringContainsString("\r\n ", $ical);
    }

    public function testShortLinesAreNotFolded(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Short', '2099-01-01 10:00:00'),
        ]);

        // Strip normal CRLF endings; any remaining CRLF+space indicates folding
        $withoutEndings = preg_replace("/\r\n(?![ \t])/", '|', $ical);
        $this->assertStringNotContainsString("\r\n", $withoutEndings);
    }

    // ── Feed: DTSTAMP ─────────────────────────────────────────────────────

    public function testVEventContainsDtstamp(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'Check', '2099-01-01 10:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $dtstamp = $this->getProperty($vevents[0], 'DTSTAMP');
        $this->assertNotNull($dtstamp);
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z$/', $dtstamp);
    }

    // ── Feed: past events included ────────────────────────────────────────

    public function testPastEventsAreIncludedInFeed(): void
    {
        // generateFeed does not filter by date; that is EventService's job
        $past = $this->timedEvent(99, 'Old Camp', '2000-06-15 09:00:00');
        $ical = $this->svc->generateFeed([$past]);

        $vevents = $this->extractVEvents($ical);
        $this->assertCount(1, $vevents);
        $summary = $this->getProperty($vevents[0], 'SUMMARY');
        $this->assertSame('Old Camp', $summary);
    }

    // ── Feed: LAST-MODIFIED ───────────────────────────────────────────────

    public function testLastModifiedIncludedWhenUpdatedAtPresent(): void
    {
        $event = $this->timedEvent(1, 'Modified', '2099-01-01 10:00:00');
        $event['updated_at'] = '2099-05-01 12:00:00';

        $ical = $this->svc->generateFeed([$event]);
        $vevents = $this->extractVEvents($ical);
        $lastMod = $this->getProperty($vevents[0], 'LAST-MODIFIED');
        $this->assertSame('20990501T120000Z', $lastMod);
    }

    public function testLastModifiedOmittedWhenUpdatedAtNull(): void
    {
        $ical = $this->svc->generateFeed([
            $this->timedEvent(1, 'No Modified', '2099-01-01 10:00:00'),
        ]);

        $vevents = $this->extractVEvents($ical);
        $this->assertStringNotContainsString('LAST-MODIFIED', $vevents[0]);
    }

    // ── Feed: multiple events ordering ───────────────────────────────────

    public function testMultipleEventsPreserveInputOrder(): void
    {
        $events = [
            $this->timedEvent(10, 'Alpha', '2099-01-01 10:00:00'),
            $this->timedEvent(20, 'Beta',  '2099-06-01 10:00:00'),
            $this->timedEvent(30, 'Gamma', '2099-12-01 10:00:00'),
        ];

        $vevents = $this->extractVEvents($this->svc->generateFeed($events));
        $this->assertCount(3, $vevents);
        $this->assertStringContainsString('UID:event-10@scoutkeeper', $vevents[0]);
        $this->assertStringContainsString('UID:event-20@scoutkeeper', $vevents[1]);
        $this->assertStringContainsString('UID:event-30@scoutkeeper', $vevents[2]);
    }
}

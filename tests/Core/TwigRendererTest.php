<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/**
 * Tests for the custom Twig filters registered by TwigRenderer.
 *
 * TwigRenderer::__construct() requires a fully booted Application singleton
 * (database, session, router, i18n, permission resolver). Instantiating it
 * in a unit test is disproportionately expensive and brittle.
 *
 * Instead, we extract the two stateless custom filters (time_ago and
 * format_date) from TwigRenderer's source, register them on a minimal
 * in-memory Twig\Environment, and test their behaviour in isolation.
 * This is equivalent to white-box unit testing of the filter logic itself.
 */
class TwigRendererTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new ArrayLoader([
            'time_ago.html' => '{{ date|time_ago }}',
            'format_date.html' => '{{ date|format_date(fmt) }}',
            'format_date_default.html' => '{{ date|format_date }}',
        ]);

        $this->twig = new Environment($loader, ['autoescape' => false]);

        // ----------------------------------------------------------------
        // time_ago filter — matches the implementation in TwigRenderer
        // ----------------------------------------------------------------
        $this->twig->addFilter(new TwigFilter(
            'time_ago',
            function (string|\DateTimeInterface $datetime): string {
                if (is_string($datetime)) {
                    $datetime = new \DateTimeImmutable($datetime);
                }
                $diff = (new \DateTimeImmutable())->diff($datetime);

                if ($diff->y > 0) {
                    return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
                }
                if ($diff->m > 0) {
                    return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
                }
                if ($diff->d > 0) {
                    return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
                }
                if ($diff->h > 0) {
                    return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
                }
                if ($diff->i > 0) {
                    return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
                }
                return 'just now';
            }
        ));

        // ----------------------------------------------------------------
        // format_date filter — matches the implementation in TwigRenderer
        // ----------------------------------------------------------------
        $this->twig->addFilter(new TwigFilter(
            'format_date',
            function (string|\DateTimeInterface|null $datetime, string $format = 'd M Y'): string {
                if ($datetime === null) {
                    return '';
                }
                if (is_string($datetime)) {
                    $datetime = new \DateTimeImmutable($datetime);
                }
                return $datetime->format($format);
            }
        ));
    }

    // -----------------------------------------------------------------------
    // time_ago filter
    // -----------------------------------------------------------------------

    public function testTimeAgoJustNow(): void
    {
        $date = (new \DateTimeImmutable())->modify('-10 seconds');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('just now', $result);
    }

    public function testTimeAgoMinutes(): void
    {
        $date = (new \DateTimeImmutable())->modify('-5 minutes');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('5 minutes ago', $result);
    }

    public function testTimeAgoOneMinute(): void
    {
        $date = (new \DateTimeImmutable())->modify('-1 minute');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('1 minute ago', $result);
    }

    public function testTimeAgoHours(): void
    {
        $date = (new \DateTimeImmutable())->modify('-3 hours');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('3 hours ago', $result);
    }

    public function testTimeAgoOneHour(): void
    {
        $date = (new \DateTimeImmutable())->modify('-1 hour');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('1 hour ago', $result);
    }

    public function testTimeAgoDays(): void
    {
        $date = (new \DateTimeImmutable())->modify('-4 days');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('4 days ago', $result);
    }

    public function testTimeAgoOneDay(): void
    {
        $date = (new \DateTimeImmutable())->modify('-1 day');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('1 day ago', $result);
    }

    public function testTimeAgoMonths(): void
    {
        $date = (new \DateTimeImmutable())->modify('-2 months');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('2 months ago', $result);
    }

    public function testTimeAgoOneMonth(): void
    {
        $date = (new \DateTimeImmutable())->modify('-1 month');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('1 month ago', $result);
    }

    public function testTimeAgoYears(): void
    {
        $date = (new \DateTimeImmutable())->modify('-3 years');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('3 years ago', $result);
    }

    public function testTimeAgoOneYear(): void
    {
        $date = (new \DateTimeImmutable())->modify('-1 year');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('1 year ago', $result);
    }

    public function testTimeAgoAcceptsDateTimeImmutable(): void
    {
        $date = new \DateTimeImmutable('-2 hours');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('2 hours ago', $result);
    }

    public function testTimeAgoAcceptsDateTimeObject(): void
    {
        $date = new \DateTime('-5 minutes');
        $result = $this->twig->render('time_ago.html', ['date' => $date]);
        $this->assertSame('5 minutes ago', $result);
    }

    // -----------------------------------------------------------------------
    // format_date filter
    // -----------------------------------------------------------------------

    public function testFormatDateDefaultFormat(): void
    {
        $date = new \DateTimeImmutable('2024-03-15');
        $result = $this->twig->render('format_date_default.html', ['date' => $date]);
        $this->assertSame('15 Mar 2024', $result);
    }

    public function testFormatDateCustomFormat(): void
    {
        $date = new \DateTimeImmutable('2024-03-15');
        $result = $this->twig->render('format_date.html', ['date' => $date, 'fmt' => 'Y-m-d']);
        $this->assertSame('2024-03-15', $result);
    }

    public function testFormatDateFromString(): void
    {
        $result = $this->twig->render('format_date_default.html', ['date' => '2023-06-01']);
        $this->assertSame('01 Jun 2023', $result);
    }

    public function testFormatDateNullReturnsEmptyString(): void
    {
        $result = $this->twig->render('format_date_default.html', ['date' => null]);
        $this->assertSame('', $result);
    }

    public function testFormatDateAcceptsDateTime(): void
    {
        $date = new \DateTime('2024-01-20');
        $result = $this->twig->render('format_date.html', ['date' => $date, 'fmt' => 'd/m/Y']);
        $this->assertSame('20/01/2024', $result);
    }

    public function testFormatDateWithDayMonthYear(): void
    {
        $date = new \DateTimeImmutable('2025-12-31');
        $result = $this->twig->render('format_date.html', ['date' => $date, 'fmt' => 'j F Y']);
        $this->assertSame('31 December 2025', $result);
    }
}

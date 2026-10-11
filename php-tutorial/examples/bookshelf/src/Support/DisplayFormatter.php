<?php

declare(strict_types=1);

namespace Bookshelf\Support;

use Bookshelf\Domain\Money;
use DateTimeImmutable;
use DateTimeInterface;
use IntlDateFormatter;
use MessageFormatter;
use NumberFormatter;
use RuntimeException;

/**
 * Formats values for people, in one locale and time zone, using the intl extension.
 */
final class DisplayFormatter
{
    private readonly NumberFormatter $currency;
    private readonly IntlDateFormatter $date;
    private readonly MessageFormatter $dueMessage;

    public function __construct(
        public readonly string $locale = 'en_US',
        string $timezone = 'UTC',
    ) {
        $this->currency = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $this->date = new IntlDateFormatter($locale, IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $timezone);
        $this->dueMessage = new MessageFormatter($locale, '{days, plural,'
            . ' =-1 {due yesterday} =0 {due today} =1 {due tomorrow}'
            . ' other {{days, number} days left}}');
    }

    /** Money amounts are stored in US cents; dividing for display is the only place a float appears. */
    public function money(Money $amount): string
    {
        return self::checked($this->currency->formatCurrency($amount->cents / 100, 'USD'));
    }

    public function date(DateTimeInterface $date): string
    {
        return self::checked($this->date->format($date));
    }

    /** "due today", "due tomorrow", "12 days left"; overdue loans are described by overdue(). */
    public function dueIn(DateTimeImmutable $dueOn, DateTimeImmutable $today): string
    {
        $days = (int) $today->setTime(0, 0)->diff($dueOn->setTime(0, 0))->format('%r%a');

        return $days < -1 ? $this->overdue(-$days) : self::checked($this->dueMessage->format(['days' => $days]));
    }

    public function overdue(int $days): string
    {
        return self::checked(MessageFormatter::formatMessage($this->locale, '{days, plural, one {# day overdue} other {# days overdue}}', [
            'days' => $days,
        ]));
    }

    /** The intl formatters return false instead of throwing when they fail. */
    private static function checked(string|false $result): string
    {
        return $result !== false ? $result : throw new RuntimeException('Formatting failed: ' . intl_get_error_message());
    }
}

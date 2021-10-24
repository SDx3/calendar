<?php

/*
 * CalendarGenerator.php
 * Copyright (c) 2021 Sander Dorigo
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
 * of the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

declare(strict_types=1);

namespace App;

use Carbon\Carbon;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\Entity\TimeZone;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\EmailAddress;
use Eluceo\iCal\Domain\ValueObject\Organizer;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;
use JsonException;

/**
 * Class CalendarGenerator
 */
class CalendarGenerator
{
    public const VERSION = '4.1';
    private string   $calendarName;
    private string   $directory;
    private array    $appointments;
    private Carbon   $start;
    private Carbon   $end;
    private array    $timeSlots;
    private array    $months;
    private array    $days;
    private Calendar $calendar;

    /**
     * @param string $directory
     * @param string $calendar
     * @throws JsonException
     */
    public function __construct(string $directory, string $calendar)
    {
        $this->calendarName = $calendar;
        $this->directory    = $directory;
        $this->appointments = [];
        $this->timeSlots    = [];
        $this->months       = [
            1  => 'januari',
            2  => 'februari',
            3  => 'maart',
            4  => 'april',
            5  => 'mei',
            6  => 'juni',
            7  => 'juli',
            8  => 'augustus',
            9  => 'september',
            10 => 'oktober',
            11 => 'november',
            12 => 'december',
        ];
        $this->days         = [
            1 => 'maandag',
            2 => 'dinsdag',
            3 => 'woensdag',
            4 => 'donderdag',
            5 => 'vrijdag',
            6 => 'zaterdag',
            7 => 'zondag',
        ];

        $this->parseJson();
    }

    /**
     * @throws JsonException
     */
    private function parseJson(): void
    {
        // loop directory:
        $list          = scandir($this->directory);
        $json          = [];
        $validCalendar = false;
        foreach ($list as $file) {
            if ('json' === substr($file, -4)) {
                $fullFile = sprintf('%s%s%s', $this->directory, DIRECTORY_SEPARATOR, $file);
                $current = [];
                // read file
                if (file_exists($fullFile)) {
                    $current = json_decode(file_get_contents($fullFile), true, 8, JSON_THROW_ON_ERROR);
                }

                // makes calendar valid?
                foreach ($current as $appointment) {
                    if ($this->calendarName === $appointment['calendar']) {
                        $validCalendar = true;
                    }
                }
                $json = array_merge($json, $current);
            }
        }
        // exit if no such calendar exists
        if (false === $validCalendar) {
            echo 'NOK';
            exit;
        }
        $this->appointments = $json;
    }

    /**
     * @return string
     */
    public function generate(): string
    {
        $this->calendar = new Calendar;
        $timezone       = new TimeZone($_ENV['TZ']);
        $this->calendar->addTimeZone($timezone);
        // loop each day
        $current = clone $this->start;
        while ($current <= $this->end) {
            $this->processAppointments($current);
            $current->addDay();
        }
        // Transform domain entity into an iCalendar component
        $componentFactory = new CalendarFactory(new TransparentEventFactory);
        return (string) $componentFactory->createCalendar($this->calendar);
    }

    /**
     * @param Carbon $date
     */
    private function processAppointments(Carbon $date): void
    {
        /** @var array $appointment */
        foreach ($this->appointments as $appointment) {
            if ($this->matchesDatePattern($date, $appointment['pattern'])) {
                $this->addAppointment($date, $appointment);
            }
            if(0 === $appointment['pattern']) {
                $current = Carbon::createFromFormat('Y-m-d', $appointment['date'], $_ENV['TZ']);
                if($current->isSameDay($date)) {
                    $this->addAppointment($date, $appointment);
                }
            }
        }
    }

    /**
     * @param Carbon $date
     * @param array  $appointment
     */
    private function addAppointment(Carbon $date, array $appointment): void
    {
        // set the start and end time of the appointment:
        $appointmentStart = clone $date;
        $appointmentEnd   = clone $date;

        // same day, possibly as other "stacked" appointments
        if (true === $appointment['stacked']) {
            $dateString                   = $date->format('Y-m-d');
            $this->timeSlots[$dateString] = array_key_exists($dateString, $this->timeSlots) ? $this->timeSlots[$dateString] + 1 : 0;

            $appointmentStart->setTime(6, 0);
            $appointmentEnd->setTime(6, 30);
            $appointmentStart->addMinutes(30 * $this->timeSlots[$dateString]);
            $appointmentEnd->addMinutes(30 * $this->timeSlots[$dateString]);
        }

        if (false === $appointment['stacked']) {
            $appointmentStart = clone $date;
            $appointmentEnd   = clone $date;

            // explode start and end:
            $parts = explode(':', $appointment['start_time']);
            $appointmentStart->setTime($parts[0], $parts[1], $parts[2]);
            $parts = explode(':', $appointment['end_time']);
            $appointmentEnd->setTime($parts[0], $parts[1], $parts[2]);

        }

        if ($this->calendarName === $appointment['calendar']) {
            $title       = $this->replaceVariables($date, $appointment['title']);
            $description = $this->generateDescription($title, $this->replaceVariables($date, $appointment['description']));
            $string      = substr(hash('sha256', sprintf('%s-%s', $date->format('Y-m-d'), $title)), 0, 16);
            $uid         = new UniqueIdentifier($string);
            $occurrence  = new TimeSpan(new DateTime($appointmentStart->toDateTime(), true), new DateTime($appointmentEnd->toDateTime(), true));
            $organizer   = new Organizer(new EmailAddress($_ENV['ORGANIZER_MAIL']), $_ENV['ORGANIZER_NAME'], null, new EmailAddress($_ENV['ORGANIZER_MAIL']));

            $vEvent = new Event($uid);
            $vEvent->setOrganizer($organizer)
                   ->setSummary($title)
                   ->setDescription($description)->setOccurrence($occurrence);
            $this->calendar->addEvent($vEvent);
        }
    }

    /**
     * @param Carbon $date
     * @param int    $pattern
     * @return bool
     */
    private function matchesDatePattern(Carbon $date, int $pattern): bool
    {
        switch ($pattern) {
            default:
                return false;
            case 1:
                // first day of the month
                return 1 === $date->day;
            case 2:
                // seventh day of the month, unless that's a saturday or sunday, then the day before
                return (5 === $date->day && $date->isFriday()) || (6 === $date->day && $date->isFriday()) || (7 === $date->day && !$date->isWeekend());
            case 3:
                // last monday of the month
                return $date->isMonday() && ($date->daysInMonth - $date->day <= 6);
            case 4:
                // last thursday of the month
                return $date->isThursday() && ($date->daysInMonth - $date->day <= 6);
            case 5:
                // second friday of the month
                $secondFriday = Carbon::parse(sprintf('second Friday of %s', $date->format('F Y')));
                return $secondFriday->isSameDay($date);
            case 6:
                $secondWednesday = Carbon::parse(sprintf('second Wednesday of %s', $date->format('F Y')));
                return $secondWednesday->isSameDay($date);
            case 7:
                return $date->isWeekday();
            case 8:
                return $date->isMonday() || $date->isThursday() || $date->isSaturday();
            case 9:
                return $date->isTuesday() || $date->isFriday();
            case 10:
                return $date->isWednesday() || $date->isSunday();
            case 11:
                return $date->isFriday();
            case 12:
                return $date->isMonday();
            case 13:
                return $date->isThursday();
            case 14:
                // is first of Jan, Apr, Jul or Nov
                return $date->day === 1 && in_array($date->month, [1, 4, 7, 11]);
            case 15:
                // is not a thursday and is a valid moment for coffee slot
                return !$date->isThursday() && !$date->isWeekend() && 0 !== $this->coffeeSlot($date);
            case 16:
                // is a thursday and is a valid moment for coffee slot
                return $date->isThursday() && 0 !== $this->coffeeSlot($date);
            case 17:

                break;
        }
    }

    /**
     * @param Carbon $date
     * @return int
     */
    private function coffeeSlot(Carbon $date): int
    {
        $weekNr = $date->isoWeek - 1;
        $slot   = 0;
        if ($date->isMonday()) {
            // slot X op maandag (1,2,3,4).
            $slot = ($weekNr % 4) + 1;
        }

        if ($date->isTuesday()) {
            // slot X op dinsdag (5,6,7,8).
            $slot = ($weekNr % 4) + 5;
        }

        if ($date->isWednesday()) {
            // slot X op woensdag
            $slot = ($weekNr % 4) + 9;
        }

        if ($date->isThursday()) {
            // slot X op donderdag
            $slot = ($weekNr % 4) + 13;
        }
        return $slot;
    }

    /**
     * @param Carbon $date
     * @param string $title
     * @return string
     */
    private function replaceVariables(Carbon $date, string $title): string
    {
        // next month:
        $next = clone $date;
        $next->addMonth();

        // coffee slot:
        $slot = $this->coffeeSlot($date);


        $search  = ['%month', '%year', '%next_month', '%next_year', '%dag', '%coffee_slot'];
        $replace = [$this->months[$date->month], $date->year, $this->months[$next->month], $next->year, $this->days[(int) $date->format('N')], $slot];

        return str_replace($search, $replace, $title);
    }

    /**
     * @param string $title
     * @param string $description
     * @return string
     */
    private function generateDescription(string $title, string $description): string
    {
        $now  = Carbon::now($_ENV['TZ'])->toIso8601String();
        $line = "Agenda: %s\r\nTitle: %s\r\nDescription: %s\r\nLast pull: %s\r\nVersion: %s";

        return sprintf($line, $this->calendarName, $title, $description, $now, self::VERSION);
    }

    /**
     * @param Carbon $start
     */
    public function setStart(Carbon $start): void
    {
        $this->start = $start;
    }

    /**
     * @param Carbon $end
     */
    public function setEnd(Carbon $end): void
    {
        $this->end = $end;
    }


}
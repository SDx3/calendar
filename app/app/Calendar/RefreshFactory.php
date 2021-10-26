<?php
/*
 * RefreshFactory.php
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

namespace App\Calendar;

use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Presentation\Component;
use Eluceo\iCal\Presentation\Component\Property;
use Eluceo\iCal\Presentation\Component\Property\Value\TextValue;
use Eluceo\iCal\Presentation\Factory\EventFactory;
use Eluceo\iCal\Presentation\Factory\TimeZoneFactory;
use Generator;


/**
 * Class RefreshFactory
 */
class RefreshFactory
{
    private EventFactory    $eventFactory;
    private TimeZoneFactory $timeZoneFactory;

    /**
     * @param EventFactory|null    $eventFactory
     * @param TimeZoneFactory|null $timeZoneFactory
     */
    public function __construct(?EventFactory $eventFactory = null, ?TimeZoneFactory $timeZoneFactory = null)
    {
        $this->eventFactory    = $eventFactory ?? new EventFactory();
        $this->timeZoneFactory = $timeZoneFactory ?? new TimeZoneFactory();
    }

    public function createCalendar(Calendar $calendar): Component
    {
        $components = $this->createCalendarComponents($calendar);
        $properties = iterator_to_array($this->getProperties($calendar), false);

        return new Component('VCALENDAR', $properties, $components);
    }

    /**
     * @return iterable<Component>
     */
    protected function createCalendarComponents(Calendar $calendar): iterable
    {
        yield from $this->eventFactory->createComponents($calendar->getEvents());
        yield from $this->timeZoneFactory->createComponents($calendar->getTimeZones());
    }

    /**
     * @param Calendar $calendar
     *
     * @return Generator<Property>
     */
    private function getProperties(Calendar $calendar): Generator
    {
        /* @see https://www.ietf.org/rfc/rfc5545.html#section-3.7.3 */
        yield new Property('PRODID', new TextValue($calendar->getProductIdentifier()));
        /* @see https://www.ietf.org/rfc/rfc5545.html#section-3.7.4 */
        yield new Property('VERSION', new TextValue('2.0'));
        /* @see https://www.ietf.org/rfc/rfc5545.html#section-3.7.1 */
        yield new Property('CALSCALE', new TextValue('GREGORIAN'));

        yield new Property('X-PUBLISHED-TTL', new TextValue('60'));
    }

}
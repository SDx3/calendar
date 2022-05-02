<?php
declare(strict_types=1);

use Carbon\Carbon;
use Eluceo\iCal\Domain\Entity\Calendar;
use Eluceo\iCal\Domain\Entity\Event;
use Eluceo\iCal\Domain\ValueObject\DateTime;
use Eluceo\iCal\Domain\ValueObject\EmailAddress;
use Eluceo\iCal\Domain\ValueObject\Location;
use Eluceo\iCal\Domain\ValueObject\Organizer;
use Eluceo\iCal\Domain\ValueObject\TimeSpan;
use Eluceo\iCal\Domain\ValueObject\UniqueIdentifier;
use Eluceo\iCal\Presentation\Factory\CalendarFactory;

require 'vendor/autoload.php';

$event = new Event();

$start = Carbon::now($_ENV['TZ'])->setHour(9)->setMinute(0)->setSecond(0);
$end   = Carbon::now($_ENV['TZ'])->setHour(17)->setMinute(0)->setSecond(0);


$title       = 'Agenda = OFFLINE';
$description = 'Deze agenda is OFFLINE';
$occurrence  = new TimeSpan(new DateTime($start->toDateTime(), true), new DateTime($end->toDateTime(), true));
$organizer   = new Organizer(new EmailAddress('sander@example.com'), 'Sander', null, null);
$loc         = new Location('Offline');
$uid         = new UniqueIdentifier('abc');

$vEvent = new Event($uid);
$vEvent->setOrganizer($organizer)
       ->setSummary($title)
       ->setLocation($loc)
       ->setDescription($description)->setOccurrence($occurrence);


$calendar           = new Calendar([$event]);
$iCalendarComponent = (new CalendarFactory())->createCalendar($calendar);


header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="cal.ics"');

echo $iCalendarComponent;
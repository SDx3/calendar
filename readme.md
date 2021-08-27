# Useful habit calendar generator

A little script that generates transparent appointments in separate ICS calendars, compatible with Outlook and Google. iCal compatibility, ironically, isn't there yet.

# How it works

## Set up the app

* Copy `.env.example` to `.env`, fill it in.
* Run `composer install`
* Build the Docker image, if you need it.

## Creating appointments

In `appointments.json` you add all the things you wish to be reminded about:

```json
[
  {
    "calendar": "personal",
    "title": "Do cardio",
    "description": "Do cardio in the morning",
    "pattern": 11,
    "start_time": "07:00:00",
    "end_time": "08:00:00",
    "stacked": false
  }
]
```

The fields are as follows:

* `calendar`. Allows you to separate different types of calendar, like "personal" and "work"
* `title`, the title as it will appear in your calendar app.
* `description`, the description as it will appear in your calendar app.
* `pattern`, this is the repetition pattern the appointment will follow. These are hard coded references [to this function](app/app/CalendarGenerator.php#L188), so check out the code to see which repetition you need.
* `start_time`, the start time of the appointments.
* `end_time`, the end time of the appointments
* `stacked`, see below.

## Title and description replacements

%month, %year, %next_month, %next_year, %dag (Dutch day of the week).

%coffee_slot is a special replacement I use for coffee moments with colleagues and crew members. It cycles depending on the day of the week and the (ISO) number of the week. 

## Stacked

If you set `"stacked": true`, the generated calendar will ignore the `start_time` and `end_time` but instead will start stacking your appointments in half-our blocks, starting at 06:00 in the morning.

This can be useful when you have repeating reminders you want to see in Outlook. Three stacked appointments, would automatically be put from 06:00-06:30, 06:30-07:00 and finally 07:00-07:30.

These "stacks" will include appointments from other calendars as well. If you stack appointments in your `personal` and `work` agenda, you may find that there are "gaps".

## Extra repetitions

If you need extra repetitions, add them [to this function](app/app/CalendarGenerator.php#L188) under a new number and send a PR.

## Generate calendar

Use `https://example.com/index.php?calendar=personal` in your calendar application. In order for this to work for Google, Office 365 etc, you must host it online somewhere.

You  can change the `calendar=something` to whatever you want, as long as there are appointments for that calendar in your `appointments.json`.

# Questions

Open an issue!

cd ~/repositories/calendar && git pull && ./build.sh && cd ~/docker-swarm && ./run-calendar.sh


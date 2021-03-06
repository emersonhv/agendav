<?php

namespace AgenDAV\Event;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use AgenDAV\Event\VObjectHelper;
use AgenDAV\Event\RecurrenceId;
use Mockery as m;

class VObjectHelperTest extends \PHPUnit_Framework_TestCase
{
    protected $vcalendar;

    public function setUp()
    {
        $this->vcalendar = new VCalendar;
    }

    public function testSetBaseVEventEmptyVCalendar()
    {
        $vevent = $this->vcalendar->create('VEVENT');
        $vevent->SUMMARY = 'Test event';
        $vevent->DTSTART = new \DateTimeImmutable();

        VObjectHelper::setBaseVEvent($this->vcalendar, $vevent);

        $this->assertEquals($this->vcalendar->VEVENT, $vevent);
    }

    public function testSetBaseVEventSimpleVCalendar()
    {
        $this->vcalendar->add('VTIMEZONE', []);

        // The base VEVENT will be the second children
        $this->vcalendar->add('VEVENT', [
            'SUMMARY' => 'This vevent will disappear',
        ]);

        $vevent = $this->vcalendar->create('VEVENT');
        $vevent->SUMMARY = 'Test event';
        $vevent->DTSTART = new \DateTimeImmutable();

        VObjectHelper::setBaseVEvent($this->vcalendar, $vevent);

        $this->assertEquals($this->vcalendar->VEVENT, $vevent);
    }

    public function testSetBaseVEventWithExceptions()
    {

        $this->addBaseEventAndExceptions();

        // Keep a copy of original base VEVENT
        $original_vevent = $this->vcalendar->VEVENT[0];

        $new_vevent = $this->vcalendar->create('VEVENT');
        $new_vevent->SUMMARY = 'New base vevent';
        $new_vevent->DTSTART = '20150220T184900Z';
        $new_vevent->RRULE = 'FREQ=DAILY';

        VObjectHelper::setBaseVEvent($this->vcalendar, $new_vevent);

        // The old VEVENT should not be there anymore
        foreach ($this->vcalendar->select('VEVENT') as $vevent) {
            $this->assertNotEquals($original_vevent, $vevent, 'Old base VEVENT was not removed');
        }
    }

    /**
     * Issue #159: setBaseVEvent replaces iCalendar properties (e.g. VERSION)
     * with the base VEVENT, and it should not
     */
    public function testSetBaseVEventIssue159()
    {
        $vevent = $this->vcalendar->create('VEVENT');
        $vevent->SUMMARY = 'Test event';
        $vevent->DTSTART = new \DateTimeImmutable();

        $old_count = count($this->vcalendar->children());
        VObjectHelper::setBaseVEvent($this->vcalendar, $vevent);

        // Make sure the number of elements gets increased by one (i.e. no
        // old properties disappeared)
        $this->assertEquals(
            $old_count + 1,
            count($this->vcalendar->children()),
            'One or more properties/component vanished'
        );
    }


    public function testFindExceptionVEvent()
    {
        $this->addBaseEventAndExceptions();

        $unexisting_exception = VObjectHelper::findExceptionVEvent(
            $this->vcalendar,
            RecurrenceId::buildFromString('20150909T184900Z')
        );

        $this->assertNull($unexisting_exception);

        $existing_exception = VObjectHelper::findExceptionVEvent(
            $this->vcalendar,
            RecurrenceId::buildFromString('20150227T184900Z')
        );

        $this->assertEquals(
            $existing_exception->SUMMARY,
            'This is the first exception'
        );
    }

    public function testSetExceptionVEvent()
    {
        $this->addBaseEventAndExceptions();

        $new_exception = $this->vcalendar->create('VEVENT');
        $new_exception->SUMMARY = 'New exception';
        $new_exception->{'RECURRENCE-ID'} = '20150302T184900Z';

        VObjectHelper::setExceptionVEvent($this->vcalendar, $new_exception);

        $this->assertEquals(
            VObjectHelper::findExceptionVEvent(
                $this->vcalendar, RecurrenceId::buildFromString('20150302T184900Z')
            ),
            $new_exception,
            'VEVENT exceptions are not added'
        );

        // Add a new VEVENT using the same RECURRENCE-ID. The original one
        // should be replaced by this one
        $modified_exception = clone $new_exception;
        $modified_exception->SUMMARY = 'Modified new exception';

        VObjectHelper::setExceptionVEvent($this->vcalendar, $modified_exception);

        $this->assertEquals(
            VObjectHelper::findExceptionVEvent(
                $this->vcalendar, RecurrenceId::buildFromString('20150302T184900Z')
            ),
            $modified_exception,
            'VEVENT exceptions are not replaced'
        );
    }

    public function testAddExdateToVEventEmptyInitialExdate()
    {
        $vevent = $this->vcalendar->create('VEVENT');
        $vevent->SUMMARY = 'Test event';
        $vevent->DTSTART = new \DateTimeImmutable();
        $vevent->RRULE = 'FREQ=DAILY';

        $now = new \DateTimeImmutable();

        VObjectHelper::addExdateToVEvent($vevent, $now);

        $this->assertEquals(
            $vevent->EXDATE->getDateTimes(),
            [ $now ]
        );
    }

    public function testAddExdateToVEventExistingExdates()
    {
        $vevent = $this->vcalendar->create('VEVENT');
        $vevent->SUMMARY = 'Test event';
        $vevent->DTSTART = new \DateTimeImmutable();
        $vevent->RRULE = 'FREQ=DAILY';

        $now = new \DateTimeImmutable();
        $other = $now->modify('+1 day');

        $vevent->add('EXDATE', [ $now ]);

        VObjectHelper::addExdateToVEvent($vevent, $other);

        $this->assertEquals(
            $vevent->EXDATE->getDateTimes(),
            [ $now, $other ]
        );
    }

    public function testRemoveAllExceptions()
    {
        $this->addBaseEventAndExceptions();

        VObjectHelper::removeAllExceptions($this->vcalendar);

        $this->assertCount(
            1,
            $this->vcalendar->VEVENT,
            'removeAllExceptions() keeps some VEVENTs around'
        );

        $this->assertNull(
            $this->vcalendar->VEVENT[0]->{'RECURRENCE-ID'},
            'removeAllExceptions() removes the VEVENT with no RECURRENCE-ID!'
        );

    }

    /**
     * Internal function, used to add a base VEVENT and two exceptions
     * to the test VCALENDAR
     */
    protected function addBaseEventAndExceptions()
    {
        $this->vcalendar->add('VEVENT', [
            'SUMMARY' => 'This vevent will disappear',
            'DTSTART' => '20150220T184900Z',
            'RRULE' => 'FREQ=DAILY',
        ]);

        $this->vcalendar->add('VEVENT', [
            'SUMMARY' => 'This is the first exception',
            'RECURRENCE-ID' => '20150227T184900Z',
        ]);

        $this->vcalendar->add('VEVENT', [
            'SUMMARY' => 'This is the second exception',
            'RECURRENCE-ID' => '20150226T184900Z',
        ]);
    }
}

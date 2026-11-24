<?php

namespace Jovian\Toolkits\Qt\UI;

use QDate;
use QObject;
use QDateEdit;
use WeakReference;
use Qt\DateFormat;
use DateTimeImmutable;
use QSizePolicy\Policy;
use Surface\Toolkits\UI\TKDatepicker;
use Jovian\Toolkits\Qt\UI\Concerns\QtNative;

/**
 * A QDateEdit with its calendar popup. Picking the day already shown changes nothing, so
 * dateChanged stays silent: the calendar's clicked(QDate) reports that pick.
 */
class QtDatepicker extends TKDatepicker
{
    use QtNative;

    protected function bootstrapNative(): void
    {
        $edit = new QDateEdit();
        $edit->setMinimumDate(new QDate(self::FIRST_YEAR, 1, 1));
        $edit->setCalendarPopup(true);
        $this->adoptNative($edit);
        $this->applyDate($this->date());

        $self = WeakReference::create($this);
        $pick = static function (QDate $date) use ($self): void {
            [$year, $month, $day] = array_pad(array_map('intval', explode('-', $date->toString(DateFormat::ISO_DATE))), 3, 0);
            $self->get()?->picked($year, $month, $day);
        };
        QObject::connect($edit, 'dateChanged(QDate)', $pick);
        $calendar = $edit->calendarWidget();
        if (! is_null($calendar)) {
            QObject::connect($calendar, 'clicked(QDate)', $pick);
        }
    }

    protected function applyDate(?DateTimeImmutable $date): void
    {
        $day = $this->shownDay();
        $this->editNative()->setDate(new QDate((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j')));
    }

    /**
     * @return array{Policy, Policy}
     */
    protected function naturalSizePolicy(): array
    {
        return [Policy::MINIMUM, Policy::FIXED];
    }

    private function editNative(): QDateEdit
    {
        /** @var QDateEdit */
        return $this->native();
    }
}

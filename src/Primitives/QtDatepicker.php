<?php

namespace Jovian\Toolkits\Qt\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QDate;
use QDateEdit;
use QObject;
use Qt\DateFormat;
use QSizePolicy\Policy;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKDatepicker;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A QDateEdit with its calendar popup, showing years 100 to 9999 (Qt's widest; its default
 * floor is 1752-09-14). Dates cross ext-qt as ISO strings; a null date shows today (QDateEdit's
 * own default is 2000-01-01) while date() stays null until the user picks. Picking the day
 * already shown changes nothing, so dateChanged stays silent: the popup calendar's clicked()
 * reports that pick.
 */
class QtDatepicker extends TKDatepicker implements QtNative
{
    use QtPrimitive;

    public const string FIRST_DAY = '0100-01-01';

    /**
     * @throws WindowException When the date is outside years 100 to 9999.
     */
    public function __construct(string $name, ToolkitWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?DateTimeImmutable $date)
    {
        self::guardYear($date);
        parent::__construct($name, $window, $parent, $placement, $date);
        $edit = $this->adoptNative(new QDateEdit());
        $edit->setMinimumDate(self::qDate(new DateTimeImmutable(self::FIRST_DAY)));
        $edit->setCalendarPopup(true);
        $edit->setDate(self::qDate($date));

        QObject::connect($edit, 'dateChanged(QDate)', fn (QDate $picked) => $this->picked($picked));
        // The popup calendar is made by setCalendarPopup(true) and wired to the edit first, so a
        // pick of a new day has already arrived through dateChanged when this runs.
        $calendar = $edit->calendarWidget();
        if (! is_null($calendar)) {
            QObject::connect($calendar, 'clicked(QDate)', fn (QDate $picked) => $this->picked($picked));
        }
    }

    /**
     * @throws WindowException When the date is outside years 100 to 9999.
     */
    public function setDate(?DateTimeImmutable $date): static
    {
        $this->live();
        self::guardYear($date);

        return parent::setDate($date);
    }

    public function native(): QDateEdit
    {
        return $this->native;
    }

    protected function naturalPolicy(): array
    {
        return [Policy::MINIMUM, Policy::FIXED];
    }

    protected function applyDate(?DateTimeImmutable $date): void
    {
        $this->applying(fn () => $this->native()->setDate(self::qDate($date)));
    }

    /**
     * The user picked $picked: record and post it, unless it is the date already recorded.
     * @param QDate $picked
     * @return void
     */
    protected function picked(QDate $picked): void
    {
        $iso = $picked->toString(DateFormat::ISO_DATE);
        if (! $this->handling() || $this->date?->format('Y-m-d') === $iso) {
            return;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        $this->nativeDateChanged($date);
        $this->session()->post(new DateChanged($this->window->name(), $this->path(), $this->uuid, $date));
    }

    /**
     * @param DateTimeImmutable|null $date
     * @return void
     * @throws WindowException
     */
    protected static function guardYear(?DateTimeImmutable $date): void
    {
        $year = is_null($date) ? null : (int) $date->format('Y');
        if (! is_null($year) && ($year < 100 || $year > 9999)) {
            throw new WindowException("A date picker shows years 100 to 9999, got {$year}.");
        }
    }

    /**
     * $date, or today when null, as Qt's date.
     * @param DateTimeImmutable|null $date
     * @return QDate
     */
    protected static function qDate(?DateTimeImmutable $date): QDate
    {
        $day = $date ?? new DateTimeImmutable('today');

        return new QDate((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
    }
}

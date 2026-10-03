<?php

namespace Jovian\Toolkits\Qt\Primitives;

use DateTimeImmutable;
use Jovian\Toolkits\Qt\Contracts\Primitives\QtNative;
use Jovian\Toolkits\Qt\Primitives\Concerns\QtPrimitive;
use QDateEdit;
use QObject;
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
        $edit->setMinimumDate(self::FIRST_DAY);
        $edit->setCalendarPopup(true);
        $edit->setDate(self::iso($date));

        QObject::connect($edit, 'dateChanged(QDate)', fn (string $iso) => $this->picked($iso));
        // The popup calendar is made by setCalendarPopup(true) and wired to the edit first, so a
        // pick of a new day has already arrived through dateChanged when this runs.
        QObject::connect($edit->calendarWidget(), 'clicked(QDate)', fn (string $iso) => $this->picked($iso));
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
        $this->applying(fn () => $this->native()->setDate(self::iso($date)));
    }

    /**
     * The user picked $iso: record and post it, unless it is the date already recorded.
     * @param string $iso
     * @return void
     */
    protected function picked(string $iso): void
    {
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

    protected static function iso(?DateTimeImmutable $date): string
    {
        return ($date ?? new DateTimeImmutable('today'))->format('Y-m-d');
    }
}

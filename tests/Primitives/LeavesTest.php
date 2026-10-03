<?php

declare(strict_types=1);

use Jovian\Toolkits\Qt\Primitives\QtButton;
use Jovian\Toolkits\Qt\Primitives\QtCheckbox;
use Jovian\Toolkits\Qt\Primitives\QtDatepicker;
use Jovian\Toolkits\Qt\Primitives\QtDropdown;
use Jovian\Toolkits\Qt\Primitives\QtImage;
use Jovian\Toolkits\Qt\Primitives\QtLabel;
use Jovian\Toolkits\Qt\Primitives\QtProgressBar;
use Jovian\Toolkits\Qt\Primitives\QtSeparator;
use Jovian\Toolkits\Qt\Primitives\QtSlider;
use Jovian\Toolkits\Qt\Primitives\QtTextArea;
use Jovian\Toolkits\Qt\Primitives\QtTextInput;
use Jovian\Toolkits\Qt\Primitives\QtToggleButton;
use Qt\AlignmentFlag;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\Contracts\Windows\Primitives\ImageScaling;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\FontWeight;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

// Leave Qt quiet for the files after this one: an open window keeps posting layout and paint work.
afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.1);
    takeMail(session());
});

/** A presented 300x200 window 'main' with content column 'm', mail drained. */
function presentedColumn(): TKPrimitiveGroup
{
    $window = driver()->open('main', 300, 200);
    $main = $window->column('m');
    $window->present();
    pumpFor(0.1);
    takeMail(session());

    return $main;
}

/**
 * The shown pixmap's size in logical pixels (device pixels over the label's ratio).
 * @return array{int, int}
 */
function pixmapSize(QLabel $label): array
{
    $pixmap = $label->pixmap();

    return [(int) round($pixmap->width() / $pixmap->devicePixelRatio()), (int) round($pixmap->height() / $pixmap->devicePixelRatio())];
}

it('shows a label with text, wrap, alignment, font and colour, and posts nothing', function (): void {
    $label = presentedColumn()->label('title', 'Hello');
    pumpFor(0.05);

    expect($label)->toBeInstanceOf(QtLabel::class)
        ->and($label->native())->toBeInstanceOf(QLabel::class)
        ->and($label->native()->text())->toBe('Hello')
        ->and($label->native()->isVisible())->toBeTrue();

    $label->setText('World')
        ->setWrap(true)
        ->setAlignment(TextAlignment::CENTER)
        ->setFont(new FontSpec(13.5, FontWeight::BOLD, 'Helvetica'))
        ->setTextColor(new Color(0.0, 0.0, 1.0));

    expect($label->native()->text())->toBe('World')
        ->and($label->native()->wordWrap())->toBeTrue()
        ->and($label->native()->alignment())->toBe(AlignmentFlag::H_CENTER->value | AlignmentFlag::V_CENTER->value)
        ->and($label->native()->styleSheet())->toContain('font-size: 13.5pt;')
        ->and($label->native()->styleSheet())->toContain('font-weight: 700;')
        ->and($label->native()->styleSheet())->toContain('font-family: "Helvetica";')
        ->and($label->native()->styleSheet())->toContain('color: '.(new Color(0.0, 0.0, 1.0))->toCss().';')
        ->and(takeMail(session()))->toBe([]);

    $label->setTextColor(null)->setAlignment(TextAlignment::RIGHT);
    expect($label->native()->styleSheet())->not->toContain(' color:')
        ->and($label->native()->alignment())->toBe(AlignmentFlag::RIGHT->value | AlignmentFlag::V_CENTER->value);
});

it('posts ButtonClicked with the path and uuid, and nothing while disabled', function (): void {
    $button = presentedColumn()->button('go', 'Go');
    $button->native()->click();

    expect($button)->toBeInstanceOf(QtButton::class)
        ->and(takeMail(session()))->toEqual([new ButtonClicked('main', 'm.go', $button->uuid())])
        ->and($button->label())->toBe('Go');

    $button->setLabel('Run')->disable();
    $button->native()->click();
    expect($button->native()->text())->toBe('Run')
        ->and($button->native()->isEnabled())->toBeFalse()
        ->and(takeMail(session()))->toBe([]);
});

it('stretches a button only on the axes it fills', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->button('b', 'B');
    $window->present();
    pumpFor(0.2);
    $natural = $button->size();

    $button->fill(true, true);
    pumpFor(0.1);
    // macOS's style lays a push button out with a few invisible pixels around its bezel.
    expect($button->size()[0])->toBe(300)
        ->and($button->size()[1])->toBeGreaterThanOrEqual(200)
        ->and($button->size()[1])->toBeLessThanOrEqual(212);

    $button->fill(true, false);
    pumpFor(0.1);
    expect($button->size())->toBe([300, $natural[1]])
        ->and($natural[1])->toBeLessThan(100);
});

it('shows an image scaled to its slot, every scaling, and refuses a file it cannot load', function (): void {
    $window = driver()->open('main', 300, 200);
    $fixed = $window->fixed('f');
    $image = $fixed->at(0, 0, 100, 100)->image('i', __DIR__.'/../fixtures/wide.png');
    $window->present();
    pumpFor(0.2);

    expect($image)->toBeInstanceOf(QtImage::class)
        ->and($image->native())->toBeInstanceOf(QWidget::class)
        ->and($image->label())->toBeInstanceOf(QLabel::class)
        ->and(pixmapSize($image->label()))->toBe([100, 50])
        ->and($image->label()->pixmap()->devicePixelRatio())->toBe($image->label()->devicePixelRatioF());

    $image->setScaling(ImageScaling::FILL);
    expect(pixmapSize($image->label()))->toBe([200, 100]);

    $image->setScaling(ImageScaling::CENTER);
    expect(pixmapSize($image->label()))->toBe([40, 20])
        ->and($image->label()->alignment())->toBe(AlignmentFlag::CENTER->value);

    $image->setScaling(ImageScaling::STRETCH);
    expect(pixmapSize($image->label()))->toBe([40, 20]);

    $image->setScaling(ImageScaling::FIT);
    $fixed->resize($image, 60, 60);
    pumpFor(0.1);
    expect(pixmapSize($image->label()))->toBe([60, 30]);

    expect(fn () => $image->setFile('/nope.png'))->toThrow(WindowException::class, "Image '/nope.png' could not be loaded.")
        ->and($image->label()->pixmap()->isNull())->toBeTrue()
        ->and(fn () => $fixed->at(0, 0, 10, 10)->image('bad', '/nope.png'))->toThrow(WindowException::class, 'could not be loaded')
        ->and($fixed->children())->toBe([$image]);

    $image->setFile(__DIR__.'/../fixtures/wide.png');
    expect(pixmapSize($image->label()))->toBe([60, 30]);
    $image->setFile(null);
    expect($image->label()->pixmap()->isNull())->toBeTrue();
});

it('draws horizontal and vertical separators', function (): void {
    $main = presentedColumn();
    $horizontal = $main->separator('h');
    $vertical = $main->row('r')->separator('v', horizontal: false);

    expect($horizontal)->toBeInstanceOf(QtSeparator::class)
        ->and($horizontal->native())->toBeInstanceOf(QFrame::class)
        ->and($horizontal->native()->frameShape())->toBe(QFrame\Shape::H_LINE)
        ->and($vertical->native()->frameShape())->toBe(QFrame\Shape::V_LINE);
});

it('shows progress as a fraction, or busy for null', function (): void {
    $bar = presentedColumn()->progressBar('p', 0.25);

    expect($bar)->toBeInstanceOf(QtProgressBar::class)
        ->and($bar->native())->toBeInstanceOf(QProgressBar::class)
        ->and([$bar->native()->minimum(), $bar->native()->maximum(), $bar->native()->value()])->toBe([0, 1000, 250]);

    $bar->setFraction(null);
    expect([$bar->native()->minimum(), $bar->native()->maximum()])->toBe([0, 0]);

    $bar->setFraction(1.0);
    expect([$bar->native()->maximum(), $bar->native()->value()])->toBe([1000, 1000]);
});

it('posts TextChanged for typing, TextSubmitted on return, and nothing for code changes', function (): void {
    $main = presentedColumn();
    $input = $main->textInput('name', 'a', placeholder: 'Name');
    $secret = $main->textInput('pw', secret: true);

    expect($input)->toBeInstanceOf(QtTextInput::class)
        ->and($input->native())->toBeInstanceOf(QLineEdit::class)
        ->and($input->native()->text())->toBe('a')
        ->and($input->native()->placeholderText())->toBe('Name')
        ->and($input->native()->echoMode())->toBe(QLineEdit\EchoMode::NORMAL)
        ->and($secret->native()->echoMode())->toBe(QLineEdit\EchoMode::PASSWORD);

    $input->native()->setText('abc');
    expect(takeMail(session()))->toEqual([new TextChanged('main', 'm.name', $input->uuid(), 'abc')])
        ->and($input->value())->toBe('abc');

    QMetaObject::invokeMethod($input->native(), 'returnPressed');
    expect(takeMail(session()))->toEqual([new TextSubmitted('main', 'm.name', $input->uuid(), 'abc')]);

    $input->setValue('code')->setPlaceholder(null);
    expect(takeMail(session()))->toBe([])
        ->and($input->native()->text())->toBe('code')
        ->and($input->native()->placeholderText())->toBe('')
        ->and($input->value())->toBe('code');
});

it('posts TextChanged from a text area, and nothing for code changes', function (): void {
    $area = presentedColumn()->textArea('notes', 'x');

    expect($area)->toBeInstanceOf(QtTextArea::class)
        ->and($area->native())->toBeInstanceOf(QPlainTextEdit::class)
        ->and($area->native()->toPlainText())->toBe('x');

    $area->native()->setPlainText("one\ntwo");
    expect(takeMail(session()))->toEqual([new TextChanged('main', 'm.notes', $area->uuid(), "one\ntwo")])
        ->and($area->value())->toBe("one\ntwo");

    $area->setValue('code');
    expect(takeMail(session()))->toBe([])
        ->and($area->native()->toPlainText())->toBe('code');
});

it('posts Toggled from a check box click, and nothing for code changes', function (): void {
    $box = presentedColumn()->checkbox('remember', 'Remember me');

    expect($box)->toBeInstanceOf(QtCheckbox::class)
        ->and($box->native())->toBeInstanceOf(QCheckBox::class)
        ->and($box->native()->text())->toBe('Remember me')
        ->and($box->native()->isChecked())->toBeFalse();

    $box->native()->click();
    expect(takeMail(session()))->toEqual([new Toggled('main', 'm.remember', $box->uuid(), true)])
        ->and($box->isChecked())->toBeTrue();

    $box->setChecked(false)->setLabel('Keep me');
    expect(takeMail(session()))->toBe([])
        ->and($box->native()->isChecked())->toBeFalse()
        ->and($box->native()->text())->toBe('Keep me');
});

it('posts Toggled from a checkable button, and nothing for code changes', function (): void {
    $button = presentedColumn()->toggleButton('bold', 'B', pressed: true);

    expect($button)->toBeInstanceOf(QtToggleButton::class)
        ->and($button->native())->toBeInstanceOf(QPushButton::class)
        ->and($button->native()->isCheckable())->toBeTrue()
        ->and($button->native()->isChecked())->toBeTrue();

    $button->native()->click();
    expect(takeMail(session()))->toEqual([new Toggled('main', 'm.bold', $button->uuid(), false)])
        ->and($button->isPressed())->toBeFalse();

    $button->setPressed(true)->setLabel('Bold');
    expect(takeMail(session()))->toBe([])
        ->and($button->native()->isChecked())->toBeTrue()
        ->and($button->native()->text())->toBe('Bold');
});

it('maps a slider range onto Qt steps and posts ValueChanged for a native move', function (): void {
    $slider = presentedColumn()->slider('volume', 0.0, 10.0, 2.5);

    expect($slider)->toBeInstanceOf(QtSlider::class)
        ->and($slider->native())->toBeInstanceOf(QSlider::class)
        ->and($slider->native()->orientation())->toBe(Qt\Orientation::HORIZONTAL)
        ->and([$slider->native()->minimum(), $slider->native()->maximum(), $slider->native()->value()])->toBe([0, 10000, 2500]);

    $slider->native()->setValue(5000);
    expect(takeMail(session()))->toEqual([new ValueChanged('main', 'm.volume', $slider->uuid(), 5.0)])
        ->and($slider->value())->toBe(5.0);

    $slider->setValue(7.5);
    expect(takeMail(session()))->toBe([])
        ->and($slider->native()->value())->toBe(7500);

    $slider->setRange(0.0, 100.0);
    expect($slider->native()->value())->toBe(750)
        ->and($slider->value())->toBe(7.5);

    $slider->setRange(10.0, 20.0);
    expect($slider->value())->toBe(10.0)
        ->and($slider->native()->value())->toBe(0)
        ->and(takeMail(session()))->toBe([]);
});

it('posts SelectionChanged from a combo box, and nothing for code changes', function (): void {
    $dropdown = presentedColumn()->dropdown('size', ['S', 'M', 'L'], 1);

    expect($dropdown)->toBeInstanceOf(QtDropdown::class)
        ->and($dropdown->native())->toBeInstanceOf(QComboBox::class)
        ->and($dropdown->native()->count())->toBe(3)
        ->and($dropdown->native()->currentIndex())->toBe(1);

    $dropdown->native()->setCurrentIndex(2);
    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.size', $dropdown->uuid(), 2, 'L')])
        ->and($dropdown->selected())->toBe(2);

    $dropdown->select(0);
    $dropdown->setOptions(['XS', 'XL']);
    expect(takeMail(session()))->toBe([])
        ->and($dropdown->native()->count())->toBe(2)
        ->and($dropdown->native()->currentIndex())->toBe(0)
        ->and($dropdown->native()->currentText())->toBe('XS');

    $dropdown->setOptions([]);
    expect(takeMail(session()))->toBe([])
        ->and($dropdown->native()->count())->toBe(0)
        ->and($dropdown->native()->currentIndex())->toBe(-1)
        ->and($dropdown->selected())->toBe(-1);
});

it('posts DateChanged from a date edit, shows today for no date, and posts nothing for code changes', function (): void {
    $main = presentedColumn();
    $picker = $main->datepicker('when', new DateTimeImmutable('2026-10-02'));
    $unset = $main->datepicker('unset');
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    expect($picker)->toBeInstanceOf(QtDatepicker::class)
        ->and($picker->native())->toBeInstanceOf(QDateEdit::class)
        ->and($picker->native()->date())->toBe('2026-10-02')
        ->and($unset->native()->date())->toBe($today)
        ->and($unset->date())->toBeNull();

    $picker->native()->setDate('2026-12-25');
    expect(takeMail(session()))->toEqual([new DateChanged('main', 'm.when', $picker->uuid(), new DateTimeImmutable('2026-12-25'))])
        ->and($picker->date())->toEqual(new DateTimeImmutable('2026-12-25'));

    $picker->setDate(new DateTimeImmutable('2027-01-01'));
    expect(takeMail(session()))->toBe([])
        ->and($picker->native()->date())->toBe('2027-01-01');

    $picker->setDate(null);
    expect(takeMail(session()))->toBe([])
        ->and($picker->native()->date())->toBe($today)
        ->and($picker->date())->toBeNull();
});

it('sizes an image from the file, shrinks it with its slot and grows it back', function (): void {
    $window = driver()->open('main', 300, 200);
    $row = $window->row('r');
    $image = $row->image('i', __DIR__.'/../fixtures/wide.png');
    // A sibling that takes the spare width and can shrink to nothing.
    $row->column('rest')->fill();
    $window->present();
    pumpFor(0.2);

    // Width from the file; height is the row's (align FILL on the cross axis).
    expect($image->size()[0])->toBe(40);

    $window->native()->resize(20, 200);
    pumpFor(0.2);
    expect($image->size()[0])->toBeLessThan(40)
        ->and(pixmapSize($image->label())[0])->toBeLessThan(40);

    $window->native()->resize(300, 200);
    pumpFor(0.2);
    expect($image->size()[0])->toBe(40)
        ->and(pixmapSize($image->label()))->toBe([40, 20]);
});

it('shows button, check box and label text literally', function (): void {
    $main = presentedColumn();
    $button = $main->button('b', 'R&D');
    $box = $main->checkbox('c', 'Save & Exit');
    $toggle = $main->toggleButton('t', 'A&B');
    $label = $main->label('l', '<b>x</b>');

    expect($button->native()->text())->toBe('R&&D')
        ->and($button->label())->toBe('R&D')
        ->and($box->native()->text())->toBe('Save && Exit')
        ->and($toggle->native()->text())->toBe('A&&B')
        ->and($label->native()->textFormat())->toBe(Qt\TextFormat::PLAIN_TEXT)
        ->and($label->native()->text())->toBe('<b>x</b>');

    $button->setLabel('Q&A');
    $box->setLabel('&');
    expect($button->native()->text())->toBe('Q&&A')
        ->and($box->native()->text())->toBe('&&');
});

it('steps a slider by a hundredth on the arrow keys and a tenth on page keys', function (): void {
    $slider = presentedColumn()->slider('s', 0.0, 1.0, 0.0);

    expect($slider->native()->singleStep())->toBe(QtSlider::STEPS / 100)
        ->and($slider->native()->pageStep())->toBe(QtSlider::STEPS / 10);
});

it('posts DateChanged when the unset picker\'s shown day is picked, once per pick', function (): void {
    $main = presentedColumn();
    $unset = $main->datepicker('unset');
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');

    QMetaObject::invokeMethod($unset->native()->calendarWidget(), 'clicked', $today);
    expect(takeMail(session()))->toEqual([new DateChanged('main', 'm.unset', $unset->uuid(), new DateTimeImmutable($today))])
        ->and($unset->date())->toEqual(new DateTimeImmutable($today));

    QMetaObject::invokeMethod($unset->native()->calendarWidget(), 'clicked', '2026-12-25');
    expect(takeMail(session()))->toEqual([new DateChanged('main', 'm.unset', $unset->uuid(), new DateTimeImmutable('2026-12-25'))])
        ->and($unset->native()->date())->toBe('2026-12-25');

    QMetaObject::invokeMethod($unset->native()->calendarWidget(), 'clicked', '2026-12-25');
    expect(takeMail(session()))->toBe([]);
});

it('shows dates from year 100 to 9999 and refuses others', function (): void {
    $main = presentedColumn();
    $picker = $main->datepicker('p', new DateTimeImmutable('1500-06-01'));

    expect($picker->native()->date())->toBe('1500-06-01')
        ->and($picker->native()->minimumDate())->toBe('0100-01-01')
        ->and(fn () => $picker->setDate((new DateTimeImmutable('9999-12-31'))->modify('+1 day')))->toThrow(WindowException::class, '100 to 9999')
        ->and($picker->date())->toEqual(new DateTimeImmutable('1500-06-01'))
        ->and(fn () => $main->datepicker('old', new DateTimeImmutable('0050-01-01')))->toThrow(WindowException::class, '100 to 9999')
        ->and($main->view('old'))->toBeNull();
});

it('posts nothing from a removed primitive whose widget has not been deleted yet', function (): void {
    $main = presentedColumn();
    $button = $main->button('b', 'B');
    $box = $main->checkbox('c', 'C');
    $toggle = $main->toggleButton('t', 'T');
    $input = $main->textInput('i');
    $area = $main->textArea('a');
    $slider = $main->slider('s', 0.0, 1.0, 0.0);
    $dropdown = $main->dropdown('d', ['x', 'y']);
    $picker = $main->datepicker('p', new DateTimeImmutable('2026-10-02'));
    foreach ([$button, $box, $toggle, $input, $area, $slider, $dropdown, $picker] as $primitive) {
        $primitive->remove();
    }

    $button->native()->click();
    $box->native()->click();
    $toggle->native()->click();
    $input->native()->setText('typed');
    QMetaObject::invokeMethod($input->native(), 'returnPressed');
    $area->native()->setPlainText('typed');
    $slider->native()->setValue(5000);
    $dropdown->native()->setCurrentIndex(1);
    $picker->native()->setDate('2027-01-01');

    expect(takeMail(session()))->toBe([]);
});

it('makes a style-sheet background visible on push buttons by drawing their border too', function (): void {
    $main = presentedColumn();
    $button = $main->button('b', 'B')->setBackground(new Color(1.0, 0.0, 0.0));
    $toggle = $main->toggleButton('t', 'T')->setBackground(new Color(0.0, 1.0, 0.0));

    expect($button->native()->styleSheet())->toContain('background-color: ')
        ->and($button->native()->styleSheet())->toContain('border: 1px solid '.(new Color(1.0, 0.0, 0.0))->toCss().';')
        ->and($toggle->native()->styleSheet())->toContain('border: 1px solid ');

    $button->setBackground(null);
    expect($button->native()->styleSheet())->toBe('');
});


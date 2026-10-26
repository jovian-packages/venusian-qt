<?php

namespace Jovian\Toolkits\Qt\Primitives\Concerns;

use QIcon;
use QLabel;
use QPixmap;
use QSize;
use QStyle\SubElement;
use QStyleOptionButton;
use QVBoxLayout;
use Qt\AlignmentFlag;
use Qt\TextFormat;
use Qt\WidgetAttribute;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Primitives\ImagePosition;

/**
 * A push or toggle button's face: its label, and a Framebuffer image as a QIcon of a QPixmap
 * whose device pixel ratio is the scale, sized in points. A QPushButton draws its icon beside the
 * text only, so ABOVE leaves the button's text empty and lays out a pixmap label over a text label,
 * both transparent for mouse events so a click reaches the button, inside the rect the style draws
 * a push button's contents into (QStyle::SE_PushButtonContents; macOS insets its bezel); the
 * button's minimum size is theirs plus those insets, since a widget that is not a window takes no
 * minimum from its layout. ONLY shows the
 * icon with no text. Both name the button for accessibility by its label.
 */
trait QtButtonImage
{
    use QtPixmap;

    private const int FACE_MARGIN = 4;

    private const int FACE_SPACING = 2;

    private ?QVBoxLayout $face_layout = null;

    /** @var list<QLabel> the pixmap and text labels ABOVE lays out */
    private array $face_labels = [];

    protected function applyLabel(string $label): void
    {
        $this->applyFace();
    }

    protected function applyImage(?Framebuffer $image, ImagePosition $position, float $scale): void
    {
        $this->applyFace();
    }

    protected function applyBordered(bool $on): void
    {
        $this->native()->setFlat(! $on);
    }

    private function applyFace(): void
    {
        $button = $this->native();
        $this->dropFaceLabels();
        $label = self::literal($this->label);
        if (is_null($this->image)) {
            $button->setIcon(new QIcon(new QPixmap()));
            $button->setText($label);
            $button->setAccessibleName('');

            return;
        }
        $pixmap = self::pixmap($this->image, $this->image_scale);
        $size = new QSize((int) round($this->image->viewportWidth() / $this->image_scale), (int) round($this->image->viewportHeight() / $this->image_scale));
        $button->setAccessibleName($this->image_position === ImagePosition::LEADING ? '' : $this->label);
        if ($this->image_position !== ImagePosition::ABOVE) {
            $button->setIcon(new QIcon($pixmap));
            $button->setIconSize($size);
            $button->setText($this->image_position === ImagePosition::ONLY ? '' : $label);

            return;
        }
        $button->setIcon(new QIcon(new QPixmap()));
        $button->setText('');
        $picture = new QLabel('', $button);
        $picture->setPixmap($pixmap);
        $text = new QLabel($this->label, $button);
        $text->setTextFormat(TextFormat::PLAIN_TEXT);
        $layout = $this->face_layout ??= $this->faceLayout();
        foreach ([$picture, $text] as $part) {
            $part->setAttribute(WidgetAttribute::TRANSPARENT_FOR_MOUSE_EVENTS);
            $layout->addWidget($part, 0, AlignmentFlag::H_CENTER);
            $part->show();
        }
        $this->face_labels = [$picture, $text];
        $textHint = $text->sizeHint();
        $width = max($size->width(), $textHint->width());
        $height = $size->height() + self::FACE_SPACING + $textHint->height();
        // The style's insets can depend on the button's size (macOS draws a tall push button as a
        // bevel): measured at a first guess, then again at the size those insets give.
        $insets = $this->contentInsets($width + 2 * self::FACE_MARGIN, $height + 2 * self::FACE_MARGIN);
        $insets = $this->contentInsets($width + $insets[0] + $insets[2], $height + $insets[1] + $insets[3]);
        $layout->setContentsMargins(...$insets);
        $button->setMinimumSize($width + $insets[0] + $insets[2], $height + $insets[1] + $insets[3]);
    }

    /**
     * Where the style draws a push button's contents in a button of $width × $height: the left,
     * top, right and bottom insets of its SE_PushButtonContents rect. The button is measured at that
     * size and put back at its own.
     *
     * @return array{int, int, int, int}
     */
    private function contentInsets(int $width, int $height): array
    {
        $button = $this->native();
        $size = $button->size();
        $button->resize($width, $height);
        $option = new QStyleOptionButton();
        $option->initFrom($button);
        $contents = $button->style()->subElementRect(SubElement::PUSH_BUTTON_CONTENTS, $option, $button);
        $button->resize($size->width(), $size->height());

        return [
            $contents->x(),
            $contents->y(),
            $width - $contents->x() - $contents->width(),
            $height - $contents->y() - $contents->height(),
        ];
    }

    private function faceLayout(): QVBoxLayout
    {
        $layout = new QVBoxLayout($this->native());
        $layout->setContentsMargins(self::FACE_MARGIN, self::FACE_MARGIN, self::FACE_MARGIN, self::FACE_MARGIN);
        $layout->setSpacing(self::FACE_SPACING);

        return $layout;
    }

    private function dropFaceLabels(): void
    {
        foreach ($this->face_labels as $part) {
            $this->face_layout?->removeWidget($part);
            $part->setParent(null);
            $part->deleteLater();
        }
        if ($this->face_labels !== []) {
            $this->native()->setMinimumSize(0, 0);
        }
        $this->face_labels = [];
    }
}

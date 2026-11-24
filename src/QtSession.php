<?php

namespace Jovian\Toolkits\Qt;

use QObject;
use QWidget;
use QMessageBox;
use Qt\WidgetAttribute;
use Surface\Toolkits\ToolkitSession;
use Surface\Toolkits\Menus\AboutPanel;
use Voyager\Contracts\Core\FrameworkCore;

class QtSession extends ToolkitSession
{
    /** The About box while it is open. Qt has no About panel of its own: this one is built. */
    protected ?QMessageBox $about_box = null;

    public function __construct(
        FrameworkCore $app,
        protected readonly string $application_name,
        protected readonly string $desktop_file_name,
    ) {
        parent::__construct($app);
    }

    /**
     * A box with the application's fields, not modal. Asked again while it is open, the same
     * box comes forward with the new fields.
     */
    public function showAbout(AboutPanel $panel, ?QWidget $over = null): QMessageBox
    {
        if (is_null($this->about_box)) {
            $this->about_box = new QMessageBox($over);
            $this->about_box->setModal(false);
            $this->about_box->setAttribute(WidgetAttribute::DELETE_ON_CLOSE);
            QObject::connect($this->about_box, 'destroyed(QObject*)', function (): void {
                $this->about_box = null;
            });
        }

        $box = $this->about_box;
        $box->setWindowTitle("About {$panel->name}");
        $box->setText(is_null($panel->version) ? $panel->name : "{$panel->name} {$panel->version}");
        $box->setInformativeText(implode("\n\n", array_filter([$panel->copyright, $panel->credits])));
        $box->show();
        $box->raise();
        $box->activateWindow();

        return $box;
    }

    public function aboutBox(): ?QMessageBox
    {
        return $this->about_box;
    }

    protected function bootstrapToolkitLibrary(): void
    {
        $this->application = new Qt()
            ->setQApplicationNames($this->application_name, $this->desktop_file_name)
            ->setTimerBudget();
    }
}

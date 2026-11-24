# Venusian Qt

[![Tests](https://github.com/jovian-packages/venusian-qt/actions/workflows/tests.yml/badge.svg)](https://github.com/jovian-packages/venusian-qt/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/jovian/venusian-qt.svg)](https://packagist.org/packages/jovian/venusian-qt)
[![Total Downloads](https://img.shields.io/packagist/dt/jovian/venusian-qt.svg)](https://packagist.org/packages/jovian/venusian-qt)
[![License](https://img.shields.io/packagist/l/jovian/venusian-qt.svg)](LICENSE)

## About Venusian Qt

> **Note:** This repository contains the Qt toolkit driver for the Venusian framework. If you want to build an application using Venusian, visit the main [Venusian skeleton repository](https://github.com/VenusianPHP/venusian).

Venusian Qt is the Qt 6 toolkit driver for Venusian Topside: it connects PHP to `QApplication`, opens its windows, builds their menu bars and turns Topside's views into real Qt widgets through [ext-qt](https://github.com/php-io-extensions/qt), all in the Laravel-like syntax you already know.

Want a desktop app that looks right on KDE, Linux at large and macOS alike? Write your view once with Topside and Venusian Qt gives you the real thing: native buttons, text inputs and areas, tables, lists and trees, split and tab views, child windows and sheets, dialogs, the clipboard, and a menu bar whose Edit items, shortcuts and enabled states behave the way Qt users expect.

Need more than widgets? A canvas shows your framebuffer with no pixel passing through PHP, and lends its surface to Venusian's rendering engines, so Metal, SDL, OpenGL and Vulkan draw straight into your window. Pointer, wheel and key mail come from the same windows.

Linux and macOS. Venusian Qt runs wherever Qt 6 does, from a desktop to a Raspberry Pi. Prefer another toolkit? Reach for `jovian/venusian-gtk`, `jovian/venusian-appkit` or `jovian/venusian-raven` and keep the same views.

> **Built with AI assistance.** This package was developed LLM-assisted — not vibe-coded. Generated code was deliberately prompted and reviewed by a professional software engineer. See [AI-assisted development](#ai-assisted-development).

## Learning Venusian Qt

Venusian Qt has documentation and guides on the [Venusian website](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-qt/overview), starting with the [overview](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-qt/overview): the [session](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-qt/guide/session), [windows and menus](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-qt/guide/windows-and-menus) and [primitives](https://venusian.projectsaturnstudios.com/docs/0.11.x/ecosystem/framework-surface-modules/venusian-qt/guide/primitives).

## AI-assisted development

This codebase was built with LLM assistance under human direction. Prompts, reviews, and architectural decisions were owned by a professional software engineer — the model accelerates drafting and exploration; it does not replace judgment on APIs, dependency direction, or what ships.

## Contributing

Thank you for considering contributing to Venusian Qt! The contribution guide can be found in the [Venusian documentation](https://venusian.projectsaturnstudios.com/docs/contributions).

## Code of Conduct

In order to ensure that the Venusian community is welcoming to all, please review and abide by the [Code of Conduct](https://venusian.projectsaturnstudios.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

Please review [our security policy](https://github.com/jovian-packages/venusian-qt/security/policy) on how to report security vulnerabilities.

## License

Venusian Qt is open-sourced software licensed under the [MIT license](LICENSE).

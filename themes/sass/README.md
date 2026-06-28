# Themes & Animations Guide

This document explains how AI Engine Pro themes are structured, how window animations work, and how to build and maintain themes efficiently.

## Overview

- Source SASS lives in `themes/sass/` and compiles to `themes/*.css`.
- The primary entry is `themes/sass/messages.scss` which includes shared mixins and variables.
- Chatbots support both embedded and windowed (popup) modes; windowed mode adds specific classes and wrappers for animation.

## Build

- Compile SASS: `pnpm sass`
- Rebuild JS (only if you changed JS): `pnpm build`

## Structure

- `.mwai-messages-theme`: Root selector for message UI themes.
- `.mwai-window`: Applied when the chatbot is used as a popup window.
- `.mwai-window-box`: Inner wrapper around header/body that receives transforms/opacity during animations.
- `.mwai-header`, `.mwai-body`, `.mwai-trigger`: Core sub-elements.

Keep borders, shadows, and radii on `.mwai-window-box` when animating to avoid visual remnants.

## Animations

Window animations are defined in `_window-animations.scss` as mixins and can be applied directly or conditionally.

Mixins:
- `window-animation-zoom()` — macOS-style zoom
- `window-animation-slide()` — slide up from bottom
- `window-animation-fade()` — simple fade in/out
- `window-animation-conditional()` — enables class-based switching

Runtime classes added by the UI (when using conditional animations):
- `mwai-animation-zoom`
- `mwai-animation-slide`
- `mwai-animation-fade`
- No class → no animation

Reference durations (mirrored in JS):
- Zoom: open 200ms, close 180ms
- Slide: open 250ms, close 200ms
- Fade: open 220ms, close 180ms

Notes:
- Animate only `transform` and `opacity` for GPU acceleration.
- On mobile, headers are hidden during animations for stability.
- Fullscreen sets `.mwai-window-box` to `width: 100%; height: 100%`.
- Reduced motion: Fade respects `prefers-reduced-motion` (opacity only).

## Center Mode & Trigger

- Enable center-open with class `mwai-center-open` (set by UI when Center is on).
- The chat window centers (via root translate) while the trigger stays pinned to its corner.
- During closing (`.mwai-closing`), the trigger is hidden to avoid mid-close jumps and reappears cleanly in its corner after the window closes.
- Transform origins adapt to the trigger position for Zoom; center origin is used for centered/fullscreen cases.

## Backdrop & Layering

- Backdrop is rendered before the window in the DOM and styled under `.mwai-backdrop`.
- Z-order: backdrop (z-index 0, pointer-events none) below `.mwai-window-box` (position: relative; z-index 1).
- Prevents clicks from being blocked and avoids semi-transparent overlay intercepting input.

## Draggable Window (Desktop)

- Desktop-only header drag moves the open popup without changing the trigger position.
- Active when popup is open and not fullscreen; uses inline `top/left` on the root container.
- Class `mwai-window-dragging` pins the trigger to its corner while dragging to avoid jumps.
- Drag position resets after close so the icon always reappears in its configured corner.
- Cursor shows “move” only when fully open (not opening/closing) to avoid flicker.

## Fade Specifics

- Fade animates the window as one block: header/body are forced fully visible during opening/open so only `.mwai-window-box` fades.
- Motion profile:
  - Open: opacity 180ms ease-out; transform 220ms cubic-bezier(0.2, 0, 0, 1) from translateY(8px) scale(0.98) to neutral
  - Close: opacity 160ms ease-in; transform 180ms cubic-bezier(0.4, 0, 1, 1)

## Creating a New Theme

1. Duplicate an existing theme section in `messages.scss` under a new root class (e.g., `.mwai-mytheme-theme`).
2. Define variables (colors, radii, typography), spacing, and component rules.
3. If your theme has a special container/header/footer, ensure borders/shadows are applied to `.mwai-window-box`.
4. Import animations: `@use 'window-animations' as animations;` and include either a fixed animation or the conditional mixin.
5. Test positions (`top-left`, `top-right`, `bottom-left`, `bottom-right`), `centerOpen`, dragging, and fullscreen across desktop/mobile.

## Maintenance Tips

- Keep animation durations in `_window-animations.scss` and update the JS timing map accordingly.
- Avoid animating layout properties (width/height/margins). Prefer `transform` and `opacity`.
- Validate mobile behavior around `760px` and below; ensure the trigger stays anchored and the header hides during transitions.
- When adding a new animation, implement a mixin and add it to `window-animation-conditional()`.
- Keep selector specificity low by hanging rules under the theme root class.

## Known Gotchas

- Borders/shadows on the outer container (not on `.mwai-window-box`) cause artifacts mid-transition.
- Missing `opening`/`closing` JS state yields flicker/incomplete transitions.
- If headers flash on mobile, ensure the mobile override that hides `.mwai-header` during animations is active.
- If the trigger appears mid-screen during close, ensure `.mwai-closing .mwai-trigger { display: none }` is present and backdrop layering is correct.

## Contributing

- Keep changes scoped; prefer small, composable additions.
- Document important decisions inline and update this README as patterns evolve.
